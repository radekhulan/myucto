<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Potvrzení o zdanitelných příjmech na žádost (§ 38j odst. 3 ZDP).
 *
 * Lhůta deset dnů běží od žádosti a den žádosti aplikace dřív neevidovala,
 * takže termín nikde nevznikl. Tok: účetní na kartě vztahu zapíše den žádosti
 * → položka checklistu dostane termín → hlídač termínů ho ukáže → potvrzení
 * vydané PŘED žádostí povinnost neuzavře.
 */
#[Group('integration')]
final class PayrollTaxableIncomeConfirmationRequestTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentRepository $employments;
    private PayrollDeadlineOverviewRepository $deadlines;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->employments = $container->get(PayrollEmploymentRepository::class);
        $this->deadlines = $container->get(PayrollDeadlineOverviewRepository::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický Žadatel o potvrzení", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary,
                 start_date, end_date)
             VALUES (?, ?, "SYN-38J", "employment", "ended", 1, "2025-01-01", "2026-08-31")',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, status, due_date,
                 deadline_source_status)
             VALUES (?, ?, "offboarding", "taxable_income_confirmation", "pending", NULL,
                     "not_derived")',
        )->execute([$this->supplierId, $this->employmentId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        isset($this->db) && $this->db->close();
    }

    public function testRecordedRequestCreatesTenDayDeadlineInTheOverview(): void
    {
        self::assertSame([], $this->openConfirmationDeadlines());

        $employment = $this->employments->updateChecklist(
            $this->supplierId,
            $this->employmentId,
            'taxable_income_confirmation',
            1,
            'pending',
            null,
            null,
            null,
            null,
            '2026-09-21',
        );

        $item = $this->checklistItem($employment);
        self::assertSame('2026-10-01', $item['due_date']);
        self::assertSame('statute_verified', $item['deadline_source_status']);

        $rows = $this->openConfirmationDeadlines();
        self::assertCount(1, $rows);
        self::assertSame('2026-10-01', $rows[0]['due_date']);
    }

    public function testOlderCertificateDoesNotCloseANewRequest(): void
    {
        $this->certificate('2026-03-01 10:00:00');
        $this->employments->updateChecklist(
            $this->supplierId,
            $this->employmentId,
            'taxable_income_confirmation',
            1,
            'pending',
            null,
            null,
            null,
            null,
            '2026-09-21',
        );
        self::assertCount(1, $this->openConfirmationDeadlines());

        $this->certificate('2026-09-25 10:00:00');
        self::assertSame([], $this->openConfirmationDeadlines());
    }

    public function testRequestDateIsRejectedOnOtherChecklistItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->employments->updateChecklist(
            $this->supplierId,
            $this->employmentId,
            'eldp_submission',
            1,
            'pending',
            null,
            null,
            null,
            null,
            '2026-09-21',
        );
    }

    /** @return list<array<string,mixed>> */
    private function openConfirmationDeadlines(): array
    {
        return $this->deadlines->checklistDeadlines(
            $this->supplierId,
            '2025-01-01',
            '2027-12-31',
            'taxable_income_confirmation',
        );
    }

    /**
     * @param array<string,mixed> $employment
     * @return array<string,mixed>
     */
    private function checklistItem(array $employment): array
    {
        foreach ($employment['checklist'] as $item) {
            if ($item['item_key'] === 'taxable_income_confirmation') {
                return $item;
            }
        }
        self::fail('Položka potvrzení v checklistu chybí.');
    }

    private function certificate(string $createdAt): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)',
        )->execute([$this->supplierId, substr($createdAt, 0, 8) . '01', substr($createdAt, 0, 8) . '20']);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "approved", "v1", ?, "{}", ?, "{}", ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('1', 64),
            hash('sha256', 'confirmation-revision-' . $this->supplierId . '-' . $createdAt),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $fileHash = hash('sha256', 'confirmation-' . $this->supplierId . '-' . $createdAt);
        $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, employee_id, document_kind,
                 document_revision_no, revision_snapshot_hash, source_snapshot_hash,
                 template_version, renderer_version, file_sha256, size_bytes, mime_type,
                 storage_key, suggested_filename, idempotency_key_hash, created_at)
             VALUES (?, ?, ?, ?, "taxable_income_advance_certificate", 1, ?, ?, "test", "test",
                     ?, 1, "application/pdf", ?, "synthetic-certificate.pdf", UNHEX(?), ?)',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            $this->employeeId,
            str_repeat('1', 64),
            str_repeat('d', 64),
            $fileHash,
            $fileHash,
            hash('sha256', 'confirmation-idempotency-' . $createdAt),
            $createdAt,
        ]);
    }
}
