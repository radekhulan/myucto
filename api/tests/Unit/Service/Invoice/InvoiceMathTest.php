<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoiceMath;
use PHPUnit\Framework\TestCase;

final class InvoiceMathTest extends TestCase
{
    public function testSingleItem21Pct(): void
    {
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(1000.00, $r['totals']['without_vat']);
        self::assertSame(210.00,  $r['totals']['vat']);
        self::assertSame(1210.00, $r['totals']['with_vat']);
        self::assertCount(1, $r['vat_breakdown']);
        self::assertSame(21.0, $r['vat_breakdown'][0]['rate']);
    }

    public function testMultipleItemsMixedRates(): void
    {
        // 21 % a 12 % v jedné faktuře — typicky např. služba + jídlo
        $r = InvoiceMath::compute([
            ['quantity' => 2, 'unit_price_without_vat' => 500.00,  'vat_rate_snapshot' => 21],  // base 1000, VAT 210
            ['quantity' => 5, 'unit_price_without_vat' => 100.00,  'vat_rate_snapshot' => 12],  // base 500,  VAT 60
            ['quantity' => 1, 'unit_price_without_vat' => 50.00,   'vat_rate_snapshot' => 0],   // base 50,   VAT 0
        ]);
        self::assertSame(1550.00, $r['totals']['without_vat']);
        self::assertSame(270.00,  $r['totals']['vat']);
        self::assertSame(1820.00, $r['totals']['with_vat']);

        // Breakdown seřazený sestupně podle rate: 21, 12, 0
        self::assertCount(3, $r['vat_breakdown']);
        self::assertSame(21.0, $r['vat_breakdown'][0]['rate']);
        self::assertSame(12.0, $r['vat_breakdown'][1]['rate']);
        self::assertSame(0.0,  $r['vat_breakdown'][2]['rate']);

        self::assertSame(1000.00, $r['vat_breakdown'][0]['base']);
        self::assertSame(210.00,  $r['vat_breakdown'][0]['vat']);
        self::assertSame(500.00,  $r['vat_breakdown'][1]['base']);
        self::assertSame(60.00,   $r['vat_breakdown'][1]['vat']);
    }

