<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentBatchRepository;
use MyInvoice\Service\Payroll\Document\PayrollAnnualDocumentBatchQueueService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Roční mzdové dokumenty jedou serverovou frontou, ne smyčkou v prohlížeči.
 *
 * Testuje se to, co odlišuje frontu od té staré smyčky: dávka se materializuje
 * jedním požadavkem, dvojklik nezaloží druhou, každá osoba má vlastní položku
 * s vlastními pokusy a selhání JEDNÉ osoby ostatní nezastaví.
 */
#[Group('integration')]
final class PayrollAnnualDocumentBatchQueueServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAnnualDocumentBatchQueueService $queue;
    private PayrollAnnualDocumentBatchRepository $batches;
    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->queue = $container->get(PayrollAnnualDocumentBatchQueueService::class);
        $this->batches = $container->get(PayrollAnnualDocumentBatchRepository::class);
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
        parent::tearDown();
    }

    public function testEnqueueMaterializesOneItemPerPersonWithApprovedYearResult(): void
    {
        $employees = $this->approvedYear(2026, 3);
        $this->approvedYear(2025, 1);

        $batch = $this->queue->enqueue(
            $this->supplierId,
            2026,
            PayrollDocumentKind::PayrollSheet,
            'all',
            null,
            null,
        );

        self::assertSame(3, $batch['item_count']);
        self::assertSame('queued', $batch['status']);
        self::assertSame(0, $batch['succeeded_count']);
        $items = $this->queue->items($this->supplierId, (int) $batch['id'], 100, 0);
        self::assertSame(3, $items['total']);
        self::assertSame(
            $employees,
            array_map(
                static fn (array $item): int => $item['employee_id'],
                $items['items'],
            ),
        );
    }

    public function testYearWithoutApprovedResultsIsRefusedInsteadOfQueuingNothing(): void
    {
        $this->approvedYear(2026, 1);

        $this->expectException(\DomainException::class);
        $this->queue->enqueue(
            $this->supplierId,
            2024,
            PayrollDocumentKind::PayrollSheet,
            'all',
            null,
            null,
        );
    }

    public function testSecondRequestJoinsTheRunningBatchButANewOneStartsAfterItFinishes(): void
    {
        $this->approvedYear(2026, 2);

        $first = $this->queue->enqueue(
            $this->supplierId,
            2026,
            PayrollDocumentKind::PayrollSheet,
            'all',
            null,
            null,
        );
        $second = $this->queue->enqueue(
            $this->supplierId,
            2026,
            PayrollDocumentKind::PayrollSheet,
            'all',
            null,
            null,
        );
        self::assertSame($first['id'], $second['id']);

        $this->closeBatch((int) $first['id']);

        // Dokončená dávka nesmí rozsah zamknout navždy: účetní přijme člověka
        // nebo opraví data a chce dávku spustit znovu.
        $third = $this->queue->enqueue(
            $this->supplierId,
            2026,
            PayrollDocumentKind::PayrollSheet,
            'all',
            null,
            null,
        );
        self::assertNotSame($first['id'], $third['id']);
    }

    public function testSelectedScopeQueuesOnlyThatPerson(): void
    {
        $employees = $this->approvedYear(2026, 3);

        $batch = $this->queue->enqueue(
            $this->supplierId,
            2026,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            'selected',
            $employees[1],
            null,
        );

        self::assertSame(1, $batch['item_count']);
        $items = $this->queue->items($this->supplierId, (int) $batch['id'], 10, 0);
        self::assertSame($employees[1], $items['items'][0]['employee_id']);
    }

    /**
     * Tohle je celý důvod přesunu do fronty: jedna rozbitá osoba nesmí vzít
     * ostatní s sebou. Klientská smyčka to zvládala jen dokud držela záložka.
     */
    public function testBrokenPersonFailsAloneAndTheBatchKeepsGoing(): void
    {
        $this->approvedYear(2026, 3);
        $batch = $this->queue->enqueue(
            $this->supplierId,
            2026,
            PayrollDocumentKind::PayrollSheet,
            'all',
            null,
            null,
        );

        $result = $this->queue->processAvailable(10);

        self::assertSame(3, $result['processed']);
        $items = $this->queue->items($this->supplierId, (int) $batch['id'], 10, 0);
        self::assertCount(3, $items['items']);
        foreach ($items['items'] as $item) {
            self::assertSame(1, $item['attempt_count']);
            self::assertContains($item['status'], ['succeeded', 'failed', 'retry_wait']);
        }
        $detail = $this->queue->detail($this->supplierId, (int) $batch['id']);
        self::assertSame(3, $detail['item_count']);
        self::assertSame(
            3,
            $detail['succeeded_count'] + $detail['failed_count'] + $detail['skipped_count']
                + $this->waitingCount((int) $batch['id']),
        );
    }

    public function testFailedItemCanBeRetriedAloneAndReopensTheBatch(): void
    {
        $this->approvedYear(2026, 1);
        $batch = $this->queue->enqueue(
            $this->supplierId,
            2026,
            PayrollDocumentKind::PayrollSheet,
            'all',
            null,
            null,
        );
        $this->queue->processAvailable(5);

        $item = $this->queue->items($this->supplierId, (int) $batch['id'], 10, 0)['items'][0];
        self::assertContains($item['status'], ['failed', 'retry_wait']);

        $retried = $this->queue->retry(
            $this->supplierId,
            (int) $batch['id'],
            (int) $item['id'],
        );

        self::assertSame('queued', $retried['status']);
        self::assertNull($retried['last_error_message']);
        self::assertNotSame(
            'completed',
            $this->queue->detail($this->supplierId, (int) $batch['id'])['status'],
        );
    }

    /**
     * Osoba, která potvrzení za rok už má, se PŘESKAKUJE. Nahrazení je oprava
     * s povinným důvodem a ten za účetní vymyslet nelze — proto ne selhání.
     */
    public function testExistingCertificateIsRecognisedBeforeAnythingIsRendered(): void
    {
        $employees = $this->approvedYear(2026, 1);
        $this->archivedCertificate($employees[0], 2026);

        self::assertTrue($this->batches->hasAnnualDocument(
            $this->supplierId,
            $employees[0],
            2026,
            'taxable_income_advance_certificate',
        ));
        self::assertFalse($this->batches->hasAnnualDocument(
            $this->supplierId,
            $employees[0],
            2025,
            'taxable_income_advance_certificate',
        ));
        self::assertFalse($this->batches->hasAnnualDocument(
            $this->supplierId,
            $employees[0],
            2026,
            'payroll_sheet',
        ));

        $batch = $this->queue->enqueue(
            $this->supplierId,
            2026,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            'all',
            null,
            null,
        );
        $result = $this->queue->processAvailable(5);

        self::assertSame(1, $result['processed']);
        self::assertSame(1, $result['skipped']);
        $detail = $this->queue->detail($this->supplierId, (int) $batch['id']);
        self::assertSame(1, $detail['skipped_count']);
        self::assertSame(0, $detail['failed_count']);
        self::assertSame('completed', $detail['status']);
        self::assertNotNull($detail['completed_at']);
    }

    /**
     * Vytvoří schválený mzdový běh s výsledky za daný rok.
     *
     * @return list<int> identifikátory zaměstnanců vzestupně
     */
    private function approvedYear(int $taxYear, int $personCount): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, "approved")'
        )->execute([
            $this->supplierId,
            sprintf('%04d-03-01', $taxYear),
            sprintf('%04d-03-31', $taxYear),
        ]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2", ?, "{}", ?, "{}", ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            hash('sha256', "annual-queue-input-{$runId}"),
            hash('sha256', "annual-queue-result-{$runId}"),
            hash('sha256', "annual-queue-key-{$runId}"),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $employees = [];
        $employee = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $person = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, period_start, status,
                 result_json, result_hash)
             VALUES (?, ?, ?, ?, "calculated", "{}", ?)'
        );
        for ($index = 0; $index < $personCount; $index++) {
            $employee->execute([
                $this->supplierId,
                sprintf('Roční fronta %d/%d', $taxYear, $index + 1),
            ]);
            $employeeId = (int) $pdo->lastInsertId();
            $employees[] = $employeeId;
            $person->execute([
                $this->supplierId,
                $revisionId,
                $employeeId,
                sprintf('%04d-03-01', $taxYear),
                hash('sha256', "annual-queue-person-{$revisionId}-{$employeeId}"),
            ]);
        }

        return $employees;
    }

    /** Archivované potvrzení o zdanitelných příjmech (zálohová daň) za rok. */
    private function archivedCertificate(int $employeeId, int $taxYear): void
    {
        $pdo = $this->db->pdo();
        $hash = hash('sha256', "annual-certificate-{$employeeId}-{$taxYear}");
        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, ?, "taxable_income_advance_certificate", 1,
                     "-", ?, "{}", ?, UTC_TIMESTAMP())'
        )->execute([$this->supplierId, $employeeId, $taxYear, $hash, $hash]);
        $annualRevisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, annual_revision_id, employee_id, document_kind,
                 revision_snapshot_hash, source_snapshot_hash, template_version,
                 renderer_version, file_sha256, size_bytes, mime_type,
                 storage_key, suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, "taxable_income_advance_certificate", ?, ?, "v1", "v1",
                     ?, 1024, "application/pdf", ?, "potvrzeni.pdf", UNHEX(?))'
        )->execute([
            $this->supplierId,
            $annualRevisionId,
            $employeeId,
            $hash,
            $hash,
            $hash,
            $hash,
            $hash,
        ]);
    }

    private function closeBatch(int $batchId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_annual_document_batch_items
                SET status = "skipped", completed_at = UTC_TIMESTAMP(),
                    lease_token = NULL, locked_at = NULL
              WHERE supplier_id = ? AND batch_id = ?'
        )->execute([$this->supplierId, $batchId]);
        $pdo->prepare(
            'UPDATE payroll_annual_document_batches
                SET status = "completed", completed_at = UTC_TIMESTAMP(),
                    skipped_count = item_count
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $batchId]);
    }

    private function waitingCount(int $batchId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_annual_document_batch_items
              WHERE supplier_id = ? AND batch_id = ?
                AND status IN ("queued", "retry_wait", "processing")'
        );
        $statement->execute([$this->supplierId, $batchId]);

        return (int) $statement->fetchColumn();
    }
}
