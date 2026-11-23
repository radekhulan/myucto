<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Migration\Abra;

require_once dirname(__DIR__, 5) . '/src/Service/Migration/Abra/AbraSource.php';
require_once dirname(__DIR__, 5) . '/src/Service/Migration/Abra/AbraDocumentMapper.php';
require_once dirname(__DIR__, 5) . '/src/Service/Migration/Abra/AbraJournalMapper.php';
require_once dirname(__DIR__, 5) . '/src/Service/Migration/Abra/AbraPaymentMapper.php';
require_once dirname(__DIR__, 5) . '/src/Service/Migration/Abra/AbraCatalogMapper.php';

use MyInvoice\Service\Migration\Abra\AbraDocumentMapper;
use MyInvoice\Service\Migration\Abra\AbraJournalMapper;
use MyInvoice\Service\Migration\Abra\AbraPaymentMapper;
use MyInvoice\Service\Migration\Abra\AbraCatalogMapper;
use MyInvoice\Service\Migration\Abra\AbraSource;
use PHPUnit\Framework\TestCase;

final class AbraMappersTest extends TestCase
{
    public function testForeignPaymentLinkWithoutForeignAmountIsNotVerified(): void
    {
        $plan = (new AbraPaymentMapper())->mapLink([
            'id' => '401', 'a' => ['id' => '1', 'evidencePath' => 'faktura-vydana/1'],
            'b' => ['id' => '2', 'evidencePath' => 'banka/2'],
            'mena' => 'code:EUR', 'castka' => '2500.00',
        ]);
        self::assertFalse($plan['amount_currency_verified']);
    }

    public function testForeignMovementDoesNotTreatDomesticAmountAsForeign(): void
    {
        $plan = (new AbraPaymentMapper())->mapMovement([
            'id' => '301', 'datUcto' => '2026-03-01', 'mena' => 'code:EUR',
            'sumCelkem' => '2500.00', 'bankaUcet' => '1000000005',
        ], 'banka');
        self::assertContains('payment_movement_invalid', $plan['blockers']);
    }

    public function testBankAnalyticUsesTargetDottedAccountCode(): void
    {
        self::assertSame('221.100', AbraSource::account('code:221100'));
        self::assertSame('221', AbraSource::account('code:221'));
        self::assertSame('311000', AbraSource::account('code:311000'));
    }

    public function testForeignBankJournalCarriesOriginalCurrencyOnBankSide(): void
    {
        $plan = (new AbraJournalMapper())->mapEntry([
            'idUcetniDenik' => '900', 'datUcto' => '2026-03-01', 'mdUcet' => 'code:221100',
            'dalUcet' => 'code:311000', 'sumTuz' => '250.00', 'sumMen' => '10.00',
            'mena' => 'code:EUR',
        ]);
        self::assertSame('debit', $plan['fx_bank_side']);
        self::assertSame('EUR', $plan['fx_currency']);
        self::assertSame('10.00', $plan['fx_amount_foreign']);
        self::assertSame('25.000000', $plan['fx_rate']);
    }

    public function testCatalogCardKeepsSourcePriceAndStockFlag(): void
    {
        $plan = (new AbraCatalogMapper())->map([
            'id' => 4, 'kod' => 'SYN-GOODS-1', 'nazev' => 'Syntetické zboží', 'mj1' => 'code:KS',
            'typZasobyK' => 'typZasoby.zbozi', 'typSzbDphK' => 'typSzbDph.dphZakl',
            'cenaZaklBezDph' => '123.4567', 'szbDph' => 21, 'skladove' => true,
            'typCenyDphK' => 'typCeny.sDph',
            'odberatele' => [
                ['mena' => 'code:EUR', 'prodejCena' => '121.00'],
                ['mena' => 'code:GBP', 'prodejCena' => '24.20'],
                ['mena' => 'code:USD', 'prodejCena' => '30.00'],
            ],
        ]);

        self::assertSame([], $plan['blockers']);
        self::assertSame('SYN-GOODS-1', $plan['card']['sku']);
        self::assertSame('123.4567', $plan['card']['sale_price_without_vat']);
        self::assertSame(21.0, $plan['card']['vat_rate_percent']);
        self::assertTrue($plan['card']['is_stocked']);
        self::assertSame(['EUR' => '100.00', 'GBP' => '20.00', 'USD' => '24.793388'], $plan['price_rows']);
        self::assertSame(['EUR' => '100.00', 'GBP' => '20.00', 'USD' => '30.00'], $plan['legacy_price_rows']);
        self::assertNotContains('catalog_price_rounded', $plan['warnings']);

        $rounded = (new AbraCatalogMapper())->map([
            'id' => 6, 'kod' => 'SYN-GOODS-2', 'nazev' => 'Syntetické zboží', 'mj1' => 'code:KS',
            'typZasobyK' => 'typZasoby.zbozi', 'cenaZaklBezDph' => '0.0040004',
        ]);
        self::assertSame('0.004', $rounded['card']['sale_price_without_vat']);
        self::assertContains('catalog_price_rounded', $rounded['warnings']);
    }

