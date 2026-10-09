<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Service\Accounting\GoPay\GoPayException;
use MyInvoice\Service\Accounting\GoPay\GoPayService;
use PHPUnit\Framework\Attributes\Group;

/**
 * GoPay v daňové evidenci: příjem vzniká inkasem platby přes GoPay (úhrada faktury ke dni
 * platby), výplata vyúčtování na bankovní účet je převod mezi vlastními prostředky a
 * poplatky jsou daňový výdaj ke dni srážky. GoPay je v deníku samostatný peněžní prostředek
 * se zůstatkem, který se po výplatě vyrovná na nulu. Nic se nepočítá dvakrát.
 */
#[Group('integration')]
final class GoPayTaxEvidenceTest extends CashJournalTestCase
{
    private const PAYOUT_ACCOUNT = '1000000005';

    private GoPayService $gopay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gopay = $this->container->get(GoPayService::class);
    }

    public function testPaymentPayoutAndFeesAreCountedOnceInCashJournal(): void
    {
        $settings = $this->configure();
        self::assertTrue($settings['configured']);
        self::assertSame('tax_evidence', $settings['mode']);
        self::assertNull($settings['settings']['gopay_account_id']);

        [$invoiceId, $creditNoteId] = $this->documents();
        $paymentId = $this->gopayPayment($invoiceId);
        $bankTxId = $this->bankPayout();

        $import = $this->gopay->import($this->supplierId, $this->userId, 'synthetic.xml', $this->xml());
        $clearing = $import['clearing'];
        self::assertSame('processed', $clearing['status']);
        self::assertSame(0, $clearing['issue_count']);
        self::assertSame($bankTxId, $clearing['bank_transaction_id']);
        self::assertNull($clearing['bank_journal_entry_id']);
        foreach ($clearing['movements'] as $movement) {
            self::assertSame('posted', $movement['status']);
            self::assertNull($movement['journal_entry_id']);
        }
        self::assertSame($paymentId, $clearing['movements'][0]['invoice_payment_id']);
        self::assertSame($creditNoteId, $clearing['movements'][1]['credit_note_id']);
        $journalEntries = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $journalEntries->execute([$this->supplierId]);
        self::assertSame(0, (int) $journalEntries->fetchColumn());

        $classification = $this->container->get(MovementClassificationRepository::class)
            ->find($this->supplierId, 'bank', $bankTxId);
        self::assertSame('transfer', $classification['tax_bucket'] ?? null);

        $result = $this->fullYear($this->supplierId, false);

        // Příjem = platba přes GoPay ke dni inkasa, snížená o vratku k dobropisu.
        self::assertEqualsWithDelta(900.0, $result['totals']['prijem_danovy'], 0.001);
        // Výdaj = poplatky (zpracování 20 + vratka 5), nic dalšího.
        self::assertEqualsWithDelta(25.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['totals']['prijem_nedanovy'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['totals']['vydaj_nedanovy'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['totals']['nezarazeno'], 0.001);
        self::assertSame([], array_values(array_filter($result['warnings'], static fn (array $w): bool => !empty($w['blocking']))));

        $payment = $this->row($result, 'invoice_payment', $paymentId);
        self::assertSame('gopay', $payment['instrument']);
        self::assertSame('income_taxable', $payment['bucket']);
        self::assertSame(self::YEAR . '-01-15', $payment['date']);

        $bank = $this->row($result, 'bank', $bankTxId);
        self::assertSame('transfer', $bank['bucket']);
        self::assertSame($clearing['id'], $bank['gopay_clearing_id']);

        $byType = [];
        foreach ($result['rows'] as $row) {
            if ($row['source_type'] === 'gopay') {
                $byType[] = [$row['bucket'], $row['direction'], $row['income'] ?? $row['expense']];
            }
        }
        // Kreditní pohyb vyúčtování v deníku není: jeho příjem nese úhrada faktury.
        self::assertEqualsCanonicalizing([
            ['income_taxable', 'out', 100.0],
            ['expense_taxable', 'out', 5.0],
            ['expense_taxable', 'out', 20.0],
            ['transfer', 'out', 875.0],
        ], $byType);

        $balances = array_column($result['balances'], null, 'instrument');
        self::assertEqualsWithDelta(0.0, $balances['gopay']['closing'], 0.001);
        self::assertEqualsWithDelta(875.0, $balances['bank']['closing'], 0.001);
        self::assertEqualsWithDelta($result['closing_balance'], array_sum(array_column($result['balances'], 'closing')), 0.001);

        // Do vyúčtování: peníze leží u GoPay, zůstatek prostředku GoPay = přijatá platba.
        $january = $this->service->build($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-01-15', ['year' => self::YEAR]);
        $janBalances = array_column($january['balances'], null, 'instrument');
        self::assertEqualsWithDelta(1000.0, $janBalances['gopay']['closing'], 0.001);
    }

    public function testManualIncomeClassificationOfPayoutCannotCountIncomeTwice(): void
    {
        $this->configure();
        [$invoiceId] = $this->documents();
        $this->gopayPayment($invoiceId);
        $bankTxId = $this->bankPayout();
        $this->gopay->import($this->supplierId, $this->userId, 'synthetic.xml', $this->xml());

        $this->container->get(MovementClassificationRepository::class)
            ->upsert($this->supplierId, 'bank', $bankTxId, 'income_taxable', 'ručně', $this->userId);

        $result = $this->fullYear($this->supplierId, false);
        self::assertSame('transfer', $this->row($result, 'bank', $bankTxId)['bucket']);
        self::assertEqualsWithDelta(900.0, $result['totals']['prijem_danovy'], 0.001);
    }

    public function testDeletingClearingReturnsPayoutToUnclassifiedAndDropsGoPayRows(): void
    {
        $this->configure();
        [$invoiceId] = $this->documents();
        $this->gopayPayment($invoiceId);
        $bankTxId = $this->bankPayout();
        $import = $this->gopay->import($this->supplierId, $this->userId, 'synthetic.xml', $this->xml());

        $this->gopay->delete($this->supplierId, (int) $import['clearing']['id'], $this->userId);

        self::assertNull($this->container->get(MovementClassificationRepository::class)
            ->find($this->supplierId, 'bank', $bankTxId));
        $result = $this->fullYear($this->supplierId, false);
        self::assertSame(0, $this->countRows($result, 'gopay'));
        self::assertSame('nezarazeno', $this->row($result, 'bank', $bankTxId)['bucket']);
        self::assertEqualsWithDelta(1000.0, $result['totals']['prijem_danovy'], 0.001);
    }

    public function testUnmatchedGoPayCreditBlocksInsteadOfDisappearing(): void
    {
        $this->configure();
        $this->documents();
        $this->bankPayout();

        $import = $this->gopay->import($this->supplierId, $this->userId, 'synthetic.xml', $this->xml());
        self::assertSame('needs_review', $import['clearing']['status']);
        self::assertSame('unmatched', $import['clearing']['movements'][0]['status']);

        $result = $this->fullYear($this->supplierId, false);
        self::assertEqualsWithDelta(1000.0, $result['totals']['nezarazeno'], 0.001);
        $blocking = array_values(array_filter($result['warnings'], static fn (array $w): bool => !empty($w['blocking'])));
        self::assertCount(1, $blocking);
        self::assertSame('gopay', $blocking[0]['source_type']);
    }

    public function testFinalTaxEvidenceClosingLocksImport(): void
    {
        $this->configure();
        $this->db->pdo()->prepare(
            "INSERT INTO tax_evidence_closings (supplier_id, year, status, checklist, opening_balances, closing_balances, unsupported_cases)
             VALUES (?, ?, 'final', '{}', '{}', '{}', '[]')"
        )->execute([$this->supplierId, self::YEAR]);

        try {
            $this->gopay->import($this->supplierId, $this->userId, 'synthetic.xml', $this->xml());
            self::fail('Import do roku s dokončenou uzávěrkou musí selhat.');
        } catch (GoPayException $e) {
            self::assertSame('closing_final', $e->errorCode);
        }
    }

    /** @return array<string,mixed> */
    private function configure(): array
    {
        return $this->gopay->saveSettings($this->supplierId, [
            'currency' => 'CZK',
            'payout_account_number' => self::PAYOUT_ACCOUNT,
            'payout_bank_code' => '0100',
            'payout_date_tolerance_days' => 3,
        ], $this->userId);
    }

    /** @return array{int,int} */
    private function documents(): array
    {
        $invoiceId = $this->saleInvoice($this->supplierId, [
            'without' => 1000.0, 'with' => 1000.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-01-15',
            'issue_date' => self::YEAR . '-01-10',
        ]);
        $creditNoteId = $this->saleInvoice($this->supplierId, [
            'type' => 'credit_note', 'without' => -100.0, 'with' => -100.0, 'status' => 'sent',
            'issue_date' => self::YEAR . '-01-20',
        ]);
        $this->db->pdo()->prepare('UPDATE invoices SET supplier_order_number = ? WHERE id IN (?, ?)')
            ->execute(['TEST000001', $invoiceId, $creditNoteId]);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')
            ->execute([$invoiceId, $creditNoteId]);
        return [$invoiceId, $creditNoteId];
    }

    private function gopayPayment(int $invoiceId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, bank_reference, source, created_by)
             VALUES (?, ?, ?, 1000, "CZK", "GOPAY:1000000001", "mark_paid", ?)'
        )->execute([$this->supplierId, $invoiceId, self::YEAR . '-01-15', $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function bankPayout(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, "gpc", "gopay-test.gpc", ?, ?, "2010", "CZK", ?, ?)'
        )->execute([$this->supplierId, hash('sha256', uniqid('gopay-de', true)), $this->accountA, self::YEAR . '-02-01', $this->userId]);
        $statementId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol, counterparty_account,
                 counterparty_bank, counterparty_name, description, match_status)
             VALUES (?, "statement", ?, 875, "CZK", "20990001", ?, "0100", "GoPay", "Clearing", "unmatched")'
        )->execute([$statementId, self::YEAR . '-02-01', self::PAYOUT_ACCOUNT]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function row(array $result, string $sourceType, int $sourceId): array
    {
        foreach ($result['rows'] as $row) {
            if ($row['source_type'] === $sourceType && $row['source_id'] === $sourceId) {
                return $row;
            }
        }
        self::fail("Řádek {$sourceType}#{$sourceId} v deníku chybí.");
    }

    private function xml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<clearing xmlns="https://www.gopay.cz/clearing" accountName="Test CZK" amount="1000.00"
 amountCreditNote="0.00" amountFee="20.00" amountFeeExternal="10.00" amountSent="875.00"
 amountStorno="100.00" amountStornoFee="5.00" amountTransfer="875.00"
 clearingId="TEST-CLEARING-DE-2099" dateClearedFrom="01.01.2099" dateClearedTo="31.01.2099"
 datePerformed="01.02.2099" variableSymbol="20990001">
 <paymentChannel fee="10.00" transactionFee="10.00" type="test" volumeFee="0.00"><movements>
  <movement accountMovementId="TEST-MOVE-DE-2099-1" amount="1000.00" counterpartyName="test"
   datePerformed="15.01.2099" orderId="TEST000001" paymentSessionId="1000000001" type="credit"/>
 </movements></paymentChannel>
 <storno>
  <stornoMovement accountMovementId="TEST-MOVE-DE-2099-2" amount="-100.00" counterpartyName="GOPAY"
   datePerformed="20.01.2099" orderId="TEST000001" paymentSessionId="1000000001" type="storno"/>
  <stornoMovement accountMovementId="TEST-MOVE-DE-2099-3" amount="-5.00" counterpartyName="GOPAY"
   datePerformed="20.01.2099" orderId="TEST000001" paymentSessionId="1000000001" type="stornoFee"/>
 </storno>
</clearing>
XML;
    }
}
