<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzScenarioRequirementCatalogRepository;
use MyInvoice\Repository\Payroll\JmhzSpecPackageRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzScenarioRequirementCatalogRepositoryTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;
    private Connection $db;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        if (!is_file($this->rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_jmhz_scenario_repo_' . bin2hex(random_bytes(6));
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
            $this->runMigrator();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Nelze vytvořit izolovanou DB: ' . $e->getMessage());
        }
        $data = $this->config->all();
        $data['db']['name'] = $this->database;
        $this->db = new Connection(new Config($data));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        if ($this->server !== null && $this->database !== '') {
            \MyInvoice\Service\Anonymization\DatabaseCloner::dropDatabase($this->server, $this->database);
        }
    }

    public function testOfficialCatalogRoundTripsIdempotentlyAndRemainsImmutable(): void
    {
        $spec = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
        (new JmhzSpecPackageRepository($this->db))->install($spec);
        $manifest = JmhzScenarioRequirementSourceCatalog::load()->manifest();
        $repository = new JmhzScenarioRequirementCatalogRepository($this->db);
        $catalogId = $repository->install($manifest, $spec);

        self::assertSame($catalogId, $repository->install($manifest, $spec));
        self::assertEquals(
            $manifest,
            $repository->find(
                JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                $manifest['manifest_sha256'],
            ),
        );
        self::assertSame(8, $this->countRows('payroll_jmhz_scenario_definitions', $catalogId));
        self::assertSame(37, $this->countRows('payroll_jmhz_interaction_definitions', $catalogId));
        self::assertSame(48, $this->countRows('payroll_jmhz_requirement_matrices', $catalogId));
        self::assertSame(1181, $this->countRows('payroll_jmhz_field_requirements', $catalogId));
        self::assertSame(22, $this->countRows('payroll_jmhz_interaction_attribute_refs', $catalogId));
        self::assertSame(442, $this->countRows('payroll_jmhz_master_attribute_axis', $catalogId));
        self::assertSame(84, $this->countRows('payroll_jmhz_matrix_evidence_axes', $catalogId));
        self::assertSame(159, $this->countRows('payroll_jmhz_matrix_evidence_members', $catalogId));

        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_jmhz_scenario_definitions SET name_raw = ?
                  WHERE catalog_id = ? AND scenario_key = ?',
            )->execute(['Pozměněný scénář', $catalogId, 'scenario_1']);
            self::fail('Zdrojový scénář JMHZ se nesmí dát změnit.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_jmhz_scenario_definitions
                    (catalog_id, package_id, scenario_key, source_sheet, source_row, ordinal,
                     matrix_id, selector_raw_type, selector_raw, name_raw, condition_raw,
                     business_description_raw, business_description_cell_kind, xsd_entrypoint,
                     selection_kind, row_hash)
                 SELECT c.id, c.package_id, ?, ?, 999, 999, m.id, ?, ?, ?, ?, ?, ?, ?, ?, ?
                   FROM payroll_jmhz_scenario_catalogs c
                   JOIN payroll_jmhz_requirement_matrices m ON m.catalog_id = c.id
                  WHERE c.id = ? LIMIT 1',
            )->execute([
                'scenario_999', 'test', 's', 'x', 'x', 'x', 'x', 'plain',
                'formBezPriznaku.xsd', 'manual_raw', str_repeat('0', 64), $catalogId,
            ]);
            self::fail('Do kompletního katalogu JMHZ se nesmí přidat další scénář.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('already contains all scenarios', $e->getMessage());
        }
    }

    private function countRows(string $table, int $catalogId): int
    {
        $allowed = [
            'payroll_jmhz_scenario_definitions',
            'payroll_jmhz_interaction_definitions',
            'payroll_jmhz_requirement_matrices',
            'payroll_jmhz_field_requirements',
            'payroll_jmhz_interaction_attribute_refs',
            'payroll_jmhz_master_attribute_axis',
            'payroll_jmhz_matrix_evidence_axes',
            'payroll_jmhz_matrix_evidence_members',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('Neplatná tabulka testu katalogu scénářů JMHZ.');
        }
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE catalog_id = ?");
        $statement->execute([$catalogId]);

        return (int) $statement->fetchColumn();
    }

    private function runMigrator(): void
    {
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--only=1334_payroll_jmhz_spec_codebooks.sql,'
                . '1335_payroll_jmhz_spec_marker_fidelity.sql,'
                . '1339_payroll_jmhz_scenario_requirement_catalog.sql',
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
