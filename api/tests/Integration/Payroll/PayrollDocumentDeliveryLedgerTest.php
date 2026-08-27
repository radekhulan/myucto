<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\PayrollDocumentDeliveryLedgerService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollDocumentDeliveryLedgerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const RESULT_HASH = '1111111111111111111111111111111111111111111111111111111111111111';

    private Connection $db;
    private PayrollDocumentDeliveryLedgerService $ledger;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $employeeId;
    private int $documentId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_document_delivery_events')) {
            self::fail('Migrace delivery ledgeru mzdových dokumentů neproběhla.');
        }
        $this->ledger = $container->get(PayrollDocumentDeliveryLedgerService::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->seedEmployee($this->supplierId, 'Doručovaná osoba');
        $this->documentId = $this->seedDocument($this->supplierId, $this->employeeId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        isset($this->db) && $this->db->close();
    }

    public function testLedgerIsAppendOnlyAndNeverStoresDocumentContentsOrDownloadTokens(): void
    {
        $handover = $this->ledger->record(
            $this->supplierId,
            $this->documentId,
            $this->userId,
            'handover',
        );
        $viewed = $this->ledger->record(
            $this->supplierId,
            $this->documentId,
            $this->userId,
            'downloaded',
        );
        $notified = $this->ledger->record(
            $this->supplierId,
            $this->documentId,
            $this->userId,
            'external_notification',
        );

        self::assertSame($this->documentId, $handover['payroll_document_id']);
        self::assertSame($this->employeeId, $handover['employee_id']);
        self::assertSame('downloaded', $viewed['event_type']);
        self::assertSame('external_notification', $notified['event_type']);
        self::assertSame(
            ['handover', 'downloaded', 'external_notification'],
            array_column($this->ledger->forDocument($this->supplierId, $this->documentId), 'event_type'),
        );

        $columns = $this->db->pdo()->query('SHOW COLUMNS FROM payroll_document_delivery_events')
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('content', $columns);
        self::assertNotContains('token', $columns);
        self::assertNotContains('recipient_email', $columns);

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_document_delivery_events SET event_type = "downloaded" WHERE id = ?',
        )->execute([$handover['id']]);
    }

    public function testTenantPersonAndDocumentMustMatch(): void
    {
        $this->expectException(\DomainException::class);
        $this->ledger->record(
            $this->otherSupplierId,
            $this->documentId,
            $this->userId,
            'handover',
        );
    }

    public function testLedgerEventsCannotBeDeleted(): void
    {
        $event = $this->ledger->record(
            $this->supplierId,
            $this->documentId,
            $this->userId,
            'handover',
        );

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_document_delivery_events WHERE id = ?',
        )->execute([$event['id']]);
    }

    public function testDatabaseRejectsForeignPerson(): void
    {
        $foreignEmployeeId = $this->seedEmployee($this->supplierId, 'Jiná osoba');
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_delivery_events
                (supplier_id, payroll_document_id, employee_id, event_type, recorded_by)
             VALUES (?, ?, ?, "handover", ?)',
        )->execute([$this->supplierId, $this->documentId, $foreignEmployeeId, $this->userId]);
    }

    private function seedEmployee(int $supplierId, string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        )->execute([$supplierId, $name]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedDocument(int $supplierId, int $employeeId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, "2026-08-01", "2026-08-20", "approved", 1)',
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "approved", "v1", ?, "{}", ?, "{}", ?, UNHEX(?))',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            self::RESULT_HASH,
            str_repeat('c', 64),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $fileHash = hash('sha256', 'delivery-ledger-document-' . $supplierId . '-' . $employeeId);
        $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, employee_id, document_kind,
                 document_revision_no, revision_snapshot_hash, source_snapshot_hash,
                 template_version, renderer_version, file_sha256, size_bytes, mime_type,
                 storage_key, suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "payslip", 1, ?, ?, "test", "test", ?, 1,
                     "application/pdf", ?, "synthetic-payroll-document.pdf", UNHEX(?))',
        )->execute([
            $supplierId,
            $runId,
            $revisionId,
            $employeeId,
            self::RESULT_HASH,
            str_repeat('d', 64),
            $fileHash,
            $fileHash,
            hash('sha256', 'delivery-ledger-idempotency-' . $supplierId . '-' . $employeeId),
        ]);
        return (int) $pdo->lastInsertId();
    }
}
