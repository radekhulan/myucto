<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentEvidenceReference;
use MyInvoice\Service\Payroll\Payment\PayrollIncomingRefundReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReversalCommand;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandOutcome;
use MyInvoice\Service\Payroll\Run\PayrollRunPaymentSettlementService;
use MyInvoice\Service\Payroll\Run\PayrollRunPaymentsUnsettledException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollPaymentEvidenceTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRunPaymentSettlementGateTest extends TestCase
{
    use IsolatedSupplierTrait;
    use PayrollPaymentEvidenceTrait;

    private Connection $connection;
    private PDO $pdo;
    private PayrollRunCommandService $commands;
    private PayrollRunRepository $runs;
    private PayrollPaymentReconciliationService $reconciliation;
    private PayrollPaymentReconciliationQueryService $reconciliationQueries;
    private PayrollPaymentBatchBuilder $batchBuilder;
    private PayrollRunPaymentSettlementService $settlement;
    private int $supplierId;
    private int $actorId;
    private int $runId;
    private int $revisionId;
    private int $allocationId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $commands = $container->get(PayrollRunCommandService::class);
        $runs = $container->get(PayrollRunRepository::class);
        $reconciliation = $container->get(
            PayrollPaymentReconciliationService::class,
        );
        $reconciliationQueries = $container->get(
            PayrollPaymentReconciliationQueryService::class,
        );
        $batchBuilder = $container->get(PayrollPaymentBatchBuilder::class);
        $settlement = $container->get(
            PayrollRunPaymentSettlementService::class,
        );
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollRunCommandService::class, $commands);
        self::assertInstanceOf(PayrollRunRepository::class, $runs);
        self::assertInstanceOf(
            PayrollPaymentReconciliationService::class,
            $reconciliation,
        );
        self::assertInstanceOf(
            PayrollPaymentReconciliationQueryService::class,
            $reconciliationQueries,
        );
        self::assertInstanceOf(PayrollPaymentBatchBuilder::class, $batchBuilder);
        self::assertInstanceOf(
            PayrollRunPaymentSettlementService::class,
            $settlement,
        );
        foreach ([
            'payroll_runs',
            'payroll_payment_allocations',
            'payroll_payment_matches',
        ] as $table) {
            if (!$connection->hasTable($table)) {
                $this->markTestSkipped('Mzdové platební migrace neproběhly.');
            }
        }

        $this->connection = $connection;
        $this->pdo = $connection->pdo();
        $this->commands = $commands;
        $this->runs = $runs;
        $this->reconciliation = $reconciliation;
        $this->reconciliationQueries = $reconciliationQueries;
        $this->batchBuilder = $batchBuilder;
        $this->settlement = $settlement;
        $this->pdo->beginTransaction();

        $sourceSupplierId = (int) $this->pdo
            ->query('SELECT MIN(id) FROM supplier')
            ->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $this->supplierId = $this->createIsolatedSupplier(
            $this->pdo,
            $sourceSupplierId,
        );
        $this->actorId = (int) $this->pdo
            ->query('SELECT MIN(id) FROM users')
            ->fetchColumn();
        self::assertGreaterThan(0, $this->actorId);
        $this->pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id = ?',
        )->execute([$this->supplierId]);
        $this->pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2099-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->actorId]);

        $this->allocationId = $this->seedAllocation(
            $this->pdo,
            $this->supplierId,
            'settlement-gate',
            'bank',
        );
        $row = $this->pdo->query(
            "SELECT revision.id AS revision_id, revision.run_id
               FROM payroll_run_revisions revision
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = revision.supplier_id
                AND liability.revision_id = revision.id
               JOIN payroll_payment_allocations allocation
                 ON allocation.supplier_id = liability.supplier_id
                AND allocation.liability_id = liability.id
              WHERE allocation.supplier_id = {$this->supplierId}
                AND allocation.id = {$this->allocationId}",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $this->revisionId = (int) $row['revision_id'];
        $this->runId = (int) $row['run_id'];
        $this->pdo->prepare(
            'UPDATE payroll_runs
                SET status = "payment_ready", current_revision_no = 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->runId]);
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

    public function testAllocationWithoutPaymentEvidenceCannotBePaidOrClosed(): void
    {
        try {
            $paid = $this->commands->markPaid(
                $this->supplierId,
                $this->runId,
                1,
                'settlement-gate-allocation-only',
                $this->actorId,
            );
            $this->commands->close(
                $this->supplierId,
                $this->runId,
                (int) $paid->run['row_version'],
                'settlement-gate-close-allocation-only',
                $this->actorId,
            );
            self::fail(
                'Pouhá platební dávka s alokací nesmí běh označit za uhrazený ani uzavřít.',
            );
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'skutečné úhrady',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            'payment_ready',
            (string) $this->runs->find($this->supplierId, $this->runId)['status'],
        );
    }

    public function testOneAccountantCanCloseRunAfterRealBankPayment(): void
    {
        $statementId = $this->seedBankStatement(
            $this->pdo,
            $this->supplierId,
            'settlement-gate-paid',
        );
        $transactionId = $this->insertBankTransaction(
            $statementId,
            '-1000.00',
            'settlement-gate-paid',
        );
        $this->reconciliation->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $transactionId,
                ),
                'settlement-gate-real-payment',
                $this->actorId,
            ),
        );

        $paid = $this->commands->markPaid(
            $this->supplierId,
            $this->runId,
            1,
            'settlement-gate-mark-paid',
            $this->actorId,
        );
        self::assertSame('paid', $paid->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::PAYMENTS_SETTLED,
            $paid->outcome?->outcome,
        );
        self::assertSame(100_000, $paid->outcome?->details['settled_minor']);

        $closed = $this->commands->close(
            $this->supplierId,
            $this->runId,
            (int) $paid->run['row_version'],
            'settlement-gate-close-paid',
            $this->actorId,
        );
        self::assertSame('closed', $closed->run['status']);
    }

    public function testPartialPaymentAndReversalReduceActualSettlement(): void
    {
        $statementId = $this->seedBankStatement(
            $this->pdo,
            $this->supplierId,
            'settlement-gate-partial',
        );
        $paymentId = $this->insertBankTransaction(
            $statementId,
            '-600.00',
            'settlement-gate-partial',
        );
        $match = $this->reconciliation->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                60_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $paymentId,
                ),
                'settlement-gate-partial-match',
                $this->actorId,
            ),
        );

        $partial = $this->settlement->inspect(
            $this->supplierId,
            $this->revisionId,
        );
        self::assertSame(60_000, $partial['settled_minor']);
        self::assertSame(40_000, $partial['uncovered'][0]['uncovered_minor']);

        $reversalId = $this->insertBankTransaction(
            $statementId,
            '200.00',
            'settlement-gate-reversal',
        );
        $this->reconciliation->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $match->id,
                20_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $reversalId,
                ),
                'settlement-gate-partial-reversal',
                $this->actorId,
            ),
        );

        $reversed = $this->settlement->inspect(
            $this->supplierId,
            $this->revisionId,
        );
        self::assertSame(40_000, $reversed['settled_minor']);
        self::assertSame(60_000, $reversed['uncovered'][0]['uncovered_minor']);
    }

    public function testPostedCashDocumentIsAcceptedAsRealPaymentEvidence(): void
    {
        $cashSupplierId = $this->createIsolatedSupplier(
            $this->pdo,
            $this->supplierId,
        );
        $allocationId = $this->seedAllocation(
            $this->pdo,
            $cashSupplierId,
            'settlement-gate-cash',
            'cash',
        );
        $registerId = $this->seedCashRegister(
            $this->pdo,
            $cashSupplierId,
            'settlement-gate-cash',
        );
        $documentId = $this->seedCashDocument(
            $this->pdo,
            $cashSupplierId,
            $registerId,
            'cash',
        );
        $this->seedCashPaymentMatch(
            $this->pdo,
            $cashSupplierId,
            $allocationId,
            $documentId,
            'settlement-gate-cash',
        );
        $revisionId = (int) $this->pdo->query(
            "SELECT liability.revision_id
               FROM payroll_payment_allocations allocation
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
              WHERE allocation.supplier_id = {$cashSupplierId}
                AND allocation.id = {$allocationId}",
        )->fetchColumn();

        $coverage = $this->settlement->inspect(
            $cashSupplierId,
            $revisionId,
        );
        self::assertSame(100_000, $coverage['settled_minor']);
        self::assertSame([], $coverage['uncovered']);
    }

    public function testCorrectionRevisionDoesNotForgetUnpaidEarlierLiability(): void
    {
        $correctionRevisionId = $this->insertCorrectionRevision();

        try {
            $this->commands->markPaid(
                $this->supplierId,
                $this->runId,
                1,
                'settlement-gate-correction-unpaid',
                $this->actorId,
            );
            self::fail(
                'Opravná revize bez nové částky nesmí zapomenout starší neuhrazený závazek.',
            );
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'skutečné úhrady',
                $exception->getMessage(),
            );
        }

        $coverage = $this->settlement->inspect(
            $this->supplierId,
            $correctionRevisionId,
        );
        self::assertSame(1, $coverage['liability_count']);
        self::assertSame(100_000, $coverage['uncovered'][0]['uncovered_minor']);
        self::assertSame(
            'payment_ready',
            (string) $this->runs->find($this->supplierId, $this->runId)['status'],
        );
    }

    public function testIncomingCorrectionIsBlockedAsUnresolvedRefund(): void
    {
        $statementId = $this->seedBankStatement(
            $this->pdo,
            $this->supplierId,
            'settlement-gate-before-refund',
        );
        $paymentId = $this->insertBankTransaction(
            $statementId,
            '-1000.00',
            'settlement-gate-before-refund',
        );
        $this->reconciliation->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $paymentId,
                ),
                'settlement-gate-before-refund',
                $this->actorId,
            ),
        );
        [$correctionRevisionId, $incomingLiabilityId] =
            $this->insertIncomingCorrectionLiability(20_000);

        $coverage = $this->settlement->inspect(
            $this->supplierId,
            $correctionRevisionId,
        );
        self::assertSame(100_000, $coverage['settled_minor']);
        self::assertSame(1, $coverage['incoming_unsettled_count']);
        self::assertSame('incoming', $coverage['uncovered'][0]['direction']);
        self::assertSame(
            20_000,
            $coverage['uncovered'][0]['uncovered_minor'],
        );
        self::assertStringContainsString(
            'příchozí opravná vratka',
            $this->settlement->blockingReason($coverage),
        );

        try {
            $this->commands->markPaid(
                $this->supplierId,
                $this->runId,
                1,
                'settlement-gate-unresolved-incoming-refund',
                $this->actorId,
            );
            self::fail(
                'Běh s nedoloženou příchozí vratkou nesmí přejít do paid.',
            );
        } catch (PayrollRunPaymentsUnsettledException $exception) {
            self::assertSame(
                1,
                $exception->coverage['incoming_unsettled_count'],
            );
        }
        self::assertSame(
            'payment_ready',
            (string) $this->runs->find(
                $this->supplierId,
                $this->runId,
            )['status'],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Příchozí opravný závazek nelze odeslat v platební dávce',
        );
        $this->batchBuilder->build(
            $this->supplierId,
            'manual',
            'incoming-refund-not-supported',
            [[
                'liability_id' => $incomingLiabilityId,
                'amount_minor' => 20_000,
            ]],
            $this->actorId,
        );
    }

    public function testIncomingCorrectionCanBeSettledByRealBankReceiptWithoutBatch(): void
    {
        $statementId = $this->seedBankStatement(
            $this->pdo,
            $this->supplierId,
            'settlement-gate-incoming-refund',
        );
        $originalPaymentId = $this->insertBankTransaction(
            $statementId,
            '-1000.00',
            'settlement-gate-before-incoming-refund',
        );
        $this->reconciliation->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $originalPaymentId,
                ),
                'settlement-gate-before-incoming-refund',
                $this->actorId,
            ),
        );
        [$correctionRevisionId, $incomingLiabilityId] =
            $this->insertIncomingCorrectionLiability(20_000);
        $beforeReceipt = $this->reconciliationQueries->forPeriod(
            $this->supplierId,
            '2099-01',
        );
        self::assertSame(
            [$incomingLiabilityId],
            array_column($beforeReceipt['incoming_liabilities'], 'id'),
        );
        $incomingTransactionId = $this->insertBankTransaction(
            $statementId,
            '200.00',
            'settlement-gate-incoming-refund',
        );

        $match = $this->reconciliation->matchIncomingRefund(
            new PayrollIncomingRefundReconciliationCommand(
                $this->supplierId,
                $incomingLiabilityId,
                20_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $incomingTransactionId,
                ),
                'settlement-gate-incoming-refund',
                $this->actorId,
            ),
        );
        $replay = $this->reconciliation->matchIncomingRefund(
            new PayrollIncomingRefundReconciliationCommand(
                $this->supplierId,
                $incomingLiabilityId,
                20_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $incomingTransactionId,
                ),
                'settlement-gate-incoming-refund',
                $this->actorId,
            ),
        );

        self::assertSame($incomingLiabilityId, $match->liabilityId);
        self::assertNull($match->allocationId);
        self::assertSame($match->id, $replay->id);
        self::assertTrue($replay->replayed);
        $afterReceipt = $this->reconciliationQueries->forPeriod(
            $this->supplierId,
            '2099-01',
        );
        self::assertSame([], $afterReceipt['incoming_liabilities']);
        $listedMatch = array_values(array_filter(
            $afterReceipt['matches'],
            static fn (array $item): bool =>
                ($item['id'] ?? null) === $match->id,
        ))[0] ?? null;
        self::assertIsArray($listedMatch);
        self::assertNull($listedMatch['allocation_id']);
        self::assertSame(
            $incomingLiabilityId,
            $listedMatch['liability_id'],
        );
        self::assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*)
                   FROM payroll_payment_allocations
                  WHERE supplier_id = {$this->supplierId}
                    AND liability_id = {$incomingLiabilityId}",
            )->fetchColumn(),
            'Příchozí vratka nesmí vytvořit pomocnou platební dávku ani alokaci.',
        );

        $coverage = $this->settlement->inspect(
            $this->supplierId,
            $correctionRevisionId,
        );
        self::assertSame(120_000, $coverage['settled_minor']);
        self::assertSame(0, $coverage['incoming_unsettled_count']);
        self::assertSame([], $coverage['uncovered']);

        $paid = $this->commands->markPaid(
            $this->supplierId,
            $this->runId,
            1,
            'settlement-gate-paid-after-incoming-refund',
            $this->actorId,
        );
        self::assertSame('paid', $paid->run['status']);
    }

    public function testIncomingRefundPartialReversalCapacityAndTenantStayFailClosed(): void
    {
        $statementId = $this->seedBankStatement(
            $this->pdo,
            $this->supplierId,
            'settlement-gate-incoming-partial',
        );
        $originalPaymentId = $this->insertBankTransaction(
            $statementId,
            '-1000.00',
            'settlement-gate-incoming-partial-original',
        );
        $this->reconciliation->match(new PayrollPaymentReconciliationCommand(
            $this->supplierId,
            $this->allocationId,
            100_000,
            PayrollPaymentEvidenceReference::bank(
                $statementId,
                $originalPaymentId,
            ),
            'settlement-gate-incoming-partial-original',
            $this->actorId,
        ));
        [$correctionRevisionId, $incomingLiabilityId] =
            $this->insertIncomingCorrectionLiability(20_000);
        $receiptId = $this->insertBankTransaction(
            $statementId,
            '200.00',
            'settlement-gate-incoming-partial-receipt',
        );
        $match = $this->reconciliation->matchIncomingRefund(
            new PayrollIncomingRefundReconciliationCommand(
                $this->supplierId,
                $incomingLiabilityId,
                12_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $receiptId,
                ),
                'settlement-gate-incoming-partial-receipt',
                $this->actorId,
            ),
        );

        $partial = $this->settlement->inspect(
            $this->supplierId,
            $correctionRevisionId,
        );
        self::assertSame(112_000, $partial['settled_minor']);
        self::assertSame(1, $partial['incoming_unsettled_count']);
        self::assertSame(8_000, $partial['uncovered'][0]['uncovered_minor']);

        $tooLargeReceiptId = $this->insertBankTransaction(
            $statementId,
            '90.00',
            'settlement-gate-incoming-over-capacity',
        );
        try {
            $this->reconciliation->matchIncomingRefund(
                new PayrollIncomingRefundReconciliationCommand(
                    $this->supplierId,
                    $incomingLiabilityId,
                    9_000,
                    PayrollPaymentEvidenceReference::bank(
                        $statementId,
                        $tooLargeReceiptId,
                    ),
                    'settlement-gate-incoming-over-capacity',
                    $this->actorId,
                ),
            );
            self::fail('Přijaté vratky nesmí překročit příchozí závazek.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'překročila příchozí mzdový závazek',
                $exception->getMessage(),
            );
        }

        $otherSupplierId = $this->createIsolatedSupplier(
            $this->pdo,
            $this->supplierId,
        );
        try {
            $this->reconciliation->matchIncomingRefund(
                new PayrollIncomingRefundReconciliationCommand(
                    $otherSupplierId,
                    $incomingLiabilityId,
                    1_000,
                    PayrollPaymentEvidenceReference::bank(
                        $statementId,
                        $receiptId,
                    ),
                    'settlement-gate-incoming-wrong-tenant',
                    $this->actorId,
                ),
            );
            self::fail('Cizí firma nesmí použít příchozí mzdový závazek.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'nebyl nalezen v aktuální firmě',
                $exception->getMessage(),
            );
        }

        $reversalId = $this->insertBankTransaction(
            $statementId,
            '-50.00',
            'settlement-gate-incoming-partial-reversal',
        );
        $reversal = $this->reconciliation->reverseIncomingRefund(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $match->id,
                5_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $reversalId,
                ),
                'settlement-gate-incoming-partial-reversal',
                $this->actorId,
            ),
        );
        self::assertSame(-5_000, $reversal->amountMinor);
        self::assertSame($match->id, $reversal->sourceMatchId);

        $afterReversal = $this->settlement->inspect(
            $this->supplierId,
            $correctionRevisionId,
        );
        self::assertSame(107_000, $afterReversal['settled_minor']);
        self::assertSame(
            13_000,
            $afterReversal['uncovered'][0]['uncovered_minor'],
        );

        $remainderId = $this->insertBankTransaction(
            $statementId,
            '130.00',
            'settlement-gate-incoming-remainder',
        );
        $this->reconciliation->matchIncomingRefund(
            new PayrollIncomingRefundReconciliationCommand(
                $this->supplierId,
                $incomingLiabilityId,
                13_000,
                PayrollPaymentEvidenceReference::bank(
                    $statementId,
                    $remainderId,
                ),
                'settlement-gate-incoming-remainder',
                $this->actorId,
            ),
        );
        $settled = $this->settlement->inspect(
            $this->supplierId,
            $correctionRevisionId,
        );
        self::assertSame(120_000, $settled['settled_minor']);
        self::assertSame([], $settled['uncovered']);
    }

    public function testIncomingRefundAcceptsPostedCashReceiptAndItsReversal(): void
    {
        [$correctionRevisionId, $incomingLiabilityId] =
            $this->insertIncomingCorrectionLiability(20_000);
        $registerId = $this->seedCashRegister(
            $this->pdo,
            $this->supplierId,
            'settlement-gate-incoming-cash',
        );
        $this->pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number,
                 issue_date, description, total_amount, currency_code,
                 counter_account_code, status)
             VALUES (?, ?, "in", "other", ?, "2099-01-22",
                     "Syntetická přijatá mzdová vratka", 200.00, "CZK",
                     "331", "posted")',
        )->execute([
            $this->supplierId,
            $registerId,
            "PPD-INCOMING-{$incomingLiabilityId}",
        ]);
        $cashDocumentId = (int) $this->pdo->lastInsertId();

        $match = $this->reconciliation->matchIncomingRefund(
            new PayrollIncomingRefundReconciliationCommand(
                $this->supplierId,
                $incomingLiabilityId,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'settlement-gate-incoming-cash',
                $this->actorId,
            ),
        );
        self::assertSame('cash', $match->evidenceKind);
        self::assertSame(
            20_000,
            $this->settlement->inspect(
                $this->supplierId,
                $correctionRevisionId,
            )['settled_minor'],
        );

        $this->pdo->prepare(
            'UPDATE cash_documents SET status = "reversed" WHERE id = ?',
        )->execute([$cashDocumentId]);
        $reversal = $this->reconciliation->reverseIncomingRefund(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $match->id,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'settlement-gate-incoming-cash-reversal',
                $this->actorId,
            ),
        );
        self::assertSame(-20_000, $reversal->amountMinor);
        $coverage = $this->settlement->inspect(
            $this->supplierId,
            $correctionRevisionId,
        );
        self::assertSame(0, $coverage['settled_minor']);
        self::assertSame(1, $coverage['incoming_unsettled_count']);
    }

    public function testRevisionFromAnotherTenantFailsClosed(): void
    {
        $otherSupplierId = $this->createIsolatedSupplier(
            $this->pdo,
            $this->supplierId,
        );

        $this->expectException(\OutOfBoundsException::class);
        $this->settlement->inspect($otherSupplierId, $this->revisionId);
    }

    private function insertBankTransaction(
        int $statementId,
        string $amount,
        string $seed,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2099-01-20", ?, "CZK", ?, ?)',
        )->execute([
            $statementId,
            $amount,
            "Syntetický platební důkaz {$seed}",
            hash('sha256', "settlement-gate-transaction:{$seed}"),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertCorrectionRevision(): int
    {
        $snapshot = '{"schema":"settlement-gate-correction.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, calculated_by, reviewed_by, approved_by,
                 calculated_at, reviewed_at, approved_at)
             VALUES (?, ?, 2, ?, "correction", "approved",
                     "settlement-gate.v1", ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     NOW(), NOW(), NOW())',
        )->execute([
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            str_repeat('b', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash('sha256', 'settlement-gate-correction', true),
            $this->actorId,
            $this->actorId,
            $this->actorId,
        ]);
        $revisionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->runId]);

        return $revisionId;
    }

    /** @return array{int,int} */
    private function insertIncomingCorrectionLiability(
        int $amountMinor,
    ): array {
        $revisionId = $this->insertCorrectionRevision();
        $previousLiability = $this->pdo->query(
            "SELECT liability.id, liability.employee_id,
                    liability.liability_reference,
                    liability.liability_kind,
                    liability.recipient_reference
               FROM payroll_payment_allocations allocation
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
              WHERE allocation.supplier_id = {$this->supplierId}
                AND allocation.id = {$this->allocationId}",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($previousLiability);
        $previousLiabilityId = (int) $previousLiability['id'];
        self::assertGreaterThan(0, $previousLiabilityId);
        $reference = (string) $previousLiability['liability_reference'];
        $snapshot = '{"schema":"settlement-gate-incoming.v1"}';
        $this->pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, period_start, employee_id,
                 result_json, result_hash, status)
             SELECT supplier_id, ?, period_start, employee_id,
                     result_json, result_hash, "calculated"
               FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ?',
        )->execute([
            $revisionId,
            $this->supplierId,
            $this->revisionId,
        ]);
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id,
                 liability_reference, liability_kind, direction,
                 recipient_reference, due_on, currency_code, amount_minor,
                 previous_liability_id, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, "incoming", ?,
                     "2099-02-20", "CZK", ?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            (int) $previousLiability['employee_id'],
            $reference,
            (string) $previousLiability['liability_kind'],
            (string) $previousLiability['recipient_reference'],
            $amountMinor,
            $previousLiabilityId,
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', $reference, true),
            $this->actorId,
        ]);

        return [$revisionId, (int) $this->pdo->lastInsertId()];
    }
}
