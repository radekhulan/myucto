<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Payroll;

use MyInvoice\Service\Accounting\Payroll\PayrollCalculator;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Očekávané hodnoty jsou vzaté z REÁLNÉHO deníku účetní (2024–2026), ne z opakování
 * vzorců v testu — proto test chytí i chybu v samotném modelu zaokrouhlení.
 */
final class PayrollCalculatorTest extends TestCase
{
    /**
     * @return array<string, array{0:float, 1:int, 2:array<string,int>}>
     */
    public static function realLedgerCases(): array
    {
        return [
            // 2024: hrubá 4 000, min. mzda 18 900 → doplatek 0,135 × 14 900 = 2 011,50 → 2 011.
            // Pozor na ZP zaměstnavatele 361 (ne 360): je to dopočet do 13,5 % z 18 900
            // (= 2 552), ne zaokrouhlených 9 % z 4 000.
            '2024 / hrubá 4 000' => [4000.0, 2024, [
                'employee_social'     => 284,
                'employee_health'     => 180,
                'health_min_topup'    => 2011,
                'employee_deductions' => 2475,
                'advance_tax'         => 600,
                'net'                 => 925,
                'employer_social'     => 992,
                'employer_health'     => 361,
                'employer_total'      => 1353,
                'health_total'        => 2552,
                'social_total'        => 1276,
                'remittance_total'    => 4428,
            ]],
            // 2025: hrubá 4 500, min. mzda 20 800 → doplatek 0,135 × 16 300 = 2 200,50 → 2 200.
            '2025 / hrubá 4 500' => [4500.0, 2025, [
                'employee_social'     => 320,
                'employee_health'     => 203,
                'health_min_topup'    => 2200,
                'employee_deductions' => 2723,
                'advance_tax'         => 675,
                'net'                 => 1102,
                'employer_social'     => 1116,
                'employer_health'     => 405,
                'employer_total'      => 1521,
                'health_total'        => 2808,
                'social_total'        => 1436,
                'remittance_total'    => 4919,
            ]],
            // 2026: hrubá 4 500, min. mzda 22 400 → doplatek 0,135 × 17 900 = 2 416,50 → 2 416.
            // Ověřeno proti podáním za 6/2026: přehled ZP (VZ 22 400, pojistné 3 024),
            // JMHZ XML pro ČSSZ (základ 4 500, zaměstnavatel 1 116, zaměstnanec 320,
            // celkem 1 436, záloha daně 675) a hromadnému příkazu k úhradě 5 135 Kč.
            '2026 / hrubá 4 500' => [4500.0, 2026, [
                'employee_social'     => 320,
                'employee_health'     => 203,
                'health_min_topup'    => 2416,
                'employee_deductions' => 2939,
                'advance_tax'         => 675,
                'net'                 => 886,
                'employer_social'     => 1116,
                'employer_health'     => 405,
                'employer_total'      => 1521,
                'health_total'        => 3024,
                'social_total'        => 1436,
                'remittance_total'    => 5135,
            ]],
        ];
    }

    /**
     * Hromadný příkaz k úhradě od účetní za 6/2026 (prik_mzd_odv202606): tři platby —
     * ZP 3 024 na VZP, OSSZ 1 436, zálohová daň 675, celkem 5 135 Kč.
     *
     * Vlastní test proto, že tohle je jediná trojice čísel, kterou jde porovnat s tím,
     * co odchází z účtu. Rozpad výš je pohled zaměstnance (z 4 500 mu zbude 886) a
     * `employer_*` pohled nákladu (1 521) — ani jeden se s příkazem nepotká, a přesně
     * proto se rekapitulace jevila jako „nesedí na účetní", i když sedí na korunu.
     */
    public function testRemittanceMatchesAccountantPaymentOrder(): void
    {
        $b = PayrollCalculator::compute(4500.0, TaxConstants::forYear(2026));

        self::assertSame(3024, $b['health_total'], 'ZP — přehled o platbě pojistného 6/2026');
        self::assertSame(1436, $b['social_total'], 'SP — JMHZ pojistneCelkem 6/2026');
        self::assertSame(675, $b['advance_tax'], 'záloha na daň — JMHZ danZalohaPoSleve 6/2026');
        self::assertSame(5135, $b['remittance_total'], 'součet příkazu k úhradě');
        self::assertSame(
            $b['health_total'] + $b['social_total'] + $b['advance_tax'],
            $b['remittance_total'],
            'remittance_total musí být součtem tří plateb příkazu, ne samostatný výpočet'
        );
    }

