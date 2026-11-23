<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\OtherItemImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaVat;
use MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy;
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

    /** Mzdové závazky převedená firma v MyÚčtu nepočítá, převádějí se jako ostatní závazky. */
    public function testOnlyTaxAccountsBelongToTheirModule(): void
    {
        foreach (['341.001', '343.100', '343'] as $account) {
            self::assertTrue(OtherItemImporter::moduleOwned($account), $account);
            self::assertFalse(MigratedOtherItemPolicy::payroll($account), $account);
        }
        foreach (['331.000', '336', '336.100', '342.000'] as $account) {
            self::assertFalse(OtherItemImporter::moduleOwned($account), $account);
            self::assertTrue(MigratedOtherItemPolicy::payroll($account), $account);
        }
        foreach (['315.000', '325', '364.000', '379.000'] as $account) {
            self::assertFalse(OtherItemImporter::moduleOwned($account), $account);
            self::assertFalse(MigratedOtherItemPolicy::payroll($account), $account);
        }
    }

    /** MDB export nevede u mezd členění DPH: bez členění a s nulovou daní je doklad mimo přiznání. */
    public function testOtherItemWithoutVatClassAndWithoutTaxIsOutsideReturn(): void
    {
        $vat = $this->vat();
        $record = $this->record();
        $record['invoiceHeader']['classificationVAT'] = ['ids' => ''];
        $record['invoiceHeader']['text'] = 'Sociální pojištění 10/2025';
        self::assertSame(1200.0, OtherItemImporter::candidate($record, 'commitment', $vat)['amount']);
        self::assertSame(1200.0, OtherItemImporter::candidate($record, 'receivable', $vat)['amount']);
        unset($record['invoiceHeader']['classificationVAT']);
        self::assertSame(1200.0, OtherItemImporter::candidate($record, 'commitment', $vat)['amount']);
        $record['invoiceSummary']['homeCurrency']['priceHighVAT'] = '252';
        self::assertNull(OtherItemImporter::candidate($record, 'commitment', $vat));

        self::assertTrue(OtherItemImporter::outsideReturnWithoutVatClass('commitment', '', 0.0));
        self::assertTrue(OtherItemImporter::outsideReturnWithoutVatClass('receivable', '', 0.004));
        self::assertFalse(OtherItemImporter::outsideReturnWithoutVatClass('commitment', '', 210.0));
        self::assertFalse(OtherItemImporter::outsideReturnWithoutVatClass('commitment', 'DOMESTIC', 0.0));
        // Skutečné přijaté a vydané faktury bez členění zůstávají v přiznání.
        foreach (['received', 'received_credit', 'issued', 'internal'] as $agenda) {
            self::assertFalse(OtherItemImporter::outsideReturnWithoutVatClass($agenda, '', 0.0), $agenda);
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
