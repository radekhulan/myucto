<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\ApprovedRevisionPayslipRepository;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Payroll\Document\ApprovedRevisionDocumentBatchService;
use MyInvoice\Service\Payroll\Document\ApprovedRevisionPayslipBatchService;
use MyInvoice\Service\Payroll\Document\PayrollArtifact;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorageScope;
use MyInvoice\Service\Payroll\Document\PayslipDocumentData;
use MyInvoice\Service\Payroll\Document\PayslipDocumentSnapshotHydrator;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Fixtures\Payroll\SyntheticPayslipFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ApprovedRevisionPayslipBatchServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ApprovedRevisionPayslipBatchService $service;
    private ApprovedRevisionDocumentBatchService $batch;
    private PayrollDocumentRepository $documents;
    private PayrollDocumentStorage $storage;
    private int $supplierId;
    private int $runId;
    private int $revisionId;
    /** @var list<int> */
    private array $employeeIds = [];
    private string $dataDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . '/myucto-approved-payslip-batch-'
            . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $service = $container->get(ApprovedRevisionPayslipBatchService::class);
        $documents = $container->get(PayrollDocumentRepository::class);
        $storage = $container->get(PayrollDocumentStorage::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(ApprovedRevisionPayslipBatchService::class, $service);
        self::assertInstanceOf(PayrollDocumentRepository::class, $documents);
        self::assertInstanceOf(PayrollDocumentStorage::class, $storage);
        $this->db = $db;
        $this->service = $service;
        $this->documents = $documents;
        $this->storage = $storage;
        $batch = $container->get(ApprovedRevisionDocumentBatchService::class);
        self::assertInstanceOf(ApprovedRevisionDocumentBatchService::class, $batch);
        $this->batch = $batch;
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $this->query(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->createApprovedRevision(2);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        $this->removeDirectory($this->dataDir);
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        parent::tearDown();
    }

    public function testGeneratesEveryPayslipAndReplaysIdempotently(): void
    {
        $first = $this->service->generate(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            null,
        );
        $second = $this->service->generate(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            null,
        );

        self::assertSame($this->employeeIds, array_column($first, 'employee_id'));
        self::assertSame(array_column($first, 'id'), array_column($second, 'id'));
        self::assertCount(2, $this->documents->forRevision(
            $this->supplierId,
            $this->revisionId,
        ));
    }

    public function testPdfRenderingFinishesBeforeBatchTransactionBoundaryStarts(): void
    {
        $documents = new TransactionObservingPayrollDocumentService($this->db);
        $service = new ApprovedRevisionPayslipBatchService(
            $this->db,
            new ApprovedRevisionPayslipRepository($this->db),
            new PayslipDocumentSnapshotHydrator(),
            $documents,
        );

        $result = $service->generate(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            null,
        );

        self::assertCount(2, $result);
        self::assertSame([], $documents->legacyGenerateTransactionStates);
        self::assertSame([false, false], $documents->renderTransactionStates);
        self::assertSame([true, true], $documents->archiveTransactionStates);
    }

    public function testSourceChangedDuringRenderingFailsBeforeArchiveWrite(): void
    {
        $documents = new TransactionObservingPayrollDocumentService(
            $this->db,
            // Zdroj se mění na VÝSLEDKU OSOBY, ne na období běhu. Období běhu se
            // schválenou revizí posunout nejde od migrace 1632 (guard C-16) —
            // a nemá jít: propagace z migrace 1593 by tím přerazítkovala i mzdy,
            // které jsou schválené a vyplacené. Zdrojový otisk pásky stejně stojí
            // na `result_json`, takže tohle je věrnější simulace téhož rizika.
            function (): void {
                $this->db->pdo()->prepare(
                    "UPDATE payroll_run_persons
                        SET result_json = JSON_SET(result_json, '$.source_changed', 1)
                      WHERE supplier_id = ? AND revision_id = ?"
                )->execute([$this->supplierId, $this->revisionId]);
            },
        );
        $service = new ApprovedRevisionPayslipBatchService(
            $this->db,
            new ApprovedRevisionPayslipRepository($this->db),
            new PayslipDocumentSnapshotHydrator(),
            $documents,
        );

        try {
            $service->generate(
                $this->supplierId,
                $this->runId,
                $this->revisionId,
                null,
            );
            self::fail('Změněný zdroj během renderu nesmí vydat staré pásky.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'během vykreslování změnil',
                $exception->getMessage(),
            );
        }

        self::assertSame([false, false], $documents->renderTransactionStates);
        self::assertSame([], $documents->archiveTransactionStates);
        self::assertSame(0, $this->documentCount());
    }

    public function testOuterRollbackCanCleanExternallyOwnedStorageScope(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT payslip_outer_transaction');
        $storageScope = $this->service->beginStorageScope();

        $this->service->generate(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            null,
            $storageScope,
        );
        self::assertCount(2, $this->documents->forRevision(
            $this->supplierId,
            $this->revisionId,
        ));
        self::assertGreaterThan(0, $this->storedFileCount());

        $pdo->exec('ROLLBACK TO SAVEPOINT payslip_outer_transaction');
        $pdo->exec('RELEASE SAVEPOINT payslip_outer_transaction');
        $this->service->cleanupStorageScope(
            $this->supplierId,
            $storageScope,
        );

        self::assertSame(0, $this->documentCount());
        self::assertSame(
            0,
            $this->storedFileCount(),
            'Rollback nadřazeného schválení nesmí ponechat osiřelé PDF.',
        );
    }

    public function testMissingPersonResultFailsClosedBeforeAnyArchiveWrite(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_persons
                SET result_json = NULL, result_hash = NULL, status = "pending"
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?'
        )->execute([
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[1],
        ]);

        try {
            $this->service->generate(
                $this->supplierId,
                $this->runId,
                $this->revisionId,
                null,
            );
            self::fail('Dávka bez výsledku jedné osoby nesmí projít.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('výsledek', mb_strtolower(
                $exception->getMessage(),
            ));
        }

        self::assertSame(0, $this->documentCount());
    }

    public function testFailureAfterFirstInsertRollsBackWholeDatabaseBatch(): void
    {
        $conflictingPdf = '%PDF-1.4 synthetic conflicting payslip';
        $stored = $this->storage->store($this->supplierId, $conflictingPdf);
        $revisionHash = (string) $this->query(
            $this->db->pdo(),
            'SELECT result_snapshot_hash
               FROM payroll_run_revisions
              WHERE id = ' . $this->revisionId
        )->fetchColumn();
        $this->documents->insertOrGet([
            'supplier_id' => $this->supplierId,
            'run_id' => $this->runId,
            'revision_id' => $this->revisionId,
            'employee_id' => $this->employeeIds[1],
            'document_kind' => PayrollDocumentKind::Payslip->value,
            'document_revision_no' => 1,
            'supersedes_document_id' => null,
            'source_snapshot_hash' => str_repeat('f', 64),
            'revision_snapshot_hash' => $revisionHash,
            'template_version' => 'synthetic-conflict-v1',
            'renderer_version' => 'synthetic-conflict-v1',
            'file_sha256' => $stored['file_sha256'],
            'size_bytes' => $stored['size_bytes'],
            'mime_type' => 'application/pdf',
            'storage_key' => $stored['storage_key'],
            'suggested_filename' => 'synthetic-conflict.pdf',
            'manifest_json' => null,
            'idempotency_key_hash' => hash('sha256', 'synthetic-conflict'),
            'created_by' => null,
        ]);

        try {
            $this->service->generate(
                $this->supplierId,
                $this->runId,
                $this->revisionId,
                null,
            );
            self::fail('Konfliktní druhá páska musí zrušit celou dávku.');
        } catch (\RuntimeException $exception) {
            // Pojmenované odmítnutí, ne syrová `PDOException` z unikátního klíče.
            self::assertStringContainsString(
                'jiným zdrojovým otiskem',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $this->documentCount());
        self::assertNull($this->documents->latestForRevisionKind(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
            PayrollDocumentKind::Payslip->value,
        ));
        self::assertSame(
            1,
            $this->storedFileCount(),
            'Rollback dávky nesmí ponechat osiřelý PDF soubor první osoby.',
        );
    }

    /**
     * Kolize na `uq_payroll_document_revision` musí skončit pojmenovaným
     * odmítnutím, ne syrovou `PDOException` z databáze.
     */
    public function testDuplicateDocumentRevisionEndsAsNamedRefusal(): void
    {
        $revisionHash = (string) $this->query(
            $this->db->pdo(),
            'SELECT result_snapshot_hash
               FROM payroll_run_revisions
              WHERE id = ' . $this->revisionId
        )->fetchColumn();
        $record = function (string $idempotencyKey) use ($revisionHash): array {
            $stored = $this->storage->store(
                $this->supplierId,
                '%PDF-1.4 ' . $idempotencyKey,
            );

            return [
                'supplier_id' => $this->supplierId,
                'run_id' => $this->runId,
                'revision_id' => $this->revisionId,
                'employee_id' => $this->employeeIds[0],
                'document_kind' => PayrollDocumentKind::Payslip->value,
                'document_revision_no' => 1,
                'supersedes_document_id' => null,
                'source_snapshot_hash' => str_repeat('e', 64),
                'revision_snapshot_hash' => $revisionHash,
                'template_version' => 'synthetic-v1',
                'renderer_version' => 'synthetic-v1',
                'file_sha256' => $stored['file_sha256'],
                'size_bytes' => $stored['size_bytes'],
                'mime_type' => 'application/pdf',
                'storage_key' => $stored['storage_key'],
                'suggested_filename' => 'synthetic.pdf',
                'manifest_json' => null,
                'idempotency_key_hash' => hash('sha256', $idempotencyKey),
                'created_by' => null,
            ];
        };

        $this->documents->insertOrGet($record('prvni'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Generated payroll document conflicts with an existing artifact.',
        );
        $this->documents->insertOrGet($record('druha'));
    }

    public function testDocumentBatchReportsCompleteMonthWithoutEndedEmployments(): void
    {
        $report = $this->batch->generate(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            null,
        );

        self::assertSame(2, $report['payslips']['archived']);
        self::assertGreaterThan(0, $report['monthly_bundle']['document_id']);
        self::assertSame([], $report['employment_exits']);
        self::assertSame([], $report['missing']);
        self::assertTrue($report['complete']);
    }

    public function testDocumentBatchNeverCallsMonthWithMissingExitCertificateComplete(): void
    {
        $employmentId = $this->createEndedEmployment($this->employeeIds[0]);

        $report = $this->batch->generate(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            null,
        );

        self::assertFalse($report['complete']);
        self::assertSame(
            ['employment_certificate_missing:' . $employmentId],
            $report['missing'],
        );
        self::assertCount(1, $report['employment_exits']);
        $exit = $report['employment_exits'][0];
        self::assertSame($employmentId, $exit['employment_id']);
        self::assertFalse($exit['documents']['employment_certificate']['archived']);
        self::assertTrue($exit['documents']['employment_certificate']['required']);
        self::assertFalse(
            $exit['documents']['average_earnings_certificate']['required'],
        );
    }

    private function createEndedEmployment(int $employeeId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, is_legacy_projection)
             VALUES (?, ?, "SYNTH-BATCH", "employment", "ended",
                     "2026-01-01", "2026-01-01", "2026-07-31", 0)'
        )->execute([$this->supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function createApprovedRevision(int $personCount): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, "2026-07-01", "2026-07-31", "approved")'
        )->execute([$this->supplierId]);
        $this->runId = (int) $pdo->lastInsertId();

        $people = [];
        for ($index = 0; $index < $personCount; ++$index) {
            $pdo->prepare(
                'INSERT INTO payroll_employees
                    (supplier_id, full_name, taxpayer_type, is_active)
                 VALUES (?, ?, "employee", 1)'
            )->execute([
                $this->supplierId,
                'Syntetická osoba ' . ($index + 1),
            ]);
            $employeeId = (int) $pdo->lastInsertId();
            $this->employeeIds[] = $employeeId;
            $document = SyntheticPayslipFixture::document();
            $people[] = [
                'employee_id' => $employeeId,
                'employments' => [],
                'totals' => [],
                'payslip_document' => $this->snapshot($document, $index + 1),
            ];
        }
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'people' => $people,
            'totals' => [],
        ];
        $resultJson = CanonicalJson::encode($result);
        $resultHash = hash('sha256', $resultJson);
        $inputJson = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'people' => [],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $this->runId,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            $resultHash,
            hash('sha256', 'synthetic-approved-payslip-batch'),
        ]);
        $this->revisionId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status,
                 result_json, result_hash)
             VALUES (?, ?, ?, "calculated", ?, ?)'
        );
        foreach ($people as $person) {
            $personJson = CanonicalJson::encode($person);
            $insert->execute([
                $this->supplierId,
                $this->revisionId,
                $person['employee_id'],
                $personJson,
                hash('sha256', $personJson),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(PayslipDocumentData $document, int $sequence): array
    {
        return [
            'schema_version' => 'payroll-payslip-document.v1',
            'employer_name' => $document->employerName,
            'employer_identification_number' =>
                $document->employerIdentificationNumber,
            'employee_display_name' => "Syntetická osoba {$sequence}",
            'employment_label' => $document->employmentLabel,
            'income_lines' => array_map(
                static fn ($line): array => $line->toTemplateData(),
                $document->incomeLines,
            ),
            'gross_minor_units' => $document->grossMinorUnits,
            'employee_social_minor_units' => $document->employeeSocialMinorUnits,
            'employee_health_minor_units' => $document->employeeHealthMinorUnits,
            'health_minimum_top_up_minor_units' =>
                $document->healthMinimumTopUpMinorUnits,
            'tax_base_minor_units' => $document->taxBaseMinorUnits,
            'tax_before_credits_minor_units' => $document->taxBeforeCreditsMinorUnits,
            'tax_non_refundable_credits_minor_units' =>
                $document->taxNonRefundableCreditsMinorUnits,
            'tax_child_credit_minor_units' => $document->taxChildCreditMinorUnits,
            'tax_bonus_eligible' => $document->taxBonusEligible,
            'tax_after_credits_minor_units' => $document->taxAfterCreditsMinorUnits,
            'tax_bonus_minor_units' => $document->taxBonusMinorUnits,
            'other_deduction_lines' => array_map(
                static fn ($line): array => $line->toTemplateData(),
                $document->otherDeductionLines,
            ),
            'rounding_adjustment_minor_units' =>
                $document->roundingAdjustmentMinorUnits,
            'net_minor_units' => $document->netMinorUnits,
            'employer_social_minor_units' => $document->employerSocialMinorUnits,
            'employer_health_minor_units' => $document->employerHealthMinorUnits,
            'gross_expense_account' => $document->grossExpenseAccount,
            'gross_liability_account' => $document->grossLiabilityAccount,
            'insurance_expense_account' => $document->insuranceExpenseAccount,
            'insurance_liability_account' => $document->insuranceLiabilityAccount,
            'currency' => $document->currency,
        ];
    }

    private function documentCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_generated_documents
              WHERE supplier_id = ? AND revision_id = ?'
        );
        $statement->execute([$this->supplierId, $this->revisionId]);
        return (int) $statement->fetchColumn();
    }

    private function storedFileCount(): int
    {
        $base = PayrollDocumentStorage::baseDir($this->supplierId);
        if (!is_dir($base)) {
            return 0;
        }
        $count = 0;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $base,
                \FilesystemIterator::SKIP_DOTS,
            ),
        ) as $item) {
            if ($item instanceof \SplFileInfo
                && $item->isFile()
                && !str_starts_with($item->getFilename(), '.tmp-')
            ) {
                ++$count;
            }
        }
        return $count;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new \RuntimeException('Dočasný adresář obsahuje neplatnou položku.');
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Synthetic payroll batch query failed.');
        }
        return $statement;
    }
}

