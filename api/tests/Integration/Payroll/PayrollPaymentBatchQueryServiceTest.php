<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchQueryService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPaymentBatchQueryServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPaymentBatchQueryService $queries;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $source);
        $sourceId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceId);
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceId,
        );
        $this->queries = new PayrollPaymentBatchQueryService(
            $connection,
            new CzechBankAccountValidator(),
            new IbanValidator(),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testListsOnlyValidMaskedPayerOptions(): void
    {
        $this->insertCurrency(
            $this->supplierId,
            'CZK',
            '1000000005',
            '0100',
            null,
            'Syntetická banka',
        );
        $this->insertCurrency(
            $this->supplierId,
            'EUR',
            null,
            null,
            'CZ1801000000001000000005',
            'Syntetická euro banka',
        );
        $this->insertCurrency(
            $this->supplierId,
            'CZK',
            '1',
            '0100',
            null,
            'Neplatná banka',
        );
        $this->insertCurrency(
            $this->otherSupplierId,
            'CZK',
            '1000000005',
            '0100',
            null,
            'Cizí banka',
        );

        $options = $this->queries->payerOptions($this->supplierId);

        self::assertCount(2, $options);
        self::assertSame(
            ['CZK' => ['abo'], 'EUR' => ['sepa']],
            array_column($options, 'export_formats', 'currency_code'),
        );
        self::assertSame('••••0005/0100', $options[0]['masked_account']);
        self::assertSame(
            'CZ•• •••• •••• •••• •••• 0005',
            $options[1]['masked_account'],
        );
        $encoded = json_encode($options, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('1000000005', $encoded);
        self::assertStringNotContainsString(
            'CZ1801000000001000000005',
            $encoded,
        );
        self::assertStringNotContainsString('Cizí banka', $encoded);
    }

    public function testListsBatchesAndExportsOnlyForTenantPeriod(): void
    {
        $batchId = $this->insertBatch(
            $this->supplierId,
            '2026-08-01',
            '2026-09-15',
        );
        $this->insertExport($this->supplierId, $batchId);
        $this->insertBatch(
            $this->supplierId,
            '2026-09-01',
            '2026-09-15',
        );
        $otherBatch = $this->insertBatch(
            $this->otherSupplierId,
            '2026-08-01',
            '2026-09-15',
        );
        $this->insertExport($this->otherSupplierId, $otherBatch);

        $batches = $this->queries->batchesForPeriod(
            $this->supplierId,
            '2026-08',
        );

        self::assertCount(1, $batches);
        self::assertSame($batchId, $batches[0]['id']);
        self::assertSame(123_456, $batches[0]['declared_total_minor']);
        self::assertSame(0, $batches[0]['settled_minor']);
        self::assertSame('2026-08', $batches[0]['period_from']);
        self::assertSame('2026-08', $batches[0]['period_to']);
        self::assertSame('2026-09-15', $batches[0]['default_payment_date']);
        self::assertSame('statutory', $batches[0]['payment_date_mode']);
        self::assertFalse($batches[0]['discarded']);
        self::assertSame('none', $batches[0]['handover_state']);
        self::assertCount(1, $batches[0]['exports']);
        self::assertSame(
            'mzdy-platby-2026-09-15.kpc',
            $batches[0]['exports'][0]['suggested_filename'],
        );
        $encoded = json_encode($batches, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('snapshot_ciphertext', $encoded);
        self::assertStringNotContainsString('instruction_ciphertext', $encoded);
        self::assertStringNotContainsString('payer_reference', $encoded);
    }

    /** Zahozená dávka zůstává v seznamu jako auditní stopa i se stavem předání. */
    public function testDiscardedBatchStaysListedWithHandoverState(): void
    {
        $discarded = $this->insertBatch($this->supplierId, '2026-08-01', '2026-09-20');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_batch_discards
                (supplier_id, batch_id, handover_state, bank_cancellation_confirmed)
             VALUES (?, ?, "submitted", 1)',
        )->execute([$this->supplierId, $discarded]);

        $batches = $this->queries->batchesForPeriod($this->supplierId, '2026-08');

        self::assertSame([$discarded], array_column($batches, 'id'));
        self::assertTrue($batches[0]['discarded']);
        self::assertSame('submitted', $batches[0]['handover_state']);
        self::assertNotNull($batches[0]['discarded_at']);
    }

    private function insertCurrency(
        int $supplierId,
        string $code,
        ?string $accountNumber,
        ?string $bankCode,
        ?string $iban,
        string $bankName,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en,
                 decimals, is_active, is_default, account_number,
                 bank_code, iban, bank_name)
             VALUES (?, ?, "Citlivý label 1000000005", ?, ?, ?, 2, 1, 0,
                     ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $code,
            $code === 'CZK' ? 'Kč' : '€',
            $code,
            $code,
            $accountNumber,
            $bankCode,
            $iban,
            $bankName,
        ]);
    }

    private function insertBatch(
        int $supplierId,
        string $periodStart,
        string $plannedPaymentDate,
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        )->execute([
            $supplierId,
            "Syntetická dávková osoba {$periodStart}",
        ]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, "approved")',
        )->execute([$supplierId, $periodStart, $plannedPaymentDate]);
        $runId = (int) $this->db->pdo()->lastInsertId();
        $snapshot = '{"schema":"synthetic-query.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $revisionKey = "synthetic-query-revision:{$supplierId}:{$periodStart}";
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-query.v1", ?,
                     ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash('sha256', $revisionKey, true),
        ]);
        $revisionId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $snapshot,
            $snapshotHash,
        ]);
        $liabilityReference = "net-wage.query.{$periodStart}";
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id,
                 liability_reference, liability_kind, direction,
                 recipient_reference, due_on, currency_code, amount_minor,
                 source_snapshot_json, source_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing",
                     "recipient:synthetic", ?, "CZK", 123456, ?, ?, ?)',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $liabilityReference,
            $plannedPaymentDate,
            $snapshot,
            $snapshotHash,
            hash('sha256', $liabilityReference, true),
        ]);
        $liabilityId = (int) $this->db->pdo()->lastInsertId();
        $hash = hash(
            'sha256',
            "synthetic-query-batch:{$supplierId}:{$periodStart}",
        );
        $reference = 'synthetic-query-' . $supplierId . '-'
            . str_replace('-', '', $periodStart);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor,
                 declared_item_count, snapshot_ciphertext, snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, "bank", "abo", "outgoing", ?, "CZK",
                     "currency:1", 123456, 1, ?, ?, ?)',
        )->execute([
            $supplierId,
            $reference,
            $plannedPaymentDate,
            'enc:v2:synthetic-query',
            $hash,
            hash('sha256', $reference, true),
        ]);
        $batchId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference,
                 recipient_reference, amount_minor,
                 instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:synthetic", 123456,
                     "enc:v2:synthetic-query", ?, ?)',
        )->execute([
            $supplierId,
            $batchId,
            "item-{$reference}",
            hash('sha256', "item-{$reference}"),
            hash('sha256', "item-{$reference}", true),
        ]);
        $itemId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, 123456, ?)',
        )->execute([
            $supplierId,
            $itemId,
            $liabilityId,
            hash('sha256', "allocation-{$reference}", true),
        ]);

        return $batchId;
    }

    private function insertExport(int $supplierId, int $batchId): void
    {
        $batch = $this->db->pdo()->prepare(
            'SELECT snapshot_hash, planned_payment_date
               FROM payroll_payment_batches
              WHERE supplier_id = ? AND id = ?',
        );
        $batch->execute([$supplierId, $batchId]);
        $row = $batch->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $plannedPaymentDate = $row['planned_payment_date'] ?? null;
        self::assertIsString($plannedPaymentDate);
        $hash = hash('sha256', "synthetic-export:{$supplierId}:{$batchId}");
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_exports
                (supplier_id, batch_id, export_format,
                 export_revision_no, source_snapshot_hash,
                 exporter_version, file_sha256, size_bytes, mime_type,
                 storage_key, suggested_filename, manifest_json,
                 idempotency_key_hash)
             VALUES (?, ?, "abo", 1, ?, "synthetic.v1", ?, 123,
                     "text/plain", ?, ?, "{}", ?)',
        )->execute([
            $supplierId,
            $batchId,
            $row['snapshot_hash'],
            $hash,
            $hash,
            'mzdy-platby-' . $plannedPaymentDate . '.kpc',
            hash('sha256', "synthetic-export-idem:{$supplierId}:{$batchId}", true),
        ]);
    }
}
