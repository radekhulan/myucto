<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use MyInvoice\Service\Export\Instance\CompleteInstanceRestoreService;
use MyInvoice\Service\Export\Instance\InstanceExportException;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetEvidence;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[Group('integration')]
final class PayrollInstanceExportCoverageTest extends TestCase
{
    /**
     * Globální legislativní podklady nejsou daty jedné firmy, ale mzdové
     * snapshoty na ně odkazují a úplný archiv je proto zachovává samostatně.
     *
     * @var list<string>
     */
    private const GLOBAL_REFERENCE_TABLES = [
        'payroll_jmhz_codebooks',
        'payroll_jmhz_codebook_entries',
        'payroll_jmhz_control_attribute_refs',
        'payroll_jmhz_control_catalogs',
        'payroll_jmhz_control_definitions',
        'payroll_jmhz_control_parameters',
        'payroll_jmhz_control_parameter_refs',
        'payroll_jmhz_control_parameter_values',
        'payroll_jmhz_dictionary_attributes',
        'payroll_jmhz_field_requirements',
        'payroll_jmhz_interaction_attribute_refs',
        'payroll_jmhz_interaction_definitions',
        'payroll_jmhz_master_attribute_axis',
        'payroll_jmhz_matrix_evidence_axes',
        'payroll_jmhz_matrix_evidence_members',
        'payroll_jmhz_requirement_matrices',
        'payroll_jmhz_scenario_catalogs',
        'payroll_jmhz_scenario_definitions',
        'payroll_jmhz_spec_packages',
        'payroll_rulesets',
    ];

    /** @var list<string> */
    private const EXPECTED_EXCLUDED_TABLES = [
        'payroll_data_migration_markers',
        'payroll_document_download_grants',
        'payroll_payment_export_download_grants',
        'payroll_period_export_jobs',
        'payroll_period_export_job_attempts',
        'payroll_period_export_job_parts',
        'payroll_period_export_job_part_attempts',
        'payroll_ruleset_audit',
        'payroll_submission_signing_profiles',
        'payroll_submission_artifact_download_grants',
    ];

