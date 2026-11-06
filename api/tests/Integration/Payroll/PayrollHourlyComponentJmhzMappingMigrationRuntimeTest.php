<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollHourlyComponentJmhzMappingMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = Bootstrap::rootDir();
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_hourly_jmhz_' . bin2hex(random_bytes(6)) . '_test';
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
            self::fail('Nelze vytvořit izolovanou DB: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    /** @var array<string,string> */
    private const CODES = [
        'MZDA_HODINOVA_ZAKLAD' => '10329',
        'MZDA_DOSAZENA_PRESCAS' => '10329',
        'NAHRADA_MZDY_VOLNO_ZA_SVATEK' => '10339',
        'DOPLATEK_MINIMALNI_MZDA' => '10329',
    ];

    private const BACKFILLS = [
        '2256_payroll_holiday_time_off.sql',
        '2258_payroll_component_minimum_wage.sql',
    ];

    public function testBackfillAddsOnlyMissingMappingsAndKeepsAccountantChoices(): void
    {
        $this->runMigrator([
            '1334_payroll_jmhz_spec_codebooks.sql',
            '1335_payroll_jmhz_spec_marker_fidelity.sql',
            '1342_payroll_component_jmhz_mapping.sql',
            '1343_payroll_component_jmhz_mapping_lifecycle.sql',
            '1344_payroll_component_jmhz_mapping_guards.sql',
        ]);
        $db = $this->databasePdo();
        $db->exec('INSERT INTO supplier (id) VALUES (1), (2)');
        $package = $db->prepare("INSERT INTO payroll_jmhz_spec_packages
            (package_key, schema_version, xsd_version, dictionary_version,
             control_catalog_version, process_version, instructions_version, manifest_json, manifest_sha256)
            VALUES (?, 'test', 'test', 'test', 'test', 'test', 'test', ?, ?)");
        $package->execute([
            'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.11_manifest-v1',
            json_encode(['payload' => ['counts' => ['attributes' => 3]]], JSON_THROW_ON_ERROR),
            '39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8',
        ]);
        $current = (int) $db->lastInsertId();
        $package->execute(['synthetic-previous-package', json_encode(['payload' => ['counts' => ['attributes' => 3]]], JSON_THROW_ON_ERROR), str_repeat('a', 64)]);
        $previous = (int) $db->lastInsertId();
        $attribute = $db->prepare('INSERT INTO payroll_jmhz_dictionary_attributes
            (package_id, attribute_id, name, row_hash) VALUES (?, ?, ?, ?)');
        foreach ([$current, $previous] as $packageId) {
            foreach (['10329', '10330', '10339'] as $target) {
                $attribute->execute([$packageId, $target, 'Syntetická kolonka ' . $target, hash('sha256', $target)]);
            }
        }
        $component = $db->prepare('INSERT INTO payroll_component_definitions
            (supplier_id, code, name, component_kind, jmhz_treatment) VALUES (?, ?, ?, ?, ?)');
        $mapping = $db->prepare('INSERT INTO payroll_component_jmhz_mappings
            (supplier_id, component_definition_id, spec_package_id, target_attribute_id, is_active, disabled_at)
            VALUES (?, ?, ?, ?, ?, ?)');
        $missing = [];
        $untouched = [];
        // Dvě firmy a více verzí stejného kódu: migrace nesmí pokrýt jen první shodu.
        foreach ([1, 2] as $supplierId) {
            foreach (self::CODES as $code => $target) {
                foreach (['missing', 'missing_version', 'custom', 'disabled', 'previous', 'excluded', 'manual_review', 'other_kind'] as $state) {
                    $treatment = in_array($state, ['excluded', 'manual_review'], true) ? $state : 'included';
                    // Vlastní stejnojmenná složka jiného druhu nedostane zařazení podle kódu.
                    $kind = $state === 'other_kind' ? 'bonus' : PayrollComponentDefaults::template($code)['component_kind'];
                    $component->execute([$supplierId, $code, 'Syntetická vlastní složka ' . $state, $kind, $treatment]);
                    $id = (int) $db->lastInsertId();
                    if (str_starts_with($state, 'missing')) {
                        $missing[$id] = [$supplierId, $target];
                    } else {
                        $untouched[] = $id;
                    }
                    if (in_array($state, ['custom', 'disabled', 'previous'], true)) {
                        $mapping->execute([$supplierId, $id, $state === 'previous' ? $previous : $current,
                            '10330', $state === 'disabled' ? 0 : 1,
                            $state === 'disabled' ? '2026-01-01 00:00:00' : null]);
                    }
                }
            }
        }
        $readChoices = static fn (): array => $db->query('SELECT * FROM payroll_component_jmhz_mappings
            WHERE component_definition_id IN (' . implode(',', $untouched) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $readDefinitions = static fn (): array => $db->query('SELECT id, supplier_id, code, name, component_kind, jmhz_treatment
            FROM payroll_component_definitions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $choicesBefore = $readChoices();
        $definitionsBefore = $readDefinitions();
        $this->runMigrator(self::BACKFILLS);
        $find = $db->prepare('SELECT supplier_id, spec_package_id, target_attribute_id, is_active
            FROM payroll_component_jmhz_mappings WHERE component_definition_id = ?');
        foreach ($missing as $id => [$supplierId, $target]) {
            $find->execute([$id]);
            self::assertSame([
                'supplier_id' => $supplierId, 'spec_package_id' => $current,
                'target_attribute_id' => $target, 'is_active' => 1,
            ], $find->fetch(PDO::FETCH_ASSOC), 'Chybí výchozí zařazení existující hodinové složky ' . $id);
            self::assertFalse($find->fetch(PDO::FETCH_ASSOC));
        }
        self::assertSame($choicesBefore, $readChoices(), 'Vlastní, vypnuté i starší zařazení účetní zůstává beze změny.');
        self::assertSame($definitionsBefore, $readDefinitions());
        $allBefore = $db->query('SELECT * FROM payroll_component_jmhz_mappings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        // Jen v izolované jednorázové DB opakujeme skutečné SQL přes oficiální migrátor.
        $delete = $db->prepare('DELETE FROM migrations WHERE filename IN (' . implode(',', array_fill(0, count(self::BACKFILLS), '?')) . ')');
        $delete->execute(self::BACKFILLS);
        $this->runMigrator(self::BACKFILLS);
        self::assertSame($allBefore, $db->query('SELECT * FROM payroll_component_jmhz_mappings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($definitionsBefore, $readDefinitions());
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
                code VARCHAR(100) NOT NULL,
                name VARCHAR(255) NOT NULL,
                component_kind VARCHAR(40) NOT NULL,
                jmhz_treatment ENUM('included','excluded','manual_review') NOT NULL,
                UNIQUE KEY uq_payroll_component_supplier_id (supplier_id, id),
                FOREIGN KEY (supplier_id) REFERENCES supplier(id)
            ) ENGINE=InnoDB",
        );
        $db->exec("CREATE TABLE payroll_absences (id BIGINT UNSIGNED PRIMARY KEY, absence_type VARCHAR(100) NOT NULL, compensation_policy VARCHAR(100) NOT NULL) ENGINE=InnoDB");
    }

    private function databasePdo(): PDO
    {
        $pdo = new PDO(
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
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    /** @param list<string> $migrations */
    private function runMigrator(array $migrations): void
    {
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--only=' . implode(',', $migrations),
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
