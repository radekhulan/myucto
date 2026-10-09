<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * Vratka k dobropisu plátce DPH snižuje daňový příjem jen o základ, DPH z dobropisu je
 * nedaňová. Dobropis má součty záporné; dřív prorata v peněžním deníku pro záporné brutto
 * DPH nevyloučila a příjem klesl o celé brutto (TaxProfileRepository to počítá správně,
 * viz CashJournalTaxProjectionIncomeTest::testPaidCreditNoteExcludesItsVatFromTaxProfileIncomeAndExpense).
 */
#[Group('integration')]
final class CashJournalCreditNoteVatTest extends CashJournalTestCase
{
    public function testBankRefundOfCreditNoteReducesIncomeByBaseOnly(): void
    {
        $creditNote = $this->saleInvoice($this->supplierId, [
            'type' => 'credit_note', 'without' => -1000.0, 'with' => -1210.0, 'status' => 'paid',
            'paid_at' => self::YEAR . '-06-15',
        ]);
        $statement = $this->statement($this->supplierId, $this->accountA);
        $refund = $this->bankTx($statement, -1210.0, ['matched_invoice_id' => $creditNote, 'match_status' => 'manual']);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'bank', $refund);
        self::assertSame('income_taxable', $row['bucket']);
        self::assertEqualsWithDelta(-1000.0, $row['base'], 0.001);
        self::assertEqualsWithDelta(-210.0, $row['vat'], 0.001);
        self::assertEqualsWithDelta(-1000.0, $result['totals']['prijem_danovy'], 0.001);
        self::assertEqualsWithDelta(-210.0, $result['totals']['prijem_nedanovy'], 0.001);
    }

    public function testCashRefundOfCreditNoteReducesIncomeByBaseOnly(): void
    {
        $creditNote = $this->saleInvoice($this->supplierId, [
            'type' => 'credit_note', 'without' => -100.0, 'with' => -121.0, 'status' => 'paid',
            'paid_at' => self::YEAR . '-06-15',
        ]);
        $cash = $this->cashDoc('out', 'invoice_payment', 121.0, ['invoice_id' => $creditNote]);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'cash', $cash);
        self::assertEqualsWithDelta(-100.0, $row['base'], 0.001);
        self::assertEqualsWithDelta(-21.0, $row['vat'], 0.001);
        self::assertEqualsWithDelta(-100.0, $result['totals']['prijem_danovy'], 0.001);
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
