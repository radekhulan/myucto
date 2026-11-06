<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * #247: přeplatek faktury plátce DPH v peněžním deníku. Poměrem DPH faktury se dělí jen
 * část úhrady, která fakturu hradí (do výše dosud neuhrazeného brutta). Přeplatek nad
 * brutto DPH nenese a celý jde do daňového příjmu, u přijaté faktury do daňového výdaje.
 * Součet DPH všech úhrad tak nepřekročí DPH faktury; při placení po částech nese přeplatek
 * až úhrada, která zbytek faktury překročí (pořadí datum úhrady a id).
 *
 * Faktura ze zadání: 1 250 + 262,50 DPH = 1 512,50.
 */
#[Group('integration')]
final class CashJournalOverpaymentTest extends CashJournalTestCase
{
    public function testBankOverpaymentOfIssuedInvoiceCarriesNoVat(): void
    {
        $invoice = $this->issuedInvoice();
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), 1513.0, ['match_status' => 'manual']);
        $this->invoicePayment($this->supplierId, $invoice, 1513.0, 'bank', $tx);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'bank', $tx);
        self::assertEqualsWithDelta(1250.50, $row['base'], 0.001);
        self::assertEqualsWithDelta(262.50, $row['vat'], 0.001);
        self::assertEqualsWithDelta(1250.50, $result['totals']['prijem_danovy'], 0.001);
        self::assertEqualsWithDelta(262.50, $result['totals']['prijem_nedanovy'], 0.001);
    }

    public function testLargerBankOverpaymentStillCarriesOnlyInvoiceVat(): void
    {
        $invoice = $this->issuedInvoice();
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), 1600.0, ['match_status' => 'manual']);
        $this->invoicePayment($this->supplierId, $invoice, 1600.0, 'bank', $tx);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'bank', $tx);
        self::assertEqualsWithDelta(1337.50, $row['base'], 0.001);
        self::assertEqualsWithDelta(262.50, $row['vat'], 0.001);
    }

    /** Legacy 1:1 párování (jen matched_invoice_id, bez úhrady v invoice_payments). */
    public function testLegacyMatchedBankOverpaymentCarriesNoVat(): void
    {
        $invoice = $this->issuedInvoice();
        $tx = $this->bankTx(
            $this->statement($this->supplierId, $this->accountA),
            1513.0,
            ['matched_invoice_id' => $invoice, 'match_status' => 'manual'],
        );

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'bank', $tx);
        self::assertEqualsWithDelta(1250.50, $row['base'], 0.001);
        self::assertEqualsWithDelta(262.50, $row['vat'], 0.001);
    }

    public function testManualOverpaymentWithoutBankLinkCarriesNoVat(): void
    {
        $invoice = $this->issuedInvoice();
        $payment = $this->invoicePayment($this->supplierId, $invoice, 1513.0, 'manual');

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'invoice_payment', $payment);
        self::assertEqualsWithDelta(1250.50, $row['base'], 0.001);
        self::assertEqualsWithDelta(262.50, $row['vat'], 0.001);
    }

    public function testCashOverpaymentLinkedToPaymentCarriesNoVat(): void
    {
        $invoice = $this->issuedInvoice();
        $payment = $this->invoicePayment($this->supplierId, $invoice, 1513.0, 'cash');
        $cash = $this->cashDoc('in', 'invoice_payment', 1513.0, ['invoice_id' => $invoice]);
        $this->db->pdo()->prepare('UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ?')
            ->execute([$payment, $cash]);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'cash', $cash);
        self::assertEqualsWithDelta(1250.50, $row['base'], 0.001);
        self::assertEqualsWithDelta(262.50, $row['vat'], 0.001);
    }

    public function testUnderpaymentStaysProportional(): void
    {
        $invoice = $this->issuedInvoice();
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), 1000.0, ['match_status' => 'manual']);
        $this->invoicePayment($this->supplierId, $invoice, 1000.0, 'bank', $tx);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'bank', $tx);
        self::assertEqualsWithDelta(826.45, $row['base'], 0.001);
        self::assertEqualsWithDelta(173.55, $row['vat'], 0.001);
    }

    /**
     * Úhrada po částech: přeplatek nese až druhá úhrada (podle data), i když byla
     * zaevidovaná dřív a má nižší id.
     */
    public function testInstallmentsLeaveOverpaymentOnPaymentThatExceedsRemainder(): void
    {
        $invoice = $this->issuedInvoice();
        $second = $this->invoicePayment($this->supplierId, $invoice, 513.0, 'manual', null, self::YEAR . '-06-20');
        $tx = $this->bankTx(
            $this->statement($this->supplierId, $this->accountA),
            1000.0,
            ['match_status' => 'manual', 'posted_at' => self::YEAR . '-06-12'],
        );
        $this->invoicePayment($this->supplierId, $invoice, 1000.0, 'bank', $tx, self::YEAR . '-06-12');

        $result = $this->fullYear($this->supplierId, true);

        $first = $this->row($result, 'bank', $tx);
        self::assertEqualsWithDelta(826.45, $first['base'], 0.001);
        self::assertEqualsWithDelta(173.55, $first['vat'], 0.001);
        $last = $this->row($result, 'invoice_payment', $second);
        self::assertEqualsWithDelta(424.05, $last['base'], 0.001);
        self::assertEqualsWithDelta(88.95, $last['vat'], 0.001);
        self::assertEqualsWithDelta(262.50, $first['vat'] + $last['vat'], 0.001);
        self::assertEqualsWithDelta(1250.50, $result['totals']['prijem_danovy'], 0.001);
    }

    /** Přeplatek se pozná i tehdy, když dřívější úhrada leží mimo zobrazené období. */
    public function testEarlierPaymentOutsideDisplayedRangeCountsAsPaid(): void
    {
        $invoice = $this->issuedInvoice();
        $this->invoicePayment($this->supplierId, $invoice, 1000.0, 'manual', null, self::YEAR . '-01-15');
        $second = $this->invoicePayment($this->supplierId, $invoice, 513.0, 'manual', null, self::YEAR . '-06-20');

        $this->setVatPayer($this->supplierId, true);
        $result = $this->service->build($this->supplierId, self::YEAR . '-06-01', self::YEAR . '-06-30', ['year' => self::YEAR]);

        $row = $this->row($result, 'invoice_payment', $second);
        self::assertEqualsWithDelta(424.05, $row['base'], 0.001);
        self::assertEqualsWithDelta(88.95, $row['vat'], 0.001);
    }

    public function testBankOverpaymentOfPurchaseInvoiceIsWholeTaxExpense(): void
    {
        $purchase = $this->receivedInvoice();
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), -1513.0, ['match_status' => 'manual']);
        $this->paymentMatch($this->supplierId, $tx, $purchase, 1513.0);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'bank', $tx);
        self::assertSame('expense_taxable', $row['bucket']);
        self::assertEqualsWithDelta(1250.50, $row['base'], 0.001);
        self::assertEqualsWithDelta(262.50, $row['vat'], 0.001);
        self::assertEqualsWithDelta(1250.50, $result['totals']['vydaj_danovy'], 0.001);
    }

    public function testCashOverpaymentOfPurchaseInvoiceIsWholeTaxExpense(): void
    {
        $purchase = $this->receivedInvoice();
        $cash = $this->cashDoc('out', 'purchase_payment', 1600.0, ['purchase_invoice_id' => $purchase]);

        $result = $this->fullYear($this->supplierId, true);

        $row = $this->row($result, 'cash', $cash);
        self::assertEqualsWithDelta(1337.50, $row['base'], 0.001);
        self::assertEqualsWithDelta(262.50, $row['vat'], 0.001);
    }

    public function testPurchaseInstallmentsBankThenCash(): void
    {
        $purchase = $this->receivedInvoice();
        $tx = $this->bankTx(
            $this->statement($this->supplierId, $this->accountA),
            -1000.0,
            ['match_status' => 'manual', 'posted_at' => self::YEAR . '-06-12'],
        );
        $this->paymentMatch($this->supplierId, $tx, $purchase, 1000.0);
        $cash = $this->cashDoc('out', 'purchase_payment', 513.0, ['purchase_invoice_id' => $purchase], null, self::YEAR . '-06-20');

        $result = $this->fullYear($this->supplierId, true);

        $first = $this->row($result, 'bank', $tx);
        self::assertEqualsWithDelta(826.45, $first['base'], 0.001);
        self::assertEqualsWithDelta(173.55, $first['vat'], 0.001);
        $last = $this->row($result, 'cash', $cash);
        self::assertEqualsWithDelta(424.05, $last['base'], 0.001);
        self::assertEqualsWithDelta(88.95, $last['vat'], 0.001);
    }

    public function testNonPayerKeepsWholePaymentAsIncome(): void
    {
        $invoice = $this->issuedInvoice();
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), 1513.0, ['match_status' => 'manual']);
        $this->invoicePayment($this->supplierId, $invoice, 1513.0, 'bank', $tx);

        $result = $this->fullYear($this->supplierId, false);

        $row = $this->row($result, 'bank', $tx);
        self::assertEqualsWithDelta(1513.0, $row['base'], 0.001);
        self::assertEqualsWithDelta(0.0, $row['vat'], 0.001);
    }

    private function issuedInvoice(): int
    {
        return $this->saleInvoice($this->supplierId, [
            'without' => 1250.0, 'with' => 1512.50, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15',
        ]);
    }

    private function receivedInvoice(): int
    {
        return $this->purchaseInvoice($this->supplierId, ['without' => 1250.0, 'with' => 1512.50]);
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
