<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * Vratka k přijatému dobropisu snižuje daňový výdaj ve stejném poměru daňová/nedaňová,
 * jaký má dobropis: u plátce DPH jen o základ (a o neodpočtenou část DPH), u neplátce
 * o celou částku. Zrcadlo CashJournalCreditNoteVatTest na příjmové straně.
 *
 * Dřív TaxExpenseAllocationCalculator::taxableAmount vracel pro záporné brutto nulu
 * a bankovní noha nebrala v úvahu směr pohybu, takže příchozí vratka nesnížila daňový
 * výdaj a nedaňový výdaj o ni naopak vzrostl.
 */
#[Group('integration')]
final class CashJournalPurchaseCreditNoteRefundTest extends CashJournalTestCase
{
    public function testBankRefundReducesExpenseByBaseOnlyForVatPayer(): void
    {
        $refund = $this->bankRefund($this->creditNote(-1000.0, -1210.0), 1210.0);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'bank', $refund);
        self::assertSame('expense_taxable', $row['bucket']);
        self::assertEqualsWithDelta(-1000.0, $row['base'], 0.001);
        self::assertEqualsWithDelta(-210.0, $row['vat'], 0.001);
        self::assertEqualsWithDelta(-1000.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-210.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    public function testBankRefundReducesExpenseByWholeAmountForNonPayer(): void
    {
        $this->bankRefund($this->creditNote(-1000.0, -1210.0), 1210.0);

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(-1210.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    public function testCashRefundReducesExpenseByBaseOnlyForVatPayer(): void
    {
        $creditNote = $this->creditNote(-100.0, -121.0);
        $cash = $this->cashDoc('in', 'purchase_payment', 121.0, ['purchase_invoice_id' => $creditNote]);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'cash', $cash);
        self::assertEqualsWithDelta(-100.0, $row['base'], 0.001);
        self::assertEqualsWithDelta(-21.0, $row['vat'], 0.001);
        self::assertEqualsWithDelta(-100.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-21.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    public function testManuallyPaidCreditNoteReducesExpenseByBaseOnly(): void
    {
        $this->creditNote(-1000.0, -1210.0, ['status' => 'paid', 'paid_at' => self::YEAR . '-06-20']);

        $result = $this->fullYear($this->supplierId, true);

        self::assertEqualsWithDelta(-1000.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-210.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    /**
     * Dobropis převzatý z cizího systému nebo přepnutý z faktury nese kladné součty. Znaménko
     * určuje druh dokladu (shodně s VatLedgerService), ne uložená částka: ručně uhrazený
     * dobropis výdaj snižuje. Dřív ho noha ručně zaplacených dokladů vedla jako výdaj.
     */
    public function testManuallyPaidCreditNoteWithPositiveTotalsReducesExpense(): void
    {
        $this->creditNote(1000.0, 1210.0, ['status' => 'paid', 'paid_at' => self::YEAR . '-06-20']);

        $result = $this->fullYear($this->supplierId, true);

        self::assertEqualsWithDelta(-1000.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-210.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    public function testManuallyPaidCreditNoteWithPositiveTotalsReducesExpenseForNonPayer(): void
    {
        $this->creditNote(1000.0, 1210.0, ['status' => 'paid', 'paid_at' => self::YEAR . '-06-20']);

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(-1210.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    /** Kladně uložený dobropis k odpisovanému majetku se posuzuje podle původní faktury. */
    public function testRefundOfPositiveCreditNoteToDepreciatedAssetStaysOutsideTaxExpense(): void
    {
        $asset = $this->purchaseInvoice($this->supplierId, [
            'without' => 100000.0, 'with' => 121000.0, 'is_fixed_asset' => 1,
        ]);
        $creditNote = $this->creditNote(1000.0, 1210.0, ['is_fixed_asset' => 1]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$asset, $creditNote]);
        $this->bankRefund($creditNote, 1210.0);

        $result = $this->fullYear($this->supplierId, true);

        self::assertEqualsWithDelta(0.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-1210.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    /** Krácený odpočet: neodpočtená polovina DPH je daňový výdaj, vratka ji snižuje taky. */
    public function testRefundOfProportionalDeductionKeepsTheSameRatio(): void
    {
        $creditNote = $this->creditNote(-1000.0, -1210.0, ['vat_deduction' => 'proportional', 'vat_deduction_percent' => 50]);
        $this->bankRefund($creditNote, 1210.0);

        $result = $this->fullYear($this->supplierId, true);

        self::assertEqualsWithDelta(-1105.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-105.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    public function testRefundOfPartlyTaxDeductibleCreditNoteForVatPayer(): void
    {
        $this->bankRefund($this->splitCreditNote(), 1210.0);

        $result = $this->fullYear($this->supplierId, true);

        self::assertEqualsWithDelta(-600.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-610.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    public function testRefundOfPartlyTaxDeductibleCreditNoteForNonPayer(): void
    {
        $this->bankRefund($this->splitCreditNote(), 1210.0);

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(-726.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-484.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    /** Dobropis k odpisovanému majetku mění vstupní cenu, ne peněžní výdaj. */
    public function testRefundOfCreditNoteToDepreciatedAssetStaysOutsideTaxExpense(): void
    {
        $asset = $this->purchaseInvoice($this->supplierId, [
            'without' => 100000.0, 'with' => 121000.0, 'is_fixed_asset' => 1,
        ]);
        $creditNote = $this->creditNote(-1000.0, -1210.0, ['is_fixed_asset' => 1]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$asset, $creditNote]);
        $this->bankRefund($creditNote, 1210.0);

        $result = $this->fullYear($this->supplierId, true);

        self::assertEqualsWithDelta(0.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-1210.0, $result['totals']['vydaj_nedanovy'], 0.001);
    }

    /** @param array<string,mixed> $opts */
    private function creditNote(float $without, float $with, array $opts = []): int
    {
        return $this->purchaseInvoice($this->supplierId, ['document_kind' => 'credit_note', 'without' => $without, 'with' => $with] + $opts);
    }

    private function bankRefund(int $creditNote, float $amount): int
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, $amount, ['match_status' => 'manual']);
        $this->paymentMatch($this->supplierId, $tx, $creditNote, $amount);
        return $tx;
    }

    /** Dobropis −1 210: daňová část −726 (základ −600), nedaňová −484 (základ −400). */
    private function splitCreditNote(): int
    {
        $creditNote = $this->creditNote(-1000.0, -1210.0);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, '518', 'Ostatní služby', 'expense', 'debit', 1, 1)"
        )->execute([$this->supplierId]);
        $insert = $pdo->prepare(
            "INSERT INTO purchase_invoice_vat_allocations
                (supplier_id, purchase_invoice_id, description, vat_rate, base_amount, vat_amount, total_amount,
                 tax_treatment, account_code, order_index)
             VALUES (?, ?, ?, 21.00, ?, ?, ?, ?, '518', ?)"
        );
        $insert->execute([$this->supplierId, $creditNote, 'Služba', -600.0, -126.0, -726.0, 'deductible', 1]);
        $insert->execute([$this->supplierId, $creditNote, 'Reprezentace', -400.0, -84.0, -484.0, 'non_deductible', 2]);
        return $creditNote;
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
}
