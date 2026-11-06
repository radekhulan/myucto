<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\BankTransactionReleaseService;
use MyInvoice\Service\PurchaseInvoice\AdvanceCoveredPaidStatus;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PDO;

/** Nativní PF: odstranění vratky obnoví skutečnou úhradu bez jakéhokoli importéru. */
#[Group('integration')]
final class PurchaseRefundReleaseStatusTest extends BankPostingTestCase
{
    public static function releaseCases(): iterable
    {
        yield 'received/unmatch' => ['received', 121.0, 0.0, 121.0, 'unmatch', 'paid'];
        yield 'booked/delete' => ['booked', 121.0, 0.0, 121.0, 'delete', 'paid'];
        yield 'underpaid' => ['received', 122.0, 0.0, 121.0, 'unmatch', 'received'];
        yield 'positive rounding unpaid' => ['received', 121.0, 0.4, 120.8, 'unmatch', 'received'];
        yield 'positive rounding paid' => ['received', 120.6, 0.4, 121.0, 'unmatch', 'paid'];
        yield 'negative rounding paid' => ['received', 121.4, -0.4, 121.0, 'unmatch', 'paid'];
        yield 'draft' => ['draft', 121.0, 0.0, 121.0, 'unmatch', 'draft'];
        yield 'cancelled' => ['cancelled', 121.0, 0.0, 121.0, 'unmatch', 'cancelled'];
        yield 'manual paid' => ['paid', 121.0, 0.0, 121.0, 'unmatch', 'paid'];
    }

    #[DataProvider('releaseCases')]
    public function testRefundReleaseRestoresOnlyFullyCoveredOpenPurchase(
        string $status, float $nominal, float $rounding, float $payment, string $mode, string $expectedStatus,
    ): void {
        $invoice = $this->purchaseInvoice('SYN-REFUND-RELEASE', $this->client('Synthetic vendor'), $nominal);
        $paidAt = $status === 'paid' ? self::YEAR . '-05-01' : null;
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = ?, rounding = ?, paid_at = ? WHERE id = ?')
            ->execute([$status, $rounding, $paidAt, $invoice]);
        $statement = $this->statement();
        $paymentDate = self::YEAR . '-06-12';
        $outgoing = $this->transaction($statement, -$payment, ['match_status' => 'manual', 'posted_at' => $paymentDate]);
        $this->paymentMatch($outgoing, $invoice, $payment);
        $refund = $this->transaction($statement, 21.0, ['match_status' => 'manual', 'posted_at' => self::YEAR . '-06-15']);
        $this->paymentMatch($refund, $invoice, 21.0);
        self::assertEqualsWithDelta($payment - 21, $this->invoiceState($invoice)['settled'], 0.001);
        self::assertSame(0, $this->entryCountForTx($refund), 'Vratka nemá zápis: release musí projít i bez něj.');

        $result = $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $refund, $mode, $this->userId);

