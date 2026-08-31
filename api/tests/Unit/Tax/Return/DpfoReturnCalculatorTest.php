<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy výpočtu DPFO (Epic DP, issue #18). Hodnoty ručně dopočtené proti
 * konstantám 2025. Pokrývá: paušál, progrese 15/23 %, sleva na manželku, bonus na děti,
 * §15 dary (cap/min), §6+§7, ztráta §7 offset §9, čistá ztráta §7–§10.
 */
final class DpfoReturnCalculatorTest extends TestCase
{
    private DpfoReturnCalculator $calc;
    /** @var array<string,mixed> */
    private array $c;

    protected function setUp(): void
    {
        $this->calc = new DpfoReturnCalculator();
        $this->c = TaxConstants::forYear(2025);
    }

    /**
     * @param array<string,mixed> $data @param array<string,mixed> $inputs @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function calcRun(array $data, array $inputs = [], array $profile = []): array
    {
        $data += ['expense_mode' => 'pausal', 'expense_rate' => 60];
        return $this->calc->compute($data, $inputs, $profile, $this->c);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function line(array $lines, string $code): float
    {
        foreach ($lines as $l) {
            if ($l['line'] === $code) {
                return (float) $l['value'];
            }
        }
        throw new \RuntimeException("Řádek $code nenalezen");
    }

    public function testPausalSimpleOsvc(): void
    {
        // §7: příjmy 1M, paušál 60 % = 600k → základ 400k. Daň 15 % = 60 000; sleva 30 840.
        $r = $this->calcRun(['s7_income' => 1000000, 's7_expenses' => 600000, 's7_base' => 400000]);
        self::assertSame(400000.0, self::line($r['lines'], '42'));
        self::assertSame(60000.0, self::line($r['lines'], '57'));
        self::assertSame(29160.0, self::line($r['lines'], '64')); // 60000 − 30840
        self::assertSame(29160.0, (float) $r['tax']);
        self::assertSame(29160.0, (float) $r['balance_due']);
        self::assertSame(60000.0, (float) $r['fields']['da_dan16']);
    }

    public function testProgressiveRate23(): void
    {
        // Základ 2 000 000 přes §6. Práh 2025 = 1 676 052.
        $r = $this->calcRun(['s7_base' => 0], ['s6_employment' => ['income' => 2000000]]);
        // 1 676 052 × 0.15 + (2 000 000 − 1 676 052) × 0.23 = 251 407.80 + 74 508.04 = 325 915.84
        self::assertSame(325916.0, (float) $r['fields']['da_dan16']);
        self::assertSame(2000000.0, self::line($r['lines'], '42'));
    }

    public function testTaxIsRoundedUpAndChildBonusBelowOneHundredIsNotClaimed(): void
    {
        $rounded = $this->calcRun(['s7_base' => 1676100]);
        self::assertSame(251419.0, (float) $rounded['fields']['da_dan16']);

        $constants = $this->c;
        $constants['child_credits'] = [30950];
        $result = $this->calc->compute(
            ['s7_base' => 411600, 'expense_mode' => 'actual', 'expense_rate' => 0],
            [],
            ['children_count' => 1],
            $constants
        );
        self::assertSame(0.0, (float) $result['summary']['child_bonus']);
        self::assertNotEmpty(array_filter($result['warnings'], static fn (string $warning): bool => str_contains($warning, 'minimum 100 Kč')));
    }

    public function testSpouseCreditAndChildBonus(): void
    {
        $r = $this->calcRun(
            ['s7_base' => 200000],
            [],
            ['spouse_credit' => true, 'children_count' => 2]
        );
        // Daň 30 000 − slevy (30 840 + 24 840) → 0. Příjem ale nedosahuje minima
        // pro výplatu bonusu, proto se zvýhodnění nevyplatí jako vratitelný bonus.
        self::assertSame(0.0, self::line($r['lines'], '64'));
        self::assertSame(0.0, (float) $r['summary']['child_bonus']);
        self::assertSame(0.0, (float) $r['balance_due']);
        self::assertNotEmpty(array_filter(
            $r['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'nedosahuje zákonného minima')
        ));
    }

    public function testDonationCapAndMin(): void
    {
        // Dary 200k, základ 500k → cap 30 % = 150k (nadlimit → warning).
        $r = $this->calcRun(['s7_base' => 500000], [], ['donations' => 200000]);
        self::assertSame(150000.0, (float) $r['fields']['kc_op15_8']);
        self::assertSame(150000.0, self::line($r['lines'], '54'));
        self::assertSame(350000.0, self::line($r['lines'], '55'));

        // Dary 500 Kč pod spodním limitem → neodečtou se.
        $r2 = $this->calcRun(['s7_base' => 500000], [], ['donations' => 500]);
        self::assertSame(0.0, (float) $r2['fields']['kc_op15_8']);
    }

    public function testSection7LossOffsetsRental(): void
    {
        // §7 ztráta −50k, §9 nájem 100k−30k = 70k → úhrn §7–10 = 20k.
        $r = $this->calcRun(
            ['s7_base' => -50000],
            ['s9_rental' => ['income' => 100000, 'expenses' => 30000]]
        );
        self::assertSame(-50000.0, self::line($r['lines'], '37'));
        self::assertSame(70000.0, self::line($r['lines'], '39'));
        self::assertSame(20000.0, self::line($r['lines'], '41'));
        self::assertSame(20000.0, self::line($r['lines'], '42'));
    }

    public function testNetLossCarriesForward(): void
    {
        // REGRESE (audit 2026-07, Fáze E nález #4): ř. 41 (kc_uhrn) SMÍ být záporný a musí
        // vykazovat skutečnou ztrátu roku — dřív se ořízl na 0 (nekonzistentní XML, ztráta se
        // nikde neevidovala). Základ daně (ř. 42) zůstává 0 (jen §6 = 0).
        $r = $this->calcRun(['s7_base' => -100000]);
        self::assertSame(-100000.0, self::line($r['lines'], '41'), 'Záporný úhrn §7–10 se vykáže (ne 0).');
        self::assertSame(-100000.0, (float) $r['fields']['kc_uhrn'], 'kc_uhrn nese skutečný záporný úhrn.');
        self::assertSame(0.0, self::line($r['lines'], '42'), 'Základ daně 0 (jen §6).');
        self::assertSame(0.0, (float) $r['fields']['kc_zakldan23']);
        self::assertSame(100000.0, (float) $r['summary']['year_tax_loss'], 'Ztráta roku k evidenci §34.');
        self::assertSame(0.0, (float) $r['tax']);
        self::assertNotEmpty($r['warnings']);
    }

    public function testLossCarryforwardAppliedToBusinessBaseOnly(): void
    {
        // Ztráta minulých let 80 000 se odečte max do výše úhrnu §7–§10 (ř. 41), NE §6.
        // §6 = 300 000, §7 = 100 000 → ř. 41 = 100 000; uplatní se jen 80 000 (ř. 44),
        // ř. 45 = 420 000 − 80 000 = 340 000.
        $r = $this->calcRun(
            ['s7_base' => 100000],
            ['s6_employment' => ['income' => 300000], 'loss_carryforward' => 80000]
        );
        self::assertSame(100000.0, self::line($r['lines'], '41'));
        self::assertSame(400000.0, self::line($r['lines'], '42'));
        self::assertSame(80000.0, self::line($r['lines'], '44'));
        self::assertSame(80000.0, (float) $r['fields']['kc_ztrata2']);
        self::assertSame(320000.0, self::line($r['lines'], '45'), 'ř.45 = 400000 − 80000.');
        self::assertSame(80000.0, (float) $r['summary']['loss_applied']);
    }

    public function testLossCarryforwardCappedToUhrn710(): void
    {
        // Ztráta 150 000 vs. úhrn §7–§10 jen 100 000 (žádné §6) → uplatní se jen 100 000,
        // zbytek 50 000 zůstává k převodu (warning).
        $r = $this->calcRun(['s7_base' => 100000], ['loss_carryforward' => 150000]);
        self::assertSame(100000.0, self::line($r['lines'], '44'), 'Uplatnění max do výše ř. 41.');
        self::assertSame(0.0, self::line($r['lines'], '45'));
        self::assertSame(0.0, (float) $r['tax']);
        self::assertNotEmpty($r['warnings']);
    }

    public function testMortgagePre2021HigherCap(): void
    {
        // Úroky 250 000: novější bytová potřeba → strop 150 000; obstaraná do 2020 → 300 000.
        $rNew = $this->calcRun(['s7_base' => 800000], [], ['mortgage_interest' => 250000]);
        self::assertSame(150000.0, (float) $rNew['fields']['kc_op28_5'], 'Od 2021 strop 150k.');

        $rOld = $this->calcRun(['s7_base' => 800000], [], ['mortgage_interest' => 250000, 'mortgage_pre_2021' => true]);
        self::assertSame(250000.0, (float) $rOld['fields']['kc_op28_5'], 'Před 2021 strop 300k → celých 250k.');
    }

    public function testRetirementCombinedCap15a(): void
    {
        // §15a: penzijko 48 000 + životko 100 000 → společný strop 48 000 (penzijko vyčerpá).
        $r = $this->calcRun(['s7_base' => 500000], [], ['pension_contrib' => 48000, 'life_insurance' => 100000]);
        self::assertSame(48000.0, (float) $r['fields']['kc_op15_12']);
        self::assertSame(0.0, (float) $r['fields']['kc_op15_13']);
        self::assertSame(48000.0, self::line($r['lines'], '54'), 'Odpočet §15a nesmí překročit 48 000.');

        // Kombinace: penzijko 20 000 + životko 40 000 → 20 000 + 28 000 = 48 000.
        $r2 = $this->calcRun(['s7_base' => 500000], [], ['pension_contrib' => 20000, 'life_insurance' => 40000]);
        self::assertSame(20000.0, (float) $r2['fields']['kc_op15_12']);
        self::assertSame(28000.0, (float) $r2['fields']['kc_op15_13']);
    }

    public function testRentalLossOffsetsBusiness(): void
    {
        // §9 ztráta −50 000 smí snížit §7 +200 000 (§5/3).
        $r = $this->calcRun(
            ['s7_base' => 200000],
            ['s9_rental' => ['income' => 0, 'expenses' => 50000]]
        );
        self::assertSame(-50000.0, self::line($r['lines'], '39'));
        self::assertSame(150000.0, self::line($r['lines'], '41'));
        self::assertSame(150000.0, self::line($r['lines'], '42'));
    }

    public function testEmploymentPlusBusinessCombined(): void
    {
        // §6 příjmy 300k (sražené zálohy 40k) + §7 základ 200k = základ 500k.
        $r = $this->calcRun(
            ['s7_base' => 200000],
            ['s6_employment' => ['income' => 300000, 'withholding' => 40000]]
        );
        self::assertSame(300000.0, self::line($r['lines'], '31'));
        self::assertSame(500000.0, self::line($r['lines'], '42'));
        // Daň 75 000 − 30 840 = 44 160; − sražené zálohy 40 000 → doplatek 4 160.
        self::assertSame(75000.0, self::line($r['lines'], '57'));
        self::assertSame(4160.0, (float) $r['balance_due']);
    }

    public function testMultipleSection7ActivitiesUseSeparateRateCaps(): void
    {
        $r = $this->calcRun(['activities' => [
            ['name' => 'Řemeslo', 'nace_code' => '43320', 'income' => 2000000, 'expense_mode' => 'pausal', 'expense_rate' => 80],
            ['name' => 'Poradenství', 'nace_code' => '62020', 'income' => 2000000, 'expense_mode' => 'pausal', 'expense_rate' => 60],
        ]]);

        self::assertSame(4000000.0, (float) $r['s7']['income']);
        self::assertSame(2800000.0, (float) $r['s7']['expenses']);
        self::assertSame(1200000.0, self::line($r['lines'], '37'));
        self::assertCount(2, $r['s7']['activities']);
    }

    public function testSection10ExpenseLimitIsAppliedPerKind(): void
    {
        $r = $this->calcRun(['s7_base' => 0], ['s10_items' => [
            ['kind' => 'Prodej movité věci', 'income' => 100000, 'expenses' => 20000],
            ['kind' => 'Příležitostný příjem', 'income' => 30000, 'expenses' => 70000],
        ]]);

        self::assertSame(80000.0, self::line($r['lines'], '40'));
        self::assertSame(30000.0, (float) $r['s10_items'][1]['allowed_expenses']);
        self::assertSame(40000.0, (float) $r['s10_items'][1]['disallowed_expenses']);
    }

    /**
     * §9/§10 hrubé podklady pro Přílohu č. 2 (VetaV/VetaJ, {@see DpfoXmlBuilder}) — 'fields'
     * nese jen NET dílčí základ (kc_zd9/kc_zd10), builder ale potřebuje hrubé kc_prij9/
     * kc_vyd9/kc_prij10/kc_vyd10 zvlášť (viz private/DANE-PLAN.md mezera č. 3).
     */
    public function testGrossSection9And10AreExposedForXmlBuilder(): void
    {
        $r = $this->calcRun(['s7_base' => 0], [
            's9_rental' => ['income' => 180000, 'expenses' => 60000],
            's10_items' => [
                ['kind' => 'Prodej movité věci', 'income' => 150000, 'expenses' => 90000],
                ['kind' => 'Příležitostný příjem', 'income' => 25000, 'expenses' => 40000],
            ],
        ]);

        self::assertSame(180000.0, (float) $r['s9']['income']);
        self::assertSame(60000.0, (float) $r['s9']['expenses']);
        self::assertSame(120000.0, (float) $r['s9']['base']);

        // Hrubý úhrn §10 nekrátí výdaje na výši příjmu položky (to dělá až kc_zd10p/'base') —
        // 'income' = 150000+25000, 'expenses' = 90000+25000 (druhá položka omezena na příjem).
        self::assertSame(175000.0, (float) $r['s10']['income']);
        self::assertSame(115000.0, (float) $r['s10']['expenses']);
        self::assertSame(60000.0, (float) $r['s10']['base']);
    }

    public function testSpouseAndChildClaimsAreProratedByMonth(): void
    {
        $months = [];
        for ($month = 1; $month <= 6; $month++) {
            $months[] = ['month' => $month, 'claimed' => true, 'order' => 1, 'ztpp' => false];
        }
        $r = $this->calcRun(['s7_base' => 1000000], [], [
            'spouse_claim' => ['eligible_months' => 6, 'ztpp' => false],
            'children' => [['first_name' => 'Eva', 'last_name' => 'Testová', 'birth_date' => '2020-01-01', 'months' => $months]],
        ]);

        self::assertSame(12420.0, (float) $r['summary']['spouse_credit']);
        self::assertSame(7602.0, (float) $r['summary']['child_credit']);
    }

    // ── EPO zkušební podání 2026-08-30: chybějící atributy pro křížové kontroly ──

    /** ř. 36 (kc_zd6p) musí EPO ověřitelně sedět na ř. 34 (kc_zd6) — od 2021 stejná hodnota. */
    public function testKcZd6pMirrorsKcZd6(): void
    {
        $r = $this->calcRun(['s7_base' => 0], ['s6_employment' => ['income' => 300000]]);
        self::assertSame(300000.0, (float) $r['fields']['kc_zd6']);
        self::assertSame(300000.0, (float) $r['fields']['kc_zd6p']);
    }

    /** ř. 71/74 — samostatné atributy odlišné od da_slevy (což je jen mezisoučet). */
    public function testDaSlevy35baAndDaSlevy35cFields(): void
    {
        $r = $this->calcRun(['s7_base' => 400000]);
        // Daň 60000 − sleva na poplatníka 30840 = 29160; bez dětí je 35c stejné.
        self::assertSame(29160.0, (float) $r['fields']['da_slevy35ba']);
        self::assertSame(29160.0, (float) $r['fields']['da_slevy35c']);
    }

    /** ř. 66–68 — vlastní invalidita/ZTP-P poplatníka musí EPO vidět jako počet měsíců. */
    public function testOwnDisabilityMonthsAreExposedForEpoFormulaCheck(): void
    {
        $r = $this->calcRun(['s7_base' => 500000], [], [
            'disability_12_months' => 6,
            'disability_3_months' => 3,
            'ztpp_months' => 12,
        ]);
        self::assertSame(6.0, (float) $r['fields']['m_invduch']);
        self::assertSame(3.0, (float) $r['fields']['m_cinvduch']);
        self::assertSame(12.0, (float) $r['fields']['m_ztpp']);

        // Bez nároku musí být pole přítomná jako explicitní 0 (EPO na chybějící atribut
        // hlásí "N", i když je nárok nulový a hodnota by formuli stejně vyhověla).
        $r2 = $this->calcRun(['s7_base' => 500000]);
        self::assertSame(0.0, (float) $r2['fields']['m_invduch']);
        self::assertSame(0.0, (float) $r2['fields']['m_cinvduch']);
        self::assertSame(0.0, (float) $r2['fields']['m_ztpp']);
    }

    /** ř. 65a/65b — m_manz a kc_manztpp musí být vždy přítomné, i bez nároku na slevu. */
    public function testSpouseMonthsAndZtppExtraAreExposed(): void
    {
        $r = $this->calcRun(['s7_base' => 500000]);
        self::assertSame(0.0, (float) $r['fields']['m_manz']);
        self::assertSame(0.0, (float) $r['fields']['kc_manztpp']);

        $r2 = $this->calcRun(['s7_base' => 500000], [], [
            'spouse_claim' => ['eligible_months' => 12, 'ztpp' => true],
        ]);
        self::assertSame(12.0, (float) $r2['fields']['m_manz']);
        // ZTP/P zdvojnásobuje slevu na manžela (24840 → 49680); "přidaná" polovina = 24840.
        self::assertSame(24840.0, (float) $r2['fields']['kc_manztpp']);
    }

    /** ř. 61 (kc_dztrata) — ztráta vzniklá v běžném ZO, EPO chce explicitní 0, ne prázdno. */
    public function testKcDztrataReflectsYearLoss(): void
    {
        $r = $this->calcRun(['s7_base' => -100000]);
        self::assertSame(100000.0, (float) $r['fields']['kc_dztrata']);

        $r2 = $this->calcRun(['s7_base' => 100000]);
        self::assertSame(0.0, (float) $r2['fields']['kc_dztrata']);
    }

    // ── § 38g ZDP: povinnost podat přiznání ──────────────────────────────────
    //
    // Systém čísla zná, poplatník povinnost často ne — a nepodané přiznání znamená
    // pokutu podle § 250 DŘ. Limity jsou v číselníku konstant (od 2023 novelou
    // 366/2022 Sb. 50 000 / 20 000 Kč), ne natvrdo, aby je novelizace neminula.

    /** Příjmy nad 50 000 Kč → povinnost podat přiznání (§ 38g odst. 1). */
    public function testIncomeAboveLimitTriggersFilingDutyWarning(): void
    {
        $r = $this->calcRun(['s7_income' => 300000, 's7_expenses' => 180000, 's7_base' => 120000]);

        self::assertNotEmpty(array_filter(
            $r['warnings'],
            static fn (string $w): bool => str_contains($w, '§ 38g odst. 1'),
        ), 'Nad limitem musí padnout upozornění na povinnost podat přiznání.');
    }

    /**
     * Zaměstnanec s ostatními příjmy do 20 000 Kč přiznání podávat nemusí (§ 38g odst. 2).
     * Formulace je podmíněná — podepsané prohlášení u všech plátců systém z dat neověří.
     */
    public function testEmployeeWithSmallOtherIncomeIsToldFilingMayNotBeRequired(): void
    {
        $r = $this->calcRun(
            ['s7_income' => 0, 's7_expenses' => 0, 's7_base' => 0],
            ['s6_employment' => ['income' => 40000, 'withholding' => 0], 's10_other' => ['income' => 5000, 'expenses' => 0]],
        );

        self::assertNotEmpty(array_filter(
            $r['warnings'],
            static fn (string $w): bool => str_contains($w, '§ 38g odst. 2'),
        ));
    }

    /**
     * Zaměstnanec s ostatními příjmy NAD 20 000 Kč se o výjimce dozvědět nesmí — přiznání
     * podat musí. Kdyby padly obě hlášky, poplatník si vybere tu pohodlnější.
     */
    public function testEmployeeWithLargeOtherIncomeGetsNoExemptionHint(): void
    {
        $r = $this->calcRun(
            ['s7_income' => 0, 's7_expenses' => 0, 's7_base' => 0],
            ['s6_employment' => ['income' => 40000, 'withholding' => 0], 's10_other' => ['income' => 25000, 'expenses' => 0]],
        );

        self::assertSame([], array_values(array_filter(
            $r['warnings'],
            static fn (string $w): bool => str_contains($w, '§ 38g odst. 2'),
        )), 'Nad limitem ostatních příjmů výjimka neplatí.');
        self::assertNotEmpty(array_filter(
            $r['warnings'],
            static fn (string $w): bool => str_contains($w, '§ 38g odst. 1'),
        ), 'Úhrn 65 000 Kč je nad hranicí 50 000 Kč.');
    }

    // ── § 16a ZDP: samostatný základ daně ────────────────────────────────────

    /**
     * Daň ze samostatného základu se počítá 15 % a přičítá až K VÝSLEDNÉ dani — slevy
     * podle § 35ba/§ 35c snižují daň podle § 16, ne tenhle základ.
     */
    public function testSeparateBaseIsTaxedFifteenPercentAndNotReducedByCredits(): void
    {
        // §7 základ 0 → daň podle §16 je 0 a sleva na poplatníka ji celou pokryje.
        // Samostatný základ 200 000 → daň 30 000, kterou sleva snížit NESMÍ.
        $r = $this->calcRun(
            ['s7_income' => 0, 's7_expenses' => 0, 's7_base' => 0],
            ['s16a_separate_base' => 200000],
        );

        self::assertSame(200000.0, (float) $r['summary']['separate_base']);
        self::assertSame(30000.0, (float) $r['summary']['separate_base_tax']);
        // `tax` je daň podle § 16 PO slevách (ta vyjde 0), samostatný základ stojí vedle ní.
        self::assertSame(0.0, (float) $r['tax']);
        self::assertSame(30000.0, (float) $r['summary']['final_tax'],
            'Sleva na poplatníka daň ze samostatného základu snížit nesmí.');
        self::assertSame(30000.0, (float) $r['balance_due']);
    }

    /** Základ samostatné daně se zaokrouhluje dolů na sta jako základ podle § 16. */
    public function testSeparateBaseIsRoundedDownToHundreds(): void
    {
        $r = $this->calcRun(
            ['s7_income' => 0, 's7_expenses' => 0, 's7_base' => 0],
            ['s16a_separate_base' => 10099],
        );

        // 10 000 × 15 % = 1 500
        self::assertSame(1500.0, (float) $r['summary']['separate_base_tax']);
    }

    /**
     * Systém na § 16a UPOZORNÍ a výslovně řekne, že se do XML nezapisuje. Atributy pro
     * samostatný základ nejsou v XSD popsané a doplnit je naslepo by znamenalo chybu
     * v podaném přiznání — mlčet by ale znamenalo, že si toho účetní nevšimne vůbec.
     */
    public function testSeparateBaseWarnsThatXmlMustBeFilledManually(): void
    {
        $r = $this->calcRun(
            ['s7_income' => 0, 's7_expenses' => 0, 's7_base' => 0],
            ['s16a_separate_base' => 50000],
        );

        $hit = array_values(array_filter(
            $r['warnings'],
            static fn (string $w): bool => str_contains($w, '§ 16a'),
        ));
        self::assertNotEmpty($hit);
        self::assertStringContainsString('NEZAPISUJE', $hit[0]);
    }

    /** Bez samostatného základu se nic nemění — chování zůstává zpětně shodné. */
    public function testWithoutSeparateBaseNothingChanges(): void
    {
        $r = $this->calcRun(['s7_income' => 1000000, 's7_expenses' => 600000, 's7_base' => 400000]);

        self::assertSame(0.0, (float) $r['summary']['separate_base_tax']);
        self::assertSame(29160.0, (float) $r['tax']);
        self::assertSame([], array_values(array_filter(
            $r['warnings'],
            static fn (string $w): bool => str_contains($w, '§ 16a'),
        )));
    }

    /** Limity se berou z číselníku, ne z literálu — jinak by je novelizace minula. */
    public function testFilingDutyLimitsComeFromTaxConstants(): void
    {
        $c = $this->c;
        $c['filing_duty_income_limit'] = 1000000.0;
        $c['filing_duty_other_income_limit'] = 900000.0;

        $r = $this->calc->compute(
            ['expense_mode' => 'pausal', 'expense_rate' => 60, 's7_income' => 0, 's7_expenses' => 0, 's7_base' => 0],
            ['s6_employment' => ['income' => 300000, 'withholding' => 0]],
            [],
            $c,
        );

        self::assertSame([], array_values(array_filter(
            $r['warnings'],
            static fn (string $w): bool => str_contains($w, '§ 38g odst. 1'),
        )), 'Se zvednutým limitem povinnost nevzniká — hodnota se musí brát z konstant.');
    }

    /** § 38a odst. 5 podíly se berou z konstant, ne z literálu — jinak by je novelizace minula. */
    public function testAdvanceEmploymentShareThresholdsComeFromTaxConstants(): void
    {
        $c = $this->c;
        $c['advance_employment_exempt_share'] = 0.80; // zvednuto z 0,50
        $c['advance_employment_half_share'] = 0.60;   // zvednuto z 0,15

        // Podíl §6 na základu je 70 % — s výchozími konstantami (0,50) by zálohy
        // nevznikaly vůbec (factor 0); se zvednutým prahem 0,80 spadá do pásma
        // „mezi 0,60 a 0,80" → poloviční záloha (factor 0,5).
        $r = $this->calc->compute(
            ['expense_mode' => 'pausal', 'expense_rate' => 60, 's7_income' => 300000, 's7_expenses' => 180000, 's7_base' => 120000],
            ['s6_employment' => ['income' => 280000, 'withholding' => 0]],
            [],
            $c,
        );

        self::assertSame(0.5, (float) $r['next_advances']['reduction_factor']);
    }

    /**
     * `bank_account` (z {@see \MyInvoice\Service\Tax\Return\DpfoReturnDataProvider::gather})
     * musí projít výpočtem beze změny — je to jediný zdroj pro VetaN
     * ({@see \MyInvoice\Service\Tax\Return\DpfoXmlBuilder::buildVetaN}).
     */
    public function testBankAccountPassesThroughFromData(): void
    {
        $account = ['account_number' => '2000145399', 'bank_code' => '0100', 'bank_name' => 'Test', 'iban' => null];
        $r = $this->calcRun(['bank_account' => $account]);
        self::assertSame($account, $r['bank_account']);
    }

    public function testBankAccountNullWhenMissingFromData(): void
    {
        $r = $this->calcRun([]);
        self::assertNull($r['bank_account']);
    }

    /**
     * ř.58 a ř.60 oddílu 4. Zjištěno pokusem proti zkušebnímu EPO 30. 8. 2026:
     * se samotným `da_dan16` úřad hlásil „daň podle § 16 zákona má být vyplněna"
     * i „daň celkem zaokrouhlená na celé Kč nahoru má být vyplněna". Teprve
     * s těmito dvěma atributy obě výtky zmizely.
     */
    public function testSection4CarriesTaxToLines58And60(): void
    {
        $r = $this->calcRun(['activities' => [['income' => 480000.0, 'expense_mode' => 'pausal', 'expense_rate' => 60]]]);

        self::assertSame($r['fields']['da_dan16'], $r['fields']['da_slezap']);
        self::assertSame((float) (int) ceil($r['fields']['da_dan16']), $r['fields']['da_celod13']);
    }

    /**
     * ř.70 (uhrn_slevy35ba). Zjištěno bisekcí proti zkušebnímu EPO 31. 8. 2026: EPO hlásilo
     * „Oddíl 5/ř.70 - položka se nerovná hodnotě příslušného vzorce (81300)" — 81 300 je
     * uhrn_slevy35ba+da_slevy, což prozradilo, že `da_slevy` (mezisoučet bez vlastního
     * tištěného řádku) kazí EPO kontrolu ř.70. Po vynechání `da_slevy` z XML výtka zmizela.
     * Calculator proto `da_slevy` do polí vůbec nedává.
     */
    public function testDaSlevyFieldIsNotComputed(): void
    {
        $r = $this->calcRun(['s7_base' => 400000]);
        self::assertArrayNotHasKey('da_slevy', $r['fields']);
    }

    /**
     * ř.77a (kc_db_po_odpd). Zjištěno bisekcí proti zkušebnímu EPO 31. 8. 2026: EPO hlásilo
     * „Oddíl 5/ř.77a - hodnota položky neodpovídá výpočtu uvedenému v pokynech k vyplnění
     * DAP. (0)" — atribut se dřív do XML vůbec neposílal. ř.77 (kc_dan_po_db) a ř.77a
     * (kc_db_po_odpd) jsou vzájemně vylučující páry (ř.75−ř.76, resp. ř.76−ř.75, ať je
     * kladné): `kc_dan_po_db` je už v $taxAfterChildren zezdola ořízlé na 0 díky
     * `$childCredit = min($taxAfter35ba, $childTotal)`, takže kdykoli vznikne bonus,
     * taxAfterChildren je právě 0 a ř.77a se rovná celému bonusu — `kc_db_po_odpd` proto
     * musí být vždy rovno `kc_danbonus` (childBonus), bez bonusu i s ním.
     */
    public function testKcDbPoOdpdMirrorsChildBonus(): void
    {
        // Bez dětí/bonusu: oba 0, oba PŘÍTOMNÉ (EPO čte nulu jako vyplněný údaj, ne jako "nic").
        $noBonus = $this->calcRun(['s7_base' => 400000]);
        self::assertSame(0.0, (float) $noBonus['fields']['kc_danbonus']);
        self::assertSame(0.0, (float) $noBonus['fields']['kc_db_po_odpd']);
        self::assertSame(29160.0, (float) $noBonus['fields']['kc_dan_po_db']);

        // S bonusem (nízký základ + 2 děti): kc_dan_po_db klesne na 0, kc_db_po_odpd
        // převezme celý bonus. `activities` (ne `s7_base`) je nutné, aby se naplnilo
        // i `s7_income` — bonus se jinak nevyplatí (viz $bonusIncome = s6Income+s7Income
        // a $bonusIncomeMinimum kontrola v compute()).
        $withBonus = $this->calcRun(
            ['activities' => [['income' => 150000.0, 'expense_mode' => 'pausal', 'expense_rate' => 60]]],
            [],
            ['children_count' => 2]
        );
        self::assertSame($withBonus['fields']['kc_danbonus'], $withBonus['fields']['kc_db_po_odpd']);
        self::assertSame(0.0, (float) $withBonus['fields']['kc_dan_po_db']);
        self::assertGreaterThan(0.0, (float) $withBonus['fields']['kc_db_po_odpd']);
    }

    /**
     * Příloha 1/ř.113 (kc_zd7p). Zjištěno bisekcí proti zkušebnímu EPO 31. 8. 2026: EPO
     * hlásilo „Příloha 1/ř.113 - hodnota položky neodpovídá hodnotě příslušného vzorce",
     * protože si ř.113 dopočítává jako součet ř.104-112, a ř.104 (kc_hosp_rozd, §7 základ
     * PŘED úpravami zvýšení/snížení) se do XML vůbec neposílal. `s7.before_adjustments`
     * musí být §7 základ PŘED ř.105/106 (increase/decrease), ne konečné `s7.base`
     * (=kc_zd7p), jinak by se ř.104+ř.105-ř.106 nerovnalo odeslanému kc_zd7p, kdykoli
     * jsou úpravy nenulové.
     */
    public function testS7BeforeAdjustmentsExcludesIncreaseAndDecrease(): void
    {
        $r = $this->calcRun([
            'activities' => [['income' => 150000.0, 'expense_mode' => 'pausal', 'expense_rate' => 60]],
            's7_increase' => 20000.0,
            's7_decrease' => 5000.0,
        ]);
        // Základ 150000 - 90000(60% paušál) = 60000 před úpravami; 60000+20000-5000=75000 po nich.
        self::assertSame(60000.0, (float) $r['s7']['before_adjustments']);
        self::assertSame(75000.0, (float) $r['s7']['base']);
        self::assertSame($r['s7']['before_adjustments'] + 20000.0 - 5000.0, $r['s7']['base']);
    }

    /**
     * Příloha 1 oddíl E (VetaC/VetaE) — {@see DpfoXmlBuilder::appendAdjustmentRows}
     * potřebuje volitelný položkový rozpis vedle souhrnu increase/decrease. Kalkulátor
     * je ČISTÁ třída bez DB — jen předá, co dostal v $data, nic nesčítá ani nevaliduje
     * (to dělá až builder). Chybí-li klíč úplně, musí dojít prázdný list, ne null/chyba.
     */
    public function testS7AdjustmentItemsPassThroughUnchanged(): void
    {
        $withItems = $this->calcRun([
            's7_base' => 75000,
            's7_increase' => 20000.0,
            's7_decrease' => 5000.0,
            's7_increase_items' => [['amount' => 20000.0, 'description' => 'Neuhrazené pojistné zaměstnavatele']],
            's7_decrease_items' => [['amount' => 5000.0, 'text' => 'Rozdíl účetních a daňových odpisů']],
        ]);
        self::assertSame(
            [['amount' => 20000.0, 'description' => 'Neuhrazené pojistné zaměstnavatele']],
            $withItems['s7']['increase_items'],
        );
        self::assertSame(
            [['amount' => 5000.0, 'text' => 'Rozdíl účetních a daňových odpisů']],
            $withItems['s7']['decrease_items'],
        );

        $withoutItems = $this->calcRun(['s7_base' => 75000, 's7_increase' => 20000.0, 's7_decrease' => 5000.0]);
        self::assertSame([], $withoutItems['s7']['increase_items']);
        self::assertSame([], $withoutItems['s7']['decrease_items']);
    }

    /**
     * Výdaje procentem z příjmů u nájmu (§ 9 odst. 4). Bez téhle volby se do přiznání
     * plnilo „výdaje ve skutečné výši" i poplatníkovi, který uplatňuje paušál — částky
     * seděly, ale způsob byl uvedený nepravdivě a úřad to nepozná.
     */
    public function testRentalPercentageExpensesAreComputedAndFlagged(): void
    {
        $r = $this->calcRun([], ['s9_rental' => ['income' => 252000.0, 'expenses' => 999.0, 'expense_mode' => 'pausal']]);

        self::assertSame(75600.0, $r['s9']['expenses']);
        self::assertTrue($r['s9']['pausal']);
        self::assertSame(176400.0, $r['s9']['base']);
    }

    /** Paušál u nájmu je shora omezený (`expense_caps`), ne prosté procento. */
    public function testRentalPercentageExpensesRespectTheCap(): void
    {
        $r = $this->calcRun([], ['s9_rental' => ['income' => 4000000.0, 'expense_mode' => 'pausal']]);

        self::assertSame(600000.0, $r['s9']['expenses']);
    }

    /** Bez volby zůstává chování jako dřív — skutečné výdaje tak, jak je účetní zadala. */
    public function testRentalDefaultsToActualExpenses(): void
    {
        $r = $this->calcRun([], ['s9_rental' => ['income' => 252000.0, 'expenses' => 40000.0]]);

        self::assertSame(40000.0, $r['s9']['expenses']);
        self::assertFalse($r['s9']['pausal']);
    }
}