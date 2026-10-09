<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
use PHPUnit\Framework\Attributes\Group;

/**
 * Zápočet dobropisu proti opravované faktuře (issue #140, migrace 1988).
 *
 * Dobropis navázaný na nezaplacenou fakturu sníží její „Zbývá uhradit", sám se vyrovná
 * a do peněžního deníku nevstupuje: příjem/výdaj je jen skutečná úhrada zbytku.
 * Ověřuje obě strany (vydaná i přijatá), stárnutí pohledávek a závazků bez dvojího
 * započtení, peněžní deník za rok a zrušení zápočtu.
 */
#[Group('integration')]
final class CreditNoteOffsetTest extends CashJournalTestCase
{
    private CreditNoteOffsetService $offsets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->offsets = $this->container->get(CreditNoteOffsetService::class);
    }

    public function testIssuedCreditNoteReducesRemainingAndCashJournalHasOnlyRealPayment(): void
    {
        [$invoiceId, $creditNoteId] = $this->issuedPair(10000.0, -2000.0);

        $result = $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);

        self::assertEqualsWithDelta(8000.0, $this->issuedRemaining($invoiceId), 0.001, 'Zbývá uhradit klesne o dobropis');
        self::assertSame('paid', $this->issuedStatus($creditNoteId), 'dobropis je vyrovnaný zápočtem');
        self::assertSame([['total' => 8000.0, 'count' => 1]], $this->agingReceivables(),
            'stárnutí pohledávek: jen zbytek faktury, dobropis se nezapočte podruhé');

        $stmt = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($stmt, 8000.0);
        $this->invoicePayment($this->supplierId, $invoiceId, 8000.0, 'bank', $tx);

        $res = $this->fullYear($this->supplierId, false);
        self::assertEqualsWithDelta(8000.0, $res['totals']['prijem_danovy'], 0.01, 'příjem = skutečně přijaté peníze');
        self::assertSame(0, $this->countRows($res, 'invoice_payment'), 'zápočet dobropisu není řádek peněžního deníku');
    }

    public function testIssuedCreditNoteIsOffsetAutomaticallyInTaxEvidence(): void
    {
        [$invoiceId, $creditNoteId] = $this->issuedPair(10000.0, -2000.0);

        $result = $this->offsets->autoApplyForInvoice($this->supplierId, $creditNoteId, $this->userId);

        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        self::assertEqualsWithDelta(8000.0, $this->issuedRemaining($invoiceId), 0.001);
    }

    public function testForeignCurrencyOffsetWithDifferentRateIsAllowedInTaxEvidence(): void
    {
        [$invoiceId, $creditNoteId] = $this->issuedPair(1000.0, -200.0);
        $eur = $this->currencyRow($this->supplierId, 'EUR', null, null);
        $this->db->pdo()->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 25 WHERE id = ?')->execute([$eur, $invoiceId]);
        $this->db->pdo()->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 24 WHERE id = ?')->execute([$eur, $creditNoteId]);

        $result = $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId);

        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        self::assertEqualsWithDelta(800.0, $this->issuedRemaining($invoiceId), 0.001);
    }

    public function testIssuedOffsetRevertRestoresInvoiceAndCreditNote(): void
    {
        [$invoiceId, $creditNoteId] = $this->issuedPair(10000.0, -2000.0);
        $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId);

        self::assertSame(1, $this->offsets->revertForDocument($this->supplierId, 'invoice', $creditNoteId));

        self::assertEqualsWithDelta(10000.0, $this->issuedRemaining($invoiceId), 0.001);
        self::assertSame('issued', $this->issuedStatus($creditNoteId));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM credit_note_offsets WHERE credit_note_id = ?', [$creditNoteId]));
    }

    public function testIssuedCreditNoteIsNotOffsetWhenInvoiceRemainingIsLower(): void
    {
        [$invoiceId, $creditNoteId] = $this->issuedPair(10000.0, -2000.0);
        $this->invoicePayment($this->supplierId, $invoiceId, 9000.0, 'manual');
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 9000 WHERE id = ?')->execute([$invoiceId]);

        $result = $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId);

        self::assertNull($result['offset_id']);
        self::assertSame('parent_remaining_too_low', $result['reason']);
        self::assertSame('issued', $this->issuedStatus($creditNoteId), 'dobropis zůstává k vrácení penězi');
    }

    public function testPurchaseCreditNoteReducesPayableAndCashJournalHasOnlyRealPayment(): void
    {
        [$invoiceId, $creditNoteId] = $this->purchasePair(10000.0, -2000.0);

        // Háček přijetí dobropisu započte sám.
        $this->container->get(PurchaseInvoiceReceiver::class)->afterReceived($this->supplierId, $creditNoteId, $this->userId);

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM credit_note_offsets WHERE credit_note_id = ?', [$creditNoteId]));
        self::assertSame('paid', $this->purchaseStatus($creditNoteId));
        self::assertSame('received', $this->purchaseStatus($invoiceId), 'faktura zůstává k úhradě zbytku');
        self::assertSame([['total' => 8000.0, 'count' => 1]], $this->agingPayables(),
            'stárnutí závazků: jen zbytek faktury');

        $stmt = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($stmt, -8000.0);
        $this->paymentMatch($this->supplierId, $tx, $invoiceId, 8000.0);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-06-15', $invoiceId]);

        $res = $this->fullYear($this->supplierId, false);
        self::assertEqualsWithDelta(8000.0, $res['totals']['vydaj_danovy'], 0.01, 'výdaj = skutečně zaplacené peníze');
        self::assertSame(0, $this->countRows($res, 'purchase_invoice', $creditNoteId), 'dobropis není v peněžním deníku');
    }

    public function testPurchaseInvoiceFullyCoveredByCreditNoteHasNoCashJournalRow(): void
    {
        [$invoiceId, $creditNoteId] = $this->purchasePair(2000.0, -2000.0);

        $result = $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        self::assertSame('paid', $this->purchaseStatus($invoiceId), 'zápočet fakturu celou pokryl');

        $res = $this->fullYear($this->supplierId, false);
        self::assertSame(0, $this->countRows($res, 'purchase_invoice'), 'bez peněz žádný výdaj ani záporný výdaj');
        self::assertEqualsWithDelta(0.0, $res['totals']['vydaj_danovy'], 0.01);
    }

    public function testPurchaseOffsetRevertReopensBothDocuments(): void
    {
        [$invoiceId, $creditNoteId] = $this->purchasePair(2000.0, -2000.0);
        $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);

        self::assertSame(1, $this->offsets->revertForDocument($this->supplierId, 'purchase_invoice', $invoiceId));

        self::assertSame('received', $this->purchaseStatus($invoiceId));
        self::assertSame('received', $this->purchaseStatus($creditNoteId));
        self::assertSame([['total' => 2000.0, 'count' => 1]], $this->agingPayables());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @return array{0:int, 1:int} */
    private function issuedPair(float $invoiceTotal, float $creditNoteTotal): array
    {
        $invoiceId = $this->saleInvoice($this->supplierId, ['without' => $invoiceTotal, 'issue_date' => self::YEAR . '-06-01']);
        $creditNoteId = $this->saleInvoice($this->supplierId, [
            'type' => 'credit_note', 'without' => $creditNoteTotal, 'issue_date' => self::YEAR . '-06-05',
        ]);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')->execute([$invoiceId, $creditNoteId]);
        return [$invoiceId, $creditNoteId];
    }

    /** @return array{0:int, 1:int} */
    private function purchasePair(float $invoiceTotal, float $creditNoteTotal): array
    {
        $invoiceId = $this->purchaseInvoice($this->supplierId, ['without' => $invoiceTotal, 'issue_date' => self::YEAR . '-06-01']);
        $creditNoteId = $this->purchaseInvoice($this->supplierId, [
            'document_kind' => 'credit_note', 'without' => $creditNoteTotal, 'issue_date' => self::YEAR . '-06-05',
        ]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$invoiceId, $creditNoteId]);
        return [$invoiceId, $creditNoteId];
    }

    private function issuedRemaining(int $id): float
    {
        return (float) $this->scalar('SELECT amount_to_pay - paid_total FROM invoices WHERE id = ?', [$id]);
    }

    private function issuedStatus(int $id): string
    {
        return (string) $this->scalar('SELECT status FROM invoices WHERE id = ?', [$id]);
    }

    private function purchaseStatus(int $id): string
    {
        return (string) $this->scalar('SELECT status FROM purchase_invoices WHERE id = ?', [$id]);
    }

    /** @return list<array{total: float, count: int}> */
    private function agingReceivables(): array
    {
        return self::agingTotals($this->container->get(CrmAggregationService::class)->agingReceivables($this->supplierId));
    }

    /** @return list<array{total: float, count: int}> */
    private function agingPayables(): array
    {
        return self::agingTotals($this->container->get(CrmAggregationService::class)->agingPayables($this->supplierId));
    }

    /**
     * @param list<array{bucket:string, currency:string, count:int, total:float}> $rows
     * @return list<array{total: float, count: int}>
     */
    private static function agingTotals(array $rows): array
    {
        $total = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            $total += $r['total'];
            $count += $r['count'];
        }
        return [['total' => round($total, 2), 'count' => $count]];
    }

    /** @param list<int|string> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
