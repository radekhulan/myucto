<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollComponentJmhzMappingMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_jmhz_mapping_' . bin2hex(random_bytes(6));
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
            $this->createParentTables();
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

    public function testMappingMigrationsAreIdempotentAndKeepRuntimeGuards(): void
    {
        $this->runMigrator();
        $db = $this->databasePdo();
        self::assertCount(3, array_filter(
            TriggerMetadata::read($db, $this->database),
            static fn (array $trigger): bool => str_starts_with($trigger['TRIGGER_NAME'], 'trg_payroll_component_jmhz_'),
        ));
        self::assertSame(1, $this->constraintCount(
            $db,
            'chk_payroll_component_jmhz_mapping_lifecycle',
        ));
        self::assertSame(1, $this->constraintCount(
            $db,
            'chk_payroll_component_jmhz_mapping_target',
        ));
        $deleteRule = $db->query(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME = 'fk_payroll_component_jmhz_mapping_component'",
        );
        self::assertInstanceOf(\PDOStatement::class, $deleteRule);
        self::assertSame('RESTRICT', $deleteRule->fetchColumn());

        $db->exec(
            "DELETE FROM migrations WHERE filename IN (
                '1334_payroll_jmhz_spec_codebooks.sql',
                '1335_payroll_jmhz_spec_marker_fidelity.sql',
                '1342_payroll_component_jmhz_mapping.sql',
                '1343_payroll_component_jmhz_mapping_lifecycle.sql',
                '1344_payroll_component_jmhz_mapping_guards.sql'
            )",
        );
        $this->runMigrator();

        self::assertCount(3, array_filter(
            TriggerMetadata::read($db, $this->database),
            static fn (array $trigger): bool => str_starts_with($trigger['TRIGGER_NAME'], 'trg_payroll_component_jmhz_'),
        ));
        self::assertSame(1, $this->constraintCount(
            $db,
            'chk_payroll_component_jmhz_mapping_lifecycle',
        ));
        self::assertSame(1, $this->constraintCount(
            $db,
            'chk_payroll_component_jmhz_mapping_target',
        ));
        $deleteRuleAfter = $db->query(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME = 'fk_payroll_component_jmhz_mapping_component'",
        );
        self::assertInstanceOf(\PDOStatement::class, $deleteRuleAfter);
        self::assertSame('RESTRICT', $deleteRuleAfter->fetchColumn());
    }

    private function createParentTables(): void
    {
        $db = $this->databasePdo();
        $db->exec(
            'CREATE TABLE supplier (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        );
        $db->exec(
            'CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        );
        $db->exec(
            "CREATE TABLE payroll_component_definitions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                jmhz_treatment ENUM('included','excluded','manual_review') NOT NULL,
                UNIQUE KEY uq_payroll_component_supplier_id (supplier_id, id),
                FOREIGN KEY (supplier_id) REFERENCES supplier(id)
            ) ENGINE=InnoDB",
        );
    }

    private function constraintCount(PDO $db, string $name): int
    {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?",
        );
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function scalar(PDO $db, string $sql): int
    {
        $stmt = $db->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
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
            '--no-analyze',
            '--only=1334_payroll_jmhz_spec_codebooks.sql,'
                . '1335_payroll_jmhz_spec_marker_fidelity.sql,'
                . '1342_payroll_component_jmhz_mapping.sql,'
                . '1343_payroll_component_jmhz_mapping_lifecycle.sql,'
                . '1344_payroll_component_jmhz_mapping_guards.sql',
        ];
        $environment = getenv();
        $environment['MYINVOICE_DB_NAME'] = $this->database;
        $environment['MYSQL_DATABASE'] = $this->database;
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->rootDir,
            $environment,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "Migrátor selhal.\n{$stdout}\n{$stderr}");
    }
}