    /**
     * Co odejde z účtu (odvody na ZP/OSSZ/FÚ + čistá mzda) musí přesně vyčerpat
     * celkový náklad zaměstnavatele — doplatek do min. VZ se z identity vykrátí,
     * protože zaměstnanci sníží čistou mzdu a zároveň zvýší odvod na ZP.
     * Kdyby se některá složka počítala dvakrát nebo chyběla, rekapitulace by se
     * rozešla s bankou.
     * @param array<string,int> $expected
     */
    #[DataProvider("realLedgerCases")]
    public function testRemittancePlusNetEqualsSuperGross(float $gross, int $year, array $expected): void
    {
        $b = PayrollCalculator::compute($gross, TaxConstants::forYear($year));
        self::assertSame(
            $b['super_gross'],
            $b['remittance_total'] + $b['net'],
            'odvody + čistá mzda = superhrubá mzda'
        );
    }

    /**
     * @param array<string,int> $expected
     */
    #[DataProvider("realLedgerCases")]
    public function testMatchesRealLedger(float $gross, int $year, array $expected): void
    {
        $b = PayrollCalculator::compute($gross, TaxConstants::forYear($year));
        foreach ($expected as $key => $value) {
            self::assertSame($value, $b[$key], "{$year}: {$key}");
        }
    }

    /**
     * Kontrola uzávěru: srážky + záloha + čistá = hrubá. Kdyby některá složka
     * "zmizela", rozpad by přestal sedět a zápis by nešel vyvážit.
     * @param array<string,int> $expected
     */
    #[DataProvider("realLedgerCases")]
    public function testGrossReconciles(float $gross, int $year, array $expected): void
    {
        $b = PayrollCalculator::compute($gross, TaxConstants::forYear($year));
        self::assertSame(
            (int) $gross,
            $b['employee_deductions'] + $b['advance_tax'] + $b['net'],
            'srážky + daň + čistá musí dát hrubou'
        );
    }

    /**
     * Úhrn zdravotního pojištění (zaměstnanec + doplatek + zaměstnavatel) musí sedět
     * na 13,5 % z vyměřovacího základu — to je smysl dopočtu ZP zaměstnavatele.
     * @param array<string,int> $expected
     */
    #[DataProvider("realLedgerCases")]
    public function testHealthTotalMatchesAssessmentBase(float $gross, int $year, array $expected): void
    {
        $c = TaxConstants::forYear($year);
        $b = PayrollCalculator::compute($gross, $c);

        self::assertSame(
            (int) ceil($c['minimum_wage'] * 0.135),
            $b['employee_health'] + $b['health_min_topup'] + $b['employer_health'],
            'úhrn ZP = 13,5 % z minimálního vyměřovacího základu'
        );
        self::assertSame($c['minimum_wage'], $b['assessment_base']);
    }

    /** Nad minimální mzdou se doplatek neuplatní a ZP zaměstnavatele je čistých 9 %. */
    public function testAboveMinimumWageNoTopup(): void
    {
        $b = PayrollCalculator::compute(50000.0, TaxConstants::forYear(2025));

        self::assertSame(0, $b['health_min_topup']);
        self::assertSame(50000, $b['assessment_base']);
        self::assertSame(2250, $b['employee_health']);   // 4,5 %
        self::assertSame(4500, $b['employer_health']);   // 9 % = dopočet do 13,5 %
        self::assertSame(3550, $b['employee_social']);   // 7,1 %
        self::assertSame(12400, $b['employer_social']);  // 24,8 %
        self::assertSame(7500, $b['advance_tax']);       // 15 %
        self::assertSame(36700, $b['net']);
    }

