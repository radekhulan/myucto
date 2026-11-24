<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\BankPostingRuleValidator;
use MyInvoice\Service\Accounting\Bank\StaleSuggestionSweep;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Import\ImportedPaidInvoicePayment;
use MyInvoice\Tests\Support\AnalyticAccountFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Spárované platby FV/PF → přímý zápis (guard předpisu H1 + M1). §8.
 */
#[Group('integration')]
final class BankPostingServiceMatchedTest extends BankPostingTestCase
{
    use AnalyticAccountFixture;

    public function testIncomingFvPosts221Over311(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-2099-1', $client, 5000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 5000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 5000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 5000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(5000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['311']['credit'], 0.001);
    }

    public function testOutgoingPfPosts321Over221(): void
    {
        $vendor = $this->client('Dodavatel s.r.o.');
        $pf = $this->purchaseInvoice('PF-2099-1', $vendor, 3000.00);
        $this->postPredpis('purchase_invoice', $pf, '501', '321', 3000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -3000.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 3000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(3000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(3000.00, $byAcc['221']['credit'], 0.001);
    }

    public function testProformaPaymentPostsAdvanceCollection(): void
    {
        // B5: platba proformy = inkaso přijaté zálohy 221/324 (dřív blanket skip
        // 'document_not_posted' — proforma se neúčtovala vůbec).
        $client = $this->client('Odběratel s.r.o.');
        $pro = $this->saleInvoice('PRO-2099-1', $client, 4000.00, 'proforma');
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $pro]);
        $this->invoicePayment($pro, $tx, 4000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(4000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(4000.00, $byAcc['324']['credit'], 0.001);
        self::assertArrayNotHasKey('311', $byAcc, 'Proforma nesmí zakládat saldokonto 311.');
    }

    public function testSuggestedMatchedPaymentApprovalRebuildsExactAllocations(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE auto_posting_policy SET level = 'suggest'
              WHERE supplier_id = ? AND operation_type = 'bank.payment.matched'"
        )->execute([$this->supplierId]);

        $client = $this->client('Odběratel kombinované platby');
        $invoice = $this->saleInvoice('FV-2099-POLICY', $client, 3000.00);
        $proforma = $this->saleInvoice('PRO-2099-POLICY', $client, 2000.00, 'proforma');
        $this->postPredpis('invoice', $invoice, '311', '602', 3000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 5000.00, ['match_status' => 'manual']);
        $this->invoicePayment($invoice, $tx, 3000.00);
        $this->invoicePayment($proforma, $tx, 2000.00);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        $entryId = $this->service->approveSuggestion(
            $this->supplierId,
            (int) $result['suggestion_id'],
            ['user_id' => $this->userId],
        );

        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(5000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(3000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(2000.00, $byAcc['324']['credit'], 0.001);
    }

    public function testFvWithoutPostedPredpisIsSkipped(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-2099-2', $client, 2500.00); // žádný předpis v deníku
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 2500.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 2500.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('document_not_posted', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertSame(0, $this->suggestionCountForTx($tx));
    }

    public function testFvWithReversedPredpisIsSkipped(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-2099-3', $client, 2500.00);
        $predpis = $this->postPredpis('invoice', $inv, '311', '602', 2500.00);
        $this->posting->reverse($this->supplierId, $predpis, ['entry_date' => self::YEAR . '-06-11', 'user_id' => $this->userId]);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 2500.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 2500.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('document_not_posted', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testPaidInvoiceWithoutPaymentRowSuggestsVerify(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-2099-4', $client, 1800.00, 'invoice', 'paid');
        $this->postPredpis('invoice', $inv, '311', '602', 1800.00);

        $stmt = $this->statement();
        // paid ∧ žádný invoice_payments řádek → člověk ověří (M1).
        $tx = $this->transaction($stmt, 1800.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('already_paid_verify', $res['reason']);
        $sug = $this->suggestionRow((int) $res['suggestion_id']);
        self::assertSame('payment_match', $sug['source']);
        self::assertSame('already_paid_verify', $sug['note']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testPaidInvoiceWithUniqueLegacyPaymentIsReconciledAndPosted(): void
    {
        $client = $this->client('Odběratel s historickou úhradou');
        $inv = $this->saleInvoice('FV-2099-LEGACY', $client, 12100.00, 'invoice', 'paid');
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 12100, paid_at = ? WHERE id = ?')
            ->execute([self::YEAR . '-06-12', $inv]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, 12100, "CZK", "legacy", NULL)'
        )->execute([$this->supplierId, $inv, self::YEAR . '-06-12']);
        $paymentId = (int) $this->db->pdo()->lastInsertId();
        $this->postPredpis('invoice', $inv, '311', '602', 12100.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 12100.00, [
            'match_status' => 'auto_exact',
            'matched_invoice_id' => $inv,
            'variable_symbol' => 'FV2099LEGACY',
        ]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action']);
        self::assertSame($tx, (int) $this->db->pdo()->query(
            "SELECT bank_transaction_id FROM invoice_payments WHERE id={$paymentId}"
        )->fetchColumn());
        self::assertSame(1, $this->entryCountForTx($tx));
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(12100.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(12100.00, $byAcc['311']['credit'], 0.001);
    }

    public function testImportedPaidInvoiceGetsLegacyPaymentAndMatchedTransferPosts(): void
    {
        $client = $this->client('Odběratel z Fakturoidu');
        $inv = $this->saleInvoice('FV-2099-FAKTUROID', $client, 7260.00, 'invoice', 'paid');
        $this->db->pdo()->prepare('UPDATE invoices SET paid_at = ?, fakturoid_id = 990108 WHERE id = ?')
            ->execute([self::YEAR . '-06-14', $inv]);

        ImportedPaidInvoicePayment::record($this->db->pdo(), $inv);
        ImportedPaidInvoicePayment::record($this->db->pdo(), $inv);

        $payments = $this->db->pdo()->query(
            "SELECT source, amount, paid_on, bank_transaction_id FROM invoice_payments WHERE invoice_id = {$inv}"
        )->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $payments);
        self::assertSame('legacy', $payments[0]['source']);
        self::assertEqualsWithDelta(7260.00, (float) $payments[0]['amount'], 0.001);
        self::assertSame(self::YEAR . '-06-14', $payments[0]['paid_on']);
        self::assertEqualsWithDelta(7260.00, (float) $this->db->pdo()->query(
            "SELECT paid_total FROM invoices WHERE id = {$inv}"
        )->fetchColumn(), 0.001);

        $this->postPredpis('invoice', $inv, '311', '602', 7260.00);
        $tx = $this->transaction($this->statement(), 7260.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(7260.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(7260.00, $byAcc['311']['credit'], 0.001);
    }

    public function testImportedPaidInvoiceCoveredByAdvanceGetsNoPayment(): void
    {
        $client = $this->client('Odběratel se zálohou');
        $inv = $this->saleInvoice('FV-2099-ZALOHA', $client, 1500.00, 'invoice', 'paid');
        $this->db->pdo()->prepare('UPDATE invoices SET advance_paid_amount = total_with_vat WHERE id = ?')
            ->execute([$inv]);

        ImportedPaidInvoicePayment::record($this->db->pdo(), $inv);

        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$inv}"
        )->fetchColumn());
    }

    public function testAlreadyPaidVerifySuggestionIsApprovedOncePredpisIsPosted(): void
    {
        [$inv, $paymentId, $tx, $suggestionId] = $this->legacyPaidTransferBeforePredpis('FV-2099-APPROVE');

        $preview = $this->service->previewTransaction($this->supplierId, $tx);
        self::assertNull($preview['reason']);
        self::assertNotSame([], $preview['lines']);
        self::assertNull($this->paymentTxId($paymentId), 'Náhled nesmí platbu navázat natrvalo.');

        $entryId = $this->service->approveSuggestion($this->supplierId, $suggestionId, $this->meta());

        self::assertSame($tx, $this->paymentTxId($paymentId));
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(3025.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(3025.00, $byAcc['311']['credit'], 0.001);
    }

    public function testStaleSweepPostsAlreadyPaidVerifyOncePredpisIsPosted(): void
    {
        [, $paymentId, $tx] = $this->legacyPaidTransferBeforePredpis('FV-2099-SWEEP');

        $report = $this->container->get(StaleSuggestionSweep::class)->run(false, $this->supplierId);

        self::assertSame(1, $report['posted'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame($tx, $this->paymentTxId($paymentId));
        self::assertSame(1, $this->entryCountForTx($tx));
    }

    /**
     * Zaplacená faktura s historickou úhradou, jejíž pohyb se vyhodnotil DŘÍV, než se
     * zaúčtoval předpis — rekonciliace tehdy neprojde a vznikne `already_paid_verify`.
     *
     * @return array{0:int,1:int,2:int,3:int} [faktura, platba, pohyb, návrh]
     */
    private function legacyPaidTransferBeforePredpis(string $varsymbol): array
    {
        $client = $this->client('Odběratel ' . $varsymbol);
        $inv = $this->saleInvoice($varsymbol, $client, 3025.00, 'invoice', 'paid');
        $this->db->pdo()->prepare('UPDATE invoices SET paid_at = ?, fakturoid_id = ? WHERE id = ?')
            ->execute([self::YEAR . '-06-12', crc32($varsymbol), $inv]);
        ImportedPaidInvoicePayment::record($this->db->pdo(), $inv);
        $paymentId = (int) $this->db->pdo()->query(
            "SELECT id FROM invoice_payments WHERE invoice_id = {$inv}"
        )->fetchColumn();

        $tx = $this->transaction($this->statement(), 3025.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('already_paid_verify', $res['reason']);

        $this->postPredpis('invoice', $inv, '311', '602', 3025.00);

        return [$inv, $paymentId, $tx, (int) $res['suggestion_id']];
    }

    private function paymentTxId(int $paymentId): ?int
    {
        $value = $this->db->pdo()->query(
            "SELECT bank_transaction_id FROM invoice_payments WHERE id = {$paymentId}"
        )->fetchColumn();
        return $value === null ? null : (int) $value;
    }

    #[TestWith(['311'])]
    #[TestWith(['311.300'])]
    public function testOutgoingIssuedCreditNoteRefundPosts311Over221(string $saldo): void
    {
        if ($saldo !== '311') {
            $this->seedAnalyticAccounts($this->db->pdo(), $this->supplierId, $saldo, '311.301');
        }
        $client = $this->client('Odběratel dobropisu');
        $credit = $this->saleInvoice('ODD-2099-EP8', $client, -1000.00, 'credit_note');
        $this->postPredpis('invoice', $credit, '602', $saldo, 1000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['match_status' => 'manual', 'matched_invoice_id' => $credit]);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, amount, match_type)
             VALUES (?, ?, ?, 1000, "manual")'
        )->execute([$this->supplierId, $tx, $credit]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(1000.00, $byAcc[$saldo]['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['221']['credit'], 0.001);
    }

    #[TestWith(['321'])]
    #[TestWith(['321.200'])]
    public function testIncomingPurchaseCreditNoteRefundPosts221Over321(string $saldo): void
    {
        if ($saldo !== '321') {
            $this->seedAnalyticAccounts($this->db->pdo(), $this->supplierId, $saldo, '321.201');
        }
        $vendor = $this->client('Dodavatel dobropisu');
        $credit = $this->purchaseInvoice('ODD-PF-2099-EP8', $vendor, -800.00, 'credit_note');
        $this->postPredpis('purchase_invoice', $credit, $saldo, '501', 800.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 800.00, ['match_status' => 'manual']);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, 800, "manual")'
        )->execute([$this->supplierId, $tx, $credit]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(800.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(800.00, $byAcc[$saldo]['credit'], 0.001);
    }

    public function testForeignRuleSuggestionApprovalUsesCzkRateAndFxTrace(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, "EUR", 25.00)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::YEAR . '-06-15']);
        $this->rule([
            'name' => 'Úrok EUR',
            'direction' => 'incoming',
            'counterparty_account' => '55501',
            'amount_min' => 1,
            'amount_max' => 200,
            'debit_account_code' => '221',
            'credit_account_code' => '662',
            'mode' => 'suggest',
            'applies_currency' => 'EUR',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 100.00, [
            'currency' => 'EUR',
            'counterparty_account' => '55501',
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        $suggestion = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertEqualsWithDelta(2500.00, (float) $suggestion['amount'], 0.001);
        self::assertEqualsWithDelta(0.70, (float) $suggestion['confidence'], 0.001);

        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $result['suggestion_id'], $this->meta());
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(2500.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(2500.00, $byAcc['662']['credit'], 0.001);
        $trace = $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id={$entryId}
              AND currency_code='EUR' AND fx_rate=25 AND amount_foreign=100"
        )->fetchColumn();
        self::assertSame(2, (int) $trace);
    }

    /**
     * Eurový faktoring: záloha od faktora MD 221 / D 379 v EUR. Pravidlo se uloží
     * i zaúčtuje a obě nohy nesou FX stopu, aby závěrka zůstatek 379 přecenila.
     */
    public function testForeignRuleMayPostToOtherLiabilities379WithFxTrace(): void
    {
        $validator = $this->container->get(BankPostingRuleValidator::class);
        $normalized = $validator->normalizeRule($this->supplierId, [
            'name' => 'Faktoring EUR',
            'direction' => 'incoming',
            'counterparty_account' => '55502',
            'debit_account_code' => '221',
            'credit_account_code' => '379',
            'applies_currency' => 'EUR',
        ]);
        self::assertSame('379', $normalized['credit_account_code']);
        self::assertSame('EUR', $normalized['applies_currency']);

        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, "EUR", 25.00)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::YEAR . '-06-15']);
        $this->rule([
            'name' => 'Faktoring EUR',
            'direction' => 'incoming',
            'counterparty_account' => '55502',
            'debit_account_code' => '221',
            'credit_account_code' => '379',
            'mode' => 'suggest',
            'applies_currency' => 'EUR',
        ]);
        $tx = $this->transaction($this->statement(), 400.00, [
            'currency' => 'EUR',
            'counterparty_account' => '55502',
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $result['suggestion_id'], $this->meta());
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(10000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(10000.00, $byAcc['379']['credit'], 0.001);
        $trace = $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id={$entryId}
              AND currency_code='EUR' AND fx_rate=25 AND amount_foreign=400"
        )->fetchColumn();
        self::assertSame(2, (int) $trace);
    }

    /** Saldokonto se dál páruje a nepeněžní/rozvahové účty mimo seznam přecenění zůstávají zakázané. */
    public function testForeignRuleStillRejectsOpenItemAndUnrevaluedBalanceAccounts(): void
    {
        $validator = $this->container->get(BankPostingRuleValidator::class);
        foreach (['311' => 'rule_saldo_forbidden', '324' => 'rule_saldo_forbidden', '411' => 'fx_rule_account_forbidden'] as $code => $error) {
            try {
                $validator->normalizeRule($this->supplierId, [
                    'direction' => 'incoming',
                    'counterparty_account' => '55503',
                    'debit_account_code' => '221',
                    'credit_account_code' => $code,
                    'applies_currency' => 'EUR',
                ]);
                self::fail("Účet {$code} nesmí projít cizoměnovým pravidlem.");
            } catch (PostingException $e) {
                self::assertSame($error, $e->errorCode, "Účet {$code}");
            }
        }
    }
}
