<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzScenarioRequirementMigrationRuntimeTest extends TestCase
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
        $this->database = 'myucto_jmhz_scenario_' . bin2hex(random_bytes(6));
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
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testMigrationIsIdempotentAndKeepsAllCompositeForeignKeys(): void
    {
        $this->runMigrator();
        $db = $this->databasePdo();
        self::assertSame(9, $this->scalar($db,
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'payroll_jmhz_%'
                AND TABLE_NAME IN (
                  'payroll_jmhz_scenario_catalogs',
                  'payroll_jmhz_scenario_definitions',
                  'payroll_jmhz_interaction_definitions',
                  'payroll_jmhz_interaction_attribute_refs',
                  'payroll_jmhz_requirement_matrices',
                  'payroll_jmhz_field_requirements',
                  'payroll_jmhz_master_attribute_axis',
                  'payroll_jmhz_matrix_evidence_axes',
                  'payroll_jmhz_matrix_evidence_members'
                )",
        ));
        self::assertCount(26, array_filter(
            TriggerMetadata::read($db, $this->database),
            static fn (array $trigger): bool => str_starts_with($trigger['TRIGGER_NAME'], 'trg_jmhz_scn_'),
        ));
        self::assertSame(2, $this->matrixOwnershipForeignKeyCount($db));

        $db->exec(
            "DELETE FROM migrations WHERE filename IN (
                '1334_payroll_jmhz_spec_codebooks.sql',
                '1335_payroll_jmhz_spec_marker_fidelity.sql',
                '1339_payroll_jmhz_scenario_requirement_catalog.sql',
                '1340_payroll_jmhz_scenario_requirement_source_fidelity.sql',
                '1341_payroll_jmhz_scenario_evidence_fidelity.sql'
            )",
        );
        $this->runMigrator();

        self::assertCount(26, array_filter(
            TriggerMetadata::read($db, $this->database),
            static fn (array $trigger): bool => str_starts_with($trigger['TRIGGER_NAME'], 'trg_jmhz_scn_'),
        ));
        self::assertSame(2, $this->matrixOwnershipForeignKeyCount($db));
    }

    private function matrixOwnershipForeignKeyCount(PDO $db): int
    {
        return $this->scalar($db,
            "SELECT COUNT(DISTINCT CONSTRAINT_NAME)
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME IN (
                  'fk_jmhz_scenario_definition_matrix',
                  'fk_jmhz_interaction_definition_matrix'
                )
                AND REFERENCED_TABLE_NAME = 'payroll_jmhz_requirement_matrices'",
        );
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

    private function runMigrator(): void
    {
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--no-analyze',
            '--only=1334_payroll_jmhz_spec_codebooks.sql,'
                . '1335_payroll_jmhz_spec_marker_fidelity.sql,'
                . '1339_payroll_jmhz_scenario_requirement_catalog.sql,'
                . '1340_payroll_jmhz_scenario_requirement_source_fidelity.sql,'
                . '1341_payroll_jmhz_scenario_evidence_fidelity.sql',
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
