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
    private const DATABASE = 'myucto_mz27_reconciliation_agent_test';

    private PDO $server;
    private Config $config;
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
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
            'CREATE DATABASE IF NOT EXISTS `' . self::DATABASE
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
    }

    public function testMigrationIsIdempotentOnIsolatedMariaDb(): void
    {
        $this->runProcess([PHP_BINARY, $this->rootDir . '/api/bin/migrate.php', '--no-backfills']);
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
            '--only=1607_payroll_operational_reconciliation_issues.sql',
        ]);
        self::assertSame(2, (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME LIKE 'payroll_operational_reconciliation_issue%'",
        )->fetchColumn());

        $supplierCount = (int) $db->query('SELECT COUNT(*) FROM supplier')->fetchColumn();
        if ($supplierCount === 0) {
            $this->runProcess([PHP_BINARY, $this->rootDir . '/api/bin/ci-seed.php']);
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
        $environment['MYINVOICE_DB_NAME'] = self::DATABASE;
        $environment['MYSQL_DATABASE'] = self::DATABASE;
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
                self::DATABASE,
            ),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