    /** Přesně na minimální mzdě je doplatek nula (hraniční případ). */
    public function testExactlyAtMinimumWage(): void
    {
        $b = PayrollCalculator::compute(20800.0, TaxConstants::forYear(2025));

        self::assertSame(0, $b['health_min_topup']);
        self::assertSame(936, $b['employee_health']);
        self::assertSame(1872, $b['employer_health']);
        self::assertSame(2808, $b['health_total']);
    }

    public function testAccountsByTaxpayerType(): void
    {
        self::assertSame(
            ['expense' => '521', 'payable' => '331'],
            PayrollCalculator::accounts(PayrollCalculator::TYPE_EMPLOYEE)
        );
        self::assertSame(
            ['expense' => '522', 'payable' => '366'],
            PayrollCalculator::accounts(PayrollCalculator::TYPE_MANAGING_PARTNER)
        );
    }

    /** Zápis musí být vyvážený a u jednatele musí použít 522/366. */
    public function testLinesAreBalancedAndUseManagingPartnerAccounts(): void
    {
        $b = PayrollCalculator::compute(4500.0, TaxConstants::forYear(2025));
        $lines = PayrollCalculator::lines($b, PayrollCalculator::TYPE_MANAGING_PARTNER);

        $debit = $credit = 0.0;
        foreach ($lines as $l) {
            $l['side'] === 'debit' ? $debit += $l['amount'] : $credit += $l['amount'];
        }
        self::assertSame($debit, $credit, 'MD musí rovnat se D');

        $codes = array_column($lines, 'account_code');
        self::assertContains('522', $codes);
        self::assertContains('366', $codes);
        self::assertNotContains('521', $codes);
        self::assertNotContains('331', $codes);

        // Na účtu poplatníka zbyde čistá mzda (4 500 D − 2 723 MD − 675 MD = 1 102).
        $balance = 0.0;
        foreach ($lines as $l) {
            if ($l['account_code'] !== '366') continue;
            $balance += $l['side'] === 'credit' ? $l['amount'] : -$l['amount'];
        }
        self::assertSame((float) $b['net'], $balance);
    }

    // ── Základ pro zálohu na daň (§38h odst. 1 ZDP) ─────────────────────────
    // Golden hodnoty z deníku účetní mají hrubou mzdu v kulatých stovkách, takže
    // zaokrouhlení základu na stokoruny nahoru v nich NENÍ vidět — proto tyhle testy.

    /** Hrubá 24 850 → základ 24 900 (ne 24 850), záloha 3 735 (ne 3 728). */
    public function testTaxBaseRoundsUpToWholeHundreds(): void
    {
        $b = PayrollCalculator::compute(24850.0, TaxConstants::forYear(2026));

        self::assertSame(24900, $b['tax_base'], 'základ zálohy se zaokrouhlí na stokoruny nahoru');
        self::assertSame(3735, $b['advance_tax'], '15 % z 24 900');
        self::assertSame(1765, $b['employee_social'], 'pojistné se počítá z hrubé, ne ze zaokrouhleného základu');
        self::assertSame(1119, $b['employee_health']);
        self::assertSame(18231, $b['net'], '24 850 − 1 765 − 1 119 − 3 735');
    }

    /** Kulatá stovka se nezaokrouhluje — jinak by se rozešly golden hodnoty z deníku. */
    public function testTaxBaseLeavesWholeHundredsUntouched(): void
    {
        $b = PayrollCalculator::compute(4500.0, TaxConstants::forYear(2026));

        self::assertSame(4500, $b['tax_base']);
        self::assertSame(675, $b['advance_tax']);
    }

    /** Do 100 Kč se základ zaokrouhluje na celé koruny, ne na stokoruny. */
    public function testTaxBaseBelowHundredRoundsToWholeKoruna(): void
    {
        $b = PayrollCalculator::compute(90.4, TaxConstants::forYear(2026));

        self::assertSame(91, $b['tax_base']);
        self::assertSame(14, $b['advance_tax']);
    }

