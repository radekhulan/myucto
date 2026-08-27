<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\CompleteInstanceRestoreService;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollInstanceRestoreRoundTripTest extends TestCase
{
    private const TARGET_DATABASE_PATTERN = '/^myucto_test_payroll_restore_[0-9a-f]{12}$/D';

    /** @var list<string> */
    private const TENANT_TABLES = [
        'currencies',
        'documents',
        'payroll_employees',
        'payroll_runs',
        'payroll_run_revisions',
        'payroll_run_persons',
        'payroll_generated_documents',
        'payroll_production_qualifications',
        'payroll_production_qualification_documents',
        'payroll_enforcement_cases',
        'payroll_enforcement_claims',
        'payroll_enforcement_case_documents',
        'payroll_person_foreign_permits',
        'payroll_retention_policies',
        'retention_holds',
        'payroll_document_batches',
        'payroll_document_batch_items',
        'payroll_document_batch_attempts',
        'payroll_obligations',
        'payroll_submissions',
        'payroll_submission_artifacts',
        'payroll_payment_liabilities',
        'payroll_payment_batches',
        'payroll_payment_items',
        'payroll_payment_allocations',
        'payroll_payment_matches',
        'bank_statements',
    ];

    private string $rootDir;
    private Config $config;
    private Connection $sourceConnection;
    private InstanceExportService $export;
    private ?PDO $server = null;
    private ?PDO $target = null;
    private string $targetDatabase = '';
    private string $targetStorage = '';
    private int $supplierId = 0;
    private bool $sourceTransaction = false;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->rootDir = Bootstrap::rootDir();
        if (!is_file($this->rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje lokální testovací DB.');
        }
        $this->config = Config::load($this->rootDir);
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->sourceConnection = $container->get(Connection::class);
            $this->export = $container->get(InstanceExportService::class);
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
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DB/DI není dostupné: ' . $exception->getMessage());
        }

        $this->targetDatabase = 'myucto_test_payroll_restore_' . bin2hex(random_bytes(6));
        self::assertMatchesRegularExpression(self::TARGET_DATABASE_PATTERN, $this->targetDatabase);
        $this->targetStorage = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . $this->targetDatabase . '_storage';

        $source = $this->sourceConnection->pdo();
        $currencyId = (int) ($source->query(
            "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
        )?->fetchColumn() ?: 0);
        $vatRateId = (int) ($source->query(
            'SELECT id FROM vat_rates ORDER BY id LIMIT 1',
        )?->fetchColumn() ?: 0);
        $countryId = (int) ($source->query(
            "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
        )?->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $countryId === 0) {
            $this->markTestSkipped('Testovací DB nemá základní číselníky.');
        }

        $source->beginTransaction();
        $this->sourceTransaction = true;
        $source->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 31", "Praha", "11000", ?, ?, ?, ?)',
        )->execute([
            'MZ-31 obnova mezd s.r.o.',
            $countryId,
            'mz31-restore@example.test',
            $currencyId,
            $vatRateId,
        ]);
        $this->supplierId = (int) $source->lastInsertId();
        $source->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals,
                 is_active, is_default, account_number, bank_code, bank_name,
                 iban, bic)
             SELECT ?, code, label, symbol, name_cs, name_en, decimals,
                    is_active, 1, account_number, bank_code, bank_name, iban, bic
               FROM currencies WHERE id = ?',
        )->execute([$this->supplierId, $currencyId]);
        $ownCurrencyId = (int) $source->lastInsertId();
        self::assertGreaterThan(0, $ownCurrencyId);
        $source->prepare(
            'UPDATE supplier SET default_currency_id = ? WHERE id = ?',
        )->execute([$ownCurrencyId, $this->supplierId]);
    }

    protected function tearDown(): void
    {
        $this->target = null;
        if ($this->server !== null && $this->isSafeTargetDatabase($this->targetDatabase)) {
            try {
                $this->server->exec('DROP DATABASE IF EXISTS `' . $this->targetDatabase . '`');
            } catch (\Throwable) {
                // Původní výsledek testu má přednost; název DB je i tak bezpečně omezený.
            }
        }
        $this->removeDirectory($this->targetStorage);

        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if ($this->supplierId > 0) {
            @unlink(RuntimePaths::storage('locks') . '/instance-export-sup' . $this->supplierId . '.lock');
            $this->removeDirectory(RuntimePaths::storage(
                'instance-exports/sup-' . $this->supplierId,
            ));
            $this->removeDirectory(RuntimePaths::storage(
                'payroll-documents/sup-' . $this->supplierId,
            ));
            $this->removeDirectory(RuntimePaths::storage(
                'documents/sup-' . $this->supplierId,
            ));
        }
        if (isset($this->sourceConnection)
            && $this->sourceTransaction
            && $this->sourceConnection->pdo()->inTransaction()) {
            $this->sourceConnection->pdo()->rollBack();
        }
        if (isset($this->sourceConnection)) {
            $this->sourceConnection->close();
        }
    }

    public function testCompletePayrollFlowSurvivesFreshDatabaseRestoreByteForByte(): void
    {
        $fixture = $this->seedCompletePayrollFlow();
        $source = $this->sourceConnection->pdo();
        $sourceRows = $this->snapshotRows($source, $fixture['bank_statement_id']);
        $sourceCounts = array_map('count', $sourceRows);
        $sourceFingerprint = $this->fingerprint($sourceRows);

        self::assertSame(1, $sourceCounts['documents'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_production_qualifications'] ?? 0);
        self::assertSame(7, $sourceCounts['payroll_production_qualification_documents'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_enforcement_cases'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_enforcement_claims'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_enforcement_case_documents'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_person_foreign_permits'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_retention_policies'] ?? 0);
        self::assertSame(1, $sourceCounts['retention_holds'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_document_batches'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_document_batch_items'] ?? 0);
        self::assertSame(1, $sourceCounts['payroll_document_batch_attempts'] ?? 0);

        $result = $this->export->runForSupplier(
            $this->supplierId,
            [InstanceExportService::PART_RESTORE],
        );
        $archivePath = (string) $result['abs_path'];
        $this->temporaryFiles[] = $archivePath;
        $this->temporaryFiles[] = $archivePath . '.sha256';
        self::assertFileExists($archivePath);
        self::assertSame(
            (string) $result['sha256'],
            hash_file('sha256', $archivePath),
            'SHA-256 celého ZIPu musí odpovídat hodnotě vrácené exportérem.',
        );
        self::assertSame(
            (string) $result['sha256'] . '  ' . basename($archivePath) . "\n",
            file_get_contents($archivePath . '.sha256'),
            'Vedlejší SHA-256 soubor musí popisovat přesně exportovaný ZIP.',
        );
        self::assertTrue((bool) ($result['manifest']['restore']['available'] ?? false));
        $assets = [];
        foreach ((array) ($result['manifest']['restore']['files'] ?? []) as $asset) {
            $assets[(string) ($asset['storage_path'] ?? '')] = $asset;
        }
        self::assertArrayHasKey(
            $fixture['dms_storage_path'],
            $assets,
            'DMS důkazní soubor musí být součástí obnovitelného archivu.',
        );
        self::assertSame($fixture['dms_sha256'], $assets[$fixture['dms_storage_path']]['sha256'] ?? null);
        self::assertSame(
            'denylist',
            $result['manifest']['sections']['data']['skipped_tables']['payroll_period_export_jobs'] ?? null,
            'Provozní exportní job se nesmí přenášet do obnovené instance.',
        );

        $this->createAndMigrateTargetDatabase();
        self::assertNotNull($this->target);
        $restore = new CompleteInstanceRestoreService(
            $this->target,
            $this->targetStorage,
            '',
            true,
        );
        $validation = $restore->validate($archivePath);
        foreach ($sourceCounts as $table => $expected) {
            self::assertSame(
                $expected,
                (int) ($validation['counts'][$table] ?? -1),
                "Před obnovou nesedí počet řádků {$table} v archivu.",
            );
        }

        $report = $restore->restore($archivePath);
        self::assertGreaterThanOrEqual(2, $report['files'], 'Obnova musí vrátit výplatní i DMS PDF.');
        self::assertGreaterThanOrEqual(1, $report['blobs'], 'Obnova musí vrátit bankovní důkaz platby.');
        self::assertSame([], $this->foreignKeyViolations($this->target));

        $targetRows = $this->snapshotRows($this->target, $fixture['bank_statement_id']);
        self::assertSame($sourceCounts, array_map('count', $targetRows));
        self::assertSame(
            $sourceRows,
            $targetRows,
            'Obnovené mzdové, podací a platební řádky musí být přesně shodné.',
        );
        self::assertSame($sourceFingerprint, $this->fingerprint($targetRows));

        $restoredPdf = $this->targetStorage . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $fixture['pdf_storage_path']);
        self::assertFileExists($restoredPdf);
        self::assertSame($fixture['pdf_bytes'], file_get_contents($restoredPdf));
        self::assertSame($fixture['pdf_sha256'], hash_file('sha256', $restoredPdf));

        $restoredDms = $this->targetStorage . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $fixture['dms_storage_path']);
        self::assertFileExists($restoredDms);
        self::assertSame($fixture['dms_bytes'], file_get_contents($restoredDms));
        self::assertSame($fixture['dms_sha256'], hash_file('sha256', $restoredDms));

        self::assertSame(
            0,
            (int) $this->target->query('SELECT COUNT(*) FROM payroll_period_export_jobs')?->fetchColumn(),
            'Obnova nesmí oživit provozní exportní job.',
        );

        $artifacts = $this->target->prepare(
            'SELECT artifact_kind, content_ciphertext, artifact_sha256
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? ORDER BY artifact_kind',
        );
        $artifacts->execute([$this->supplierId]);
        $artifactRows = $artifacts->fetchAll(PDO::FETCH_ASSOC) ?: [];
        self::assertSame([
            [
                'artifact_kind' => 'outbound_xml',
                'content_ciphertext' => $fixture['xml_ciphertext'],
                'artifact_sha256' => hash('sha256', $fixture['xml_bytes']),
            ],
            [
                'artifact_kind' => 'validation_protocol',
                'content_ciphertext' => $fixture['protocol_ciphertext'],
                'artifact_sha256' => hash('sha256', $fixture['protocol_bytes']),
            ],
        ], $artifactRows);

        $bank = $this->target->prepare(
            'SELECT file_content, file_hash FROM bank_statements
              WHERE supplier_id = ? AND id = ?',
        );
        $bank->execute([$this->supplierId, $fixture['bank_statement_id']]);
        $restoredBank = $bank->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($restoredBank);
        self::assertSame($fixture['bank_bytes'], $restoredBank['file_content']);
        self::assertSame(hash('sha256', $fixture['bank_bytes']), $restoredBank['file_hash']);
    }

    /**
     * @return array{
     *   bank_statement_id:int,
     *   pdf_storage_path:string,pdf_bytes:string,pdf_sha256:string,
     *   dms_storage_path:string,dms_bytes:string,dms_sha256:string,
     *   xml_bytes:string,xml_ciphertext:string,
     *   protocol_bytes:string,protocol_ciphertext:string,
     *   bank_bytes:string
     * }
     */
    private function seedCompletePayrollFlow(): array
    {
        $pdo = $this->sourceConnection->pdo();
        $actorId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')?->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $actorId, 'Testovací DB musí obsahovat uživatele pro auditní FK.');
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická zaměstnankyně", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, "2099-01-01", "2099-02-15", "approved", 1)',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $resultJson = '{"schema":"synthetic-mz31-result.v1","net_pay_minor":4200000}';
        $resultHash = hash('sha256', $resultJson);
        $inputJson = '{"schema":"synthetic-mz31-input.v1","period":"2099-01"}';
        $inputHash = hash('sha256', $inputJson);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved", "synthetic-mz31.v1",
                     ?, ?, ?, ?, ?, ?, "2099-02-01 10:00:00")',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            $inputHash,
            $resultJson,
            $resultHash,
            hash('sha256', "mz31-revision-{$this->supplierId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $dmsBytes = "%PDF-1.7\n% synthetic MZ-31 evidence bundle\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
        $dmsHash = hash('sha256', $dmsBytes);
        $dmsFilename = $dmsHash . '-mz31-evidence.pdf';
        $dmsStoragePath = 'documents/sup-' . $this->supplierId . '/'
            . substr($dmsHash, 0, 2) . '/' . $dmsFilename;
        $dmsPath = RuntimePaths::storage($dmsStoragePath);
        self::assertTrue(@mkdir(dirname($dmsPath), 0750, true) || is_dir(dirname($dmsPath)));
        self::assertSame(strlen($dmsBytes), file_put_contents($dmsPath, $dmsBytes));
        $pdo->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope)
             VALUES (?, "Syntetický důkazní balíček MZ-31", "mz31-evidence.pdf", ?, ?,
                     "application/pdf", ?, "pdf", "manual", ?, "company")',
        )->execute([
            $this->supplierId,
            $dmsFilename,
            $dmsHash,
            strlen($dmsBytes),
            $actorId,
        ]);
        $dmsDocumentId = (int) $pdo->lastInsertId();

        $qualificationEvidence = '{"schema":"synthetic-mz31-qualification.v1","recovery":true}';
        $pdo->prepare(
            'INSERT INTO payroll_production_qualifications
                (supplier_id, module_state_row_version, support_matrix_version,
                 support_matrix_sha256, evidence_json, evidence_sha256, qualified_by, qualified_at)
             VALUES (?, 1, "synthetic-mz31.v1", ?, ?, ?, ?, "2099-02-02 10:00:00")',
        )->execute([
            $this->supplierId,
            hash('sha256', 'synthetic-mz31-support-matrix'),
            $qualificationEvidence,
            hash('sha256', $qualificationEvidence),
            $actorId,
        ]);
        $qualificationId = (int) $pdo->lastInsertId();
        $qualificationDocument = $pdo->prepare(
            'INSERT INTO payroll_production_qualification_documents
                (supplier_id, qualification_id, evidence_key, sequence_no, document_id, document_sha256)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        foreach ([
            ['parallel_run', 1],
            ['parallel_run', 2],
            ['correction_scenario', 1],
            ['recovery_drill', 1],
            ['expert_approval', 1],
            ['rollback_plan', 1],
            ['post_go_live_monitoring', 1],
        ] as [$evidenceKey, $sequenceNo]) {
            $qualificationDocument->execute([
                $this->supplierId,
                $qualificationId,
                $evidenceKey,
                $sequenceNo,
                $dmsDocumentId,
                $dmsHash,
            ]);
        }

        $pdo->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status, effective_from,
                 evidence_complete, recipient_verified, created_by, updated_by)
             VALUES (?, ?, ?, "enforcement", "received", "2099-01-01", 1, 0, ?, ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            "synthetic-mz31-enforcement-{$this->supplierId}",
            $actorId,
            $actorId,
        ]);
        $enforcementCaseId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, legal_basis, category, outstanding_minor_units,
                 first_payer_delivered_on)
             VALUES (?, ?, ?, "statutory", "non_priority", 10000, "2099-01-15")',
        )->execute([
            $this->supplierId,
            $enforcementCaseId,
            "synthetic-mz31-claim-{$this->supplierId}",
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_case_documents
                (supplier_id, case_id, dms_document_id, evidence_kind, document_sha256, verified_by)
             VALUES (?, ?, ?, "initial_order", ?, ?)',
        )->execute([
            $this->supplierId,
            $enforcementCaseId,
            $dmsDocumentId,
            $dmsHash,
            $actorId,
        ]);

        $pdo->prepare(
            'INSERT INTO payroll_person_foreign_permits
                (supplier_id, employee_id, permit_kind, permit_label, issuing_country_code,
                 effective_from, valid_until, document_supplier_id, document_id, document_sha256,
                 recorded_by)
             VALUES (?, ?, "work", "Syntetické pracovní oprávnění", "UA", "2099-01-01",
                     "2099-12-31", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            $this->supplierId,
            $dmsDocumentId,
            $dmsHash,
            $actorId,
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_retention_policies
                (supplier_id, category, extra_years, reason)
             VALUES (?, "payroll_sheet", 1, "Syntetické prodloužení retence MZ-31")',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO retention_holds
                (supplier_id, subject_kind, subject_id, reason, description, placed_on, created_by)
             VALUES (?, "payroll_employee", ?, "enforcement", "Syntetická exekuce MZ-31",
                     "2099-02-02", ?)',
        )->execute([$this->supplierId, $employeeId, $actorId]);

        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $resultJson,
            $resultHash,
        ]);

        $pdfBytes = "%PDF-1.7\n% synthetic MZ-31 payslip\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
        $pdfHash = hash('sha256', $pdfBytes);
        $pdfStoragePath = 'payroll-documents/sup-' . $this->supplierId . '/'
            . substr($pdfHash, 0, 2) . '/' . $pdfHash;
        $pdfPath = RuntimePaths::storage($pdfStoragePath);
        self::assertTrue(@mkdir(dirname($pdfPath), 0750, true) || is_dir(dirname($pdfPath)));
        self::assertSame(strlen($pdfBytes), file_put_contents($pdfPath, $pdfBytes));
        $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, employee_id, document_kind,
                 document_revision_no, revision_snapshot_hash,
                 source_snapshot_hash, template_version, renderer_version,
                 file_sha256, size_bytes, mime_type, storage_key,
                 suggested_filename, manifest_json, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "payslip", 1, ?, ?, "synthetic-template.v1",
                     "synthetic-renderer.v1", ?, ?, "application/pdf", ?,
                     "mz31-synthetic-payslip.pdf", "{}", ?)',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            $employeeId,
            $resultHash,
            $inputHash,
            $pdfHash,
            strlen($pdfBytes),
            $pdfHash,
            hash('sha256', "mz31-pdf-{$this->supplierId}", true),
        ]);
        $generatedDocumentId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_document_batches
                (supplier_id, run_id, revision_id, status, source_snapshot_hash,
                 idempotency_key_hash, item_count, succeeded_count, failed_count,
                 requested_by, completed_at)
             VALUES (?, ?, ?, "completed", ?, ?, 1, 1, 0, ?, "2099-02-02 12:00:00")',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            $resultHash,
            hash('sha256', "mz31-document-batch-{$revisionId}", true),
            $actorId,
        ]);
        $documentBatchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_document_batch_items
                (supplier_id, batch_id, employee_id, source_snapshot_hash, status,
                 attempt_count, available_at, document_id, completed_at)
             VALUES (?, ?, ?, ?, "succeeded", 1, "2099-02-02 11:00:00", ?, "2099-02-02 12:00:00")',
        )->execute([
            $this->supplierId,
            $documentBatchId,
            $employeeId,
            $resultHash,
            $generatedDocumentId,
        ]);
        $documentBatchItemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_document_batch_attempts
                (supplier_id, batch_id, item_id, attempt_no, lease_token, status, started_at, finished_at)
             VALUES (?, ?, ?, 1, ?, "succeeded", "2099-02-02 11:00:00", "2099-02-02 12:00:00")',
        )->execute([
            $this->supplierId,
            $documentBatchId,
            $documentBatchItemId,
            random_bytes(16),
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_period_export_jobs
                (supplier_id, export_scope, period_start, period_end, status, available_at, requested_by)
             VALUES (?, "monthly", "2099-01-01", "2099-01-31", "queued", "2099-02-02 10:00:00", ?)',
        )->execute([$this->supplierId, $actorId]);

        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, "test", "JMHZ", "payroll_run", ?, "2099-01-01",
                     "2099-01-31", "regular", "vrep_apep", "prepared",
                     "payroll_run_approved", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            "run:{$runId}",
            "revision:{$revisionId}",
            hash('sha256', "mz31-obligation-event-{$revisionId}"),
            hash('sha256', "mz31-obligation-request-{$revisionId}"),
            hash('sha256', "mz31-obligation-idem-{$revisionId}", true),
        ]);
        $obligationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind,
                 channel, status, source_revision_id, source_snapshot_hash,
                 request_fingerprint, idempotency_key_hash)
             VALUES (?, "test", ?, "regular", "vrep_apep", "prepared", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $obligationId,
            $revisionId,
            $resultHash,
            hash('sha256', "mz31-submission-request-{$revisionId}"),
            hash('sha256', "mz31-submission-idem-{$revisionId}", true),
        ]);
        $submissionId = (int) $pdo->lastInsertId();
        $xmlBytes = '<?xml version="1.0" encoding="UTF-8"?><JMHZ period="2099-01"/>';
        $xmlCiphertext = 'enc:v2:' . base64_encode($xmlBytes);
        $protocolBytes = "VALIDATION OK\nXSD: synthetic-jmhz-2099.xsd\n";
        $protocolCiphertext = 'enc:v2:' . base64_encode($protocolBytes);
        $artifact = $pdo->prepare(
            'INSERT INTO payroll_submission_artifacts
                (supplier_id, environment, submission_id, artifact_kind,
                 direction, mime_type, content_ciphertext, byte_size,
                 artifact_sha256, xsd_version, catalog_version, channel,
                 idempotency_key_hash)
             VALUES (?, "test", ?, ?, ?, ?, ?, ?, ?, ?, ?, "vrep_apep", ?)',
        );
        $artifact->execute([
            $this->supplierId,
            $submissionId,
            'outbound_xml',
            'outbound',
            'application/xml',
            $xmlCiphertext,
            strlen($xmlBytes),
            hash('sha256', $xmlBytes),
            'synthetic-jmhz-2099.xsd',
            'synthetic-jmhz-2099',
            hash('sha256', "mz31-xml-{$submissionId}", true),
        ]);
        $artifact->execute([
            $this->supplierId,
            $submissionId,
            'validation_protocol',
            'internal',
            'text/plain',
            $protocolCiphertext,
            strlen($protocolBytes),
            hash('sha256', $protocolBytes),
            'synthetic-jmhz-2099.xsd',
            'synthetic-jmhz-2099',
            hash('sha256', "mz31-protocol-{$submissionId}", true),
        ]);

        $liabilitySnapshot = '{"schema":"synthetic-mz31-liability.v1","amount_minor":4200000}';
        $pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing", ?, "2099-02-15",
                     "CZK", 4200000, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            "net-wage.mz31.{$revisionId}",
            'employee:synthetic',
            $liabilitySnapshot,
            hash('sha256', $liabilitySnapshot),
            hash('sha256', "mz31-liability-{$revisionId}", true),
        ]);
        $liabilityId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "abo", "outgoing", "2099-02-15", "CZK",
                     "payer:synthetic", 4200000, 1, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            "mz31-batch-{$revisionId}",
            'enc:v2:' . base64_encode('synthetic MZ-31 payment batch'),
            hash('sha256', "mz31-batch-snapshot-{$revisionId}"),
            hash('sha256', "mz31-batch-idem-{$revisionId}", true),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "employee:synthetic", 4200000, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $batchId,
            "mz31-item-{$revisionId}",
            'enc:v2:' . base64_encode('synthetic MZ-31 payment instruction'),
            hash('sha256', "mz31-item-instruction-{$revisionId}"),
            hash('sha256', "mz31-item-idem-{$revisionId}", true),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, 4200000, ?)',
        )->execute([
            $this->supplierId,
            $itemId,
            $liabilityId,
            hash('sha256', "mz31-allocation-{$revisionId}", true),
        ]);
        $allocationId = (int) $pdo->lastInsertId();

        $bankBytes = "0740000000010000000501004200000MZ31\r\n";
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, file_content,
                 account_number, bank_code, currency, statement_date,
                 transaction_count)
             VALUES (?, "gpc", ?, ?, ?, "1000000005", "0100", "CZK",
                     "2099-02-28", 1)',
        )->execute([
            $this->supplierId,
            "mz31-payment-{$this->supplierId}.gpc",
            hash('sha256', $bankBytes),
            $bankBytes,
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol,
                 counterparty_account, description, import_fingerprint)
             VALUES (?, "2099-02-15", -42000.00, "CZK", "209901",
                     "1000000005", "Syntetická úhrada mzdy MZ-31", ?)',
        )->execute([
            $statementId,
            hash('sha256', "mz31-bank-transaction-{$statementId}"),
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, idempotency_key_hash)
             VALUES (?, ?, "matched", 4200000, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $allocationId,
            $statementId,
            $transactionId,
            hash('sha256', "mz31-match-{$allocationId}", true),
        ]);

        return [
            'bank_statement_id' => $statementId,
            'pdf_storage_path' => $pdfStoragePath,
            'pdf_bytes' => $pdfBytes,
            'pdf_sha256' => $pdfHash,
            'dms_storage_path' => $dmsStoragePath,
            'dms_bytes' => $dmsBytes,
            'dms_sha256' => $dmsHash,
            'xml_bytes' => $xmlBytes,
            'xml_ciphertext' => $xmlCiphertext,
            'protocol_bytes' => $protocolBytes,
            'protocol_ciphertext' => $protocolCiphertext,
            'bank_bytes' => $bankBytes,
        ];
    }

    private function createAndMigrateTargetDatabase(): void
    {
        self::assertNotNull($this->server);
        if (!$this->isSafeTargetDatabase($this->targetDatabase)) {
            self::fail('Odmítnut nebezpečný název cílové testovací databáze.');
        }
        $this->server->exec(
            'CREATE DATABASE `' . $this->targetDatabase
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );

        $environment = getenv();
        $environment['MYINVOICE_DB_NAME'] = $this->targetDatabase;
        $environment['MYSQL_DATABASE'] = $this->targetDatabase;
        $process = proc_open(
            [PHP_BINARY, $this->rootDir . '/api/bin/migrate.php', '--no-backfills'],
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
        $exitCode = proc_close($process);
        self::assertSame(
            0,
            $exitCode,
            "Migrace prázdné cílové DB selhala.\n"
                . substr((string) $stdout . "\n" . (string) $stderr, -12000),
        );

        $this->target = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $this->config->get('db.host', '127.0.0.1'),
                (int) $this->config->get('db.port', 3306),
                $this->targetDatabase,
            ),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        self::assertTrue(@mkdir($this->targetStorage, 0700, true) || is_dir($this->targetStorage));
        self::assertSame([], array_values(array_diff(scandir($this->targetStorage) ?: [], ['.', '..'])));
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function snapshotRows(PDO $pdo, int $bankStatementId): array
    {
        $snapshot = [];
        foreach (self::TENANT_TABLES as $table) {
            $statement = $pdo->prepare(
                'SELECT * FROM `' . $table . '` WHERE supplier_id = ? ORDER BY id',
            );
            $statement->execute([$this->supplierId]);
            $snapshot[$table] = $this->normalizeRows($statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
        $transactions = $pdo->prepare(
            'SELECT * FROM bank_transactions WHERE statement_id = ? ORDER BY id',
        );
        $transactions->execute([$bankStatementId]);
        $snapshot['bank_transactions'] = $this->normalizeRows(
            $transactions->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
        return $snapshot;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        foreach ($rows as &$row) {
            ksort($row);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,list<array<string,mixed>>> $snapshot */
    private function fingerprint(array $snapshot): string
    {
        $encode = static function (mixed $value) use (&$encode): mixed {
            if (is_string($value)) {
                return ['base64' => base64_encode($value)];
            }
            if (is_array($value)) {
                $result = [];
                foreach ($value as $key => $item) {
                    $result[$key] = $encode($item);
                }
                return $result;
            }
            return $value;
        };
        return hash('sha256', (string) json_encode($encode($snapshot), JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function foreignKeyViolations(PDO $pdo): array
    {
        $foreignKeys = $pdo->query(
            'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME,
                    REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
        )?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $constraints = [];
        foreach ($foreignKeys as $foreignKey) {
            $constraints[$foreignKey['TABLE_NAME'] . ':' . $foreignKey['CONSTRAINT_NAME']][] = $foreignKey;
        }
        $violations = [];
        foreach ($constraints as $key => $parts) {
            $first = $parts[0];
            $join = implode(' AND ', array_map(
                static fn (array $part): string => 'child.`' . $part['COLUMN_NAME']
                    . '` = parent.`' . $part['REFERENCED_COLUMN_NAME'] . '`',
                $parts,
            ));
            $present = implode(' AND ', array_map(
                static fn (array $part): string => 'child.`' . $part['COLUMN_NAME'] . '` IS NOT NULL',
                $parts,
            ));
            $sql = sprintf(
                'SELECT COUNT(*) FROM `%s` child LEFT JOIN `%s` parent ON %s'
                    . ' WHERE %s AND parent.`%s` IS NULL',
                $first['TABLE_NAME'],
                $first['REFERENCED_TABLE_NAME'],
                $join,
                $present,
                $first['REFERENCED_COLUMN_NAME'],
            );
            if ((int) $pdo->query($sql)?->fetchColumn() > 0) {
                $violations[] = (string) $key;
            }
        }
        return $violations;
    }

    private function isSafeTargetDatabase(string $database): bool
    {
        return preg_match(self::TARGET_DATABASE_PATTERN, $database) === 1;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