    public function testReverseChargeKeepsNominalRateButZeroesVat(): void
    {
        // Reverse charge (přenesená daň. povinnost): nominální sazby (21/12 %) ZŮSTÁVAJÍ
        // pro zobrazení i breakdown, ale daň = 0 (odvede ji zákazník).
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => 500.00,  'vat_rate_snapshot' => 12],
        ], reverseCharge: true);
        self::assertSame(1500.00, $r['totals']['without_vat']);
        self::assertSame(0.00,    $r['totals']['vat']);
        self::assertSame(1500.00, $r['totals']['with_vat']);
        // Sazby zůstávají (seřazeno DESC), daň u každé 0
        self::assertCount(2, $r['vat_breakdown']);
        self::assertSame(21.0,    $r['vat_breakdown'][0]['rate']);
        self::assertSame(1000.00, $r['vat_breakdown'][0]['base']);
        self::assertSame(0.00,    $r['vat_breakdown'][0]['vat']);
        self::assertSame(12.0,   $r['vat_breakdown'][1]['rate']);
        self::assertSame(500.00, $r['vat_breakdown'][1]['base']);
        self::assertSame(0.00,   $r['vat_breakdown'][1]['vat']);
    }

    /** Smíšený doklad s přenesením: tuzemský řádek (reverse_charge=false) nese daň dodavatele zdola i shora. */
    public function testLineFlagOverridesDocumentReverseCharge(): void
    {
        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 50000.00, 'vat_rate_snapshot' => 21, 'reverse_charge' => true],
            ['quantity' => 1, 'unit_price_without_vat' => 20000.00, 'vat_rate_snapshot' => 21, 'reverse_charge' => false],
        ];
        $r = InvoiceMath::compute($items, reverseCharge: true);
        self::assertSame(0.00, $r['items'][0]['vat']);
        self::assertSame(4200.00, $r['items'][1]['vat']);
        self::assertSame(74200.00, $r['totals']['with_vat']);

        $items[1]['unit_price_without_vat'] = 24200.00;
        $r = InvoiceMath::compute($items, reverseCharge: true, pricesIncludeVat: true);
        self::assertSame(0.00, $r['items'][0]['vat']);
        self::assertSame(4200.00, $r['items'][1]['vat']);
        self::assertSame(20000.00, $r['items'][1]['base']);
    }

    public function testEmptyItemsReturnsZeros(): void
    {
        $r = InvoiceMath::compute([]);
        self::assertSame(0.0, $r['totals']['without_vat']);
        self::assertSame(0.0, $r['totals']['vat']);
        self::assertSame(0.0, $r['totals']['with_vat']);
        self::assertSame([], $r['vat_breakdown']);
    }

    public function testRoundingHalfPenny(): void
    {
        // 7.255 → 7.26 (PHP round half-away-from-zero default)
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 7.255, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(7.26, $r['totals']['without_vat']);
        // VAT z 7.26 * 0.21 = 1.5246 → 1.52
        self::assertSame(1.52, $r['totals']['vat']);
        self::assertSame(8.78, $r['totals']['with_vat']);
    }

    public function testBottomUp21PctRoundsHalfUpMathematically(): void
    {
        // Issue #82: 151,50 × 21 % = přesně 31,815 → matematicky půl nahoru → 31,82.
        // Dělit až nakonec (base*rate/100), aby float reprezentace 0,21 nesrazila
        // výsledek na 31,81. Frontend (JS) počítá identicky.
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 151.50, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(151.50, $r['totals']['without_vat']);
        self::assertSame(31.82,  $r['totals']['vat']);
        self::assertSame(183.32, $r['totals']['with_vat']);
    }

    public function testDecimalQuantity(): void
    {
        // Hodiny: 1.5 × 1500 Kč/h = 2250
        $r = InvoiceMath::compute([
            ['quantity' => 1.5, 'unit_price_without_vat' => 1500.00, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(2250.00, $r['totals']['without_vat']);
        self::assertSame(472.50,  $r['totals']['vat']);
        self::assertSame(2722.50, $r['totals']['with_vat']);
    }

    public function testExactMinuteDurationOverridesLossyQuantityProjection(): void
    {
        $r = InvoiceMath::compute([
            [
                'quantity' => 0.017,
                'duration_minutes' => 1,
                'unit_price_without_vat' => 1000.00,
                'vat_rate_snapshot' => 0,
            ],
        ]);

        self::assertSame(16.67, $r['totals']['without_vat']);
        self::assertSame(16.67, $r['totals']['with_vat']);
    }

    public function testLegacyDecimalHoursRemainUnchangedWithoutDuration(): void
    {
        $r = InvoiceMath::compute([
            [
                'quantity' => 0.33,
                'duration_minutes' => null,
                'unit_price_without_vat' => 1000.00,
                'vat_rate_snapshot' => 0,
            ],
        ]);

        self::assertSame(330.00, $r['totals']['without_vat']);
    }

    public function testExactMinuteDurationSupportsCreditNoteSign(): void
    {
        $r = InvoiceMath::compute([
            [
                'quantity' => -0.017,
                'duration_minutes' => -1,
                'unit_price_without_vat' => 1000.00,
                'vat_rate_snapshot' => 21,
            ],
        ]);

        self::assertSame(-16.67, $r['totals']['without_vat']);
        self::assertSame(-3.50, $r['totals']['vat']);
        self::assertSame(-20.17, $r['totals']['with_vat']);
    }

    public function testExactMinuteAmountsRoundHalfUpForNetGrossAndCreditNotes(): void
    {
        foreach ([false, true] as $pricesIncludeVat) {
            foreach ([
                [60, 0.015, 0.02],
                [1, 0.9, 0.02],
                [20, 0.045, 0.02],
                [-60, 0.015, -0.02],
                [-1, 0.9, -0.02],
            ] as [$durationMinutes, $rate, $expected]) {
                $r = InvoiceMath::compute([[
                    'quantity' => $durationMinutes / 60,
                    'duration_minutes' => $durationMinutes,
                    'unit_price_without_vat' => $rate,
                    'vat_rate_snapshot' => 0,
                ]], pricesIncludeVat: $pricesIncludeVat);

                self::assertSame($expected, $r['totals']['with_vat']);
                self::assertSame($expected, $r['items'][0]['with']);
            }
        }
    }

    public function testPerItemTotalsReturned(): void
    {
        $r = InvoiceMath::compute([
            ['quantity' => 2, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(200.00, $r['items'][0]['base']);
        self::assertSame(42.00,  $r['items'][0]['vat']);
        self::assertSame(242.00, $r['items'][0]['with']);
        self::assertSame(21.0,   $r['items'][0]['rate']);
    }

    public function testZeroVatRateDoesNotProduceVatTax(): void
    {
        // Položky se sazbou 0% (osvobozené)
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 0],
        ]);
        self::assertSame(1000.00, $r['totals']['without_vat']);
        self::assertSame(0.00,    $r['totals']['vat']);
        self::assertSame(1000.00, $r['totals']['with_vat']);
    }

    public function testNegativeDiscountLineReducesTotalsAndBreakdown(): void
    {
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => -100.00, 'vat_rate_snapshot' => 21],
        ]);

        self::assertSame(900.00, $r['totals']['without_vat']);
        self::assertSame(189.00, $r['totals']['vat']);
        self::assertSame(1089.00, $r['totals']['with_vat']);
        self::assertCount(1, $r['vat_breakdown']);
        self::assertSame(900.00, $r['vat_breakdown'][0]['base']);
        self::assertSame(189.00, $r['vat_breakdown'][0]['vat']);
    }

    // ─── Režim SHORA (prices_include_vat = true) ────────────────────────────
    // Cena položky je VČETNĚ DPH (gross); DPH se počítá koeficientem rate/(100+rate).
    // Zde je veškeré daňové riziko — celek MUSÍ sedět na haléř a SUM(řádkový vat)
    // per sazba == round(SUM(gross) × koeficient), aby DPHDP3/KH/kniha seděly.

    public function testTopDownSingleItem21PctMatchesToTheCent(): void
    {
        // Klasický problém z plánu: 33 Kč s DPH @21 %. Zdola by ×1,21 nesedělo (32,9967).
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        self::assertSame(27.27, $r['totals']['without_vat']);
        self::assertSame(5.73,  $r['totals']['vat']);
        self::assertSame(33.00, $r['totals']['with_vat']); // sedí přesně
        self::assertSame(27.27, $r['items'][0]['base']);
        self::assertSame(5.73,  $r['items'][0]['vat']);
        self::assertSame(33.00, $r['items'][0]['with']);
        self::assertSame(27.27, $r['vat_breakdown'][0]['base']);
        self::assertSame(5.73,  $r['vat_breakdown'][0]['vat']);
    }

    public function testTopDownReceipt344MatchesPlanExpectation(): void
    {
        // Účtenka 344 Kč s DPH @21 % → base 284,30 / DPH 59,70 (viz plán).
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 344.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        self::assertSame(284.30, $r['totals']['without_vat']);
        self::assertSame(59.70,  $r['totals']['vat']);
        self::assertSame(344.00, $r['totals']['with_vat']);
    }

    public function testTopDownRoundingDistributionAcrossSameRateLines(): void
    {
        // Tři řádky 33 Kč s DPH @21 %. Per-řádek vat = 5,73 → součet 17,19, ale daň
        // z celého grossu 99 koeficientem = round(99×21/121) = 17,18. Reziduum −0,01
        // se dorovná na nejsilnějším řádku (zde první), aby SUM(vat) == 17,18.
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        // SUM řádkové daně přesně odpovídá koeficientové dani z celkového grossu.
        self::assertSame(17.18, $r['totals']['vat']);
        self::assertSame(81.82, $r['totals']['without_vat']);
        self::assertSame(99.00, $r['totals']['with_vat']);
        // Reziduum dorovnáno na prvním (nejsilnějším) řádku: 5,72 místo 5,73.
        self::assertSame(5.72,  $r['items'][0]['vat']);
        self::assertSame(27.28, $r['items'][0]['base']);
        self::assertSame(5.73,  $r['items'][1]['vat']);
        self::assertSame(5.73,  $r['items'][2]['vat']);
        // Breakdown per sazba sedí s koeficientem (klíčové pro KH/DPHDP3).
        self::assertSame(81.82, $r['vat_breakdown'][0]['base']);
        self::assertSame(17.18, $r['vat_breakdown'][0]['vat']);
        // Invariant: součet řádkové daně == daň z celkového grossu koeficientem.
        $lineVatSum = $r['items'][0]['vat'] + $r['items'][1]['vat'] + $r['items'][2]['vat'];
        self::assertSame(round(99.0 * 21 / 121, 2), round($lineVatSum, 2));
    }

    public function testTopDownMixedRatesEachMatchesCoefficient(): void
    {
        // Mix 21/12/0, vše s DPH. Každá sazba se počítá zvlášť koeficientem.
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 21], // vat 17,36 / base 82,64
            ['quantity' => 1, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 12], // vat 10,71 / base 89,29
            ['quantity' => 1, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 0],  // vat 0     / base 100
        ], pricesIncludeVat: true);

        self::assertSame(300.00, $r['totals']['with_vat']); // gross total přesně
        self::assertSame(28.07,  $r['totals']['vat']);      // 17,36 + 10,71
        self::assertSame(271.93, $r['totals']['without_vat']);

        // Breakdown DESC dle sazby: 21, 12, 0
        self::assertSame(21.0, $r['vat_breakdown'][0]['rate']);
        self::assertSame(82.64, $r['vat_breakdown'][0]['base']);
        self::assertSame(17.36, $r['vat_breakdown'][0]['vat']);
        self::assertSame(12.0, $r['vat_breakdown'][1]['rate']);
        self::assertSame(89.29, $r['vat_breakdown'][1]['base']);
        self::assertSame(10.71, $r['vat_breakdown'][1]['vat']);
        self::assertSame(0.0, $r['vat_breakdown'][2]['rate']);
        self::assertSame(100.00, $r['vat_breakdown'][2]['base']);
        self::assertSame(0.00, $r['vat_breakdown'][2]['vat']);
    }

    public function testTopDownReverseChargeZeroesTaxAndKeepsGrossAsBase(): void
    {
        // RC + prices_include_vat: daň 0 (odvede zákazník), základ = celý gross.
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 121.00, 'vat_rate_snapshot' => 21],
        ], reverseCharge: true, pricesIncludeVat: true);

        self::assertSame(121.00, $r['totals']['without_vat']);
        self::assertSame(0.00,   $r['totals']['vat']);
        self::assertSame(121.00, $r['totals']['with_vat']);
        self::assertSame(21.0,   $r['vat_breakdown'][0]['rate']); // nominální sazba zůstává
        self::assertSame(0.00,   $r['vat_breakdown'][0]['vat']);
    }

    public function testTopDownZeroRateLeavesPriceUnchanged(): void
    {
        // Neplátce / osvobozeno (sazba 0) — cena beze změny v obou režimech.
        $r = InvoiceMath::compute([
            ['quantity' => 2, 'unit_price_without_vat' => 500.00, 'vat_rate_snapshot' => 0],
        ], pricesIncludeVat: true);

        self::assertSame(1000.00, $r['totals']['without_vat']);
        self::assertSame(0.00,    $r['totals']['vat']);
        self::assertSame(1000.00, $r['totals']['with_vat']);
    }

    public function testTopDownDisplayNetUnitPriceDerivation(): void
    {
        // DUALITA ZOBRAZENÍ: v režimu „ceny s DPH" nese unit_price_without_vat brutto,
        // ale UI/PDF/exporty ukazují NETTO jednotkovou cenu = round(řádkový base / množství).
        // Tento test fixuje invariant, na kterém ta zobrazení stojí.
        // 2 ks × 605 Kč s DPH @21 % → řádkový gross 1210, base 1000, DPH 210.
        $r = InvoiceMath::compute([
            ['quantity' => 2, 'unit_price_without_vat' => 605.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        self::assertSame(1000.00, $r['items'][0]['base']);
        self::assertSame(210.00,  $r['items'][0]['vat']);
        self::assertSame(1210.00, $r['items'][0]['with']);

        // Netto jednotková cena pro zobrazení = base / množství = 500,00.
        $qty = 2.0;
        $displayNetUnit = round($r['items'][0]['base'] / $qty, 2);
        self::assertSame(500.00, $displayNetUnit);
        // A zpětně: netto × množství × (1+sazba) musí dát přesně řádkový gross.
        self::assertSame(1210.00, round($displayNetUnit * $qty * 1.21, 2));
    }

    public function testDisplayNetUnitPriceEqualsRawInBottomUpMode(): void
    {
        // V běžném režimu (zdola) je unit_price_without_vat už netto → zobrazení musí být
        // identické s uloženou jednotkovou cenou (base/qty == unit_price).
        $r = InvoiceMath::compute([
            ['quantity' => 3, 'unit_price_without_vat' => 333.33, 'vat_rate_snapshot' => 21],
        ]);
        $displayNetUnit = round($r['items'][0]['base'] / 3.0, 2);
        self::assertSame(333.33, $displayNetUnit);
    }

    public function testReceiptVatPayerGross1200ExactToCent(): void
    {
        // Účtenka u PLÁTCE DPH: 1× 1200 Kč včetně DPH @21 % (reálný případ z provozu).
        // Koeficient: DPH = round(1200×21/121) = 208,26, základ = 991,74, celkem přesně 1200.
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1200.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        self::assertSame(991.74,  $r['totals']['without_vat']);
        self::assertSame(208.26,  $r['totals']['vat']);
        self::assertSame(1200.00, $r['totals']['with_vat']);
        // Zobrazené netto/ks = base/qty.
        self::assertSame(991.74, round($r['items'][0]['base'] / 1.0, 2));
    }

    public function testReceiptNonVatPayerGrossEqualsNetNoTax(): void
    {
        // Účtenka u NEPLÁTCE DPH: žádná DPH (sazba 0). I když je zapnutý režim „ceny s DPH",
        // brutto == netto a daň 0 — nesmí vzniknout fiktivní DPH ani změna ceny.
        $r = InvoiceMath::compute([
            ['quantity' => 2, 'unit_price_without_vat' => 300.00, 'vat_rate_snapshot' => 0],
        ], pricesIncludeVat: true);

        self::assertSame(600.00, $r['totals']['without_vat']);
        self::assertSame(0.00,   $r['totals']['vat']);
        self::assertSame(600.00, $r['totals']['with_vat']);
        self::assertSame(0.0,    $r['vat_breakdown'][0]['rate']);
        self::assertSame(0.00,   $r['vat_breakdown'][0]['vat']);
    }

    public function testSameGrossNumberInterpretedAsNetInflatesTotal(): void
    {
        // SIMULACE: unit_price_without_vat = 1200 (uživatel myslel cenu S DPH).
        //  - Správně (režim s DPH): celek 1200, základ 991,74, DPH 208,26.
        //  - Špatně (běžný režim, bere 1200 jako netto): celek 1452 → o 252 víc.
        // Test dokumentuje, PROČ musí příznak prices_include_vat putovat se všemi kopiemi
        // dokladu (proforma→faktura, dobropis, reissue) — jinak se totály nafouknou.
        $items = [['quantity' => 1, 'unit_price_without_vat' => 1200.00, 'vat_rate_snapshot' => 21]];

        $asGross = InvoiceMath::compute($items, pricesIncludeVat: true);
        self::assertSame(1200.00, $asGross['totals']['with_vat']);
        self::assertSame(208.26,  $asGross['totals']['vat']);

        $asNet = InvoiceMath::compute($items); // běžná varianta (zdola)
        self::assertSame(1452.00, $asNet['totals']['with_vat']);
        self::assertSame(252.00,  $asNet['totals']['vat']);

        // Rozdíl celku = 252 Kč — přesně nafouknutá DPH při záměně režimu.
        self::assertSame(252.00, round($asNet['totals']['with_vat'] - $asGross['totals']['with_vat'], 2));
    }

    public function testReceiptVatPayerGrossMixedRatesRoundingResiduum(): void
    {
        // Účtenka plátce, více sazeb, ať se ověří per-sazba koeficient + haléřové reziduum.
        // 3× 33 @21 % (gross 99 → DPH 17,18) + 2× 50 @12 % (gross 100 → DPH 10,71).
        $r = InvoiceMath::compute([
            ['quantity' => 3, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 2, 'unit_price_without_vat' => 50.00, 'vat_rate_snapshot' => 12],
        ], pricesIncludeVat: true);

        self::assertSame(199.00, $r['totals']['with_vat']); // 99 + 100 přesně
        // 21 %: round(99×21/121)=17,18 ; 12 %: round(100×12/112)=10,71
        self::assertSame(27.89, $r['totals']['vat']);
        self::assertSame(171.11, $r['totals']['without_vat']);
        // Invariant per sazba (klíčové pro KH/přiznání).
        self::assertSame(17.18, $r['vat_breakdown'][0]['vat']);
        self::assertSame(10.71, $r['vat_breakdown'][1]['vat']);
    }

    // ─── Override rekapitulace DPH per sazba (přijaté faktury, § 73 ZDPH) ────────

    public function testVatOverrideMatchesSupplierDocument(): void
    {
        // Issue #82 / Alza: vypočtené DPH 31,82, ale doklad uvádí 31,81. Override DPH
        // té sazby srovná řádek i totály přesně na doklad.
        $items = [['quantity' => 1, 'unit_price_without_vat' => 151.50, 'vat_rate_snapshot' => 21]];
        $r = InvoiceMath::compute($items, false, false, [
            ['rate' => 21, 'base' => 151.50, 'vat' => 31.81],
        ]);
        self::assertSame(151.50, $r['totals']['without_vat']);
        self::assertSame(31.81,  $r['totals']['vat']);
        self::assertSame(183.31, $r['totals']['with_vat']);
        self::assertSame(31.81,  $r['items'][0]['vat']);
        self::assertSame(183.31, $r['items'][0]['with']);
        self::assertSame(31.81,  $r['vat_breakdown'][0]['vat']);
    }

    public function testVatOverrideDistributesResidualToHeaviestLine(): void
    {
        // Víc řádků téže sazby: override cílí součet sazby, reziduum padne na nejsilnější
        // řádek (max |base|). 100 + 51,50 base; vypočtené vat 21,00 + 10,82 = 31,82.
        // Override vat=31,81 → reziduum −0,01 na řádku s base 100.
        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 21], // base 100, vat 21,00
            ['quantity' => 1, 'unit_price_without_vat' => 51.50,  'vat_rate_snapshot' => 21], // base 51,50, vat 10,82
        ];
        $r = InvoiceMath::compute($items, false, false, [
            ['rate' => 21, 'vat' => 31.81],
        ]);
        self::assertSame(31.81, $r['totals']['vat']);
        self::assertSame(20.99, $r['items'][0]['vat']); // reziduum na nejsilnějším řádku
        self::assertSame(10.82, $r['items'][1]['vat']);
        self::assertSame(31.81, $r['vat_breakdown'][0]['vat']);
    }

    public function testVatOverrideMultipleRatesIndependently(): void
    {
        // Multi-rate doklad: override 21 % i 12 % zvlášť dle rekapitulace dokladu.
        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 151.50, 'vat_rate_snapshot' => 21], // vat 31,82
            ['quantity' => 1, 'unit_price_without_vat' => 87.50,  'vat_rate_snapshot' => 12], // vat 10,50
        ];
        $r = InvoiceMath::compute($items, false, false, [
            ['rate' => 21, 'base' => 151.50, 'vat' => 31.81],
            ['rate' => 12, 'base' => 87.50,  'vat' => 10.49],
        ]);
        self::assertSame(42.30, $r['totals']['vat']); // 31,81 + 10,49
        self::assertSame(239.00, $r['totals']['without_vat']);
        self::assertSame(281.30, $r['totals']['with_vat']);
        // Breakdown DESC: 21 %, 12 %
        self::assertSame(31.81, $r['vat_breakdown'][0]['vat']);
        self::assertSame(10.49, $r['vat_breakdown'][1]['vat']);
    }

    public function testVatOverrideCanAdjustBaseToo(): void
    {
        // Override umí přepsat základ i daň (potvrzeno uživatelem).
        $items = [['quantity' => 1, 'unit_price_without_vat' => 151.50, 'vat_rate_snapshot' => 21]];
        $r = InvoiceMath::compute($items, false, false, [
            ['rate' => 21, 'base' => 151.49, 'vat' => 31.81],
        ]);
        self::assertSame(151.49, $r['totals']['without_vat']);
        self::assertSame(31.81,  $r['totals']['vat']);
        self::assertSame(183.30, $r['totals']['with_vat']);
        self::assertSame(151.49, $r['items'][0]['base']);
    }

    public function testEmptyVatOverridesLeaveComputationUnchanged(): void
    {
        $items = [['quantity' => 1, 'unit_price_without_vat' => 151.50, 'vat_rate_snapshot' => 21]];
        $base = InvoiceMath::compute($items);
        $withEmpty = InvoiceMath::compute($items, false, false, []);
        self::assertSame($base['totals'], $withEmpty['totals']);
        self::assertSame(31.82, $withEmpty['totals']['vat']); // beze změny = matematická hodnota
    }

    public function testVatOverrideIgnoredUnderReverseCharge(): void
    {
        // RC: na dokladu zahraničního dodavatele není česká DPH → override se ignoruje.
        $items = [['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 21]];
        $r = InvoiceMath::compute($items, true, false, [
            ['rate' => 21, 'vat' => 210.00],
        ]);
        self::assertSame(0.00,    $r['totals']['vat']);
        self::assertSame(1000.00, $r['totals']['without_vat']);
    }

    public function testTopDownCreditNoteNegativeQuantity(): void
    {
        // Dobropis v režimu „ceny s DPH": záporné množství, brutto cena. Koeficient musí
        // dát záporný základ/daň a celek = záporné brutto (zrcadlí původní fakturu).
        $r = InvoiceMath::compute([
            ['quantity' => -1, 'unit_price_without_vat' => 1210.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        self::assertSame(-1210.00, $r['totals']['with_vat']);
        self::assertSame(-210.00,  $r['totals']['vat']);
        self::assertSame(-1000.00, $r['totals']['without_vat']);
    }

    public function testBottomUpCreditNoteNegativeQuantity(): void
    {
        // Dobropis v běžném režimu (zdola): záporné množství, netto cena.
        $r = InvoiceMath::compute([
            ['quantity' => -2, 'unit_price_without_vat' => 500.00, 'vat_rate_snapshot' => 21],
        ]);

        self::assertSame(-1000.00, $r['totals']['without_vat']);
        self::assertSame(-210.00,  $r['totals']['vat']);
        self::assertSame(-1210.00, $r['totals']['with_vat']);
    }

    public function testBottomUpUnchangedWhenFlagFalse(): void
    {
        // Regrese: stejná data zdola (default) vs. shora dají RŮZNÝ základ/daň —
        // potvrzuje, že příznak skutečně přepíná metodu a default zůstává beze změny.
        $items = [['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21]];

        $down = InvoiceMath::compute($items); // zdola (default)
        self::assertSame(33.00, $down['totals']['without_vat']);
        self::assertSame(6.93,  $down['totals']['vat']);   // 33 × 0,21
        self::assertSame(39.93, $down['totals']['with_vat']);

        $up = InvoiceMath::compute($items, pricesIncludeVat: true); // shora
        self::assertSame(27.27, $up['totals']['without_vat']);
        self::assertSame(5.73,  $up['totals']['vat']);
        self::assertSame(33.00, $up['totals']['with_vat']);
    }
}
