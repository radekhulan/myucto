<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRegistrationEventBusinessDeduplicationMigrationRuntimeTest extends TestCase
{
    private const MIGRATION = '1605_payroll_registration_event_business_deduplication.sql';

    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_regzec_business_' . bin2hex(random_bytes(6));
        try {
            $this->server = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;charset=utf8mb4',
                    (string) $this->config->get('db.host', '127.0.0.1'),
                    (int) $this->config->get('db.port', 3306),
                ),
                (string) $this->config->get('db.user'),
                (string) $this->config->get('db.pass', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $this->server->exec(
                "CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            );
            $this->createSnapshotsTable();
        } catch (\Throwable $exception) {
            $this->markTestSkipped(
                'Nelze vytvořit izolovanou DB: ' . $exception->getMessage(),
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testMigrationAddsBusinessKeyToEmptyHistory(): void
    {
        $result = $this->runMigrator();

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertSame(1, $this->businessIndexCount());
        self::assertSame(0, $this->snapshotCount());
    }

    public function testMigrationPreservesPopulatedHistoryAndIsIdempotent(): void
    {
        $this->insertSnapshot('populated-first', 'a');
        $this->insertSnapshot('populated-second', 'b');

        $first = $this->runMigrator();
        self::assertSame(0, $first['exit_code'], $first['output']);
        self::assertSame(1, $this->businessIndexCount());
        self::assertSame(2, $this->snapshotCount());
        self::assertSame('utf8mb4_bin', $this->sourceReferenceCollation());

        $this->insertSnapshot('Case-42', 'c');
        $this->insertSnapshot('case-42', 'd');
        self::assertSame(4, $this->snapshotCount());

        $this->db()->exec(
            "DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'",
        );
        $second = $this->runMigrator();
        self::assertSame(0, $second['exit_code'], $second['output']);
        self::assertSame(1, $this->businessIndexCount());
        self::assertSame(4, $this->snapshotCount());
    }

    public function testMigrationFailsClosedForPopulatedBusinessDuplicates(): void
    {
        $this->insertSnapshot('duplicate-source', 'a');
        $this->insertSnapshot('duplicate-source', 'b');

        $result = $this->runMigrator();

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString(
            'business-key duplicates require manual resolution',
            $result['output'],
        );
        self::assertSame(0, $this->migrationMarkerCount());
        self::assertSame(0, $this->businessIndexCount());
        self::assertSame(2, $this->snapshotCount());
    }

    private function createSnapshotsTable(): void
    {
        $this->db()->exec(
            'CREATE TABLE payroll_registration_event_snapshots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                environment ENUM("production", "test") NOT NULL,
                employment_id BIGINT UNSIGNED NOT NULL,
                interaction_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                effective_on DATE NOT NULL,
                source_reference VARCHAR(191) NOT NULL,
                snapshot_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->db()->exec(
            'CREATE TRIGGER trg_payroll_registration_event_test_immutable_update
             BEFORE UPDATE ON payroll_registration_event_snapshots
             FOR EACH ROW SIGNAL SQLSTATE "45000"
             SET MESSAGE_TEXT = "Test history must remain immutable"',
        );
        $this->db()->exec(
            'CREATE TRIGGER trg_payroll_registration_event_test_immutable_delete
             BEFORE DELETE ON payroll_registration_event_snapshots
             FOR EACH ROW SIGNAL SQLSTATE "45000"
             SET MESSAGE_TEXT = "Test history must remain immutable"',
        );
    }

    private function insertSnapshot(string $sourceReference, string $fingerprintSeed): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO payroll_registration_event_snapshots
                (supplier_id, environment, employment_id, interaction_code,
                 effective_on, source_reference, snapshot_fingerprint)
             VALUES (1, "test", 10, "change", "2026-03-30", ?, ?)',
        );
        $statement->execute([
            $sourceReference,
            hash('sha256', $fingerprintSeed),
        ]);
    }

    private function businessIndexCount(): int
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = "payroll_registration_event_snapshots"
                AND INDEX_NAME = "uq_payroll_registration_event_business"',
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function migrationMarkerCount(): int
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*) FROM migrations WHERE filename = ?',
        );
        $statement->execute([self::MIGRATION]);

        return (int) $statement->fetchColumn();
    }

    private function sourceReferenceCollation(): string
    {
        $statement = $this->db()->query(
            'SELECT COLLATION_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = "payroll_registration_event_snapshots"
                AND COLUMN_NAME = "source_reference"',
        );

        return (string) $statement->fetchColumn();
    }

    private function snapshotCount(): int
    {
        return (int) $this->db()->query(
            'SELECT COUNT(*) FROM payroll_registration_event_snapshots',
        )->fetchColumn();
    }

    private function db(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $this->config->get('db.host', '127.0.0.1'),
                (int) $this->config->get('db.port', 3306),
                $this->database,
            ),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /** @return array{exit_code:int,output:string} */
    private function runMigrator(): array
    {
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--only=' . self::MIGRATION,
        ];
        $environment = getenv();
        $environment['MYINVOICE_DB_NAME'] = $this->database;
        $environment['MYSQL_DATABASE'] = $this->database;
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->rootDir,
            $environment,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'output' => "{$stdout}\n{$stderr}",
        ];
    }
}
