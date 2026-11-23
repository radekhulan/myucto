<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzControlCatalogMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        if (!is_file($this->rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_jmhz_control_' . bin2hex(random_bytes(6));
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
        } catch (\Throwable $e) {
            $this->markTestSkipped('Nelze vytvořit izolovanou DB: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            \MyInvoice\Service\Anonymization\DatabaseCloner::dropDatabase($this->server, $this->database);
        }
    }

    public function testMigrationsAreIdempotentOnMariaDb(): void
    {
        $this->runMigrator();
        $db = $this->databasePdo();
        self::assertCount(17, array_filter(
            TriggerMetadata::read($db, $this->database),
            static fn (array $trigger): bool => str_starts_with($trigger['TRIGGER_NAME'], 'trg_jmhz_ctl_'),
        ));
        self::assertSame(1, $this->parameterValueTypeConstraintCount($db));

        $db->exec(
            'ALTER TABLE payroll_jmhz_control_parameter_values '
            . 'DROP CONSTRAINT chk_jmhz_parameter_value_type',
        );
        $db->exec(
            "DELETE FROM migrations WHERE filename = '1338_payroll_jmhz_control_value_fidelity.sql'",
        );

        $this->runMigrator();

        self::assertSame(1, $this->parameterValueTypeConstraintCount($db));
        $db->exec(
            "DELETE FROM migrations WHERE filename IN (
                '1334_payroll_jmhz_spec_codebooks.sql',
                '1335_payroll_jmhz_spec_marker_fidelity.sql',
                '1336_payroll_jmhz_control_catalog.sql',
                '1337_payroll_jmhz_control_catalog_fidelity.sql',
                '1338_payroll_jmhz_control_value_fidelity.sql'
            )",
        );

        $this->runMigrator();

        self::assertCount(17, array_filter(
            TriggerMetadata::read($db, $this->database),
            static fn (array $trigger): bool => str_starts_with($trigger['TRIGGER_NAME'], 'trg_jmhz_ctl_'),
        ));
        self::assertSame(6, $this->scalar($db,
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'payroll_jmhz_control_%'",
        ));
        self::assertSame(1, $this->parameterValueTypeConstraintCount($db));
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

    private function scalar(PDO $db, string $sql): int
    {
        $statement = $db->prepare($sql);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function parameterValueTypeConstraintCount(PDO $db): int
    {
        return $this->scalar($db,
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = 'payroll_jmhz_control_parameter_values'
                AND CONSTRAINT_NAME = 'chk_jmhz_parameter_value_type'
                AND CONSTRAINT_TYPE = 'CHECK'",
        );
    }

    private function runMigrator(): void
    {
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--only=1334_payroll_jmhz_spec_codebooks.sql,1335_payroll_jmhz_spec_marker_fidelity.sql,1336_payroll_jmhz_control_catalog.sql,1337_payroll_jmhz_control_catalog_fidelity.sql,1338_payroll_jmhz_control_value_fidelity.sql',
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