    // ── Progresivní sazba (§38h odst. 2 ZDP) ─────────────────────────────────

    /** Měsíční hranice = 3× průměrná mzda = roční strop SP (48×) / 16. */
    public function testHighRateThresholdMatchesSocialMaxBase(): void
    {
        foreach ([2024, 2025, 2026] as $year) {
            $c = TaxConstants::forYear($year);
            self::assertSame(
                intdiv((int) $c['social_max_base'], 16),
                (int) $c['advance_tax_high_threshold'],
                "hranice §38h odst. 2 pro {$year} = social_max_base / 16"
            );
        }
    }

    /** Pod hranicí se nic nemění — běžné mzdy zůstávají na 15 %. */
    public function testBelowHighThresholdUsesLowRateOnly(): void
    {
        $c = TaxConstants::forYear(2026);
        $b = PayrollCalculator::compute(146900.0, $c);

        self::assertSame(146900, $b['tax_base']);
        self::assertSame(0, $b['tax_high_base']);
        self::assertSame(22035, $b['advance_tax'], '15 % z 146 900');
    }

    /**
     * Těsně nad hranicí je rozdíl proti plochým 15 % malý (248 Kč u hrubé 150 000),
     * takže by se chybějící progrese dala snadno přehlédnout — hlídáno explicitně.
     */
    public function testJustAboveHighThresholdDiffersFromFlatRate(): void
    {
        $c = TaxConstants::forYear(2026);
        $b = PayrollCalculator::compute(150000.0, $c);

        self::assertSame(3099, $b['tax_high_base'], '150 000 − 146 901');
        self::assertSame(22748, $b['advance_tax']);
        self::assertSame(248, $b['advance_tax'] - 22500, 'o 248 Kč víc než plochých 15 %');
    }

    /** Nad hranicí se 23 % daní JEN část nad ní, ne celý základ. */
    public function testAboveHighThresholdTaxesOnlyTheExcess(): void
    {
        $c = TaxConstants::forYear(2026);
        $b = PayrollCalculator::compute(250000.0, $c);

        self::assertSame(146901, $b['tax_high_threshold'], '3 × 48 967');
        self::assertSame(250000, $b['tax_base']);
        self::assertSame(103099, $b['tax_high_base'], '250 000 − 146 901');
        // 15 % × 146 901 = 22 035,15 + 23 % × 103 099 = 23 712,77 → 45 747,92 → 45 748
        self::assertSame(45748, $b['advance_tax']);
        self::assertNotSame(
            (int) ceil(250000 * 0.23),
            $b['advance_tax'],
            '23 % se nesmí uplatnit na celý základ'
        );
    }

    /**
     * Hranice §38h odst. 2 (146 901) není násobek stovky, takže o progresi rozhoduje
     * až ZAOKROUHLENÝ základ (§38h odst. 1): 146 900 je ještě celý v 15 %, kdežto
     * hrubá 146 901 se zaokrouhlí na 147 000 a 99 Kč spadne do 23 %.
     */
    public function testHighRateAppliesToRoundedBaseNotGross(): void
    {
        $c = TaxConstants::forYear(2026);

        $under = PayrollCalculator::compute(146900.0, $c);
        self::assertSame(146900, $under['tax_base']);
        self::assertSame(0, $under['tax_high_base']);

        $over = PayrollCalculator::compute(146901.0, $c);
        self::assertSame(147000, $over['tax_base'], 'zaokrouhlení nahoru přes hranici');
        self::assertSame(99, $over['tax_high_base'], '147 000 − 146 901');
        // 15 % × 146 901 = 22 035,15 + 23 % × 99 = 22,77 → 22 057,92 → 22 058
        self::assertSame(22058, $over['advance_tax']);
    }

    /** Bez hranice v konstantách se počítá jednou sazbou — starší roky beze změny. */
    public function testWithoutThresholdFallsBackToSingleRate(): void
    {
        $c = TaxConstants::forYear(2026);
        unset($c['advance_tax_high_threshold']);
        $b = PayrollCalculator::compute(250000.0, $c);

        self::assertSame(0, $b['tax_high_base']);
        self::assertSame(37500, $b['advance_tax'], '15 % z 250 000');
    }

