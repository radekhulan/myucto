<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollIncomingRefundMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_payroll_refund_' . bin2hex(random_bytes(6));
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
            $this->createLegacySchema();
        } catch (\Throwable $exception) {
            $this->markTestSkipped(
                'Nelze vytvořit izolovanou DB: ' . $exception->getMessage(),
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->server === null
            || preg_match('/^myucto_payroll_refund_[0-9a-f]{12}$/D', $this->database) !== 1
        ) {
            return;
        }
        $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
    }

    public function testExistingMatchesAreBackfilledAndGuardRemainsImmutable(): void
    {
        $db = $this->db();
        self::assertFalse($this->columnExists('payroll_payment_matches', 'liability_id'));

        $this->runMigrator();

        self::assertTrue($this->columnExists('payroll_payment_matches', 'liability_id'));
        self::assertSame(
            '10',
            (string) $db->query(
                'SELECT liability_id FROM payroll_payment_matches WHERE id = 30',
            )->fetchColumn(),
        );
        $this->assertMatchIsImmutable($db);

        $db->exec(
            "DELETE FROM migrations
              WHERE filename = '1559_payroll_incoming_refund_reconciliation.sql'",
        );
        $this->runMigrator();

        self::assertSame(
            '10',
            (string) $db->query(
                'SELECT liability_id FROM payroll_payment_matches WHERE id = 30',
            )->fetchColumn(),
        );
        $this->assertMatchIsImmutable($db);
    }

    private function createLegacySchema(): void
    {
        $this->db()->exec(
            "CREATE TABLE payroll_payment_liabilities (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                amount_minor BIGINT UNSIGNED NOT NULL,
                direction VARCHAR(16) NOT NULL,
                currency_code CHAR(3) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_liability_owner (supplier_id,id)
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_payment_allocations (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                liability_id BIGINT UNSIGNED NOT NULL,
                amount_minor BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_allocation_owner (supplier_id,id)
             ) ENGINE=InnoDB;
             CREATE TABLE bank_statements (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL,
                PRIMARY KEY (id)
             ) ENGINE=InnoDB;
             CREATE TABLE bank_transactions (
                id BIGINT UNSIGNED NOT NULL,
                statement_id BIGINT UNSIGNED NOT NULL,
                posted_at DATE NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                currency CHAR(3) NULL,
                import_fingerprint VARCHAR(191) NULL,
                PRIMARY KEY (id)
             ) ENGINE=InnoDB;
             CREATE TABLE cash_documents (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                issue_date DATE NOT NULL,
                total_amount DECIMAL(15,2) NOT NULL,
                currency_code CHAR(3) NOT NULL,
                doc_type VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                doc_number VARCHAR(191) NULL,
                PRIMARY KEY (id)
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_payment_matches (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                allocation_id BIGINT UNSIGNED NOT NULL,
                event_kind VARCHAR(16) NOT NULL,
                source_match_id BIGINT UNSIGNED NULL,
                amount_minor BIGINT NOT NULL,
                bank_statement_id BIGINT UNSIGNED NULL,
                bank_transaction_id BIGINT UNSIGNED NULL,
                cash_document_id BIGINT UNSIGNED NULL,
                actual_payment_date DATE NOT NULL,
                evidence_amount_minor BIGINT UNSIGNED NOT NULL,
                evidence_currency_code CHAR(3) NOT NULL,
                evidence_fact_hash CHAR(64) NOT NULL,
                idempotency_key_hash BINARY(32) NOT NULL,
                matched_by BIGINT UNSIGNED NULL,
                created_at DATETIME(6) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_match_owner (supplier_id,id)
             ) ENGINE=InnoDB;
             CREATE TRIGGER trg_payroll_payment_match_immutable_update
             BEFORE UPDATE ON payroll_payment_matches
             FOR EACH ROW
             SIGNAL SQLSTATE '45000'
               SET MESSAGE_TEXT = 'Payroll payment matches are immutable';
             INSERT INTO payroll_payment_liabilities
                (id,supplier_id,amount_minor,direction,currency_code)
             VALUES (10,7,5000,'outgoing','CZK');
             INSERT INTO payroll_payment_allocations
                (id,supplier_id,liability_id,amount_minor)
             VALUES (20,7,10,5000);
             INSERT INTO payroll_payment_matches
                (id,supplier_id,allocation_id,event_kind,source_match_id,
                 amount_minor,bank_statement_id,bank_transaction_id,
                 cash_document_id,actual_payment_date,evidence_amount_minor,
                 evidence_currency_code,evidence_fact_hash,
                 idempotency_key_hash,matched_by,created_at)
             VALUES (30,7,20,'matched',NULL,5000,40,50,NULL,'2026-08-20',
                     5000,'CZK',REPEAT('a',64),UNHEX(REPEAT('01',32)),1,
                     '2026-08-20 12:00:00.000000')",
        );
    }

    private function assertMatchIsImmutable(PDO $db): void
    {
        try {
            $db->exec(
                'UPDATE payroll_payment_matches SET amount_minor = 4999 WHERE id = 30',
            );
            self::fail('Neměnný ledger dovolil změnit částku existujícího párování.');
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                'Payroll payment matches are immutable',
                $exception->getMessage(),
            );
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*)
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        );
        $statement->execute([$this->database, $table, $column]);

        return (int) $statement->fetchColumn() === 1;
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

    private function runMigrator(): void
    {
        $filename = '1559_payroll_incoming_refund_reconciliation.sql';
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            "--only={$filename}",
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
        self::assertSame(
            0,
            proc_close($process),
            "Migrátor selhal.\n{$stdout}\n{$stderr}",
        );
    }
}
