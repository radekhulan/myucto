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

    /**
     * Dobropis na plnou výši nechá fakturu zaplacenou jedinou platbou se zdrojem
     * `credit_note`. Sloučená úhrada ji nesmí „rekonciliovat" s bankou: bankovní zápis
     * by 311 odúčtoval podruhé, saldo už snížil dobropis.
     */
    public function testSplitPaymentDoesNotReconcileCreditNoteOffset(): void
    {
        [$client, $invoiceId] = $this->fullyOffsetInvoice('FV-2099-CNO-S', 10000.00);
        $otherId = $this->saleInvoice('FV-2099-CNO-S2', $client, 2000.00);
        $this->postPredpis('invoice', $otherId, '311', '602', 2000.00);
        $tx = $this->transaction($this->statement(), 12000.00);

        $res = $this->callAction(
            $this->container->get(\MyInvoice\Action\Bank\BankStatementAction::class),
            'manualMatch',
            'POST',
            'admin',
            ['invoice_ids' => [$invoiceId, $otherId]],
            ['id' => (string) $tx],
        );

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('cannot_reconcile', $res['body']['error']['code'] ?? null);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ?', [$tx]));
        self::assertEqualsWithDelta(0.0, $this->bank311Credit($tx), 0.001, '311 se bankou neodúčtuje podruhé');
        self::assertEqualsWithDelta(2000.00, $this->receivable311([$invoiceId, $otherId]), 0.001,
            'saldo 311: faktura vyrovnaná dobropisem, druhá otevřená');
    }

    public function testSplitSuggestionsDoNotOfferCreditNoteOffsetForReconciliation(): void
    {
        [$client, $invoiceId] = $this->fullyOffsetInvoice('FV-2099-CNO-N', 10000.00);
        $otherId = $this->saleInvoice('FV-2099-CNO-N2', $client, 2000.00);
        $tx = $this->transaction($this->statement(), 12000.00);

        $res = $this->callAction(
            $this->container->get(\MyInvoice\Action\Bank\BankStatementAction::class),
            'splitSuggestions',
            'GET',
            'admin',
            [],
            ['id' => (string) $tx],
        );

        self::assertSame(200, $res['status']);
        foreach ($res['body']['suggestions'] ?? [] as $s) {
            $ids = array_map(static fn (array $i): int => (int) $i['id'], $s['invoices'] ?? []);
            self::assertNotContains($invoiceId, $ids, 'faktura vyrovnaná dobropisem se k rekonciliaci nenabízí');
        }
        self::assertNotSame(0, $otherId);
    }

    public function testReconcileToBankTransactionSkipsOffsetAndSettlementPayments(): void
    {
        [, $invoiceId] = $this->fullyOffsetInvoice('FV-2099-CNO-R', 5000.00);
        $tx = $this->transaction($this->statement(), 5000.00);

        $thrown = null;
        try {
            $this->container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)
                ->reconcileToBankTransaction($invoiceId, $tx);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        self::assertNotNull($thrown, 'Platba ze zápočtu dobropisu se na bankovní pohyb navázat nesmí.');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ?', [$tx]));

        $client = $this->client('Odběratel zápočtu proti účtu');
        $settled = $this->saleInvoice('FV-2099-CNO-R2', $client, 3000.00, 'invoice', 'paid');
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 3000, 'CZK', 'settlement')"
        )->execute([$this->supplierId, $settled, self::YEAR . '-06-12']);
        $tx2 = $this->transaction($this->statement(), 3000.00);
        $this->expectException(\RuntimeException::class);
        $this->container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)
            ->reconcileToBankTransaction($settled, $tx2);
    }

    /**
     * Pojistka v zaúčtování: kdyby platba ze zápočtu dobropisu přesto visela na pohybu
     * (data z doby před opravou), bankovní zápis 221/311 nevznikne a pohyb jde ke kontrole.
     */
    public function testBankPostingRefusesPaymentSettledByCreditNote(): void
    {
        [, $invoiceId] = $this->fullyOffsetInvoice('FV-2099-CNO-P', 10000.00);
        $tx = $this->transaction($this->statement(), 10000.00, ['match_status' => 'manual', 'matched_invoice_id' => $invoiceId]);
        $this->db->pdo()->prepare(
            "UPDATE invoice_payments SET bank_transaction_id = ? WHERE invoice_id = ? AND source = 'credit_note'"
        )->execute([$tx, $invoiceId]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertNotSame('posted', $res['action']);
        self::assertSame('overpaid_verify', $res['reason']);
        self::assertEqualsWithDelta(0.0, $this->bank311Credit($tx), 0.001);
    }

    /**
     * Zápočet dobropisu a potom „Označit uhrazeno": faktura má dvě nenavázané platby
     * (credit_note + mark_paid). Bankovní pohyb na zbytek se musí automaticky spárovat,
     * navázat na platbu mark_paid a zaúčtovat 221/311 jen zbytkem.
     */
    public function testMarkPaidAfterOffsetIsMatchedAndPostedAutomatically(): void
    {
        [$invoiceId] = $this->partiallyOffsetInvoice('2099881101', 10000.00, 2000.00);
        $markPaid = $this->payments()->recordPayment($invoiceId, 8000.00, self::YEAR . '-06-12', ['source' => 'mark_paid']);
        self::assertTrue($markPaid['became_paid']);

        $tx = $this->transaction($this->statement(), 8000.00, ['variable_symbol' => '2099881101']);
        $match = $this->container->get(\MyInvoice\Service\Bank\StatementMatcher::class)->match($tx);
        self::assertSame('auto_exact', $match['status'] ?? null, json_encode($match, JSON_UNESCAPED_UNICODE));

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(8000.00, $this->bank311Credit($tx), 0.001);
        self::assertSame($tx, (int) $this->scalar('SELECT bank_transaction_id FROM invoice_payments WHERE id = ?', [$markPaid['payment_id']]));
        self::assertEqualsWithDelta(0.0, $this->receivable311([$invoiceId]), 0.001, 'saldo faktury i dobropisu je vyrovnané');
    }

    /** Totéž s ručně spárovaným pohybem: rekonciliace nesmí skončit v ruční frontě. */
    public function testMarkPaidAfterOffsetManualMatchIsPosted(): void
    {
        [$invoiceId] = $this->partiallyOffsetInvoice('2099881102', 10000.00, 2000.00);
        $this->payments()->recordPayment($invoiceId, 8000.00, self::YEAR . '-06-12', ['source' => 'mark_paid']);
        $tx = $this->transaction($this->statement(), 8000.00, ['match_status' => 'manual', 'matched_invoice_id' => $invoiceId]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(8000.00, $this->bank311Credit($tx), 0.001);
    }

    /**
     * Odběratel zaplatil původní částku dřív, než dostal dobropis. Ruční spárování
     * nesmí platbu oříznout na zbytek po zápočtu (allocation_mismatch): zápočet padá,
     * platba se zaeviduje celá a dobropis zůstává k vrácení.
     */
    public function testFullOriginalPaymentAfterOffsetReleasesOffsetOnManualMatch(): void
    {
        [$invoiceId, $creditNoteId] = $this->partiallyOffsetInvoice('2099881103', 10000.00, 2000.00);
        $tx = $this->transaction($this->statement(), 10000.00);

        $res = $this->callAction(
            $this->container->get(\MyInvoice\Action\Bank\BankStatementAction::class),
            'manualMatch',
            'POST',
            'admin',
            ['invoice_id' => $invoiceId],
            ['id' => (string) $tx],
        );

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('posted', $res['body']['posting']['action'] ?? null, json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(10000.00, $this->bank311Credit($tx), 0.001);
        self::assertSame('paid', $this->invoiceStatus($invoiceId));
        self::assertSame('issued', $this->invoiceStatus($creditNoteId), 'dobropis je zase k vrácení penězi');
        self::assertSame(0, $this->offsetCount($creditNoteId));
    }

    public function testFullOriginalPaymentAfterOffsetIsMatchedAutomatically(): void
    {
        [$invoiceId, $creditNoteId] = $this->partiallyOffsetInvoice('2099881104', 10000.00, 2000.00);
        $tx = $this->transaction($this->statement(), 10000.00, ['variable_symbol' => '2099881104']);

        $match = $this->container->get(\MyInvoice\Service\Bank\StatementMatcher::class)->match($tx);
        self::assertSame('auto_exact', $match['status'] ?? null, json_encode($match, JSON_UNESCAPED_UNICODE));
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(10000.00, $this->bank311Credit($tx), 0.001);
        self::assertSame('issued', $this->invoiceStatus($creditNoteId));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Faktura a dobropis s předpisy, ručně započtené. Zbývá uhradit = total − credit.
     *
     * @return array{0:int, 1:int} [faktura, dobropis]
     */
    private function partiallyOffsetInvoice(string $varsymbol, float $total, float $credit): array
    {
        $client = $this->client('Odběratel ' . $varsymbol);
        $invoiceId = $this->saleInvoice($varsymbol, $client, $total);
        $creditNoteId = $this->saleInvoice($varsymbol . '9', $client, -$credit, 'credit_note');
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')
            ->execute([$invoiceId, $creditNoteId]);
        $this->postPredpis('invoice', $invoiceId, '311', '602', $total);
        $this->postPredpis('invoice', $creditNoteId, '602', '311', $credit);

        $result = $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        self::assertEqualsWithDelta($total - $credit, $this->remaining($invoiceId), 0.001);
        return [$invoiceId, $creditNoteId];
    }

    private function payments(): \MyInvoice\Service\Invoice\InvoicePaymentService
    {
        return $this->container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class);
    }

    /**
     * Faktura s předpisem 311/602 a dobropis na plnou výši s předpisem 602/311,
     * ručně započtené. Faktura je zaplacená jedinou platbou `credit_note`.
     *
     * @return array{0:int, 1:int} [klient, faktura]
     */
    private function fullyOffsetInvoice(string $varsymbol, float $total): array
    {
        $client = $this->client('Odběratel ' . $varsymbol);
        $invoiceId = $this->saleInvoice($varsymbol, $client, $total);
        $creditNoteId = $this->saleInvoice($varsymbol . '-D', $client, -$total, 'credit_note');
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')
            ->execute([$invoiceId, $creditNoteId]);
        $this->postPredpis('invoice', $invoiceId, '311', '602', $total);
        $this->postPredpis('invoice', $creditNoteId, '602', '311', $total);

        $result = $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        self::assertSame('paid', $this->invoiceStatus($invoiceId));
        return [$client, $invoiceId];
    }

    private function bank311Credit(int $txId): float
    {
        return (float) $this->scalar(
            "SELECT COALESCE(SUM(l.amount), 0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.source_type = 'bank' AND e.source_id = ?
                AND e.reversed_by IS NULL AND l.side = 'credit' AND a.account_code LIKE '311%'",
            [$this->supplierId, $txId],
        );
    }

    /**
     * Zůstatek 311 ze všech zápisů daných faktur, jejich dobropisů a bankovních pohybů,
     * které je hradí.
     *
     * @param list<int> $invoiceIds
     */
    private function receivable311(array $invoiceIds): float
    {
        $place = implode(',', array_fill(0, count($invoiceIds), '?'));
        return (float) $this->scalar(
            "SELECT COALESCE(SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.reversed_by IS NULL AND a.account_code LIKE '311%'
                AND ((e.source_type = 'invoice' AND e.source_id IN (
                         SELECT id FROM invoices WHERE id IN ($place) OR parent_invoice_id IN ($place)))
                  OR (e.source_type = 'bank' AND e.source_id IN (
                         SELECT bank_transaction_id FROM invoice_payments WHERE invoice_id IN ($place))))",
            [$this->supplierId, ...$invoiceIds, ...$invoiceIds, ...$invoiceIds],
        );
    }

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