    // ── Slevy na dani (§35ba, §38h odst. 4 ZDP) ─────────────────────────────

    /**
     * Bez předaných slev se rozpad chová jako dřív — tak to má i reálný deník
     * účetní, kde jednatel prohlášení podepsané nemá (JMHZ danZalohaPoSleve = 675).
     */
    public function testWithoutCreditsWithheldEqualsGrossAdvance(): void
    {
        $b = PayrollCalculator::compute(4500.0, TaxConstants::forYear(2026));

        self::assertSame(0, $b['credit_total']);
        self::assertSame(675, $b['advance_tax']);
        self::assertSame(675, $b['advance_tax_withheld']);
        self::assertSame(886, $b['net']);
    }

    /** S podepsaným prohlášením se sráží záloha snížená o 1/12 slevy na poplatníka. */
    public function testTaxpayerCreditReducesWithheldAdvance(): void
    {
        $c = TaxConstants::forYear(2026);
        $credits = PayrollCalculator::monthlyCredits($c, true, 0);
        $b = PayrollCalculator::compute(24850.0, $c, $credits);

        self::assertSame(2570, $credits['taxpayer'], '30 840 / 12');
        self::assertSame(3735, $b['advance_tax'], 'záloha před slevou zůstává viditelná');
        self::assertSame(1165, $b['advance_tax_withheld'], '3 735 − 2 570');
        self::assertSame(20801, $b['net'], '24 850 − 1 765 − 1 119 − 1 165');
        self::assertSame(
            $b['health_total'] + $b['social_total'] + $b['advance_tax_withheld'],
            $b['remittance_total'],
            'na FÚ odchází sražená záloha, ne hrubá'
        );
    }

    /** Sleva vyšší než záloha nesmí vyrobit zápornou daň ani bonus (§35ba odst. 1). */
    public function testCreditIsCappedAtAdvanceTax(): void
    {
        $c = TaxConstants::forYear(2026);
        $b = PayrollCalculator::compute(4500.0, $c, PayrollCalculator::monthlyCredits($c, true, 2));

        self::assertSame(675, $b['advance_tax']);
        self::assertSame(0, $b['advance_tax_withheld'], 'ořez na nulu, ne −2 887');
        self::assertSame(1561, $b['net'], '4 500 − 2 939 − 0');
    }

    /**
     * Na účet zálohové daně patří sražená záloha po slevě, ne hrubá — jinak by
     * přeplácela FÚ. Výchozí kontace je od Ú-13 analytická (342.100), protože
     * srážková daň dostala vlastní účet 342.200.
     */
    public function testLinesPostWithheldAdvanceToTaxAccount(): void
    {
        $c = TaxConstants::forYear(2026);
        $b = PayrollCalculator::compute(24850.0, $c, PayrollCalculator::monthlyCredits($c, true, 0));
        $lines = PayrollCalculator::lines($b, PayrollCalculator::TYPE_MANAGING_PARTNER);

        $tax = 0.0;
        $debit = $credit = 0.0;
        foreach ($lines as $l) {
            if ($l['account_code'] === '342.100') $tax += $l['amount'];
            $l['side'] === 'debit' ? $debit += $l['amount'] : $credit += $l['amount'];
        }
        self::assertSame(1165.0, $tax, 'na 342.100 jde záloha po slevě');
        self::assertSame($debit, $credit, 'zápis musí zůstat vyvážený');

        // Na účtu poplatníka zbyde čistá mzda včetně nesražené slevy.
        $balance = 0.0;
        foreach ($lines as $l) {
            if ($l['account_code'] !== '366') continue;
            $balance += $l['side'] === 'credit' ? $l['amount'] : -$l['amount'];
        }
        self::assertSame((float) $b['net'], $balance);
    }

    // ── §15a z. 589/1992 — maximální vyměřovací základ SP ──────────────────────