    public function testCatalogCurrencyPriceWithoutVatIsNeverDividedByRate(): void
    {
        $plan = (new AbraCatalogMapper())->map([
            'id' => 5, 'kod' => 'SYN-NET-1', 'nazev' => 'Net price', 'mj1' => 'code:KS',
            'typZasobyK' => 'typZasoby.zbozi', 'typSzbDphK' => 'typSzbDph.dphZakl',
            'cenaZaklBezDph' => '100.00', 'szbDph' => 21, 'typCenyDphK' => 'typCeny.bezDph',
            'odberatele' => [['mena' => 'code:EUR', 'prodejCena' => '121.00']],
        ]);
        self::assertSame(['EUR' => '121.00'], $plan['price_rows']);
    }

    public function testIssuedInvoiceKeepsSourceItemTotalsAndHistoricalPartner(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 101,
            'kod' => 'VF-2026-001',
            'datVyst' => '2026-02-03',
            'datSplat' => '2026-02-17',
            'duzpUcto' => '2026-02-03',
            'mena' => 'code:CZK',
            'typCenyDphK' => 'typCeny.sDph',
            'sumZklCelkem' => 1000.0,
            'sumDphCelkem' => 210.0,
            'sumCelkem' => 1210.0,
            'firma' => ['id' => 88, 'evidencePath' => 'adresar/88'],
            'nazFirmy' => 'Syntetický odběratel s.r.o.',
            'ic' => '12345679',
            'ulice' => 'Testovací 1',
            'mesto' => 'Praha',
            'psc' => '11000',
            'stat' => 'code:CZ',
            'polozkyDokladu' => [[
                'id' => 1001,
                'nazev' => 'Syntetická služba',
                'mnozMj' => 2,
                'mj' => 'code:ks',
                'szbDph' => 21,
                'sumZkl' => 1000,
                'sumDph' => 210,
                'sumCelkem' => 1210,
            ]],
        ], 'faktura-vydana');

        self::assertSame([], $document['blockers']);
        self::assertSame('101', $document['source_key']);
        self::assertSame(2026, $document['year']);
        self::assertSame('CZK', $document['currency']);
        self::assertTrue($document['prices_include_vat']);
        self::assertSame('88', $document['partner']['source_key']);
        self::assertSame(605.0, $document['items'][0]['unit_price']);
        self::assertSame(1210.0, $document['items'][0]['total']);
        self::assertContains('document_snapshot_requires_review', $document['warnings']);
    }

    public function testForeignDocumentWithoutRateIsExplicitlyBlocked(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 102,
            'kod' => 'FV-USD-1',
            'datVyst' => '2026-03-01',
            'datSplat' => '2026-03-15',
            'mena' => 'code:USD',
            'sumZklCelkemMen' => 100,
            'sumDphCelkemMen' => 0,
            'sumCelkemMen' => 100,
            'bezPolozek' => true,
        ], 'faktura-vydana');

        self::assertContains('document_exchange_rate_invalid', $document['blockers']);
        self::assertContains('foreign_document_tax_requires_review', $document['warnings']);
    }

    public function testForeignTaxWithoutMappedVatTreatmentStaysDraft(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 106, 'datVyst' => '2026-03-01', 'datSplat' => '2026-03-15',
            'mena' => 'code:EUR', 'kurz' => 25, 'kurzMnozstvi' => 1, 'zuctovano' => true,
            'sumZklCelkemMen' => 100, 'sumDphCelkemMen' => 20, 'sumCelkemMen' => 120,
            'polozkyDokladu' => [[
                'id' => 1, 'nazev' => 'Syntetická služba', 'mnozMj' => 1,
                'sumZklMen' => 100, 'sumDphMen' => 20, 'sumCelkemMen' => 120,
                'szbDph' => 20, 'clenDph' => 'code:26',
            ]],
        ], 'faktura-vydana');

        self::assertSame('draft', $document['status']);
        self::assertNull($document['booked_at']);
        self::assertContains('document_tax_classification_requires_review', $document['warnings']);
    }

    public function testIssuedDomesticVatLinesUseSharedReturnClassification(): void
    {
        $row = [
            'id' => 110, 'datVyst' => '2026-03-01', 'datSplat' => '2026-03-15',
            'mena' => 'code:CZK', 'zuctovano' => true,
            'sumZklCelkem' => 200, 'sumDphCelkem' => 33, 'sumCelkem' => 233,
            'polozkyDokladu' => [
                ['id' => 1, 'mnozMj' => 1, 'sumZkl' => 100, 'sumDph' => 21, 'sumCelkem' => 121,
                    'szbDph' => 21, 'clenDph' => 'code:01-02'],
                ['id' => 2, 'mnozMj' => 1, 'sumZkl' => 100, 'sumDph' => 12, 'sumCelkem' => 112,
                    'szbDph' => 12, 'clenDph' => 'code:01-02'],
            ],
        ];

        $document = (new AbraDocumentMapper())->map($row, 'faktura-vydana');

        self::assertSame('sent', $document['status']);
        self::assertSame(['1', '2'], array_column($document['items'], 'vat_classification'));
        self::assertNotNull($document['booked_at']);
    }

    public function testIssuedEuSupplyWithoutVatUsesSharedReturnClassification(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 111, 'datVyst' => '2026-03-01', 'datSplat' => '2026-03-15',
            'mena' => 'code:EUR', 'kurz' => 25, 'zuctovano' => true,
            'sumZklCelkemMen' => 100, 'sumDphCelkemMen' => 0, 'sumCelkemMen' => 100,
            'polozkyDokladu' => [[
                'id' => 1, 'mnozMj' => 1, 'sumZklMen' => 100, 'sumDphMen' => 0,
                'sumCelkemMen' => 100, 'szbDph' => 0, 'clenDph' => 'code:20',
            ]],
        ], 'faktura-vydana');

        self::assertSame('sent', $document['status']);
        self::assertSame('20', $document['items'][0]['vat_classification']);
    }

    /** Ř. 26: tuzemský odběratel (i bez státu na dokladu) = § 71i, zahraniční = služba mimo tuzemsko. */
    public function testIssuedLine26SplitsByCustomerCountry(): void
    {
        $row = static fn (int $id, array $partner): array => $partner + [
            'id' => $id, 'datVyst' => '2026-03-01', 'datSplat' => '2026-03-15',
            'mena' => 'code:CZK', 'zuctovano' => true,
            'sumZklCelkem' => 100, 'sumDphCelkem' => 0, 'sumCelkem' => 100,
            'polozkyDokladu' => [[
                'id' => 1, 'mnozMj' => 1, 'sumZkl' => 100, 'sumDph' => 0,
                'sumCelkem' => 100, 'szbDph' => 0, 'clenDph' => 'code:26',
            ]],
        ];

        foreach ([[112, ['stat' => 'code:CZ'], '71i'], [113, [], '71i'], [114, ['stat' => 'code:US'], '26s']] as [$id, $partner, $code]) {
            $document = (new AbraDocumentMapper())->map($row($id, $partner), 'faktura-vydana');
            self::assertSame($code, $document['items'][0]['vat_classification'], (string) $id);
        }
        $purchase = (new AbraDocumentMapper())->map($row(115, ['stat' => 'code:CZ']), 'faktura-prijata');
        self::assertNotSame('71i', $purchase['items'][0]['vat_classification'], 'Přijatý doklad se nepřeřazuje.');
    }

    public function testPurchasedDomesticVatUsesSharedReturnClassification(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 112, 'datVyst' => '2026-03-01', 'datSplat' => '2026-03-15',
            'mena' => 'code:CZK', 'zuctovano' => true,
            'sumZklCelkem' => 100, 'sumDphCelkem' => 21, 'sumCelkem' => 121,
            'polozkyDokladu' => [[
                'id' => 1, 'mnozMj' => 1, 'sumZkl' => 100, 'sumDph' => 21,
                'sumCelkem' => 121, 'szbDph' => 21, 'clenDph' => 'code:40-41',
            ]],
        ], 'faktura-prijata');

        self::assertSame('booked', $document['status']);
        self::assertSame('40', $document['items'][0]['vat_classification']);
        self::assertSame('full', $document['vat_deduction']);
    }

    public function testPurchasedDomesticVatWithSmallZeroRateAdjustmentRemainsBookable(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 212, 'datVyst' => '2026-04-01', 'datSplat' => '2026-04-15',
            'mena' => 'code:CZK', 'zuctovano' => true,
            'sumZklCelkem' => 99.70, 'sumDphCelkem' => 21.00, 'sumCelkem' => 120.70,
            'polozkyDokladu' => [
                ['id' => 1, 'mnozMj' => 1, 'sumZkl' => 100, 'sumDph' => 21,
                    'sumCelkem' => 121, 'szbDph' => 21, 'clenDph' => 'code:40-41'],
                ['id' => 2, 'mnozMj' => 1, 'sumZkl' => -0.30, 'sumDph' => 0,
                    'sumCelkem' => -0.30, 'szbDph' => 0, 'clenDph' => 'code:40-41'],
            ],
        ], 'faktura-prijata');

        self::assertSame('booked', $document['status']);
        self::assertNotNull($document['booked_at']);
        self::assertSame('40', $document['items'][0]['vat_classification']);
        self::assertFalse($document['items'][1]['vat_requires_review']);
    }

    public function testPurchasedSelfAssessmentSeparatesSourceTaxFromVendorPayable(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 113, 'datVyst' => '2026-03-01', 'datSplat' => '2026-03-15',
            'mena' => 'code:EUR', 'kurz' => 25, 'zuctovano' => true,
            'sumZklCelkemMen' => 100, 'sumDphCelkemMen' => 21, 'sumCelkemMen' => 100,
            'polozkyDokladu' => [[
                'id' => 1, 'mnozMj' => 1, 'sumZklMen' => 100, 'sumDphMen' => 21,
                'sumCelkemMen' => 121, 'szbDph' => 21,
                'clenDph' => 'code:03-04, 43-44',
            ]],
        ], 'faktura-prijata');

        self::assertTrue($document['reverse_charge']);
        self::assertSame('booked', $document['status']);
        self::assertSame('23', $document['items'][0]['vat_classification']);
        self::assertSame(0.0, $document['items'][0]['vat']);
        self::assertSame(100.0, $document['items'][0]['total']);
        self::assertSame(0.0, $document['total_vat']);
        self::assertSame(100.0, $document['stored_total_with_vat']);
        self::assertSame('full', $document['vat_deduction']);
        self::assertNotContains('document_source_total_mismatch_requires_review', $document['warnings']);
    }

    public function testPurchasedSelfAssessmentWithUntaxedSideItemKeepsVendorPayable(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 114, 'datVyst' => '2026-03-01', 'datSplat' => '2026-03-15',
            'mena' => 'code:EUR', 'kurz' => 25, 'zuctovano' => true,
            'sumZklCelkemMen' => 110, 'sumDphCelkemMen' => 21, 'sumCelkemMen' => 110,
            'polozkyDokladu' => [
                ['id' => 1, 'mnozMj' => 1, 'sumZklMen' => 100, 'sumDphMen' => 21,
                    'sumCelkemMen' => 121, 'szbDph' => 21, 'clenDph' => 'code:03-04, 43-44'],
                ['id' => 2, 'mnozMj' => 1, 'sumZklMen' => 10, 'sumDphMen' => 0,
                    'sumCelkemMen' => 10, 'szbDph' => 0, 'clenDph' => 'code:000P'],
            ],
        ], 'faktura-prijata');

        self::assertTrue($document['reverse_charge']);
        self::assertSame('booked', $document['status']);
        self::assertSame(['23', null], array_column($document['items'], 'vat_classification'));
        self::assertSame(0.0, $document['items'][0]['vat']);
        self::assertSame(110.0, $document['total_with_vat']);
        self::assertSame(110.0, $document['stored_total_with_vat']);
    }

    public function testForeignPurchaseKeepsIndependentCzkTaxBaseAndExcludesAdvanceOffset(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 214, 'datVyst' => '2026-08-01', 'datSplat' => '2026-08-15',
            'mena' => 'code:EUR', 'kurz' => 25, 'zuctovano' => true,
            'sumZklCelkemMen' => 80, 'sumDphCelkemMen' => 0, 'sumCelkemMen' => 80,
            'polozkyDokladu' => [
                ['id' => 1, 'mnozMj' => 1, 'sumZklMen' => 100, 'sumDphMen' => 21,
                    'sumCelkemMen' => 121, 'sumZkl' => 2571.50, 'sumDph' => 540.02,
                    'szbDph' => 21, 'clenDph' => 'code:03-04, 43-44'],
                ['id' => 2, 'mnozMj' => 1, 'sumZklMen' => -20, 'sumDphMen' => 0,
                    'sumCelkemMen' => -20, 'sumZkl' => -514.30, 'sumDph' => 0,
                    'szbDph' => 0, 'clenDph' => 'code:000P'],
            ],
        ], 'faktura-prijata');

        self::assertSame(2571.50, $document['items'][0]['import_tax_base_czk']);
        self::assertSame(540.02, $document['items'][0]['import_tax_vat_czk']);
        self::assertFalse($document['items'][0]['import_tax_excluded']);
        self::assertTrue($document['items'][1]['import_tax_excluded']);
        self::assertSame(80.0, $document['stored_total_with_vat']);
    }

    public function testVatInclusiveFallbackItemUsesGrossUnitPrice(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 105, 'datVyst' => '2026-03-01', 'datSplat' => '2026-03-15',
            'mena' => 'code:CZK', 'typCenyDphK' => 'typCeny.sDph',
            'sumZklCelkem' => 100, 'sumDphCelkem' => 21, 'sumCelkem' => 121,
        ], 'faktura-vydana');

        self::assertTrue($document['prices_include_vat']);
        self::assertSame(121.0, $document['items'][0]['unit_price']);
    }

    public function testPurchaseStoredTotalExcludesRounding(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 103,
            'kod' => 'PF-ROUND-1',
            'datVyst' => '2026-03-02',
            'datSplat' => '2026-03-16',
            'mena' => 'code:CZK',
            'sumZklCelkem' => 100,
            'sumDphCelkem' => 0,
            'sumCelkem' => 100.01,
            'bezPolozek' => true,
        ], 'faktura-prijata');

        self::assertSame(100.01, $document['total_with_vat']);
        self::assertSame(0.01, $document['rounding']);
        self::assertSame(100.0, $document['stored_total_with_vat']);
    }

    public function testPurchaseWithUnexplainedHeaderDifferenceKeepsItemsWithoutInventedRounding(): void
    {
        $document = (new AbraDocumentMapper())->map([
            'id' => 104,
            'kod' => 'PF-MISMATCH-1',
            'datVyst' => '2026-03-02',
            'datSplat' => '2026-03-16',
            'mena' => 'code:CZK',
            'sumZklCelkem' => 100,
            'sumDphCelkem' => 21,
            'sumCelkem' => 12000,
            'polozkyDokladu' => [[
                'id' => 1041, 'nazev' => 'Syntetická služba', 'mnozMj' => 1,
                'sumZkl' => 100, 'sumDph' => 21, 'sumCelkem' => 121,
            ]],
        ], 'faktura-prijata');

        self::assertSame([], $document['blockers']);
        self::assertSame(121.0, $document['stored_total_with_vat']);
        self::assertSame(0.0, $document['rounding']);
        self::assertSame(12000.0, $document['source_total_with_vat']);
        self::assertContains('document_source_total_mismatch_requires_review', $document['warnings']);
    }

    public function testJournalPreservesRedStornoAndAccountSwap(): void
    {
        $entry = (new AbraJournalMapper())->mapEntry([
            'idUcetniDenik' => 501,
            'datUcto' => '2026-04-30',
            'mdUcet' => 'code:311100',
            'dalUcet' => 'code:602100',
            'sumTuz' => -1210,
            'accountsSwapped' => true,
            'zuctovano' => true,
            'dimens' => ['stredisko' => 'code:PRODEJ'],
        ]);

        self::assertSame([], $entry['blockers']);
        self::assertSame('602100', $entry['debit']);
        self::assertSame('311100', $entry['credit']);
        self::assertSame(1210.0, $entry['amount']);
        self::assertTrue($entry['is_red_storno']);
        self::assertSame('stredisko:PRODEJ', $entry['cost_center']);
    }

    public function testPaymentLinkKeepsBothTypedRelations(): void
    {
        $link = (new AbraPaymentMapper())->mapLink([
            'id' => 901,
            'a' => ['id' => 101, 'evidencePath' => 'faktura-vydana/101'],
            'b' => ['id' => 701, 'evidencePath' => 'banka/701'],
            'castka' => 1210,
            'mena' => 'code:CZK',
            'storno' => false,
        ]);

        self::assertSame([], $link['blockers']);
        self::assertSame(['evidence' => 'faktura-vydana', 'key' => '101'], $link['a']);
        self::assertSame(['evidence' => 'banka', 'key' => '701'], $link['b']);
        self::assertSame(1210.0, $link['amount']);
    }

    public function testPaymentLinkUsesTypedReferencesAndAllowsNonFinancialRelation(): void
    {
        $link = (new AbraPaymentMapper())->mapLink([
            'id' => 902, 'a' => 'source display label', 'a@ref' => '/c/demo/faktura-vydana/101.json',
            'b' => 'another display label', 'b@ref' => '/c/demo/skladovy-pohyb/701.json',
            'castka' => '0.00', 'mena' => 'code:CZK',
        ]);

        self::assertSame([], $link['blockers']);
        self::assertSame(['evidence' => 'faktura-vydana', 'key' => '101'], $link['a']);
        self::assertSame(['evidence' => 'skladovy-pohyb', 'key' => '701'], $link['b']);
        self::assertSame(0.0, $link['amount']);
    }

    public function testForeignBankMovementIsSupportedButForeignCashIsBlocked(): void
    {
        $mapper = new AbraPaymentMapper();
        $row = [
            'id' => 701,
            'datUcto' => '2026-05-04',
            'mena' => 'code:EUR',
            'sumCelkemMen' => 125.50,
            'typPohybuK' => 'typPohybu.prijem',
            'banka' => ['id' => 12, 'evidencePath' => 'banka/12'],
        ];

        self::assertSame([], $mapper->mapMovement($row, 'banka')['blockers']);
        self::assertContains('payment_foreign_currency_unsupported', $mapper->mapMovement($row, 'pokladni-pohyb')['blockers']);
    }

    public function testBankMovementsUseMonthlyStatementAndCatalogAccountWithoutExposingPaypalAddress(): void
    {
        $lookup = AbraPaymentMapper::bankAccountLookup([[
            'id' => 12, 'kod' => 'PAYPAL_EUR', 'nazev' => 'PayPal EUR',
            'buc' => 'synthetic@example.test', 'mena' => 'code:EUR', 'primUcet' => 'code:221200',
        ]]);
        $plan = (new AbraPaymentMapper())->mapMovement([
            'id' => 701, 'datUcto' => '2026-05-04', 'mena' => 'code:EUR',
            'sumCelkemMen' => 125.50, 'typPohybuK' => 'typPohybu.prijem',
            'banka' => 'code:PAYPAL_EUR', 'banka@ref' => '/c/demo/bankovni-ucet/12.json',
            'vypisCisDokl' => 'source-statement-77',
        ], 'banka', $lookup);

        self::assertSame('12', $plan['account_key']);
        self::assertSame('ABRA-12', $plan['account_number']);
        self::assertSame('2026-05', $plan['statement_key']);
        self::assertSame('PayPal EUR', $plan['account_label']);
        self::assertSame([], $plan['blockers']);
    }

    public function testBankCatalogReadsSourceSortCodeReference(): void
    {
        $lookup = AbraPaymentMapper::bankAccountLookup([[
            'id' => 7, 'kod' => 'SYN-BANK', 'nazev' => 'Synthetic bank', 'buc' => '9000000001',
            'smerKod' => 'code:0100', 'mena' => 'code:CZK', 'primUcet' => 'code:221100',
        ]]);

        self::assertSame('0100', $lookup['id:7']['bank_code']);
    }

    public function testCanonicalHashIgnoresAssociativeKeyOrderButDetectsChangedValue(): void
    {
        self::assertSame(AbraSource::hash(['id' => 1, 'kod' => 'A']), AbraSource::hash(['kod' => 'A', 'id' => 1]));
        self::assertNotSame(AbraSource::hash(['id' => 1, 'kod' => 'A']), AbraSource::hash(['id' => 1, 'kod' => 'B']));
    }

    public function testMissingIdentityBlocksJournalAndLongIdentitiesRemainDistinct(): void
    {
        $row = ['datUcto' => '2025-06-01', 'mdUcet' => 'code:311000', 'dalUcet' => 'code:602000', 'sumTuz' => 100];
        self::assertContains('journal_identity_missing', (new AbraJournalMapper())->mapEntry($row)['blockers']);
        $prefix = str_repeat('A', 190);
        self::assertNotSame(AbraSource::sourceKey(['id' => $prefix . '1']), AbraSource::sourceKey(['id' => $prefix . '2']));
    }

    public function testZeroValueTextItemKeepsSourceQuantity(): void
    {
        $row = ['id' => 1, 'datVyst' => '2025-06-01', 'datSplat' => '2025-06-15', 'mena' => 'code:CZK',
            'sumZklCelkem' => 0, 'sumDphCelkem' => 0, 'sumCelkem' => 0,
            'polozkyDokladu' => [['id' => 2, 'popis' => 'Synthetic note', 'mnozMj' => 0, 'sumZkl' => 0, 'sumDph' => 0, 'sumCelkem' => 0]]];
        $plan = (new AbraDocumentMapper())->map($row, 'faktura-vydana');
        self::assertSame([], $plan['blockers']);
        self::assertSame(0.0, $plan['items'][0]['quantity']);
        self::assertSame(0.0, $plan['items'][0]['unit_price']);
        $row['sumZklCelkem'] = $row['sumCelkem'] = 10;
        $row['polozkyDokladu'][0]['sumZkl'] = 10;
        $row['polozkyDokladu'][0]['sumCelkem'] = 10;
        $row['polozkyDokladu'][0]['cenaMj'] = 10;
        $plan = (new AbraDocumentMapper())->map($row, 'faktura-vydana');
        self::assertSame([], $plan['blockers']);
        self::assertSame(0.0, $plan['items'][0]['quantity']);
        self::assertSame(10.0, $plan['items'][0]['base']);
        self::assertSame(10.0, $plan['items'][0]['unit_price']);
        self::assertContains('document_zero_quantity_requires_review', $plan['warnings']);
    }

    public function testCompactDocumentKeepsMappedFieldsAndStableIdentity(): void
    {
        $row = ['id' => 1, 'datVyst' => '2025-06-01', 'datSplat' => '2025-06-15', 'mena' => 'code:EUR', 'kurz' => 25,
            'sumZklCelkemMen' => 10, 'sumDphCelkemMen' => 2.1, 'sumCelkemMen' => 12.1,
            'polozkyDokladu' => [['id' => 2, 'nazev' => 'Synthetic item', 'mnozMj' => 2, 'mj' => 'code:ks', 'szbDph' => 21,
                'sumZklMen' => 10, 'sumDphMen' => 2.1, 'sumCelkemMen' => 12.1, 'unusedRelation' => str_repeat('x', 1000)]]];
        $compact = AbraSource::compactDocument($row);
        self::assertArrayNotHasKey('unusedRelation', $compact['polozkyDokladu'][0]);
        self::assertSame((new AbraDocumentMapper())->map($row, 'faktura-vydana'), (new AbraDocumentMapper())->map($compact, 'faktura-vydana'));
        self::assertSame(AbraSource::movementHash($row), AbraSource::movementHash($compact));
    }
}
