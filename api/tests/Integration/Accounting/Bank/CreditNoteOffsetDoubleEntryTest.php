<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
use PHPUnit\Framework\Attributes\Group;

/**
 * Zápočet dobropisu (issue #140) v podvojném účetnictví. Automaticky se dobropis
 * započítává jen v daňové evidenci, ruční zápočet nesmí přes párování banky
 * odúčtovat saldokonto 311 podruhé.
 */
#[Group('integration')]
final class CreditNoteOffsetDoubleEntryTest extends BankPostingTestCase
{
    private CreditNoteOffsetService $offsets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->offsets = $this->container->get(CreditNoteOffsetService::class);
    }

    public function testIssuedCreditNoteIsNotOffsetAutomaticallyInDoubleEntry(): void
    {
        [$invoiceId, $creditNoteId] = $this->issuedPair('FV-2099-CNO-A', 10000.00, -2000.00);

        $result = $this->offsets->autoApplyForInvoice($this->supplierId, $creditNoteId, $this->userId);

        self::assertNull($result['offset_id']);
        self::assertSame(0, $this->offsetCount($creditNoteId));
        self::assertSame(0, $this->paymentCount($invoiceId), 'faktura v PÚ nedostane platbu ze zápočtu');
        self::assertSame('issued', $this->invoiceStatus($creditNoteId));
    }

    public function testReceivedPurchaseCreditNoteIsNotOffsetAutomaticallyInDoubleEntry(): void
    {
        $vendor = $this->client('Dodavatel CNO');
        $invoiceId = $this->purchaseInvoice('PF-2099-CNO-A', $vendor, 10000.00);
        $creditNoteId = $this->purchaseInvoice('PF-2099-CNO-B', $vendor, -2000.00, 'credit_note');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$invoiceId, $creditNoteId]);

        $this->container->get(PurchaseInvoiceReceiver::class)->afterReceived($this->supplierId, $creditNoteId, $this->userId);

        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM credit_note_offsets WHERE doc_type = 'purchase_invoice' AND credit_note_id = ?",
            [$creditNoteId],
        ));
        self::assertSame('received', (string) $this->scalar('SELECT status FROM purchase_invoices WHERE id = ?', [$creditNoteId]));
    }

    public function testManualOffsetStaysAvailableInDoubleEntry(): void
    {
        [$invoiceId, $creditNoteId] = $this->issuedPair('FV-2099-CNO-M', 10000.00, -2000.00);

        $result = $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId);

        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        self::assertEqualsWithDelta(8000.00, $this->remaining($invoiceId), 0.001);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @return array{0:int, 1:int} */
    private function issuedPair(string $varsymbol, float $invoiceTotal, float $creditNoteTotal): array
    {
        $client = $this->client('Odběratel ' . $varsymbol);
        $invoiceId = $this->saleInvoice($varsymbol, $client, $invoiceTotal);
        $creditNoteId = $this->saleInvoice($varsymbol . '-D', $client, $creditNoteTotal, 'credit_note');
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')
            ->execute([$invoiceId, $creditNoteId]);
        return [$invoiceId, $creditNoteId];
    }

    private function offsetCount(int $creditNoteId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM credit_note_offsets WHERE doc_type = 'invoice' AND credit_note_id = ?",
            [$creditNoteId],
        );
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]);
    }

    private function invoiceStatus(int $id): string
    {
        return (string) $this->scalar('SELECT status FROM invoices WHERE id = ?', [$id]);
    }

    private function remaining(int $id): float
    {
        return (float) $this->scalar('SELECT amount_to_pay - paid_total FROM invoices WHERE id = ?', [$id]);
    }

    /** @param list<int|string> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
