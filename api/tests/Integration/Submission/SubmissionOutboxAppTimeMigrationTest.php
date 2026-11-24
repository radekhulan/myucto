<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 2339 převede časy odchozí fronty zapsané přes `UTC_TIMESTAMP()` na čas
 * aplikace, a to právě jednou. Běží proti vlastní dočasné databázi s tabulkami
 * v rozsahu, kterého se migrace dotýká, protože DDL a výměna triggeru se nedají
 * vrátit transakcí.
 */
#[Group('integration')]
final class SubmissionOutboxAppTimeMigrationTest extends TestCase
{
    private const MIGRATION = '2339_submission_outbox_app_time.sql';

    private ?PDO $db = null;
    private string $database = '';
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $config = Config::load($this->rootDir);
        $this->database = 'myucto_outbox_time_' . bin2hex(random_bytes(6));
        $this->db = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                (string) $config->get('db.host', '127.0.0.1'),
                (int) $config->get('db.port', 3306),
            ),
            (string) $config->get('db.user'),
            (string) $config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->db->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->db->exec("USE `{$this->database}`");
        $this->db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        // `tax_submissions.submitted_at` je TIMESTAMP: čte se a zapisuje v zóně
        // session, stejně jako ho zapsala aplikace (Connection nastavuje app.timezone).
        $this->db->exec("SET time_zone = 'Europe/Prague'");
        $this->db->exec(
            "CREATE TABLE submission_outbox (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                environment ENUM('production','test') NOT NULL,
                channel VARCHAR(32) NOT NULL,
                dispatch_mode ENUM('channel','manual','gateway') NOT NULL DEFAULT 'channel',
                agenda_code VARCHAR(48) NOT NULL,
                artifact_kind VARCHAR(32) NOT NULL,
                artifact_id BIGINT UNSIGNED NOT NULL,
                artifact_sha256 CHAR(64) NOT NULL,
                idempotency_key_hash BINARY(32) NOT NULL,
                correlation_reference VARCHAR(64) NOT NULL,
                external_message_id VARCHAR(64) NULL,
                dispatch_state VARCHAR(16) NOT NULL DEFAULT 'ready',
                acceptance_state VARCHAR(16) NOT NULL DEFAULT 'unknown',
                artifact_validated_at DATETIME NULL,
                recipient_box_verified_at DATETIME NULL,
                confirmed_at DATETIME NULL,
                sent_at DATETIME NULL,
                delivered_at DATETIME NULL,
                accepted_at DATETIME NULL,
                rejected_at DATETIME NULL,
                failed_at DATETIME NULL,
                receipt_document_id BIGINT UNSIGNED NULL,
                receipt_matched_by VARCHAR(32) NULL,
                receipt_attached_at DATETIME NULL,
                row_version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB;
             CREATE TABLE submission_outbox_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                outbox_id BIGINT UNSIGNED NOT NULL,
                outcome VARCHAR(16) NOT NULL
             ) ENGINE=InnoDB;
             CREATE TABLE tax_submissions (
                id INT UNSIGNED PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                submission_ref VARCHAR(100) NULL,
                submitted_at TIMESTAMP NULL DEFAULT NULL
             ) ENGINE=InnoDB",
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null
            && preg_match('/^myucto_outbox_time_[0-9a-f]{12}$/D', $this->database) === 1
        ) {
            \MyInvoice\Service\Anonymization\DatabaseCloner::dropDatabase($this->db, $this->database);
        }
    }

    public function testUtcTimesAreConvertedExactlyOnceAndNewRowsAreAppTime(): void
    {
        // Odeslané kanálem (pokus `sent` v ledgeru): UTC → čas aplikace, i přes půlnoc.
        $channel = $this->outbox('DM-1', 'channel', '2026-08-20 22:00:00', null, [
            'confirmed_at' => '2026-08-20 21:59:00',
            'artifact_validated_at' => '2026-01-15 23:30:00',
            'recipient_box_verified_at' => '2026-08-20 21:58:00',
            'receipt_attached_at' => '2026-10-25 00:30:00',
        ], 1, true);
        // Ručně odeslané: `sent_at` nese čas z doručenky a zůstává.
        $manual = $this->outbox('DM-2', 'manual', '2026-08-20 22:00:00', null, [
            'confirmed_at' => '2026-03-29 00:59:59',
            'failed_at' => '2026-03-29 01:00:00',
        ], 2, false);
        // Převod by `sent_at` posunul za doručení: zůstává.
        $late = $this->outbox('DM-3', 'channel', '2026-08-20 22:00:00', '2026-08-20 22:30:00', [], 3, true);
        $this->tax(1, 'ISDS DM-1', '2026-08-20 22:00:00');
        $this->tax(2, 'ISDS DM-2', '2026-08-20 22:00:00');
        $this->tax(4, 'ISDS DM-1', '2026-08-20 21:00:00');

        $this->runMigrator();
        $first = $this->snapshot();

        self::assertSame('2026-08-21 00:00:00', $first['outbox'][$channel]['sent_at']);
        self::assertSame('2026-08-20 23:59:00', $first['outbox'][$channel]['confirmed_at']);
        self::assertSame('2026-01-16 00:30:00', $first['outbox'][$channel]['artifact_validated_at']);
        self::assertSame('2026-08-20 23:58:00', $first['outbox'][$channel]['recipient_box_verified_at']);
        // Poslední neděle v říjnu 2026 je 25. 10., přechod na SEČ v 01:00 UTC.
        self::assertSame('2026-10-25 02:30:00', $first['outbox'][$channel]['receipt_attached_at']);
        self::assertSame('2', $first['outbox'][$channel]['row_version']);
        self::assertSame('app', $first['outbox'][$channel]['time_basis']);

        self::assertSame('2026-08-20 22:00:00', $first['outbox'][$manual]['sent_at']);
        // Poslední neděle v březnu 2026 je 29. 3., přechod na SELČ v 01:00 UTC.
        self::assertSame('2026-03-29 01:59:59', $first['outbox'][$manual]['confirmed_at']);
        self::assertSame('2026-03-29 03:00:00', $first['outbox'][$manual]['failed_at']);

        self::assertSame('2026-08-20 22:00:00', $first['outbox'][$late]['sent_at']);
        self::assertSame('app', $first['outbox'][$late]['time_basis']);

        self::assertSame('2026-08-21 00:00:00', $first['tax'][1]);
        self::assertSame('2026-08-20 22:00:00', $first['tax'][2], 'Ruční odeslání se nepřevádí.');
        self::assertSame('2026-08-20 21:00:00', $first['tax'][4], 'Čas, který neodpovídá frontě, se nepřevádí.');

        // Druhý běh nemá co převést.
        $this->db->exec("DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'");
        $this->runMigrator();
        self::assertSame($first, $this->snapshot());

        // Nový zápis je v čase aplikace bez zásahu kódu.
        $fresh = $this->outbox('DM-4', 'channel', null, null, [], 5, false);
        self::assertSame('app', $this->snapshot()['outbox'][$fresh]['time_basis']);

        // Trigger je zpátky: `sent_at` je dál jednorázový zápis.
        $this->expectException(PDOException::class);
        $this->db->exec(
            "UPDATE submission_outbox SET sent_at = '2026-08-22 10:00:00', row_version = row_version + 1
              WHERE id = {$channel}",
        );
    }

    /** @param array<string,string> $times */
    private function outbox(
        string $messageId,
        string $mode,
        ?string $sentAt,
        ?string $deliveredAt,
        array $times,
        int $taxId,
        bool $sentAttempt,
    ): int {
        $columns = ['supplier_id', 'environment', 'channel', 'dispatch_mode', 'agenda_code', 'artifact_kind',
            'artifact_id', 'artifact_sha256', 'idempotency_key_hash', 'correlation_reference',
            'external_message_id', 'dispatch_state', 'sent_at', 'delivered_at', ...array_keys($times)];
        $values = [7, 'production', 'isds', $mode, 'DPHDP3', 'tax_submission', $taxId, str_repeat('a', 64),
            hash('sha256', $messageId, true), 'TEST-' . $messageId, $sentAt === null ? null : $messageId,
            $sentAt === null ? 'ready' : ($deliveredAt === null ? 'sent' : 'delivered'),
            $sentAt, $deliveredAt, ...array_values($times)];
        $this->db->prepare(
            'INSERT INTO submission_outbox (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
        )->execute($values);
        $id = (int) $this->db->lastInsertId();
        if ($sentAttempt) {
            $this->db->prepare(
                "INSERT INTO submission_outbox_attempts (supplier_id, outbox_id, outcome) VALUES (7, ?, 'sent')",
            )->execute([$id]);
        }

        return $id;
    }

    private function tax(int $id, string $reference, string $submittedAt): void
    {
        $this->db->prepare(
            'INSERT INTO tax_submissions (id, supplier_id, submission_ref, submitted_at) VALUES (?, 7, ?, ?)',
        )->execute([$id, $reference, $submittedAt]);
    }

    /** @return array{outbox:array<int,array<string,?string>>,tax:array<int,?string>} */
    private function snapshot(): array
    {
        $outbox = [];
        foreach ($this->db->query(
            'SELECT id, confirmed_at, sent_at, delivered_at, failed_at, accepted_at, rejected_at,
                    artifact_validated_at, recipient_box_verified_at, receipt_attached_at,
                    row_version, time_basis
               FROM submission_outbox ORDER BY id',
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $outbox[(int) $row['id']] = array_map(
                static fn (mixed $value): ?string => $value === null ? null : (string) $value,
                $row,
            );
        }

        return [
            'outbox' => $outbox,
            'tax' => $this->db->query('SELECT id, submitted_at FROM tax_submissions ORDER BY id')
                ->fetchAll(PDO::FETCH_KEY_PAIR),
        ];
    }

    private function runMigrator(): void
    {
        $env = getenv();
        self::assertIsArray($env);
        $env['MYINVOICE_DB_NAME'] = $this->database;
        $env['MYSQL_DATABASE'] = $this->database;
        $env['MYINVOICE_SCHEMA_CACHE'] = '0';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $this->rootDir . '/api/bin/migrate.php', '--no-backfills', '--no-analyze', '--only=' . self::MIGRATION],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->rootDir,
            $env,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "Migrátor selhal.\n{$stdout}\n{$stderr}");
    }
}
