<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\OtherItemImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaVat;
use PHPUnit\Framework\TestCase;

final class OtherItemImporterTest extends TestCase
{
    public function testOpenPartlyPaidAndSettledNonVatItemsAreCandidates(): void
    {
        $vat = $this->vat();
        $record = $this->record();
        self::assertSame(1200.0, OtherItemImporter::candidate($record, 'receivable', $vat)['amount']);
        self::assertSame(1200.0, OtherItemImporter::candidate($record, 'commitment', $vat)['remaining']);

        $record['invoiceHeader']['liquidation']['amountHome'] = '800.00';
        $record['liquidations']['liquidation'] = ['id' => '1'];
        self::assertSame(800.0, OtherItemImporter::candidate($record, 'receivable', $vat)['remaining']);
        unset($record['invoiceHeader']['liquidation']);
        self::assertSame(0.0, OtherItemImporter::candidate($record, 'receivable', $vat)['remaining']);
        $record['invoiceHeader']['liquidation']['amountHome'] = '1300.00';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        $record['invoiceHeader']['liquidation']['amountHome'] = '-1';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
    }

    public function testVariableSymbolKeepsOnlyDigits(): void
    {
        $record = $this->record();
        $record['invoiceHeader']['symVar'] = '2025001';
        self::assertSame('2025001', OtherItemImporter::candidate($record, 'receivable', $this->vat())['symbol']);
        $record['invoiceHeader']['symVar'] = 'OP/25';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $this->vat())['symbol']);
    }

    public function testVatAndForeignCurrencyNeverEnterOtherItems(): void
    {
        $vat = $this->vat();
        $record = $this->record();
        $record['invoiceHeader']['classificationVAT']['ids'] = 'DOMESTIC';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        $record['invoiceHeader']['classificationVAT']['ids'] = 'OUTSIDE';
        $record['invoiceSummary']['homeCurrency']['priceHighVAT'] = '252';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        $record['invoiceSummary']['homeCurrency']['priceHighVAT'] = '0';
        $record['invoiceSummary']['homeCurrency']['priceSum'] = '1452';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        $record['invoiceSummary']['homeCurrency']['priceSum'] = '1200';
        $record['invoiceDetail']['invoiceItem'] = ['homeCurrency' => ['price' => '1000', 'priceVAT' => '200']];
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        unset($record['invoiceDetail']);
        $record['invoiceSummary']['foreignCurrency']['currency']['ids'] = 'EUR';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
    }

    public function testPostingTakesOneBalanceAccountAgainstMergedCounterAccounts(): void
    {
        $receivable = [
            ['side' => 'debit', 'amount' => '1200.00', 'account_code' => '315.000', 'account_type' => 'asset'],
            ['side' => 'credit', 'amount' => '1200.00', 'account_code' => '602.000', 'account_type' => 'revenue'],
        ];
        self::assertSame(['account' => '315.000', 'lines' => [['account_code' => '602.000', 'amount' => 1200.0]]],
            OtherItemImporter::postingLines($receivable, 'receivable', 1200.0));
        $payable = [
            ['side' => 'debit', 'amount' => '700.00', 'account_code' => '513.000', 'account_type' => 'expense'],
            ['side' => 'credit', 'amount' => '700.00', 'account_code' => '379.000', 'account_type' => 'liability'],
            ['side' => 'debit', 'amount' => '300.00', 'account_code' => '527.000', 'account_type' => 'expense'],
            ['side' => 'credit', 'amount' => '300.00', 'account_code' => '379.000', 'account_type' => 'liability'],
            ['side' => 'debit', 'amount' => '200.00', 'account_code' => '513.000', 'account_type' => 'expense'],
            ['side' => 'credit', 'amount' => '200.00', 'account_code' => '379.000', 'account_type' => 'liability'],
        ];
        self::assertSame(['account' => '379.000', 'lines' => [
            ['account_code' => '513.000', 'amount' => 900.0],
            ['account_code' => '527.000', 'amount' => 300.0],
        ]], OtherItemImporter::postingLines($payable, 'commitment', 1200.0));
        self::assertNull(OtherItemImporter::postingLines($payable, 'commitment', 1199.0));

        // Saldokontní strana na dvou účtech, na nerozvahovém účtu nebo s protiúčtem 315/325.
        $twoBalances = $receivable;
        $twoBalances[] = ['side' => 'debit', 'amount' => '100.00', 'account_code' => '378.000', 'account_type' => 'asset'];
        $twoBalances[] = ['side' => 'credit', 'amount' => '100.00', 'account_code' => '602.000', 'account_type' => 'revenue'];
        self::assertNull(OtherItemImporter::postingLines($twoBalances, 'receivable', 1300.0));
        $receivable[0]['account_type'] = 'expense';
        self::assertNull(OtherItemImporter::postingLines($receivable, 'receivable', 1200.0));
        $receivable[0]['account_type'] = 'asset';
        $receivable[1]['account_code'] = '325.000';
        self::assertNull(OtherItemImporter::postingLines($receivable, 'receivable', 1200.0));
    }

    /** Doplatek daně vede POHODA na stejném účtu i předkontaci jako zálohy; rozhoduje text. */
    public function testOnlyAdvanceTextOnIncomeTaxAccountIsATaxAdvance(): void
    {
        self::assertTrue(OtherItemImporter::isTaxAdvance('341.000', 'Daň z příjmů (záloha) 3/4'));
        self::assertTrue(OtherItemImporter::isTaxAdvance('341', 'Zaloha DPPO'));
        self::assertFalse(OtherItemImporter::isTaxAdvance('341.000', 'Odvod daně z příjmů PO/2025 - doplatek'));
        self::assertFalse(OtherItemImporter::isTaxAdvance('342.000', 'Záloha na daň ze mzdy'));
    }

    public function testPayrollAndTaxAccountsBelongToTheirModules(): void
    {
        foreach (['331.000', '336', '341.001', '342.000', '343.100'] as $account) {
            self::assertTrue(OtherItemImporter::moduleOwned($account), $account);
        }
        foreach (['315.000', '325', '364.000', '379.000'] as $account) {
            self::assertFalse(OtherItemImporter::moduleOwned($account), $account);
        }
    }

    private function vat(): PohodaVat
    {
        $make = \Closure::bind(static fn (array $classes): PohodaVat => new PohodaVat($classes), null, PohodaVat::class);
        return $make([
            'OUTSIDE' => ['lines' => [], 'name' => 'Mimo přiznání', 'section' => ''],
            'DOMESTIC' => ['lines' => [1, 2], 'name' => 'Tuzemsko', 'section' => ''],
        ]);
    }

    private function record(): array
    {
        return [
            'invoiceHeader' => [
                'number' => ['numberRequested' => 'OP25001'], 'date' => '2025-06-01',
                'dateDue' => '2025-06-15', 'text' => 'Nájem', 'classificationVAT' => ['ids' => 'OUTSIDE'],
                'liquidation' => ['amountHome' => '1200.00'],
            ],
            'invoiceSummary' => ['homeCurrency' => [
                'priceNone' => '1200.00', 'priceLow' => '0', 'priceLowVAT' => '0',
                'priceHigh' => '0', 'priceHighVAT' => '0', 'price3' => '0', 'price3VAT' => '0',
            ]],
        ];
    }
}