final class TransactionObservingPayrollDocumentService extends PayrollDocumentService
{
    /** @var list<bool> */
    public array $renderTransactionStates = [];
    /** @var list<bool> */
    public array $archiveTransactionStates = [];
    /** @var list<bool> */
    public array $legacyGenerateTransactionStates = [];

    private readonly int $initialSavepointCount;
    private bool $sourceMutated = false;

    public function __construct(
        private readonly Connection $db,
        private readonly ?\Closure $mutateSource = null,
    ) {
        $this->initialSavepointCount = $this->savepointCount();
    }

    /** @return array<string,mixed> */
    public function generatePayslip(
        int $supplierId,
        int $runId,
        int $revisionId,
        int $employeeId,
        PayslipDocumentData $data,
        string $idempotencyKey,
        ?int $actorUserId,
        ?int $supersedesDocumentId = null,
        ?PayrollDocumentStorageScope $storageScope = null,
    ): array {
        $this->legacyGenerateTransactionStates[] = $this->batchSavepointExists();

        return ['id' => $employeeId, 'employee_id' => $employeeId];
    }

    public function renderPayslip(PayslipDocumentData $data): PayrollArtifact
    {
        $this->renderTransactionStates[] = $this->batchSavepointExists();
        if (!$this->sourceMutated && $this->mutateSource !== null) {
            $this->sourceMutated = true;
            ($this->mutateSource)();
        }

        return new PayrollArtifact(
            PayrollDocumentKind::Payslip,
            '%PDF-1.4 synthetic transaction-boundary test',
            'application/pdf',
            'synteticka-vyplatni-paska.pdf',
            $data->sourceSnapshotSha256,
            'synthetic-template-v1',
            'synthetic-renderer-v1',
        );
    }