    /**
     * Bez kontextu zaměstnance se strop NEUPLATNÍ. Není to opomenutí: `compute()` se
     * volá i nad REKAPITULACÍ za všechny zaměstnance, kde je součet hrubých mezd
     * legitimně vyšší než strop jednotlivce — kdyby se krátilo i tam, srazilo by to
     * pojistné celé firmě.
     */
    public function testWithoutEmployeeContextSocialIsNotCapped(): void
    {
        $c = TaxConstants::forYear(2025);
        $gross = (float) $c['social_max_base'] * 2;

        $b = PayrollCalculator::compute($gross, $c);

        self::assertFalse($b['social_base_capped'], 'Bez ročního kontextu se krátit nesmí.');
        self::assertSame((int) round($gross), $b['social_base']);
    }

    /** Pod stropem se nic nemění, i když kontext zaměstnance známe. */
    public function testBelowAnnualCapNothingChanges(): void
    {
        $c = TaxConstants::forYear(2025);

        $withCtx = PayrollCalculator::compute(50_000.0, $c, null, 0.0);
        $withoutCtx = PayrollCalculator::compute(50_000.0, $c);

        self::assertFalse($withCtx['social_base_capped']);
        self::assertSame($withoutCtx['employee_social'], $withCtx['employee_social']);
        self::assertSame($withoutCtx['employer_social'], $withCtx['employer_social']);
    }

    /**
     * Zaměstnanec už stropu dosáhl → sociální pojistné je v dalším měsíci NULOVÉ
     * na obou stranách, ale zdravotní se platí dál z plné hrubé mzdy (jeho strop
     * byl zrušen v roce 2013).
     */
    public function testAboveAnnualCapStopsSocialButNotHealth(): void
    {
        $c = TaxConstants::forYear(2025);
        $max = (float) $c['social_max_base'];

        $b = PayrollCalculator::compute(100_000.0, $c, null, $max);

        self::assertTrue($b['social_base_capped']);
        self::assertSame(0, $b['social_base'], 'Zbývající základ je nula.');
        self::assertSame(0, $b['employee_social'], '§15a: nad stropem zaměstnanec SP neplatí.');
        self::assertSame(0, $b['employer_social'], '§15a: nad stropem neplatí ani zaměstnavatel.');
        self::assertGreaterThan(0, $b['employee_health'], 'ZP strop nemá — platí se dál.');
    }

    /** Měsíc, ve kterém se strop překročí, se rozdělí: pojistné jen ze zbytku do stropu. */
    public function testMonthCrossingTheCapIsProRated(): void
    {
        $c = TaxConstants::forYear(2025);
        $max = (float) $c['social_max_base'];
        $ytd = $max - 30_000.0;   // do stropu zbývá 30 000
        $gross = 100_000.0;       // z toho se pojistí jen 30 000

        $b = PayrollCalculator::compute($gross, $c, null, $ytd);

        self::assertTrue($b['social_base_capped']);
        self::assertSame(30_000, $b['social_base']);

        self::assertSame(
            (int) ceil(30_000.0 * (float) $c['payroll']['employee_social']),
            $b['employee_social'],
        );
        self::assertSame(
            (int) ceil(30_000.0 * (float) $c['payroll']['employer_social']),
            $b['employer_social'],
        );

        // Zdravotní se počítá dál z PLNÉ hrubé mzdy, ne ze zkráceného základu.
        self::assertSame(
            (int) ceil($gross * (float) $c['payroll']['employee_health']),
            $b['employee_health'],
        );
    }

    /** Čistá mzda musí sedět i po zkrácení — jinak by se rozpad rozešel sám se sebou. */
    public function testNetStaysConsistentWhenCapped(): void
    {
        $c = TaxConstants::forYear(2025);
        $b = PayrollCalculator::compute(100_000.0, $c, null, (float) $c['social_max_base']);

        self::assertSame(
            $b['employee_social'] + $b['employee_health'] + $b['health_min_topup'],
            $b['employee_deductions'],
        );
        self::assertSame(
            $b['gross'] - $b['employee_deductions'] - $b['advance_tax_withheld'],
            $b['net'],
        );
    }