    private Connection $db;
    private TenantScopeResolver $scopes;
    private InstanceExportService $export;
    private int $supplierId = 0;
    private bool $inTx = false;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->scopes = $container->get(TenantScopeResolver::class);
            $this->export = $container->get(InstanceExportService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")?->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')?->fetchColumn() ?: 0);
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1")?->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $countryId === 0) {
            $this->markTestSkipped('Chybí základní číselníky testovací databáze.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute([
            'Export mezd test s.r.o.',
            $countryId,
            'payroll-export@example.test',
            $currencyId,
            $vatRateId,
        ]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            }
        }
        if ($this->supplierId > 0) {
            @unlink(RuntimePaths::storage('locks') . '/instance-export-sup' . $this->supplierId . '.lock');
            $this->removeDir(RuntimePaths::storage('instance-exports/sup-' . $this->supplierId));
            $this->removeDir(RuntimePaths::storage('payroll-documents/sup-' . $this->supplierId));
            $this->removeDir(RuntimePaths::storage('payroll-payment-exports/sup-' . $this->supplierId));
            $this->removeDir(RuntimePaths::storage('payroll-period-exports/sup-' . $this->supplierId));
        }
        if (isset($this->db) && $this->inTx && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testEveryPayrollTableHasAnExplicitExportPolicy(): void
    {
        $expectedGlobal = self::GLOBAL_REFERENCE_TABLES;
        $configuredGlobal = InstanceExportService::SHARED_PAYROLL_TABLES;
        sort($expectedGlobal);
        sort($configuredGlobal);
        self::assertSame($expectedGlobal, $configuredGlobal);
        $scopes = $this->scopes->resolveAll($this->supplierId);
        $skipped = $this->scopes->skipped();
        $tables = $this->db->pdo()->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'payroll\\_%'
              ORDER BY TABLE_NAME"
        )?->fetchAll(PDO::FETCH_COLUMN) ?: [];

        self::assertGreaterThan(100, count($tables), 'Test skutečně pokrývá celé mzdové schéma.');
        foreach ($tables as $tableValue) {
            $table = (string) $tableValue;
            if (in_array($table, self::GLOBAL_REFERENCE_TABLES, true)) {
                self::assertSame('no_tenant_scope', $skipped[$table] ?? null, "Globální tabulka {$table} nesmí uniknout jako tenantová.");
                continue;
            }
            if (in_array($table, self::EXPECTED_EXCLUDED_TABLES, true)) {
                self::assertArrayNotHasKey($table, $scopes, "Pomocná tabulka {$table} nesmí být v obnovitelném exportu.");
                self::assertArrayHasKey($table, $skipped, "Manifest musí vysvětlit vynechání {$table}.");
                continue;
            }
            self::assertArrayHasKey($table, $scopes, "Mzdová tabulka {$table} nemá bezpečný tenantový export.");
        }
    }

    public function testEverySubmissionTableHasAnExplicitExportPolicy(): void
    {
        $excluded = [
            'submission_channel_credentials',
            'submission_isds_mobile_credentials',
            'submission_isds_auth_flows',
        ];
        $scopes = $this->scopes->resolveAll($this->supplierId);
        $skipped = $this->scopes->skipped();
        $tables = $this->db->pdo()->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'submission\\_%'
              ORDER BY TABLE_NAME"
        )?->fetchAll(PDO::FETCH_COLUMN) ?: [];

        self::assertGreaterThan(5, count($tables), 'Test pokrývá i sdílenou platformu mzdových podání.');
        foreach ($tables as $tableValue) {
            $table = (string) $tableValue;
            if (in_array($table, $excluded, true)) {
                self::assertArrayNotHasKey($table, $scopes, "Citlivá tabulka {$table} nesmí být v exportu.");
                self::assertArrayHasKey($table, $skipped, "Manifest musí vysvětlit vynechání {$table}.");
                continue;
            }
            self::assertArrayHasKey($table, $scopes, "Tabulka podání {$table} nemá bezpečný tenantový export.");
        }
    }

    public function testEphemeralIsdsCredentialsAndPrivateKeysAreNotExported(): void
    {
        $scopes = $this->scopes->resolveAll($this->supplierId);
        foreach ([
            'submission_channel_credentials',
            'submission_isds_mobile_credentials',
            'submission_isds_auth_flows',
            'payroll_submission_signing_profiles',
        ] as $table) {
            self::assertArrayNotHasKey($table, $scopes, "Citlivá nebo jednorázová tabulka {$table} nesmí být v exportu.");
        }
        self::assertTrue(TenantScopeResolver::isSecretColumn('certificate_ciphertext'));
        self::assertTrue(TenantScopeResolver::isSecretColumn('certificate_passphrase_ciphertext'));
        self::assertTrue(TenantScopeResolver::isSecretColumn('private_key_ciphertext'));
    }

    public function testAuthoritativeA1ProfilesAreIncludedInRestorableTenantData(): void
    {
        $scope = $this->scopes->resolveAll($this->supplierId)['payroll_registration_a1_profiles'] ?? null;
        self::assertNotNull($scope, 'Autoritativní profily REGZEC A1 musí být v obnovitelném exportu firmy.');
        self::assertSame('supplier_id = ?', $scope->where);
        foreach ([
            'supplier_id', 'employee_id', 'employment_id', 'effective_on',
            'profile_ciphertext', 'profile_hash', 'reference_hash', 'row_version',
        ] as $column) {
            self::assertContains($column, $scope->columns, "Export REGZEC A1 postrádá {$column}.");
        }
    }

    public function testNoRestoredPayrollRowRequiresAnOmittedCredential(): void
    {
        $scopes = $this->scopes->resolveAll($this->supplierId);
        $secretParents = [
            'epo_signing_credentials',
            'submission_channel_credentials',
            'submission_isds_mobile_credentials',
            'submission_isds_auth_flows',
        ];
        $placeholders = implode(', ', array_fill(0, count($secretParents), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, c.IS_NULLABLE
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.COLUMNS c
                 ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
                AND c.TABLE_NAME = k.TABLE_NAME
                AND c.COLUMN_NAME = k.COLUMN_NAME
              WHERE k.TABLE_SCHEMA = DATABASE()
                AND k.REFERENCED_TABLE_NAME IN (' . $placeholders . ')
                AND (k.TABLE_NAME LIKE "payroll\\_%" OR k.TABLE_NAME LIKE "submission\\_%")'
        );
        $stmt->execute($secretParents);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $foreignKey) {
            if (strtoupper((string) $foreignKey['IS_NULLABLE']) === 'YES') {
                continue;
            }
            $table = (string) $foreignKey['TABLE_NAME'];
            self::assertArrayNotHasKey(
                $table,
                $scopes,
                "Tabulka {$table} vyžaduje vynechané přihlašovací tajemství {$foreignKey['REFERENCED_TABLE_NAME']}.",
            );
        }
    }

    public function testGlobalPayrollConfigurationAndPinnedCatalogsArePreserved(): void
    {
        $rulesetId = 'phpunit-export-provenance-' . bin2hex(random_bytes(5));
        $privateReason = 'Interní důvod správce jiné firmy ' . bin2hex(random_bytes(8));
        $foreignUserId = 4_000_000_001;
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_rulesets
                (ruleset_id, domain, lifecycle, data, content_hash, reason,
                 created_by, updated_by, reviewed_by, reviewed_at, approved_by, approved_at, row_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $rulesetId,
            'health_insurance',
            'active',
            '{"parameters":{},"sources":[]}',
            str_repeat('c', 64),
            $privateReason,
            $foreignUserId,
            $foreignUserId,
            $foreignUserId,
            '2026-08-20 10:00:00',
            $foreignUserId,
            '2026-08-20 11:00:00',
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_ruleset_audit
                (ruleset_id, domain, action, reason, snapshot_json, snapshot_hash, lifecycle, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $rulesetId,
            'health_insurance',
            'created',
            $privateReason,
            '{"parameters":{},"sources":[]}',
            str_repeat('d', 64),
            'active',
            $foreignUserId,
        ]);

        $result = $this->export->runForSupplier($this->supplierId, [InstanceExportService::PART_DATA]);
        $this->tempPaths[] = (string) $result['abs_path'];
        $this->tempPaths[] = (string) $result['abs_path'] . '.sha256';

        $shared = (array) ($result['manifest']['sections']['data']['shared_payroll_tables'] ?? []);
        foreach (self::GLOBAL_REFERENCE_TABLES as $table) {
            self::assertArrayHasKey($table, $shared, "Globální mzdový podklad {$table} v exportu chybí.");
            $expected = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM `{$table}`")?->fetchColumn();
            self::assertSame($expected, (int) ($shared[$table]['rows'] ?? -1), "Počet řádků {$table} v exportu nesedí.");
            if ($table === 'payroll_rulesets') {
                self::assertNotSame([], $shared[$table]['redacted_columns'] ?? [], 'Ruleset musí odstranit globální správcovskou provenienci.');
            } else {
                self::assertSame([], $shared[$table]['redacted_columns'] ?? null, "Globální podklad {$table} nesmí vyžadovat redakci.");
            }
            self::assertArrayNotHasKey(
                $table,
                (array) ($result['manifest']['sections']['data']['skipped_tables'] ?? []),
                "Exportovaný globální podklad {$table} nesmí být současně veden jako vynechaný.",
            );
        }
        self::assertArrayNotHasKey('payroll_ruleset_audit', $shared, 'Globální audit správců nepatří do exportu jedné firmy.');
        self::assertArrayHasKey(
            'payroll_ruleset_audit',
            (array) ($result['manifest']['sections']['data']['skipped_tables'] ?? []),
        );

        $archive = new ZipArchive();
        self::assertTrue($archive->open((string) $result['abs_path']) === true);
        $rulesetEntry = (string) ($shared['payroll_rulesets']['entry'] ?? '');
        $rulesetJsonl = (string) $archive->getFromName($rulesetEntry);
        $archive->close();
        self::assertStringNotContainsString($privateReason, $rulesetJsonl);
        self::assertStringNotContainsString((string) $foreignUserId, $rulesetJsonl);
        $exportedRuleset = null;
        foreach (preg_split('/\R/', trim($rulesetJsonl)) ?: [] as $line) {
            $candidate = json_decode($line, true);
            if (is_array($candidate) && ($candidate['ruleset_id'] ?? null) === $rulesetId) {
                $exportedRuleset = $candidate;
                break;
            }
        }
        self::assertIsArray($exportedRuleset);
        self::assertArrayNotHasKey('reason', $exportedRuleset, 'Volný důvod globálního správce nesmí opustit instanci.');
        foreach (['created_by', 'updated_by', 'reviewed_by', 'approved_by'] as $column) {
            self::assertSame(0, $exportedRuleset[$column] ?? null, "Identita {$column} musí být neutralizována.");
        }
        foreach (['activated_by', 'superseded_by'] as $column) {
            self::assertNull($exportedRuleset[$column] ?? null, "Prázdná provenance {$column} zůstává prázdná.");
        }
        $lifecycle = PayrollRulesetLifecycle::from((string) $exportedRuleset['lifecycle']);
        $review = PayrollRulesetEvidence::technicalReview($exportedRuleset, null, $lifecycle, '');
        self::assertNotNull($review);
        self::assertNotNull(
            PayrollRulesetEvidence::approval($exportedRuleset, null, $review, $lifecycle, ''),
            'Neutralizace identity nesmí zrušit schválení a změnit účinný mzdový výpočet.',
        );
    }

    public function testPayrollDocumentsAndEncryptedPaymentExportsAreRestorable(): void
    {
        $documentBytes = "%PDF-1.4\nsynthetic payroll document\n%%EOF\n";
        $documentHash = hash('sha256', $documentBytes);
        $documentDir = RuntimePaths::storage(
            'payroll-documents/sup-' . $this->supplierId . '/' . substr($documentHash, 0, 2),
        );
        self::assertTrue(@mkdir($documentDir, 0750, true) || is_dir($documentDir));
        $documentPath = $documentDir . '/' . $documentHash;
        self::assertSame(strlen($documentBytes), file_put_contents($documentPath, $documentBytes));

        $paymentStorageKey = str_repeat('a', 64);
        $paymentBytes = 'enc:v2:synthetic-encrypted-payment-export';
        $paymentDir = RuntimePaths::storage(
            'payroll-payment-exports/sup-' . $this->supplierId . '/aa',
        );
        self::assertTrue(@mkdir($paymentDir, 0750, true) || is_dir($paymentDir));
        $paymentPath = $paymentDir . '/' . $paymentStorageKey;
        self::assertSame(strlen($paymentBytes), file_put_contents($paymentPath, $paymentBytes));

        $periodStorageKey = str_repeat('b', 64);
        $periodBytes = 'enc:v2:synthetic-encrypted-period-export';
        $periodDir = RuntimePaths::storage(
            'payroll-period-exports/sup-' . $this->supplierId . '/bb',
        );
        self::assertTrue(@mkdir($periodDir, 0750, true) || is_dir($periodDir));
        $periodPath = $periodDir . '/' . $periodStorageKey;
        self::assertSame(strlen($periodBytes), file_put_contents($periodPath, $periodBytes));

        $result = $this->export->runForSupplier($this->supplierId, [InstanceExportService::PART_FILES]);
        $this->tempPaths[] = (string) $result['abs_path'];
        $this->tempPaths[] = (string) $result['abs_path'] . '.sha256';

        $assets = [];
        foreach ((array) ($result['manifest']['restore']['files'] ?? []) as $asset) {
            $assets[(string) ($asset['storage_path'] ?? '')] = $asset;
        }
        $documentStoragePath = 'payroll-documents/sup-' . $this->supplierId . '/'
            . substr($documentHash, 0, 2) . '/' . $documentHash;
        $paymentStoragePath = 'payroll-payment-exports/sup-' . $this->supplierId . '/aa/' . $paymentStorageKey;
        $periodStoragePath = 'payroll-period-exports/sup-' . $this->supplierId . '/bb/' . $periodStorageKey;
        self::assertArrayHasKey($documentStoragePath, $assets, 'Výplatní PDF je obnovitelná příloha.');
        self::assertArrayHasKey($paymentStoragePath, $assets, 'Zašifrovaný bankovní export mezd je obnovitelná příloha.');
        self::assertArrayHasKey($periodStoragePath, $assets, 'Zašifrovaný měsíční nebo roční archiv mezd je obnovitelná příloha.');
        self::assertSame($documentHash, $assets[$documentStoragePath]['sha256'] ?? null);
        self::assertSame(hash('sha256', $paymentBytes), $assets[$paymentStoragePath]['sha256'] ?? null);
        self::assertSame(hash('sha256', $periodBytes), $assets[$periodStoragePath]['sha256'] ?? null);

        $archive = new ZipArchive();
        self::assertTrue($archive->open((string) $result['abs_path']) === true);
        self::assertSame($documentBytes, $archive->getFromName((string) $assets[$documentStoragePath]['entry']));
        self::assertSame($paymentBytes, $archive->getFromName((string) $assets[$paymentStoragePath]['entry']));
        self::assertSame($periodBytes, $archive->getFromName((string) $assets[$periodStoragePath]['entry']));
        $archive->close();
    }

    public function testSharedPayrollRestoreInsertsMatchesAndRejectsConflicts(): void
    {
        $rulesetId = 'phpunit-export-' . bin2hex(random_bytes(6));
        $row = [
            'ruleset_id' => $rulesetId,
            'domain' => 'health_insurance',
            'version' => null,
            'effective_from' => null,
            'effective_to' => null,
            'lifecycle' => null,
            'capability' => null,
            'data' => '{"parameters":{},"sources":[]}',
            'content_hash' => str_repeat('b', 64),
            'reason' => 'Syntetický test obnovy',
            'created_by' => null,
            'updated_by' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'activated_by' => null,
            'activated_at' => null,
            'superseded_by' => null,
            'superseded_at' => null,
            'row_version' => '1',
            'created_at' => '2099-01-01 00:00:00',
            'updated_at' => '2099-01-01 00:00:00',
        ];
        $names = array_keys($row);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_rulesets (`' . implode('`, `', $names) . '`) VALUES ('
            . implode(', ', array_fill(0, count($names), '?')) . ')'
        )->execute(array_values($row));

        $dir = RuntimePaths::storage('tmp/payroll-export-restore-' . bin2hex(random_bytes(6)));
        self::assertTrue(@mkdir($dir, 0700, true) || is_dir($dir));
        $this->tempPaths[] = $dir;
        $exportedRow = array_diff_key($row, ['reason' => true]);
        self::assertNotFalse(file_put_contents(
            $dir . '/ruleset.jsonl',
            json_encode($exportedRow, JSON_UNESCAPED_UNICODE) . "\n",
        ));
        $this->db->pdo()->prepare('DELETE FROM payroll_rulesets WHERE ruleset_id = ?')->execute([$rulesetId]);

        $restore = new CompleteInstanceRestoreService($this->db->pdo(), $dir);
        $method = new \ReflectionMethod($restore, 'restoreSharedEntry');
        $counts = ['payroll_rulesets' => 1];
        $method->invokeArgs($restore, [$dir, 'payroll_rulesets', 'ruleset.jsonl', &$counts]);
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payroll_rulesets WHERE ruleset_id = " . $this->db->pdo()->quote($rulesetId)
        )?->fetchColumn());
        $restored = $this->db->pdo()->query(
            "SELECT reason, created_by, updated_by FROM payroll_rulesets WHERE ruleset_id = "
            . $this->db->pdo()->quote($rulesetId)
        )?->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($restored);
        self::assertSame('Obnoveno z úplného exportu firmy bez globální správcovské provenance.', $restored['reason']);
        self::assertNull($restored['created_by']);
        self::assertNull($restored['updated_by']);

        $method->invokeArgs($restore, [$dir, 'payroll_rulesets', 'ruleset.jsonl', &$counts]);
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payroll_rulesets WHERE ruleset_id = " . $this->db->pdo()->quote($rulesetId)
        )?->fetchColumn(), 'Shodný globální podklad lze bezpečně potvrdit opakovaně.');

        $this->db->pdo()->prepare(
            'UPDATE payroll_rulesets SET data = ?, content_hash = ?, row_version = row_version + 1 WHERE ruleset_id = ?'
        )->execute(['{"parameters":{"different":true},"sources":[]}', str_repeat('e', 64), $rulesetId]);
        try {
            $method->invokeArgs($restore, [$dir, 'payroll_rulesets', 'ruleset.jsonl', &$counts]);
            self::fail('Odlišný globální podklad musí obnovu zastavit.');
        } catch (\ReflectionException $e) {
            throw $e;
        } catch (InstanceExportException $e) {
            self::assertSame('restore_shared_conflict', $e->errorCode);
        }
    }

    public function testRestoreStillAcceptsPreviousArchiveVersion(): void
    {
        $dir = RuntimePaths::storage('tmp/payroll-export-v3-' . bin2hex(random_bytes(6)));
        self::assertTrue(@mkdir($dir, 0700, true) || is_dir($dir));
        $this->tempPaths[] = $dir;
        $archivePath = $dir . '/previous-format.zip';
        $archive = new ZipArchive();
        self::assertTrue($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $archive->addFromString('manifest.json', (string) json_encode([
            'format' => 'myucto-instance-export',
            'version' => 3,
            'restore' => [
                'available' => true,
                'files' => [],
                'documents' => [],
                'blobs' => [],
            ],
            'sections' => [
                'data' => [
                    'tables' => [],
                    'identity' => ['entries' => []],
                ],
            ],
            'checksums' => [],
        ], JSON_UNESCAPED_UNICODE));
        $archive->close();

        $restore = new CompleteInstanceRestoreService($this->db->pdo(), $dir);
        $result = $restore->validate($archivePath);
        self::assertSame(3, (int) ($result['manifest']['version'] ?? 0));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
