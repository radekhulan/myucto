<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DomainException;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Payment\PayrollBankEvidenceGuard;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentEvidenceReference;
use MyInvoice\Service\Payroll\Payment\PayrollIncomingRefundReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReversalCommand;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPaymentReconciliationServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $connection;
    private PDO $pdo;
    private PayrollPaymentReconciliationService $service;
    private PayrollPaymentReconciliationQueryService $queries;
    private int $supplierId;
    private int $allocationId;
    private int $statementId;

    /** Rozsah důkazů končí dneškem, takže testované období musí být v minulosti. */
    private const PAST_PERIOD = '2026-01';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $service = $container->get(PayrollPaymentReconciliationService::class);
        self::assertInstanceOf(PayrollPaymentReconciliationService::class, $service);
        $queries = $container->get(
            PayrollPaymentReconciliationQueryService::class,
        );
        self::assertInstanceOf(
            PayrollPaymentReconciliationQueryService::class,
            $queries,
        );

        $this->connection = $connection;
        $this->pdo = $connection->pdo();
        $this->service = $service;
        $this->queries = $queries;
        $this->pdo->beginTransaction();

        $sourceSupplierId = (int) $this
            ->query('SELECT MIN(id) FROM supplier')
            ->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $this->supplierId = $this->createIsolatedSupplier(
            $this->pdo,
            $sourceSupplierId,
        );
        [$revisionId, $employeeId] = $this->createApprovedRevision();
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            100_000,
        );
        $this->allocationId = $this->insertAllocation(
            $liabilityId,
            'bank',
            100_000,
        );
        $this->statementId = $this->insertBankStatement();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function testBankPartialMatchesReplayAndPartialThenFullReversalUseExactMinorUnits(): void
    {
        $firstTransactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-600.01',
            'first-part',
        );
        $first = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                60_001,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $firstTransactionId,
                ),
                'bank-first',
                null,
            ),
        );
        self::assertSame(60_001, $first->amountMinor);
        self::assertSame('2099-01-20', $first->actualPaymentDate);
        self::assertFalse($first->replayed);

        $replay = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                60_001,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $firstTransactionId,
                ),
                'bank-first',
                null,
            ),
        );
        self::assertSame($first->id, $replay->id);
        self::assertTrue($replay->replayed);
        try {
            $this->service->match(
                new PayrollPaymentReconciliationCommand(
                    $this->supplierId,
                    $this->allocationId,
                    60_000,
                    PayrollPaymentEvidenceReference::bank(
                        $this->statementId,
                        $firstTransactionId,
                    ),
                    'bank-first',
                    null,
                ),
            );
            self::fail('Změněný idempotentní replay musí být odmítnut.');
        } catch (DomainException $exception) {
            self::assertStringContainsString(
                'Idempotentní replay neodpovídá',
                $exception->getMessage(),
            );
        }

        $secondTransactionId = $this->insertBankTransaction(
            '2099-01-21',
            '-399.99',
            'second-part',
        );
        $second = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                39_999,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $secondTransactionId,
                ),
                'bank-second',
                null,
            ),
        );

        $partialReversalTransactionId = $this->insertBankTransaction(
            '2099-01-25',
            '250.01',
            'partial-reversal',
        );
        $partial = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $first->id,
                25_001,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $partialReversalTransactionId,
                ),
                'bank-partial-reversal',
                null,
            ),
        );
        self::assertSame(-25_001, $partial->amountMinor);
        self::assertSame($first->id, $partial->sourceMatchId);

        $remainingReversalTransactionId = $this->insertBankTransaction(
            '2099-01-26',
            '350.00',
            'remaining-reversal',
        );
        $remaining = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $first->id,
                35_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $remainingReversalTransactionId,
                ),
                'bank-remaining-reversal',
                null,
            ),
        );
        self::assertSame(-35_000, $remaining->amountMinor);

        $fullReversalTransactionId = $this->insertBankTransaction(
            '2099-01-27',
            '399.99',
            'full-reversal',
        );
        $full = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $second->id,
                39_999,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $fullReversalTransactionId,
                ),
                'bank-full-reversal',
                null,
            ),
        );
        self::assertSame(-39_999, $full->amountMinor);
        self::assertSame(
            0,
            (int) $this->query(
                "SELECT SUM(amount_minor)
                   FROM payroll_payment_matches
                  WHERE supplier_id = {$this->supplierId}
                    AND allocation_id = {$this->allocationId}",
            )->fetchColumn(),
        );
    }

    public function testPeriodTotalsKeepPartialOutgoingAndReceivedRefundSeparate(): void
    {
        $outgoingTransactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-600.00',
            'period-totals-outgoing-partial',
        );
        $this->service->match(new PayrollPaymentReconciliationCommand(
            $this->supplierId,
            $this->allocationId,
            60_000,
            PayrollPaymentEvidenceReference::bank(
                $this->statementId,
                $outgoingTransactionId,
            ),
            'period-totals-outgoing-partial',
            null,
        ));
        $revisionId = (int) $this->query(
            'SELECT liability.revision_id
               FROM payroll_payment_allocations allocation
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
              WHERE allocation.supplier_id = ' . $this->supplierId
            . ' AND allocation.id = ' . $this->allocationId,
        )->fetchColumn();
        $snapshot = '{"schema":"synthetic-incoming-refund.v1"}';
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "other.period-totals-refund", "other", "incoming",
                     "recipient:synthetic", "2099-01-10", "CZK", 20000,
                     ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', 'period-totals-refund-' . $this->supplierId, true),
        ]);
        $incomingLiabilityId = (int) $this->pdo->lastInsertId();
        $incomingTransactionId = $this->insertBankTransaction(
            '2099-01-21',
            '200.00',
            'period-totals-incoming-refund',
        );
        $this->service->matchIncomingRefund(
            new PayrollIncomingRefundReconciliationCommand(
                $this->supplierId,
                $incomingLiabilityId,
                20_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $incomingTransactionId,
                ),
                'period-totals-incoming-refund',
                null,
            ),
        );

        self::assertSame([
            'outgoing' => [
                'liability_count' => 1,
                'required_minor' => 100_000,
                'settled_minor' => 60_000,
                'remaining_minor' => 40_000,
            ],
            'incoming' => [
                'liability_count' => 1,
                'required_minor' => 20_000,
                'settled_minor' => 20_000,
                'remaining_minor' => 0,
            ],
        ], $this->queries->periodTotals($this->supplierId, '2099-01'));
    }

    public function testPeriodTotalsKeepOriginalPaymentAndCorrectionRefundSeparate(): void
    {
        $originalLiability = $this->query(
            'SELECT liability.id, liability.revision_id, liability.employee_id,
                    revision.run_id
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
              WHERE liability.supplier_id = ' . $this->supplierId
            . ' AND liability.id = (
                    SELECT allocation.liability_id
                      FROM payroll_payment_allocations allocation
                     WHERE allocation.supplier_id = ' . $this->supplierId
            . ' AND allocation.id = ' . $this->allocationId . '
                  )',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($originalLiability);
        $snapshot = '{"schema":"synthetic-payroll-correction.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 2, ?, "correction", "approved",
                     "synthetic-payment.v1", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            (int) $originalLiability['run_id'],
            (int) $originalLiability['revision_id'],
            str_repeat('a', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash('sha256', 'synthetic-correction-' . $this->supplierId, true),
        ]);
        $correctionRevisionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $correctionRevisionId,
            (int) $originalLiability['employee_id'],
            $snapshot,
            $snapshotHash,
        ]);
        $this->pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $this->supplierId,
            (int) $originalLiability['run_id'],
        ]);
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, previous_liability_id,
                 source_snapshot_json, source_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "net-wage.synthetic", "net_wage", "incoming",
                     "recipient:synthetic", "2099-01-10", "CZK", 20000,
                     ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $correctionRevisionId,
            (int) $originalLiability['employee_id'],
            (int) $originalLiability['id'],
            $snapshot,
            $snapshotHash,
            hash('sha256', 'corrected-liability-' . $this->supplierId, true),
        ]);

        self::assertSame([
            'outgoing' => [
                'liability_count' => 1,
                'required_minor' => 100_000,
                'settled_minor' => 0,
                'remaining_minor' => 100_000,
            ],
            'incoming' => [
                'liability_count' => 1,
                'required_minor' => 20_000,
                'settled_minor' => 0,
                'remaining_minor' => 20_000,
            ],
        ], $this->queries->periodTotals($this->supplierId, '2099-01'));
    }

    public function testQueriesPayrollPeriodAcrossNextMonthBatchAndLateEvidence(): void
    {
        [$revisionId, $employeeId] = $this->createApprovedRevision(
            '2024-08-01',
        );
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            25_000,
            'net-wage.period-scope.synthetic',
            '2024-09-10',
        );
        $allocationId = $this->insertAllocation(
            $liabilityId,
            'bank',
            25_000,
            '2024-09-10',
        );
        $lateTransactionId = $this->insertBankTransaction(
            '2025-12-15',
            '-250.00',
            'late-period-evidence',
        );
        $earlyTransactionId = $this->insertBankTransaction(
            '2024-08-25',
            '-250.00',
            'early-period-evidence',
        );
        $otherSupplierId = $this->createIsolatedSupplier(
            $this->pdo,
            $this->supplierId,
        );
        $this->pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, "synthetic-foreign-evidence.gpc", ?,
                     "1000000005", "0100", "CZK", "2025-12-31")',
        )->execute([
            $otherSupplierId,
            hash('sha256', "foreign-evidence:{$otherSupplierId}"),
        ]);
        $otherStatementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2025-12-16", -250.00, "CZK",
                     "Syntetická cizí platba", ?)',
        )->execute([
            $otherStatementId,
            hash('sha256', "foreign-transaction:{$otherSupplierId}"),
        ]);

        $beforeMatch = $this->queries->forPeriod(
            $this->supplierId,
            '2024-08',
        );
        $allocations = $beforeMatch['allocations'] ?? null;
        $bankEvidence = $beforeMatch['bank_evidence'] ?? null;
        self::assertIsArray($allocations);
        self::assertIsArray($bankEvidence);

        self::assertSame(
            [$allocationId],
            array_column($allocations, 'id'),
        );
        self::assertSame(
            [$lateTransactionId, $earlyTransactionId],
            array_column($bankEvidence, 'bank_transaction_id'),
        );

        $match = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $allocationId,
                25_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $lateTransactionId,
                ),
                'late-period-evidence',
                null,
            ),
        );
        $afterMatch = $this->queries->forPeriod(
            $this->supplierId,
            '2024-08',
        );
        $matches = $afterMatch['matches'] ?? null;
        self::assertIsArray($matches);

        self::assertSame(
            [$match->id],
            array_column($matches, 'id'),
        );
        $firstMatch = $matches[0] ?? null;
        self::assertIsArray($firstMatch);
        self::assertSame(
            '2025-12-15',
            $firstMatch['actual_payment_date'] ?? null,
        );
    }

    public function testCashEvidenceSupportsIdempotentMatchAndFullReversal(): void
    {
        [$revisionId, $employeeId] = $this->createApprovedRevision('2099-02-01');
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            20_000,
            'net-wage.cash.synthetic',
        );
        $allocationId = $this->insertAllocation(
            $liabilityId,
            'cash',
            20_000,
        );
        $cashDocumentId = $this->insertCashDocument();

        $match = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $allocationId,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'cash-match',
                null,
            ),
        );
        self::assertSame('2099-02-10', $match->actualPaymentDate);
        self::assertSame('cash', $match->evidenceKind);

        $this->pdo->prepare(
            'UPDATE cash_documents SET status = "reversed" WHERE id = ?',
        )->execute([$cashDocumentId]);
        $reversal = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $match->id,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'cash-reversal',
                null,
            ),
        );
        self::assertSame(-20_000, $reversal->amountMinor);

        $replay = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $match->id,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'cash-reversal',
                null,
            ),
        );
        self::assertSame($reversal->id, $replay->id);
        self::assertTrue($replay->replayed);
    }

    public function testGloballyOwnedBankEvidenceAndCrossTenantEvidenceFailClosed(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-1000.00',
            'owned',
        );
        $invoiceId = (int) $this
            ->query('SELECT id FROM invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        self::assertGreaterThan(
            0,
            $invoiceId,
            'Syntetická testovací databáze musí obsahovat výchozí fakturu.',
        );
        $invoiceSupplierId = (int) $this
            ->query("SELECT supplier_id FROM invoices WHERE id = {$invoiceId}")
            ->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source,
                 bank_transaction_id)
             VALUES (?, ?, "2099-01-20", 1000.00, "CZK", "bank", ?)',
        )->execute([$invoiceSupplierId, $invoiceId, $transactionId]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Bankovní pohyb už vlastní fakturační párování.',
        );
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'owned-bank',
                null,
            ),
        );
    }

    public function testBankEvidenceOwnedByPurchaseInvoiceMatchFailsClosed(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-1000.00',
            'purchase-owned',
        );
        $purchaseInvoiceId = (int) $this
            ->query('SELECT id FROM purchase_invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        self::assertGreaterThan(
            0,
            $purchaseInvoiceId,
            'Syntetická testovací databáze musí obsahovat přijatou fakturu.',
        );
        $purchaseSupplierId = (int) $this->query(
            "SELECT supplier_id
               FROM purchase_invoices
              WHERE id = {$purchaseInvoiceId}",
        )->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id,
                 amount, match_type)
             VALUES (?, ?, ?, 1000.00, "manual")',
        )->execute([
            $purchaseSupplierId,
            $transactionId,
            $purchaseInvoiceId,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Bankovní pohyb už vlastní fakturační párování.',
        );
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'purchase-owned-bank',
                null,
            ),
        );
    }

    public function testCashDocumentLinkedToInvoiceAndWrongTenantAreRejected(): void
    {
        [$revisionId, $employeeId] = $this->createApprovedRevision('2099-02-01');
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            20_000,
            'net-wage.cash-owned.synthetic',
        );
        $allocationId = $this->insertAllocation(
            $liabilityId,
            'cash',
            20_000,
        );
        $cashDocumentId = $this->insertCashDocument();
        $invoiceId = (int) $this
            ->query('SELECT id FROM invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        self::assertGreaterThan(0, $invoiceId);
        $this->pdo->prepare(
            'UPDATE cash_documents
                SET purpose = "invoice_payment", invoice_id = ?
              WHERE id = ?',
        )->execute([$invoiceId, $cashDocumentId]);

        try {
            $this->service->match(
                new PayrollPaymentReconciliationCommand(
                    $this->supplierId,
                    $allocationId,
                    20_000,
                    PayrollPaymentEvidenceReference::cash($cashDocumentId),
                    'owned-cash',
                    null,
                ),
            );
            self::fail('Pokladní doklad faktury nesmí být použit pro mzdu.');
        } catch (DomainException $exception) {
            self::assertStringContainsString(
                'Pokladní doklad už vlastní fakturační párování.',
                $exception->getMessage(),
            );
        }

        $wrongSupplierId = $this->supplierId === 1 ? 2 : 1;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Platební alokace nebyla nalezena');
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $wrongSupplierId,
                $allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $this->insertBankTransaction(
                        '2099-01-21',
                        '-1000.00',
                        'wrong-tenant',
                    ),
                ),
                'wrong-tenant',
                null,
            ),
        );
    }

    public function testBankTransactionOwnedByDirectBankReconciliationIsRejected(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-1000.00',
            'bank-reconciliation',
        );
        $invoiceId = (int) $this
            ->query('SELECT id FROM invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        self::assertGreaterThan(0, $invoiceId);
        $this->pdo->prepare(
            'UPDATE bank_transactions
                SET matched_invoice_id = ?, match_status = "manual"
              WHERE id = ?',
        )->execute([$invoiceId, $transactionId]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Bankovní pohyb už vlastní bankovní reconciliation.',
        );
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'bank-reconciliation-owned',
                null,
            ),
        );
    }

    public function testDatabasePreventsLaterTakeoverOfPayrollEvidence(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-1000.00',
            'database-ownership',
        );
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'database-ownership',
                null,
            ),
        );

        $invoiceId = (int) $this
            ->query('SELECT id FROM invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        $invoiceSupplierId = (int) $this->query(
            "SELECT supplier_id FROM invoices WHERE id = {$invoiceId}",
        )->fetchColumn();
        $purchaseInvoiceId = (int) $this
            ->query('SELECT id FROM purchase_invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        $purchaseSupplierId = (int) $this->query(
            "SELECT supplier_id
               FROM purchase_invoices
              WHERE id = {$purchaseInvoiceId}",
        )->fetchColumn();
        self::assertGreaterThan(0, $invoiceId);
        self::assertGreaterThan(0, $purchaseInvoiceId);

        $bankTakeovers = [
            function () use (
                $invoiceSupplierId,
                $invoiceId,
                $transactionId,
            ): void {
                $this->pdo->prepare(
                    'INSERT INTO invoice_payments
                        (supplier_id, invoice_id, paid_on, amount, currency,
                         source, bank_transaction_id)
                     VALUES (?, ?, "2099-01-20", 1000.00, "CZK",
                             "bank", ?)',
                )->execute([
                    $invoiceSupplierId,
                    $invoiceId,
                    $transactionId,
                ]);
            },
            function () use (
                $purchaseSupplierId,
                $purchaseInvoiceId,
                $transactionId,
            ): void {
                $this->pdo->prepare(
                    'INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id,
                         purchase_invoice_id, amount, match_type)
                     VALUES (?, ?, ?, 1000.00, "manual")',
                )->execute([
                    $purchaseSupplierId,
                    $transactionId,
                    $purchaseInvoiceId,
                ]);
            },
            function () use ($invoiceId, $transactionId): void {
                $this->pdo->prepare(
                    'UPDATE bank_transactions
                        SET matched_invoice_id = ?, match_status = "manual"
                      WHERE id = ?',
                )->execute([$invoiceId, $transactionId]);
            },
        ];
        foreach ($bankTakeovers as $takeover) {
            try {
                $takeover();
                self::fail(
                    'Bankovní důkaz mzdy nesmí později převzít fakturace.',
                );
            } catch (\PDOException $exception) {
                self::assertStringContainsString(
                    'Payroll bank evidence is already owned',
                    $exception->getMessage(),
                );
            }
        }

        [$revisionId, $employeeId] = $this->createApprovedRevision(
            '2099-02-01',
        );
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            20_000,
            'net-wage.cash-database-ownership.synthetic',
        );
        $allocationId = $this->insertAllocation(
            $liabilityId,
            'cash',
            20_000,
        );
        $cashDocumentId = $this->insertCashDocument();
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $allocationId,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'cash-database-ownership',
                null,
            ),
        );

        try {
            $this->pdo->prepare(
                'UPDATE cash_documents
                    SET purpose = "invoice_payment", invoice_id = ?
                  WHERE id = ?',
            )->execute([$invoiceId, $cashDocumentId]);
            self::fail(
                'Pokladní důkaz mzdy nesmí později převzít fakturace.',
            );
        } catch (\PDOException $exception) {
            self::assertStringContainsString(
                'Payroll cash evidence is already owned',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Nabídka důkazů se posílá OŘEZANÁ a ořezání se přiznává.
     *
     * Bankovní důkazy sahají od nejstaršího data splatnosti do dneška, takže
     * u dlouho běžící firmy je to neohraničená odpověď. Ořezat ji mlčky by
     * ale bylo horší než ji neposlat vůbec: picker by tvrdil, že nic dalšího
     * neexistuje, a hledaná transakce by zmizela bez jediné stopy.
     */
    public function testOfferedBankEvidenceIsCappedAndAdmitsIt(): void
    {
        $this->seedPastPeriodEvidence(
            PayrollPaymentReconciliationQueryService::OFFERED_LIMIT + 1,
        );

        $page = $this->queries->forPeriod($this->supplierId, self::PAST_PERIOD);

        self::assertCount(
            PayrollPaymentReconciliationQueryService::OFFERED_LIMIT,
            $page['bank_evidence'],
            'Nabídka se musí zastavit na stropu.',
        );
        self::assertTrue(
            $page['bank_evidence_truncated'],
            'Oříznutá nabídka to musí přiznat — jinak vypadá jako úplná.',
        );
    }

    /**
     * Serverové hledání musí najít i důkaz, který se do poslané nabídky nevešel.
     *
     * Přesně tohle stránkování neumí: „vybrat jde jen to, co je na první
     * straně" je u pickeru horší chování než dlouhá odpověď.
     */
    public function testOptionSearchReachesEvidenceOutsideTheOfferedList(): void
    {
        $this->seedPastPeriodEvidence(
            PayrollPaymentReconciliationQueryService::OFFERED_LIMIT + 1,
        );
        // Nejstarší transakce leží v řazení (posted_at DESC) až za stropem.
        $needleId = $this->insertBankTransaction(
            self::PAST_PERIOD . '-02',
            '-1234.00',
            'jehla-v-kupce',
        );

        $page = $this->queries->forPeriod($this->supplierId, self::PAST_PERIOD);
        $offeredIds = array_map(
            static fn (array $row): int => (int) $row['bank_transaction_id'],
            $page['bank_evidence'],
        );
        self::assertNotContains(
            $needleId,
            $offeredIds,
            'Předpoklad testu: hledaný důkaz nesmí být v poslané nabídce.',
        );

        $found = $this->queries->searchOptions(
            $this->supplierId,
            self::PAST_PERIOD,
            'bank_evidence',
            ['search' => 'jehla-v-kupce', 'usage' => 'match'],
        );

        self::assertSame(
            [$needleId],
            array_map(
                static fn (array $row): int => (int) $row['bank_transaction_id'],
                $found['items'],
            ),
        );
        self::assertFalse($found['truncated']);
    }

    /** Oříznutý VÝSLEDEK HLEDÁNÍ to musí přiznat úplně stejně jako nabídka. */
    public function testOptionSearchAdmitsItsOwnTruncation(): void
    {
        $this->seedPastPeriodEvidence(8);

        $found = $this->queries->searchOptions(
            $this->supplierId,
            self::PAST_PERIOD,
            'bank_evidence',
            ['usage' => 'match'],
            3,
        );

        self::assertCount(3, $found['items']);
        self::assertTrue($found['truncated']);
        self::assertSame(3, $found['limit']);
    }

    /** Zúžení podle měny a směru patří na server, ne do prohlížeče. */
    public function testOptionSearchNarrowsByCurrencyAndDirection(): void
    {
        $this->seedPastPeriodEvidence(3);

        $incoming = $this->queries->searchOptions(
            $this->supplierId,
            self::PAST_PERIOD,
            'bank_evidence',
            ['usage' => 'match', 'direction' => 'incoming', 'currency' => 'CZK'],
        );
        self::assertSame([], $incoming['items'], 'Odchozí platba není příchozí důkaz.');

        $outgoing = $this->queries->searchOptions(
            $this->supplierId,
            self::PAST_PERIOD,
            'bank_evidence',
            ['usage' => 'match', 'direction' => 'outgoing', 'currency' => 'CZK'],
        );
        self::assertCount(3, $outgoing['items']);

        $foreign = $this->queries->searchOptions(
            $this->supplierId,
            self::PAST_PERIOD,
            'bank_evidence',
            ['usage' => 'match', 'direction' => 'outgoing', 'currency' => 'EUR'],
        );
        self::assertSame([], $foreign['items']);
    }

    public function testOptionSearchRejectsUnknownKindAndUsage(): void
    {
        try {
            $this->queries->searchOptions(
                $this->supplierId,
                self::PAST_PERIOD,
                'invoices',
            );
            self::fail('Neznámý druh nabídky musí skončit chybou.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->queries->searchOptions(
            $this->supplierId,
            self::PAST_PERIOD,
            'bank_evidence',
            ['usage' => 'anything'],
        );
    }

    /**
     * Období v minulosti se schváleným během, alokací a `$count` nespárovanými
     * bankovními transakcemi. Rozsah důkazů sahá od nejstarší splatnosti do
     * dneška, takže období MUSÍ ležet v minulosti — jinak je rozsah prázdný.
     */
    private function seedPastPeriodEvidence(int $count): void
    {
        [$revisionId, $employeeId] = $this->createApprovedRevision(
            self::PAST_PERIOD . '-01',
        );
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            100_000,
            'net-wage.past',
            self::PAST_PERIOD . '-10',
        );
        $this->insertAllocation(
            $liabilityId,
            'bank',
            100_000,
            self::PAST_PERIOD . '-10',
        );
        for ($i = 0; $i < $count; ++$i) {
            $this->insertBankTransaction(
                sprintf('%s-%02d', self::PAST_PERIOD, ($i % 20) + 8),
                '-1000.00',
                sprintf('nabidka-%03d', $i),
            );
        }
    }

    /** @return array{int,int} */
    private function createApprovedRevision(
        string $periodStart = '2099-01-01',
    ): array {
        $this->pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická platební osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $this->pdo->lastInsertId();

        $paymentDate = substr($periodStart, 0, 8) . '10';
        $this->pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, "approved")',
        )->execute([$this->supplierId, $periodStart, $paymentDate]);
        $runId = (int) $this->pdo->lastInsertId();

        $snapshot = '{"schema":"synthetic-payroll-result.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-payment.v1",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash(
                'sha256',
                "synthetic-approved-revision-{$periodStart}",
                true,
            ),
        ]);
        $revisionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $snapshot,
            $snapshotHash,
        ]);

        return [$revisionId, $employeeId];
    }

    private function insertLiability(
        int $revisionId,
        int $employeeId,
        int $amountMinor,
        string $reference = 'net-wage.synthetic',
        string $dueOn = '2099-01-10',
    ): int {
        $snapshot = '{"schema":"synthetic-liability.v1"}';
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing", ?,
                     ?, "CZK", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $reference,
            'recipient:synthetic',
            $dueOn,
            $amountMinor,
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', "liability-{$reference}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertAllocation(
        int $liabilityId,
        string $channel,
        int $amountMinor,
        string $plannedPaymentDate = '2099-01-10',
    ): int {
        $reference = "{$channel}-{$liabilityId}";
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "manual", "outgoing", ?, "CZK",
                     "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            "batch-{$reference}",
            $channel,
            $plannedPaymentDate,
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', "batch-{$reference}"),
            hash('sha256', "batch-{$reference}", true),
        ]);
        $batchId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:synthetic", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $batchId,
            "item-{$reference}",
            $amountMinor,
            'enc:v2:synthetic-instruction',
            hash('sha256', "item-{$reference}"),
            hash('sha256', "item-{$reference}", true),
        ]);
        $itemId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash('sha256', "allocation-{$reference}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankStatement(): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, "synthetic-payroll-reconciliation.gpc", ?,
                     "1000000005", "0100", "CZK", "2099-01-31")',
        )->execute([
            $this->supplierId,
            hash(
                'sha256',
                "synthetic-payroll-reconciliation-{$this->supplierId}",
            ),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * C-09 — mzdy spotřebují bankovní pohyb, aniž by přepsaly `match_status`
     * (ten je vyhrazený fakturačnímu párování). Bez stráže by týž pohyb
     * bankovní matcher podruhé přiřadil k přijaté faktuře a rozpadlo by se
     * saldo. Storno se vždy opírá o vlastní pohyb opačného směru, takže
     * obsazené zůstávají oba.
     */
    public function testBankMovementUsedByPayrollIsReportedAsTakenToTheBankModule(): void
    {
        $guard = new PayrollBankEvidenceGuard($this->connection);

        $paymentTransactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-600.00',
            'guard-payment',
        );
        self::assertFalse(
            $guard->isUsedByPayroll($paymentTransactionId),
            'Nepoužitý pohyb musí zůstat volný pro fakturační párování.',
        );

        $match = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                60_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $paymentTransactionId,
                ),
                'guard-payment-match',
                null,
            ),
        );
        self::assertSame(60_000, $match->amountMinor);
        self::assertTrue($guard->isUsedByPayroll($paymentTransactionId));
        // Mzdy `match_status` záměrně nepřepisují — právě proto tahle stráž
        // existuje a proto se na ni bankovní strana musí ptát.
        self::assertSame(
            'unmatched',
            (string) $this->query(
                'SELECT match_status FROM bank_transactions'
                . " WHERE id = {$paymentTransactionId}",
            )->fetchColumn(),
        );

        $refundTransactionId = $this->insertBankTransaction(
            '2099-01-27',
            '600.00',
            'guard-refund',
        );
        $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $match->id,
                60_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $refundTransactionId,
                ),
                'guard-payment-reversal',
                null,
            ),
        );
        self::assertTrue(
            $guard->isUsedByPayroll($paymentTransactionId),
            'Původní odchozí pohyb zůstává spotřebovaný i po stornu.',
        );
        self::assertTrue(
            $guard->isUsedByPayroll($refundTransactionId),
            'Vratka posloužila jako důkaz storna, volná také není.',
        );
        self::assertFalse($guard->isUsedByPayroll(0));
    }

    private function insertBankTransaction(
        string $postedAt,
        string $amount,
        string $reference,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, ?, ?, "CZK", ?, ?)',
        )->execute([
            $this->statementId,
            $postedAt,
            $amount,
            "Syntetická mzdová platba {$reference}",
            hash(
                'sha256',
                "synthetic-bank-{$this->supplierId}-{$reference}",
            ),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertCashDocument(): int
    {
        $this->pdo->prepare(
            'INSERT INTO cash_registers
                (supplier_id, name, currency_code, account_code)
             VALUES (?, "Syntetická mzdová pokladna", "CZK", "211999")',
        )->execute([$this->supplierId]);
        $registerId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number,
                 issue_date, description, total_amount, currency_code,
                 counter_account_code, status)
             VALUES (?, ?, "out", "other", ?, "2099-02-10",
                     "Syntetická hotovostní mzda", 200.00, "CZK",
                     "331", "posted")',
        )->execute([
            $this->supplierId,
            $registerId,
            "VPD-SYNTHETIC-{$this->supplierId}",
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function query(string $sql): \PDOStatement
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }
}