    // ── Rozhodný příjem (§ 6 odst. 1 písm. a) z. 187/2006) ───────────────────
    //
    // Účast na nemocenském a tím i důchodovém pojištění vzniká až při DOSAŽENÍ
    // rozhodného příjmu. Pod ním jde o zaměstnání malého rozsahu (§ 7) a sociální
    // pojistné se neodvádí vůbec. Dřív se počítalo vždy z hrubé mzdy, takže se
    // strhávalo i tam, kde stát nic nenárokuje.

    /** Pod rozhodným příjmem se sociální pojistné neodvádí ani jednou stranou. */
    public function testBelowParticipationThresholdNoSocialInsurance(): void
    {
        $b = PayrollCalculator::compute(4_400.0, TaxConstants::forYear(2026));

        self::assertTrue($b['below_participation']);
        self::assertSame(0, $b['employee_social'], 'Zaměstnanec SP neplatí.');
        self::assertSame(0, $b['employer_social'], 'Zaměstnavatel SP neplatí taky.');
        self::assertSame(0, $b['social_base']);
    }

    /**
     * Hranice je „DOSÁHNE", ne „přesáhne" — přesně na rozhodném příjmu účast VZNIKÁ.
     * Potvrzeno reálným ELDP účetní za 06/2026 (kód S++, vyměřovací základ 4 500 Kč).
     */
    public function testExactlyAtThresholdParticipationArises(): void
    {
        $b = PayrollCalculator::compute(4_500.0, TaxConstants::forYear(2026));

        self::assertFalse($b['below_participation']);
        self::assertSame(320, $b['employee_social'], '7,1 % ze 4 500 zaokrouhleno nahoru.');
        self::assertSame(1_116, $b['employer_social'], '24,8 % ze 4 500.');
    }

    /**
     * Zdravotního pojištění se rozhodný příjem NETÝKÁ — tam žádný není a minimální
     * vyměřovací základ platí dál. Kdyby se vynulovalo i ZP, přišla by pojišťovna
     * o pojistné, které jí náleží.
     */
    public function testHealthInsuranceIsUnaffectedByThreshold(): void
    {
        $c = TaxConstants::forYear(2026);
        $b = PayrollCalculator::compute(4_400.0, $c);

        self::assertGreaterThan(0, $b['employee_health']);
        self::assertGreaterThan(0, $b['health_min_topup'], 'Doplatek do minimálního VZ zůstává.');
    }

    /** Hranice se bere z číselníku — 2024 měl 4 000 Kč, ne 4 500. */
    public function testThresholdComesFromYearConstants(): void
    {
        self::assertSame(4000, TaxConstants::forYear(2024)['sickness_participation_threshold']);
        self::assertSame(4500, TaxConstants::forYear(2026)['sickness_participation_threshold']);

        // 4 200 Kč: v roce 2024 účast VZNIKÁ (nad 4 000), v roce 2026 ne (pod 4 500).
        self::assertFalse(PayrollCalculator::compute(4_200.0, TaxConstants::forYear(2024))['below_participation']);
        self::assertTrue(PayrollCalculator::compute(4_200.0, TaxConstants::forYear(2026))['below_participation']);
    }

    public function testRejectsNegativeGross(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollCalculator::compute(-1.0, TaxConstants::forYear(2025));
    }

