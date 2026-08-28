<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDocumentBatchRepository;
use MyInvoice\Service\Payroll\Document\PayrollDocumentBatchQueueService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Service\Payroll\Document\PayslipDocumentData;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Fixtures\Payroll\SyntheticPayslipFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollDocumentBatchQueueServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDocumentBatchQueueService $queue;
    private PayrollDocumentBatchRepository $batches;
    private int $supplierId;
    private string $dataDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . '/myucto-payroll-document-queue-' . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->queue = $container->get(PayrollDocumentBatchQueueService::class);
        $this->batches = $container->get(PayrollDocumentBatchRepository::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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

    public function testEnqueueOfFiveHundredPeopleDoesNotRenderDuringApprovalIntent(): void
    {
        [$runId, $revisionId] = $this->approvedRevision(500, null, false);

        $batch = $this->queue->enqueueApprovedRevision(
            $this->supplierId,
            $runId,
            $revisionId,
            null,
        );

        self::assertSame(500, $batch['item_count']);
        self::assertSame('queued', $batch['status']);
        self::assertSame(0, $this->documentCount($revisionId));
        self::assertSame(500, $this->itemCount((int) $batch['id']));
    }

    public function testOneBrokenPersonDoesNotCancelSuccessfulNeighboursAndPreventsZip(): void
    {
        [$runId, $revisionId] = $this->approvedRevision(3, 1, true);
        $batch = $this->queue->enqueueApprovedRevision(
            $this->supplierId,
            $runId,
            $revisionId,
            null,
        );

        $result = $this->queue->processAvailable(10);
        $detail = $this->queue->detail($this->supplierId, (int) $batch['id']);

        self::assertSame(3, $result['processed']);
        self::assertSame(2, $result['succeeded']);
        self::assertSame(1, $result['failed']);
        self::assertSame(2, $detail['succeeded_count']);
        self::assertSame('retry_wait', $detail['status']);
        self::assertNull($detail['bundle_document_id']);
        self::assertSame(0, $this->bundleCount($revisionId));
    }

    public function testManualRetryReusesExistingPayslipWithoutDuplicate(): void
    {
        [$runId, $revisionId] = $this->approvedRevision(2, null, true);
        $batch = $this->queue->enqueueApprovedRevision(
            $this->supplierId,
            $runId,
            $revisionId,
            null,
        );
        self::assertTrue($this->queue->processOne()['succeeded']);
        $items = $this->queue->items($this->supplierId, (int) $batch['id'], 10, 0);
        $first = $items['items'][0];
        $firstDocumentId = (int) $first['document_id'];
        $this->db->pdo()->prepare(
            'UPDATE payroll_document_batch_items
                SET status = "failed", document_id = NULL, completed_at = NULL,
                    last_error_code = "synthetic_retry",
                    last_error_message = "Synthetic retry request."
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $first['id']]);

        $this->queue->retry($this->supplierId, (int) $batch['id'], (int) $first['id']);
        $processed = $this->queue->processAvailable(2);
        self::assertSame(2, $processed['succeeded']);
        $after = $this->queue->items($this->supplierId, (int) $batch['id'], 10, 0);

        self::assertSame($firstDocumentId, (int) $after['items'][0]['document_id']);
        self::assertSame(1, $this->payslipCountForEmployee(
            $revisionId,
            (int) $first['employee_id'],
        ));
    }

    public function testTenantCannotReadOrRetryAnotherTenantBatch(): void
    {
        [$runId, $revisionId] = $this->approvedRevision(1, null, true);
        $batch = $this->queue->enqueueApprovedRevision(
            $this->supplierId,
            $runId,
            $revisionId,
            null,
        );
        $sourceSupplierId = (int) $this->db->pdo()
            ->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $otherSupplierId = $this->createIsolatedSupplier(
            $this->db->pdo(),
            $sourceSupplierId,
        );

        self::assertNull($this->queue->detail($otherSupplierId, (int) $batch['id']));
        $this->expectException(\DomainException::class);
        $this->queue->retry($otherSupplierId, (int) $batch['id'], 1);
    }

    public function testExpiredWorkerLeaseIsRecoveredAsAnotherAttempt(): void
    {
        [$runId, $revisionId] = $this->approvedRevision(1, null, true);
        $batch = $this->queue->enqueueApprovedRevision(
            $this->supplierId,
            $runId,
            $revisionId,
            null,
        );
        $first = $this->batches->claimNext();
        self::assertIsArray($first);
        $this->db->pdo()->prepare(
            'UPDATE payroll_document_batch_items
                SET locked_at = UTC_TIMESTAMP() - INTERVAL 16 MINUTE
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $first['id']]);

        $second = $this->batches->claimNext();

        self::assertIsArray($second);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(2, $second['attempt_count']);
        self::assertNotSame($first['lease_token'], $second['lease_token']);
        $attempts = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_document_batch_attempts
              WHERE supplier_id = ? AND batch_id = ? ORDER BY attempt_no'
        );
        $attempts->execute([$this->supplierId, $batch['id']]);
        self::assertSame(['stale', 'running'], $attempts->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testExpiredFinalLeaseMarksBatchFailedWithoutAnotherClaim(): void
    {
        [$runId, $revisionId] = $this->approvedRevision(1, null, true);
        $batch = $this->queue->enqueueApprovedRevision(
            $this->supplierId,
            $runId,
            $revisionId,
            null,
        );
        $claim = $this->batches->claimNext();
        self::assertIsArray($claim);
        $this->db->pdo()->prepare(
            'UPDATE payroll_document_batch_items
                SET attempt_count = 3,
                    locked_at = UTC_TIMESTAMP() - INTERVAL 16 MINUTE
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $claim['id']]);

        self::assertNull($this->batches->claimNext());
        $detail = $this->queue->detail($this->supplierId, (int) $batch['id']);

        self::assertSame('failed', $detail['status']);
        self::assertSame(1, $detail['failed_count']);
        self::assertNull($detail['bundle_document_id']);
    }

    public function testConcurrentBundleFinalizationReusesTheSameArchive(): void
    {
        [$runId, $revisionId] = $this->approvedRevision(1, null, true);
        $batch = $this->queue->enqueueApprovedRevision(
            $this->supplierId,
            $runId,
            $revisionId,
            null,
        );

        self::assertTrue($this->queue->processOne()['succeeded']);
        $completed = $this->queue->detail($this->supplierId, (int) $batch['id']);
        self::assertSame('completed', $completed['status']);
        self::assertIsInt($completed['bundle_document_id']);

        $this->batches->attachBundle(
            $this->supplierId,
            (int) $batch['id'],
            $completed['bundle_document_id'],
        );
        $this->queue->finalizeReadyBatches();

        $afterReplay = $this->queue->detail($this->supplierId, (int) $batch['id']);
        self::assertSame($completed['bundle_document_id'], $afterReplay['bundle_document_id']);
        self::assertSame(1, $this->bundleCount($revisionId));
    }

    /** @return array{int,int} */
    private function approvedRevision(
        int $personCount,
        ?int $brokenIndex,
        bool $renderable,
    ): array {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, "2026-07-01", "2026-07-31", "approved")'
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $people = [];
        for ($index = 0; $index < $personCount; $index++) {
            $pdo->prepare(
                'INSERT INTO payroll_employees
                    (supplier_id, full_name, taxpayer_type, is_active)
                 VALUES (?, ?, "employee", 1)'
            )->execute([$this->supplierId, 'Fronta osoba ' . ($index + 1)]);
            $employeeId = (int) $pdo->lastInsertId();
            $payslip = $renderable
                ? $this->snapshot(SyntheticPayslipFixture::document(), $index + 1)
                : [];
            if ($brokenIndex === $index) {
                $payslip = ['schema_version' => 'broken-payslip'];
            }
            $people[] = [
                'employee_id' => $employeeId,
                'employments' => [],
                'totals' => [],
                'payslip_document' => $payslip,
            ];
        }
        $resultJson = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'people' => $people,
            'totals' => [],
        ]);
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
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2", ?, ?, ?, ?, ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', 'queue-revision-' . $runId),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status, result_json, result_hash)
             VALUES (?, ?, ?, "calculated", ?, ?)'
        );
        foreach ($people as $person) {
            $json = CanonicalJson::encode($person);
            $insert->execute([
                $this->supplierId,
                $revisionId,
                $person['employee_id'],
                $json,
                hash('sha256', $json),
            ]);
        }
        return [$runId, $revisionId];
    }

    /** @return array<string,mixed> */
    private function snapshot(PayslipDocumentData $document, int $sequence): array
    {
        return [
            'schema_version' => 'payroll-payslip-document.v1',
            'employer_name' => $document->employerName,
            'employer_identification_number' => $document->employerIdentificationNumber,
            'employee_display_name' => "Fronta osoba {$sequence}",
            'employment_label' => $document->employmentLabel,
            'income_lines' => array_map(
                static fn ($line): array => $line->toTemplateData(),
                $document->incomeLines,
            ),
            'gross_minor_units' => $document->grossMinorUnits,
            'employee_social_minor_units' => $document->employeeSocialMinorUnits,
            'employee_health_minor_units' => $document->employeeHealthMinorUnits,
            'health_minimum_top_up_minor_units' => $document->healthMinimumTopUpMinorUnits,
            'tax_base_minor_units' => $document->taxBaseMinorUnits,
            'tax_before_credits_minor_units' => $document->taxBeforeCreditsMinorUnits,
            'tax_non_refundable_credits_minor_units' => $document->taxNonRefundableCreditsMinorUnits,
            'tax_child_credit_minor_units' => $document->taxChildCreditMinorUnits,
            'tax_bonus_eligible' => $document->taxBonusEligible,
            'tax_after_credits_minor_units' => $document->taxAfterCreditsMinorUnits,
            'tax_bonus_minor_units' => $document->taxBonusMinorUnits,
            'other_deduction_lines' => array_map(
                static fn ($line): array => $line->toTemplateData(),
                $document->otherDeductionLines,
            ),
            'rounding_adjustment_minor_units' => $document->roundingAdjustmentMinorUnits,
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

    private function documentCount(int $revisionId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_generated_documents
              WHERE supplier_id = ? AND revision_id = ?'
        );
        $statement->execute([$this->supplierId, $revisionId]);
        return (int) $statement->fetchColumn();
    }

    private function itemCount(int $batchId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_document_batch_items
              WHERE supplier_id = ? AND batch_id = ?'
        );
        $statement->execute([$this->supplierId, $batchId]);
        return (int) $statement->fetchColumn();
    }

    private function bundleCount(int $revisionId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_generated_documents
              WHERE supplier_id = ? AND revision_id = ?
                AND document_kind = "monthly_bundle"'
        );
        $statement->execute([$this->supplierId, $revisionId]);
        return (int) $statement->fetchColumn();
    }

    private function payslipCountForEmployee(int $revisionId, int $employeeId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_generated_documents
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?
                AND document_kind = "payslip"'
        );
        $statement->execute([$this->supplierId, $revisionId, $employeeId]);
        return (int) $statement->fetchColumn();
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
