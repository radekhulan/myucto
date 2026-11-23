<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollOperationalReconciliationMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private Config $config;
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_reconciliation_' . bin2hex(random_bytes(6)) . '_test';
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
            'CREATE DATABASE `' . $this->database
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $this->createParentTables();
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            if (preg_match('/^myucto_reconciliation_[0-9a-f]{12}_test$/D', $this->database) !== 1) {
                throw new \LogicException('Neplatný název izolované testovací DB.');
            }
            \MyInvoice\Service\Anonymization\DatabaseCloner::dropDatabase($this->server, $this->database);
        }
    }

    public function testMigrationIsIdempotentOnIsolatedMariaDb(): void
    {
        $this->runProcess([
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--no-analyze',
            '--only=1607_payroll_operational_reconciliation_issues.sql',
        ]);
        $db = $this->databasePdo();
        self::assertSame(2, (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (
                    'payroll_operational_reconciliation_issues',
                    'payroll_operational_reconciliation_issue_events'
                )",
        )->fetchColumn());
        self::assertSame(3, (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME LIKE 'fk_payroll_operational_reconciliation%'",
        )->fetchColumn());

        $db->exec(
            "DELETE FROM migrations
              WHERE filename = '1607_payroll_operational_reconciliation_issues.sql'",
        );
        $this->runProcess([
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--no-analyze',
            '--only=1607_payroll_operational_reconciliation_issues.sql',
        ]);
        self::assertSame(2, (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME LIKE 'payroll_operational_reconciliation_issue%'",
        )->fetchColumn());

    }

    private function createParentTables(): void
    {
        $source = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $this->config->get('db.host', '127.0.0.1'),
                (int) $this->config->get('db.port', 3306),
                (string) $this->config->get('db.name'),
            ),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $target = $this->databasePdo();
        $target->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([
                'countries',
                'currencies',
                'vat_rates',
                'supplier_groups',
                'supplier',
                'roles',
                'users',
                'payroll_offices',
                'payroll_runs',
                'payroll_run_revisions',
            ] as $table) {
                $ddl = $source->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
                $target->exec($ddl[1]);
            }
        } finally {
            $target->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** @param list<string> $command */
    private function runProcess(array $command): void
    {
        $entrypoint = $command[1] ?? null;
        if (isset($command[1]) && is_string($command[1]) && str_ends_with($command[1], '.php')) {
            $entrypoint = $command[1];
            array_splice($command, 1, 1, [
                '-r',
                '$loader = require ' . var_export($this->rootDir . '/api/vendor/autoload.php', true) . ';'
                . '$loader->addPsr4("MyInvoice\\\\", '
                . var_export($this->rootDir . '/api/src', true) . ', true);'
                . 'require ' . var_export($entrypoint, true) . ';',
                '--',
            ]);
        }
        $environment = getenv();
        self::assertIsArray($environment);
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
        self::assertSame(0, proc_close($process), "Příkaz selhal.\n{$stdout}\n{$stderr}");
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
}