        self::assertSame('none', $result['journal']);
        $after = $this->invoiceState($invoice);
        self::assertEqualsWithDelta($payment, $after['settled'], 0.001);
        self::assertSame($expectedStatus, $after['status']);
        self::assertSame($status === 'paid' ? $paidAt : ($expectedStatus === 'paid' ? $paymentDate : null),
            $after['paid_at'] === null ? null : substr($after['paid_at'], 0, 10));
        $repo = $this->container->get(PurchaseInvoiceRepository::class);
        $list = $repo->listGroupedByMonth(['supplier_id' => $this->supplierId, 'status' => 'paid', 'q' => 'SYN-REFUND-RELEASE']);
        $ids = [];
        foreach ($list['data'] as $group) foreach ($group['invoices'] as $item) $ids[] = (int) $item['id'];
        self::assertSame($expectedStatus === 'paid', in_array($invoice, $ids, true));
    }

    public function testCreditOffsetDateIsUsedWhenItIsTheOnlyRemainingSettlement(): void
    {
        $vendor = $this->client('Synthetic credit offset vendor');
        $invoice = $this->purchaseInvoice('SYN-REFUND-OFFSET', $vendor, 121.0);
        $credit = $this->purchaseInvoice('SYN-REFUND-CREDIT', $vendor, -121.0, 'credit_note');
        $offsetDate = self::YEAR . '-06-12';
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ?, status = ?, paid_at = ? WHERE id = ?')
            ->execute([$invoice, 'paid', $offsetDate, $credit]);
        $this->db->pdo()->prepare("INSERT INTO credit_note_offsets
            (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, created_by)
            VALUES (?, 'purchase_invoice', ?, ?, 121, ?, ?)")
            ->execute([$this->supplierId, $invoice, $credit, $offsetDate, $this->userId]);
        self::assertSame($offsetDate, (new AdvanceCoveredPaidStatus($this->db))->lastPaymentDate($this->supplierId, $invoice));
        $refund = $this->transaction($this->statement(), 21.0, ['match_status' => 'manual']);
        $this->paymentMatch($refund, $invoice, 21.0);
        self::assertEqualsWithDelta(100.0, $this->invoiceState($invoice)['settled'], 0.001);

        $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $refund, BankTransactionReleaseService::MODE_UNMATCH, $this->userId);

        $after = $this->invoiceState($invoice);
        self::assertSame('paid', $after['status']);
        self::assertSame($offsetDate, substr($after['paid_at'], 0, 10));
        self::assertEqualsWithDelta(121.0, $after['settled'], 0.001);
    }

    public static function manualTransitions(): iterable
    {
        yield 'ručně neuhrazeno' => [[['paid', 'received']], 'received'];
        yield 'ručně neuhrazeno a zaúčtováno' => [[['paid', 'received'], ['received', 'booked']], 'received'];
        yield 'ručně znovu uhrazeno' => [[['paid', 'received'], ['received', 'paid']], 'paid'];
    }

    /** @param list<array{0:string,1:string}> $transitions */
    #[DataProvider('manualTransitions')]
    public function testManuallyUnpaidInvoiceStaysOpen(array $transitions, string $expectedStatus): void
    {
        $invoice = $this->purchaseInvoice('SYN-REFUND-MANUAL', $this->client('Synthetic manual vendor'), 121.0);
        $logger = $this->container->get(ActivityLogger::class);
        foreach ($transitions as [$from, $to]) {
            $logger->log('purchase_invoice.transitioned', $this->userId, 'purchase_invoice', $invoice, ['from' => $from, 'to' => $to]);
        }
        $refund = $this->refundedPayment($invoice, 121.0, self::YEAR . '-06-12');

        $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $refund, BankTransactionReleaseService::MODE_UNMATCH, $this->userId);

        self::assertSame($expectedStatus, $this->invoiceState($invoice)['status']);
    }

    public function testTakenOverInvoiceKeepsStatusFromSource(): void
    {
        $invoice = $this->purchaseInvoice('SYN-REFUND-TAKEN', $this->client('Synthetic taken over vendor'), 121.0);
        $this->db->pdo()->prepare("INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id)
            VALUES (?, 'purchase_invoice', ?, ?)")
            ->execute([$this->supplierId, 'SYN-TAKEN-' . $invoice, $invoice]);
        $refund = $this->refundedPayment($invoice, 121.0, self::YEAR . '-06-12');

        $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $refund, BankTransactionReleaseService::MODE_UNMATCH, $this->userId);

        self::assertSame(['received', null], [$this->invoiceState($invoice)['status'], $this->invoiceState($invoice)['paid_at']]);
    }

    /** Datum úhrady je datum odchozí platby, ne vratky, která na faktuře zůstává. */
    public function testRemainingRefundIsNotPaymentDate(): void
    {
        $invoice = $this->purchaseInvoice('SYN-REFUND-DATE', $this->client('Synthetic refund date vendor'), 121.0);
        $statement = $this->statement();
        $kept = $this->transaction($statement, 21.0, ['match_status' => 'manual', 'posted_at' => self::YEAR . '-06-15']);
        $this->paymentMatch($kept, $invoice, 21.0);
        $released = $this->refundedPayment($invoice, 142.0, self::YEAR . '-06-12', self::YEAR . '-06-20');

        $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $released, BankTransactionReleaseService::MODE_UNMATCH, $this->userId);

        $after = $this->invoiceState($invoice);
        self::assertSame('paid', $after['status']);
        self::assertSame(self::YEAR . '-06-12', substr((string) $after['paid_at'], 0, 10));
    }

    /** Odchozí platba a spárovaná příchozí vratka 21 Kč; faktura je otevřená. Vrací id vratky. */
    private function refundedPayment(int $invoice, float $payment, string $paymentDate, string $refundDate = ''): int
    {
        $statement = $this->statement();
        $outgoing = $this->transaction($statement, -$payment, ['match_status' => 'manual', 'posted_at' => $paymentDate]);
        $this->paymentMatch($outgoing, $invoice, $payment);
        $refund = $this->transaction($statement, 21.0, ['match_status' => 'manual', 'posted_at' => $refundDate !== '' ? $refundDate : self::YEAR . '-06-15']);
        $this->paymentMatch($refund, $invoice, 21.0);
        return $refund;
    }

    private function invoiceState(int $id): array
    {
        $settled = PurchaseSettledExpr::settled('pi');
        $stmt = $this->db->pdo()->prepare("SELECT pi.status, pi.paid_at, ($settled) AS settled
            FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.id = ?");
        $stmt->execute([$this->supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $row['settled'] = (float) $row['settled'];
        return $row;
    }
}