    public function testRejectsConstantsWithoutPayrollRates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollCalculator::compute(4500.0, ['minimum_wage' => 20800]);
    }

    // ── Zaokrouhlení zdravotního pojištění — jedno, ne po složkách ─────────────
    // Účetní počítá ÚHRN jako 13,5 % z vyměřovacího základu a zaměstnancovu část
    // dopočítává jako úhrn − zaměstnavatel. Kdyby se každá složka zaokrouhlila
    // nahoru zvlášť (4,5 % → 203 a doplatek 2 200,50 → 2 201), vyšlo by o korunu
    // víc na 336.200 a o korunu míň na čisté mzdě — a to KAŽDÝ měsíc, takže by
    // rozdíl narůstal (za rok 2025 by dělal 12 Kč).

    /**
     * @return array<string, array{0:int, 1:int, 2:int, 3:int, 4:int, 5:int}>
     *   rok, min. mzda, úhrn ZP, zaměstnavatel, zaměstnanec (4,5 % + doplatek), čistá mzda
     */
    public static function healthRoundingCases(): array
    {
        return [
            // 13,5 % × 20 800 = 2 808; zaměstnanec 2 808 − 405 = 2 403; čistá 1 102.
            '2025 / min. mzda 20 800' => [2025, 20800, 2808, 405, 2403, 1102],
            // 13,5 % × 22 400 = 3 024; zaměstnanec 3 024 − 405 = 2 619; čistá 886.
            '2026 / min. mzda 22 400' => [2026, 22400, 3024, 405, 2619, 886],
        ];
    }

    #[DataProvider("healthRoundingCases")]
    public function testHealthTotalIsRoundedOnceAndSplitExactly(
        int $year,
        int $minimumWage,
        int $healthTotal,
        int $employerHealth,
        int $employeeHealthShare,
        int $net,
    ): void {
        $c = TaxConstants::forYear($year);
        self::assertSame($minimumWage, $c['minimum_wage'], 'předpoklad testu — minimální mzda ročníku');

        $b = PayrollCalculator::compute(4500.0, $c);
        $employeeShare = $b['employee_health'] + $b['health_min_topup'];

        self::assertSame($healthTotal, $b['health_total'], 'úhrn = 13,5 % ze základu, zaokrouhleno JEDNOU');
        self::assertSame($employerHealth, $b['employer_health'], '9 % z hrubé mzdy');
        self::assertSame($employeeHealthShare, $employeeShare, 'zaměstnanec = úhrn − zaměstnavatel');
        self::assertSame(
            $b['health_total'],
            $employeeShare + $b['employer_health'],
            'složky ZP musí dát úhrn na korunu — jinak odvod na ZP nesedí s rozpadem'
        );
        self::assertSame($net, $b['net'], 'čistá mzda dle deníku účetní');
    }

    /**
     * Invariant „složky = úhrn" nesmí platit jen v golden hodnotách. Prochází se
     * široký rozsah hrubých mezd včetně těch, kde se doplatek do min. VZ láme na
     * půlkorunu a kde se ceil zaměstnancových 4,5 % potkává s floor doplatku.
     */
    public function testHealthPartsAlwaysSumToTotal(): void
    {
        foreach ([2024, 2025, 2026] as $year) {
            $c = TaxConstants::forYear($year);
            foreach ([0, 1, 7, 11, 100, 999, 4000, 4500, 4501, 10000, 18899, 20800, 22400, 33333, 150000] as $gross) {
                $b = PayrollCalculator::compute((float) $gross, $c);
                self::assertSame(
                    $b['health_total'],
                    $b['employee_health'] + $b['health_min_topup'] + $b['employer_health'],
                    "{$year} / hrubá {$gross}: složky ZP nedaly úhrn"
                );
                self::assertGreaterThanOrEqual(0, $b['employer_health'], "{$year} / hrubá {$gross}");
                self::assertGreaterThanOrEqual(0, $b['health_min_topup'], "{$year} / hrubá {$gross}");
            }
        }
    }

    /**
     * Doplatek do minimálního VZ vychází v každém ročníku na půlkorunu
     * (2024: 2 011,50 | 2025: 2 200,50 | 2026: 2 416,50). Zaokrouhluje se DOLŮ,
     * protože nahoru by úhrn ZP přestřelil 13,5 % z vyměřovacího základu.
     */
    public function testMinimumTopUpRoundsDownSoTotalHolds(): void
    {
        self::assertSame(2011, PayrollCalculator::compute(4000.0, TaxConstants::forYear(2024))['health_min_topup']);
        self::assertSame(2200, PayrollCalculator::compute(4500.0, TaxConstants::forYear(2025))['health_min_topup']);
        self::assertSame(2416, PayrollCalculator::compute(4500.0, TaxConstants::forYear(2026))['health_min_topup']);
    }
}