    /** @return array<string,mixed> */
    public function archivePayslip(
        int $supplierId,
        int $runId,
        int $revisionId,
        int $employeeId,
        PayrollArtifact $artifact,
        string $idempotencyKey,
        ?int $actorUserId,
        ?int $supersedesDocumentId = null,
        ?PayrollDocumentStorageScope $storageScope = null,
    ): array {
        $this->archiveTransactionStates[] = $this->batchSavepointExists();

        return ['id' => $employeeId, 'employee_id' => $employeeId];
    }

    public function beginStorageScope(): PayrollDocumentStorageScope
    {
        return new PayrollDocumentStorageScope();
    }

    public function commitStorageScope(PayrollDocumentStorageScope $scope): void
    {
        $scope->close();
    }

    public function cleanupStorageScope(
        int $supplierId,
        PayrollDocumentStorageScope $scope,
    ): void {
        $scope->close();
    }

    private function batchSavepointExists(): bool
    {
        return $this->savepointCount() > $this->initialSavepointCount;
    }

    private function savepointCount(): int
    {
        $statement = $this->db->pdo()->query(
            "SHOW SESSION STATUS LIKE 'Com_savepoint'",
        );
        if ($statement === false) {
            throw new \RuntimeException('Stav SAVEPOINTů se nepodařilo načíst.');
        }
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return (int) ($row['Value'] ?? 0);
    }
}
