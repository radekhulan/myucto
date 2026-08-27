<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRunResultPeriodMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_payroll_result_period_' . bin2hex(random_bytes(6));
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
        if ($this->server !== null
            && preg_match('/^myucto_payroll_result_period_[0-9a-f]{12}$/D', $this->database) === 1
        ) {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testBackfillsDerivedPeriodsAndRemainsIdempotent(): void
    {
        $db = $this->db();
        $this->runMigrator();

        foreach ([
            'payroll_run_persons',
            'payroll_run_employments',
            'payroll_net_results',
        ] as $table) {
            self::assertTrue($this->columnExists($table, 'period_start'));
            self::assertTrue($this->indexExists($table, 'idx_' . $table . '_period_employee'));
        }
        self::assertSame([], $this->periodMismatches($db));
        $this->assertInsertAndUpdateNormalization($db);
        $this->assertParentPeriodPropagation($db);

        $db->exec(
            "DELETE FROM migrations
              WHERE filename IN (
                '1592_payroll_run_result_periods.sql',
                '1593_payroll_run_result_period_parent_sync.sql'
              )",
        );
        $this->runMigrator();

        self::assertSame([], $this->periodMismatches($db));
        self::assertSame(4, (int) $db->query(
            'SELECT COUNT(*) FROM payroll_run_persons',
        )->fetchColumn());
        self::assertSame(4, (int) $db->query(
            'SELECT COUNT(*) FROM payroll_run_employments',
        )->fetchColumn());
        self::assertSame(4, (int) $db->query(
            'SELECT COUNT(*) FROM payroll_net_results',
        )->fetchColumn());
    }

    private function createLegacySchema(): void
    {
        $this->db()->exec(
            "CREATE TABLE payroll_runs (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                period_start DATE NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_payroll_run_supplier_id (supplier_id, id)
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_run_revisions (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                run_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_payroll_run_revision_supplier_id (supplier_id, id)
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_run_persons (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                revision_id BIGINT UNSIGNED NOT NULL,
                employee_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id)
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_run_employments (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                revision_id BIGINT UNSIGNED NOT NULL,
                employee_id BIGINT UNSIGNED NOT NULL,
                employment_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id)
             ) ENGINE=InnoDB;
             CREATE TABLE payroll_net_results (
                id BIGINT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                revision_id BIGINT UNSIGNED NOT NULL,
                employee_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id)
             ) ENGINE=InnoDB;
             INSERT INTO payroll_runs (id, supplier_id, period_start)
             VALUES (10, 7, '2026-01-01'), (20, 7, '2026-02-01');
             INSERT INTO payroll_run_revisions (id, supplier_id, run_id)
             VALUES (100, 7, 10), (200, 7, 20);
             INSERT INTO payroll_run_persons (id, supplier_id, revision_id, employee_id)
             VALUES (1000, 7, 100, 50), (2000, 7, 200, 50);
             INSERT INTO payroll_run_employments
                (id, supplier_id, revision_id, employee_id, employment_id)
             VALUES (1100, 7, 100, 50, 500), (2100, 7, 200, 50, 500);
             INSERT INTO payroll_net_results (id, supplier_id, revision_id, employee_id)
             VALUES (1200, 7, 100, 50), (2200, 7, 200, 50);",
        );
    }

    /** @return list<array<string,mixed>> */
    private function periodMismatches(PDO $db): array
    {
        $statement = $db->query(
            "SELECT 'person' AS family, result.id
               FROM payroll_run_persons result
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = result.supplier_id
                AND revision.id = result.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE result.period_start <> run.period_start
             UNION ALL
             SELECT 'employment' AS family, result.id
               FROM payroll_run_employments result
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = result.supplier_id
                AND revision.id = result.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE result.period_start <> run.period_start
             UNION ALL
             SELECT 'net' AS family, result.id
               FROM payroll_net_results result
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = result.supplier_id
                AND revision.id = result.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE result.period_start <> run.period_start",
        );

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertInsertAndUpdateNormalization(PDO $db): void
    {
        $db->exec(
            'INSERT INTO payroll_run_persons (id, supplier_id, revision_id, employee_id)
             VALUES (3000, 7, 200, 51)',
        );
        $db->exec(
            "INSERT INTO payroll_run_persons
                (id, supplier_id, revision_id, employee_id, period_start)
             VALUES (4000, 7, 200, 52, '2000-01-01')",
        );
        $db->exec(
            'INSERT INTO payroll_run_employments
                (id, supplier_id, revision_id, employee_id, employment_id)
             VALUES (3100, 7, 200, 51, 501)',
        );
        $db->exec(
            "INSERT INTO payroll_run_employments
                (id, supplier_id, revision_id, employee_id, employment_id, period_start)
             VALUES (4100, 7, 200, 52, 502, '2000-01-01')",
        );
        $db->exec(
            'INSERT INTO payroll_net_results (id, supplier_id, revision_id, employee_id)
             VALUES (3200, 7, 200, 51)',
        );
        $db->exec(
            "INSERT INTO payroll_net_results
                (id, supplier_id, revision_id, employee_id, period_start)
             VALUES (4200, 7, 200, 52, '2000-01-01')",
        );
        $db->exec(
            "UPDATE payroll_run_persons SET period_start = '2000-01-01' WHERE id = 3000;
             UPDATE payroll_run_employments SET period_start = '2000-01-01' WHERE id = 3100;
             UPDATE payroll_net_results SET period_start = '2000-01-01' WHERE id = 3200;",
        );

        self::assertSame([], $this->periodMismatches($db));
    }

    private function assertParentPeriodPropagation(PDO $db): void
    {
        $db->exec("UPDATE payroll_runs SET period_start = '2026-03-01' WHERE id = 20");

        self::assertSame([], $this->periodMismatches($db));
        foreach ([
            'payroll_run_persons',
            'payroll_run_employments',
            'payroll_net_results',
        ] as $table) {
            self::assertSame(3, (int) $db->query(
                "SELECT COUNT(*) FROM {$table}
                  WHERE revision_id = 200 AND period_start = '2026-03-01'",
            )->fetchColumn());
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        );
        $statement->execute([$this->database, $table, $column]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function indexExists(string $table, string $index): bool
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        );
        $statement->execute([$this->database, $table, $index]);

        return (int) $statement->fetchColumn() > 0;
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
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--only=1592_payroll_run_result_periods.sql,1593_payroll_run_result_period_parent_sync.sql',
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
