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
}
