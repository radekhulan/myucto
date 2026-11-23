<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Podklad hromadného rozeslání pásek: jen platné výplatní pásky revize.
 *
 * Skrytá ani nahrazená verze se zaměstnanci poslat nesmí — dostal by pásku,
 * kterou už seznam dokumentů za platnou nevydává. Firemní sestavy (mzdový list
 * firmy, rekapitulace) nemají adresáta a do dávky nepatří vůbec.
 */
#[Group('integration')]
final class PayrollRevisionPayslipsForDeliveryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDocumentRepository $documents;
    private int $supplierId;
    private int $userId;
    private int $runId;
    private int $revisionId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->documents = $container->get(PayrollDocumentRepository::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        [$this->runId, $this->revisionId] = $this->seedRevision();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        isset($this->db) && $this->db->close();
    }

    public function testOnlyCurrentVisiblePayslipsOfTheRevisionAreDelivered(): void
    {
        $current = $this->seedEmployee('Syntetická Aktuální');
        $hidden = $this->seedEmployee('Syntetická Skrytá');
        $corrected = $this->seedEmployee('Syntetická Opravená');

        $currentPayslip = $this->seedDocument($current, 'payslip', 1);
        $this->seedDocument($current, 'payroll_sheet', 1);
        $hiddenPayslip = $this->seedDocument($hidden, 'payslip', 1);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_generated_document_hidden (supplier_id, document_id, hidden_by)
             VALUES (?, ?, ?)',
        )->execute([$this->supplierId, $hiddenPayslip, $this->userId]);
        $oldPayslip = $this->seedDocument($corrected, 'payslip', 1);
        $newPayslip = $this->seedDocument($corrected, 'payslip', 2, $oldPayslip);

        $rows = $this->documents->currentPayslipsForRevision(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
        );

        self::assertSame(
            [$currentPayslip, $newPayslip],
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
        );
        self::assertSame('Syntetická Aktuální', $rows[0]['employee_name']);
    }

    private function seedEmployee(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        )->execute([$this->supplierId, $name]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{int,int} */
    private function seedRevision(): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, "2026-08-01", "2026-08-20", "approved", 1)',
        )->execute([$this->supplierId]);
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
            hash('sha256', 'bulk-delivery-revision-' . $this->supplierId),
        ]);

        return [$runId, (int) $pdo->lastInsertId()];
    }

    private function seedDocument(
        ?int $employeeId,
        string $kind,
        int $documentRevisionNo,
        ?int $supersedes = null,
    ): int {
        $seed = "bulk-delivery-{$this->supplierId}-{$employeeId}-{$kind}-{$documentRevisionNo}";
        $fileHash = hash('sha256', $seed);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, employee_id, document_kind,
                 document_revision_no, supersedes_document_id, revision_snapshot_hash,
                 source_snapshot_hash, template_version, renderer_version, file_sha256,
                 size_bytes, mime_type, storage_key, suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "test", "test", ?, 1,
                     "application/pdf", ?, "synthetic-payroll-document.pdf", UNHEX(?))',
        )->execute([
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            $employeeId,
            $kind,
            $documentRevisionNo,
            $supersedes,
            str_repeat('1', 64),
            str_repeat('d', 64),
            $fileHash,
            $fileHash,
            hash('sha256', 'idempotency-' . $seed),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
