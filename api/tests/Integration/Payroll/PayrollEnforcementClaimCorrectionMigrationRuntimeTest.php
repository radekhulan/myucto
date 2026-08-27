<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollEnforcementClaimCorrectionMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_enforcement_claim_' . bin2hex(random_bytes(6));
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
            || preg_match('/^myucto_enforcement_claim_[0-9a-f]{12}$/D', $this->database) !== 1
        ) {
            return;
        }
        $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
    }

    public function testMigrationTwiceAllowsOnlyUnusedPreActivationClaimMutation(): void
    {
        $db = $this->db();
        $this->runMigrator();

        $db->exec(
            'UPDATE payroll_enforcement_claims
                SET outstanding_minor_units = 120000, row_version = row_version + 1
              WHERE id = 10',
        );
        self::assertSame(
            '120000',
            (string) $db->query(
                'SELECT outstanding_minor_units FROM payroll_enforcement_claims WHERE id = 10',
            )->fetchColumn(),
        );
        $db->exec('DELETE FROM payroll_enforcement_claims WHERE id = 10');
        self::assertSame(
            '0',
            (string) $db->query(
                'SELECT COUNT(*) FROM payroll_enforcement_claims WHERE id = 10',
            )->fetchColumn(),
        );

        $db->exec(
            "INSERT INTO payroll_enforcement_claims
                (id,supplier_id,case_id,claim_key,outstanding_minor_units,row_version)
             VALUES (11,7,1,'claim_snapshot',100000,1);
             INSERT INTO payroll_enforcement_month_results
                (supplier_id,input_snapshot_json)
             VALUES (7,'{\"claims\":[{\"id\":\"claim_snapshot\"}]}')",
        );
        $this->assertBlocked(
            $db,
            'UPDATE payroll_enforcement_claims SET outstanding_minor_units = 1 WHERE id = 11',
        );
        $this->assertBlocked(
            $db,
            'DELETE FROM payroll_enforcement_claims WHERE id = 11',
        );

        $db->exec(
            "INSERT INTO payroll_enforcement_claims
                (id,supplier_id,case_id,claim_key,outstanding_minor_units,row_version)
             VALUES (12,7,1,'claim_payment',100000,1);
             INSERT INTO payroll_payment_liabilities
                (supplier_id,liability_kind,liability_reference)
             VALUES (7,'enforcement','enforcement:c1:cl12')",
        );
        $this->assertBlocked(
            $db,
            'DELETE FROM payroll_enforcement_claims WHERE id = 12',
        );

        $db->exec(
            "INSERT INTO payroll_enforcement_claims
                (id,supplier_id,case_id,claim_key,outstanding_minor_units,row_version)
             VALUES (14,7,1,'claim_ledger',100000,1);
             INSERT INTO payroll_enforcement_ledger (supplier_id,claim_id)
             VALUES (7,14)",
        );
        $this->assertBlocked(
            $db,
            'UPDATE payroll_enforcement_claims SET outstanding_minor_units = 2 WHERE id = 14',
        );
        $this->assertBlocked(
            $db,
            'DELETE FROM payroll_enforcement_claims WHERE id = 14',
        );

        $db->exec(
            "DELETE FROM payroll_payment_liabilities;
             UPDATE payroll_enforcement_cases SET status = 'withhold_and_hold' WHERE id = 1",
        );
        $this->assertBlocked(
            $db,
            'UPDATE payroll_enforcement_claims SET outstanding_minor_units = 2 WHERE id = 12',
        );

        $db->exec(
            "DELETE FROM migrations
              WHERE filename = '1560_payroll_enforcement_claim_correction.sql'",
        );
        $this->runMigrator();

        $this->assertBlocked(
            $db,
            'DELETE FROM payroll_enforcement_claims WHERE id = 11',
        );
        $db->exec(
            "UPDATE payroll_enforcement_cases SET status = 'received' WHERE id = 1;
             INSERT INTO payroll_enforcement_claims
                (id,supplier_id,case_id,claim_key,outstanding_minor_units,row_version)
             VALUES (13,7,1,'claim_after_second_run',100000,1);
             DELETE FROM payroll_enforcement_claims WHERE id = 13",
        );
        self::assertSame(
            '0',
            (string) $db->query(
                'SELECT COUNT(*) FROM payroll_enforcement_claims WHERE id = 13',
            )->fetchColumn(),
        );
    }

    public function testFirstPayerPriorityMigrationBackfillsOnlyDeliveredClaimsAndGuardsDrift(): void
    {
        $db = $this->db();
        $this->runMigrator();
        $this->runMigrator('1594_payroll_enforcement_first_payer_priority.sql');

        self::assertSame(
            '2026-05-20',
            (string) $db->query(
                'SELECT first_payer_delivered_on FROM payroll_enforcement_claims WHERE id = 10',
            )->fetchColumn(),
        );
        self::assertFalse((bool) $db->query(
            'SELECT first_payer_delivered_on IS NOT NULL FROM payroll_enforcement_claims WHERE id = 15',
        )->fetchColumn());
        self::assertSame(
            '2026-05-18',
            (string) $db->query(
                'SELECT first_payer_delivered_on FROM payroll_enforcement_claims WHERE id = 16',
            )->fetchColumn(),
        );

        $db->exec(
            "INSERT INTO payroll_enforcement_claims
                (id, supplier_id, case_id, claim_key, legal_basis,
                 outstanding_minor_units, priority_date, first_payer_delivered_on,
                 order_or_notice_delivered, row_version)
             VALUES (11, 7, 1, 'claim_derived', 'statutory', 100000,
                     '2000-01-01', '2026-05-21', 1, 1)",
        );
        self::assertSame(
            '2026-05-21',
            (string) $db->query(
                'SELECT priority_date FROM payroll_enforcement_claims WHERE id = 11',
            )->fetchColumn(),
        );
        $db->exec(
            "UPDATE payroll_enforcement_claims
                SET priority_date = '2000-01-01' WHERE id = 11",
        );
        self::assertSame(
            '2026-05-21',
            (string) $db->query(
                'SELECT priority_date FROM payroll_enforcement_claims WHERE id = 11',
            )->fetchColumn(),
        );
        $db->exec(
            "UPDATE payroll_enforcement_claims
                SET first_payer_delivered_on = '2026-05-23', priority_date = '2000-01-01'
              WHERE id = 15",
        );
        self::assertSame(
            ['2026-05-23', '2026-05-23'],
            $db->query(
                'SELECT first_payer_delivered_on, priority_date
                   FROM payroll_enforcement_claims WHERE id = 15',
            )->fetch(PDO::FETCH_NUM),
        );
        $this->assertBlocked(
            $db,
            "UPDATE payroll_enforcement_claims
                SET first_payer_delivered_on = '2026-05-24' WHERE id = 15",
            'Statutory enforcement first payer delivery date is immutable',
        );
        $this->assertBlocked(
            $db,
            "INSERT INTO payroll_enforcement_claims
                (id, supplier_id, case_id, claim_key, legal_basis,
                 outstanding_minor_units, row_version)
             VALUES (12, 7, 1, 'claim_missing_delivery', 'statutory', 100000, 1)",
            'Statutory enforcement claim requires first payer delivery date',
        );
        $db->exec(
            "INSERT INTO payroll_enforcement_claims
                (id, supplier_id, case_id, claim_key, legal_basis,
                 outstanding_minor_units, priority_date, row_version)
             VALUES (13, 7, 1, 'claim_voluntary', 'voluntary_agreement', 100000,
                     '2026-05-22', 1)",
        );
        self::assertSame(
            '2026-05-22',
            (string) $db->query(
                'SELECT priority_date FROM payroll_enforcement_claims WHERE id = 13',
            )->fetchColumn(),
        );

        $db->exec(
            "DELETE FROM migrations
              WHERE filename = '1594_payroll_enforcement_first_payer_priority.sql'",
        );
        $this->runMigrator('1594_payroll_enforcement_first_payer_priority.sql');
        self::assertSame(
            '2026-05-21',
            (string) $db->query(
                'SELECT priority_date FROM payroll_enforcement_claims WHERE id = 11',
            )->fetchColumn(),
        );
    }

    private function createLegacySchema(): void
    {
        $this->db()->exec(
            "CREATE TABLE payroll_enforcement_cases (
                id BIGINT UNSIGNED PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_enforcement_claims (
                id BIGINT UNSIGNED PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                case_id BIGINT UNSIGNED NOT NULL,
                claim_key VARCHAR(64) NOT NULL,
                enforcement_order_key VARCHAR(64) NULL,
                legal_basis VARCHAR(32) NOT NULL DEFAULT 'statutory',
                category VARCHAR(32) NOT NULL DEFAULT 'non_priority',
                outstanding_minor_units BIGINT UNSIGNED NOT NULL,
                maintenance_weight_minor_units BIGINT UNSIGNED NULL,
                priority_date DATE NULL,
                order_issued_on DATE NULL,
                legal_title_verified TINYINT(1) NOT NULL DEFAULT 0,
                order_or_notice_delivered TINYINT(1) NOT NULL DEFAULT 0,
                priority_classification_verified TINYINT(1) NOT NULL DEFAULT 0,
                agreement_verified TINYINT(1) NOT NULL DEFAULT 0,
                due_monetary_claim_verified TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                row_version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_enforcement_month_results (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                input_snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(input_snapshot_json))
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_enforcement_allocations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                claim_id BIGINT UNSIGNED NULL
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_enforcement_ledger (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                claim_id BIGINT UNSIGNED NULL
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_payment_liabilities (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                liability_kind VARCHAR(32) NOT NULL,
                liability_reference VARCHAR(96) NOT NULL
             ) ENGINE=InnoDB;
             CREATE TRIGGER trg_payroll_enforcement_claim_immutable_delete
             BEFORE DELETE ON payroll_enforcement_claims
             FOR EACH ROW
             SIGNAL SQLSTATE '45000'
               SET MESSAGE_TEXT = 'Payroll enforcement claims cannot be hard-deleted';
             INSERT INTO payroll_enforcement_cases (id,supplier_id,status)
             VALUES (1,7,'received');
             INSERT INTO payroll_enforcement_claims
                (id,supplier_id,case_id,claim_key,legal_basis,outstanding_minor_units,
                 priority_date,order_or_notice_delivered,row_version)
             VALUES (10,7,1,'claim_unused','statutory',100000,'2026-05-20',1,1),
                    (15,7,1,'claim_undelivered','statutory',100000,'2026-05-19',0,1),
                    (16,7,1,'claim_used','statutory',100000,'2026-05-18',1,1);
             INSERT INTO payroll_enforcement_allocations (supplier_id,claim_id)
             VALUES (7,16)",
        );
    }

    private function assertBlocked(PDO $db, string $sql, ?string $message = null): void
    {
        try {
            $db->exec($sql);
            self::fail('Databázový fail-closed guard dovolil změnit použitou pohledávku.');
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                $message ?? 'Payroll enforcement claim has a retained footprint',
                $exception->getMessage(),
            );
        }
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

    private function runMigrator(
        string $filename = '1560_payroll_enforcement_claim_correction.sql',
    ): void
    {
        $vendorDir = getenv('MYUCTO_TEST_VENDOR_DIR');
        if (!is_string($vendorDir) || $vendorDir === '') {
            $vendorDir = $this->rootDir . '/api/vendor';
        }
        $code = 'require ' . var_export($vendorDir . '/autoload.php', true) . ';'
            . ' MyInvoice\\Bootstrap::rootDir();'
            . ' require ' . var_export($this->rootDir . '/api/bin/migrate.php', true) . ';';
        $command = [
            PHP_BINARY,
            '-r',
            $code,
            '--',
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
