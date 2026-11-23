<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Service\Bank\Match\MatchCandidateProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Přijatá účtenka v cizí měně uhrazená korunovou platbou z banky (#39).
 *
 * Účtenka se hradí stejně jako cizoměnová faktura: korunový pohyb ji nabídne mezi
 * kandidáty, jde spárovat ručně i sloučeně s dalšími doklady a zaúčtuje se 321 MD
 * v kurzu předpisu, 221 D ve skutečné korunové částce a rozdíl jde na 563/663.
 */
#[Group('integration')]
final class FxReceiptBankPaymentTest extends BankPostingTestCase
{
    private int $eurId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eurId = (int) ($this->db->pdo()->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'EUR' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0) ?: $this->currencyRow($this->supplierId, 'EUR');
    }

    /** Zaplaceno méně korun, než činil závazek v kurzu předpisu: kurzový zisk 663. */
    public function testCzkPaymentOfEurReceiptIsOfferedMatchedAndBooksGain(): void
    {
        $receipt = $this->eurReceipt('UCT-EUR-GAIN', 100.00, 25.00);
        $tx = $this->transaction($this->statement(), -2450.00, ['currency' => 'CZK', 'counterparty_name' => 'Kavárna Test']);

        self::assertContains($receipt, $this->candidateIds($tx), 'Korunová platba musí nabídnout cizoměnovou účtenku.');

        $result = $this->match($tx, ['purchase_invoice_id' => $receipt]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame('paid', $this->documentStatus($receipt));

        $byAcc = $this->linesByAccountCode($this->postedEntry($tx));
        self::assertEqualsWithDelta(2500.00, $byAcc['321']['debit'], 0.001, '321 MD = 100 EUR × kurz předpisu 25.');
        self::assertEqualsWithDelta(2450.00, $byAcc['221']['credit'], 0.001, '221 D = skutečná korunová platba.');
        self::assertEqualsWithDelta(50.00, $byAcc['663']['credit'], 0.001, '663 D = kurzový zisk.');
        self::assertArrayNotHasKey('563', $byAcc);
    }

    /** Zaplaceno víc korun, než činil závazek v kurzu předpisu: kurzová ztráta 563. */
    public function testCzkPaymentOfEurReceiptBooksLoss(): void
    {
        $receipt = $this->eurReceipt('UCT-EUR-LOSS', 100.00, 25.00);
        $tx = $this->transaction($this->statement(), -2560.00, ['currency' => 'CZK', 'counterparty_name' => 'Kavárna Test']);

        self::assertContains($receipt, $this->candidateIds($tx));
        $result = $this->match($tx, ['purchase_invoice_id' => $receipt]);
        self::assertSame(200, $result['status'], json_encode($result['body']));

        $byAcc = $this->linesByAccountCode($this->postedEntry($tx));
        self::assertEqualsWithDelta(2500.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(2560.00, $byAcc['221']['credit'], 0.001);
        self::assertEqualsWithDelta(60.00, $byAcc['563']['debit'], 0.001, '563 MD = kurzová ztráta.');
        self::assertArrayNotHasKey('663', $byAcc);
    }

    /** Jedna korunová platba za dvě cizoměnové účtenky: návrh, sloučené párování i zaúčtování. */
    public function testCzkPaymentSettlesTwoEurReceiptsTogether(): void
    {
        $first = $this->eurReceipt('UCT-EUR-SPLIT-1', 20.00, 25.00);
        $second = $this->eurReceipt('UCT-EUR-SPLIT-2', 30.00, 24.00);
        $tx = $this->transaction($this->statement(), -1240.00, ['currency' => 'CZK', 'counterparty_name' => 'Kavárna Test']);

        $suggestions = $this->callAction($this->action(), 'splitSuggestions', 'GET', 'admin', [], ['id' => (string) $tx]);
        $pairs = array_map(
            static function (array $suggestion): array {
                $ids = array_map(static fn (array $doc): int => (int) $doc['id'], $suggestion['invoices'] ?? []);
                sort($ids);
                return $ids;
            },
            $suggestions['body']['suggestions'] ?? [],
        );
        $expected = [$first, $second];
        sort($expected);
        self::assertContains($expected, $pairs, 'Sloučený návrh musí nabídnout obě cizoměnové účtenky.');

        $result = $this->match($tx, ['purchase_invoice_ids' => [$first, $second]]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame('paid', $this->documentStatus($first));
        self::assertSame('paid', $this->documentStatus($second));

        $entryId = $this->postedEntry($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(1220.00, $byAcc['321']['debit'], 0.001, '20 × 25 + 30 × 24 v kurzech předpisů.');
        self::assertEqualsWithDelta(1240.00, $byAcc['221']['credit'], 0.001);
        self::assertEqualsWithDelta(20.00, $byAcc['563']['debit'], 0.001);
    }

    /** Účtenka v EUR se zaúčtovaným předpisem 518/321 s cizoměnovou stopou na závazku. */
    private function eurReceipt(string $number, float $foreign, float $rate): int
    {
        $issue = self::YEAR . '-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, payment_method, status, created_by)
             VALUES (?, ?, ?, "{}", "receipt", "full", ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "card", "booked", ?)'
        )->execute([
            $this->supplierId, $this->client('Kavárna Test'), $number, $issue, $issue, $issue, $issue,
            $this->eurId, $rate, $foreign, $foreign, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $czk = round($foreign * $rate, 2);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => $issue,
            'document_no' => 'PREDPIS-' . $number,
            'description' => 'Předpis účtenky EUR',
            'source_type' => 'purchase_invoice',
            'source_id'   => $id,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['518']['id'], 'side' => 'debit', 'amount' => $czk],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => $czk,
             'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreign],
        ]);
        return $id;
    }

    /** @return list<int> */
    private function candidateIds(int $txId): array
    {
        $tx = $this->db->pdo()->query("SELECT * FROM bank_transactions WHERE id = {$txId}")->fetch(\PDO::FETCH_ASSOC);
        $candidates = $this->container->get(MatchCandidateProvider::class)->candidatesFor($this->supplierId, $tx);
        return array_values(array_filter(array_map(
            static fn (array $candidate): int => (int) ($candidate['purchase_invoice_id'] ?? 0),
            $candidates,
        )));
    }

    /** @param array<string,mixed> $body @return array{status:int, body:array<string,mixed>} */
    private function match(int $txId, array $body): array
    {
        return $this->callAction($this->action(), 'manualMatch', 'POST', 'admin', $body, ['id' => (string) $txId]);
    }

    private function action(): BankStatementAction
    {
        return $this->container->get(BankStatementAction::class);
    }

    /** Zápis banky po spárování; pokud ho párování nezaúčtovalo samo, zaúčtuje ho služba. */
    private function postedEntry(int $txId): int
    {
        $entryId = $this->entryId($txId);
        if ($entryId === 0) {
            $res = $this->service->handleTransaction($txId, $this->userId);
            self::assertSame('posted', $res['action'], json_encode($res));
            $entryId = $this->entryId($txId);
        }
        self::assertGreaterThan(0, $entryId);
        return $entryId;
    }

    private function entryId(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'bank' AND source_id = {$txId} AND reversed_by IS NULL LIMIT 1"
        )->fetchColumn();
    }

    private function documentStatus(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = {$id}")->fetchColumn();
    }
}
