<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzWorkMonthConditionalMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_jmhz_work_conditional_' . bin2hex(random_bytes(6));
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
            $this->createLegacyTable();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Nelze vytvořit izolovanou DB: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testConditionalMigrationsAreIdempotentAndPreserveLegacyRevision(): void
    {
        $db = $this->databasePdo();
        $legacyHash = str_repeat('a', 64);
        $db->prepare(
            'INSERT INTO payroll_jmhz_work_month_revisions
                (derivation_version, agreed_fund_millihours, worked_millihours, summary_sha256)
             VALUES ("jmhz-work-month-core.v1", 168000, 160000, ?)'
        )->execute([$legacyHash]);

        $this->runMigrator();
        $legacy = $db->query(
            'SELECT summary_sha256, conditional_blocks_confirmed,
                    unworked_hours_occurred, work_obstacles_occurred,
                    unworked_total_millihours, control_catalog_key,
                    control_manifest_sha256
               FROM payroll_jmhz_work_month_revisions WHERE id = 1'
        );
        self::assertInstanceOf(\PDOStatement::class, $legacy);
        self::assertSame([
            'summary_sha256' => $legacyHash,
            'conditional_blocks_confirmed' => null,
            'unworked_hours_occurred' => null,
            'work_obstacles_occurred' => null,
            'unworked_total_millihours' => null,
            'control_catalog_key' => null,
            'control_manifest_sha256' => null,
        ], $legacy->fetch(PDO::FETCH_ASSOC));
        self::assertSame(4, $this->constraintCount($db));

        $db->exec(
            "DELETE FROM migrations WHERE filename IN (
                '1351_payroll_jmhz_work_month_conditional_blocks.sql',
                '1352_payroll_jmhz_work_month_conditional_contract.sql',
                '1354_payroll_jmhz_work_month_control_binding.sql',
                '1356_payroll_jmhz_work_month_control_binding_guard.sql'
            )",
        );
        $this->runMigrator();
        self::assertSame(4, $this->constraintCount($db));

        $this->assertRejected($db,
            "INSERT INTO payroll_jmhz_work_month_revisions
                (derivation_version, agreed_fund_millihours, worked_millihours,
                 summary_sha256, conditional_blocks_confirmed)
             VALUES ('jmhz-work-month.v2', 168000, 160000, '" . str_repeat('b', 64) . "', 1)",
            'chk_payroll_jmhz_work_month_conditional_confirmation',
        );
        $this->assertRejected($db,
            "INSERT INTO payroll_jmhz_work_month_revisions
                (derivation_version, agreed_fund_millihours, worked_millihours,
                 summary_sha256, conditional_blocks_confirmed,
                 unworked_hours_occurred, work_obstacles_occurred)
             VALUES ('jmhz-work-month.v2', 168000, 160000, '" . str_repeat('c', 64)
                . "', 1, 1, 0)",
            'chk_payroll_jmhz_work_month_unworked_block',
        );
        $this->assertRejected($db,
            "INSERT INTO payroll_jmhz_work_month_revisions
                (derivation_version, agreed_fund_millihours, worked_millihours,
                 summary_sha256, conditional_blocks_confirmed,
                 unworked_hours_occurred, work_obstacles_occurred)
             VALUES ('jmhz-work-month.v2', 168000, 160000, '" . str_repeat('f', 64)
                . "', 1, 0, 0)",
            'chk_payroll_jmhz_work_month_control_binding',
        );
        $db->exec(
            "INSERT INTO payroll_jmhz_work_month_revisions
                (derivation_version, agreed_fund_millihours, worked_millihours,
                 summary_sha256, conditional_blocks_confirmed,
                 unworked_hours_occurred, work_obstacles_occurred,
                 unworked_total_millihours, unworked_paid_millihours,
                 dpn_with_employer_compensation_millihours,
                 employee_obstacle_paid_millihours, control_catalog_key,
                 control_manifest_sha256)
             VALUES ('jmhz-work-month.v2', 168000, 160000, '" . str_repeat('d', 64)
                . "', 1, 1, 1, 80000, 0, 80000, 80000,
                    'jmhz-controls-1.4.2.8-source-v4', '" . str_repeat('e', 64) . "')",
        );
        $count = $db->query('SELECT COUNT(*) FROM payroll_jmhz_work_month_revisions');
        self::assertInstanceOf(\PDOStatement::class, $count);
        self::assertSame(2, (int) $count->fetchColumn());
    }

    private function createLegacyTable(): void
    {
        $this->databasePdo()->exec(
            'CREATE TABLE payroll_jmhz_work_month_revisions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                derivation_version VARCHAR(64) NOT NULL,
                scenario_manifest_sha256 CHAR(64) NULL,
                agreed_fund_millihours INT UNSIGNED NOT NULL,
                worked_millihours INT UNSIGNED NOT NULL,
                summary_sha256 CHAR(64) NOT NULL
            ) ENGINE=InnoDB',
        );
    }

    private function constraintCount(PDO $db): int
    {
        $statement = $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = 'payroll_jmhz_work_month_revisions'
                AND CONSTRAINT_NAME IN (
                    'chk_payroll_jmhz_work_month_conditional_confirmation',
                    'chk_payroll_jmhz_work_month_conditional_ranges',
                    'chk_payroll_jmhz_work_month_unworked_block',
                    'chk_payroll_jmhz_work_month_obstacle_block'
                  )",
        );
        self::assertInstanceOf(\PDOStatement::class, $statement);
        return (int) $statement->fetchColumn();
    }

    private function assertRejected(PDO $db, string $sql, string $constraint): void
    {
        try {
            $db->exec($sql);
            self::fail("Databáze měla odmítnout {$constraint}.");
        } catch (\PDOException $e) {
            self::assertStringContainsString($constraint, $e->getMessage());
        }
    }

    private function databasePdo(): PDO
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
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--only=1351_payroll_jmhz_work_month_conditional_blocks.sql,'
                . '1352_payroll_jmhz_work_month_conditional_contract.sql,'
                . '1354_payroll_jmhz_work_month_control_binding.sql,'
                . '1356_payroll_jmhz_work_month_control_binding_guard.sql',
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
        self::assertSame(0, proc_close($process), "Migrátor selhal.\n{$stdout}\n{$stderr}");
    }
}
