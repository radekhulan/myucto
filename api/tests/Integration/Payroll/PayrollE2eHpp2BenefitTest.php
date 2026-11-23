<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Benefity HPP nad výchozím číselníkem: stravenkový paušál, volnočasový
 * a zdravotní benefit, přechodné ubytování. Základní mzda 40 000 Kč, ruční
 * přepočty podle sady 2026 (pravidla v {@see PayrollE2eHpp2ComponentFlowTrait}).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2BenefitTest extends TestCase
{
    use PayrollE2eHpp2ComponentFlowTrait;

    protected function setUp(): void
    {
        $this->bootComponentFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Stravenkový paušál 150 Kč za směnu, duben 2026 má 20 odpracovaných
     * směn (22 všedních dnů bez Velkého pátku 3. 4. a Velikonočního pondělí
     * 6. 4.): příspěvek 3 000 Kč.
     *
     * Limit osvobození (§ 6 odst. 9 písm. b) ZDP): 70 % horní hranice
     * stravného 185 Kč při cestě 5 až 12 hodin (vyhl. č. 573/2025 Sb. § 3
     * písm. a)) = 129,50 Kč za směnu, za 20 směn 2 590 Kč. Zdanitelných je
     * 3 000 − 2 590 = 410 Kč (příjem i vyměřovací základ).
     *
     * Ruční přepočet: základ 40 410; SP 40 410 × 7,1 % = 2 869,11 → 2 870;
     * ZP 40 410 × 13,5 % = 5 455,35 → 5 456, zaměstnanec 5 456 / 3
     * = 1 818,67 → 1 819, zaměstnavatel 3 637; záloha ze 40 500 × 15 %
     * = 6 075 − 2 570 = 3 505; výplata 43 000 − 2 870 − 1 819 − 3 505
     * = 34 806.
     *
     * Hlášení: 10286 = 43 000 (včetně osvobozené části), 10289 osvobozené
     * příjmy = 2 590 (jen osvobozená část), 10328 = 40 000 (stravenkový
     * paušál do mzdy za práci nepatří), 10344 = 40 410 − 8 194 = 32 216.
     */
    public function testMealAllowanceAboveShiftLimitTaxesOnlyTheExcess(): void
    {
        $person = $this->hire('Syntetická Strávnice', 'female', '1992-06-21');
        $worked = self::workdays(self::PERIOD);
        self::assertCount(20, $worked);
        $this->approveMonth($person['employment_id'], $worked);
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $meal = $this->input($person, 'PRISPEVEK_STRAVOVANI', 300_000, 'stravne');
        self::assertSame(
            ['meal_per_shift', 259_000, 41_000],
            [$meal['benefit_basket'], $meal['benefit_exempt_minor'], $meal['benefit_taxable_minor']],
            'Rozpad příspěvku na 20 směn po 129,50 Kč a nadlimit.',
        );

        $run = $this->approvedRun('meal');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_041_000,
            'social_base' => 4_041_000,
            'health_base' => 4_041_000,
            'social' => 287_000,
            'health_employee' => 181_900,
            'health_employer' => 363_700,
            'advance' => 350_500,
            'cash_income' => 4_300_000,
            'non_cash_income' => 0,
            'net' => 3_480_600,
        ]);

        $xml = $this->dryRunXml($run['revision_id'], 'meal');
        foreach ([
            '<form:zuctovanoCelkem>43000</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>40000</form:mzdaZuctovana>',
            '<form:zakladDane>40410</form:zakladDane>',
            '<form:vypoctenaZaloha>6075</form:vypoctenaZaloha>',
            '<form:mzdaCista>32216</form:mzdaCista>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    /**
     * Volnočasový benefit 30 000 Kč (rekreace) a zdravotní benefit 10 000 Kč
     * jako nepeněžní plnění ze sociálního fondu.
     *
     * § 6 odst. 9 písm. d) ZDP: bod 2 (rekreace, sport, kultura) je osvobozen
     * v úhrnu do poloviny průměrné mzdy 48 967 Kč = 24 483,50 Kč za rok, bod 1
     * (zdravotní plnění) do celé průměrné mzdy. Z rekreace je tedy
     * zdanitelných 30 000 − 24 483,50 = 5 516,50 Kč, zdravotní benefit je
     * osvobozen celý. Osvobozený příjem není vyměřovacím základem (§ 5
     * odst. 1 z. č. 589/1992 Sb., § 3 odst. 1 z. č. 592/1992 Sb.), nadlimitní
     * část ano.
     *
     * Ruční přepočet: základ 40 000 + 5 516,50 = 45 516,50; vyměřovací základ
     * SP 45 517 (§ 5d) × 7,1 % = 3 231,71 → 3 232; ZP 45 516,50 × 13,5 %
     * = 6 144,73 → 6 145, zaměstnanec 2 048,33 → 2 049, zaměstnavatel 4 096;
     * záloha ze 45 600 × 15 % = 6 840 − 2 570 = 4 270; výplata (benefit je
     * nepeněžní) 40 000 − 3 232 − 2 049 − 4 270 = 30 449.
     */
    public function testLeisureBenefitAboveAnnualLimitAndExemptHealthBenefit(): void
    {
        $person = $this->hire('Syntetický Rekreant', 'male', '1984-09-09');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');

        // Výchozí benefitní složky čekají na posouzení účetní: vstup jde
        // založit, ale měsíc s neposouzenou složkou nesmí tiše projít.
        $leisureId = $this->componentId('REKREACE_VOLNY_CAS');
        $healthId = $this->componentId('ZDRAVOTNI_BENEFIT');
        $this->classifyAsExemptBasket($leisureId);
        $this->classifyAsExemptBasket($healthId);

        $leisure = $this->input($person, 'REKREACE_VOLNY_CAS', 3_000_000, 'rekreace');
        self::assertSame(
            ['non_cash_leisure', 2_448_350, 551_650],
            [$leisure['benefit_basket'], $leisure['benefit_exempt_minor'], $leisure['benefit_taxable_minor']],
        );
        $health = $this->input($person, 'ZDRAVOTNI_BENEFIT', 1_000_000, 'zdravi');
        self::assertSame(
            ['non_cash_health', 1_000_000, 0],
            [$health['benefit_basket'], $health['benefit_exempt_minor'], $health['benefit_taxable_minor']],
        );

        $run = $this->approvedRun('leisure');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_551_650,
            'social_base' => 4_551_700,
            'health_base' => 4_551_650,
            'social' => 323_200,
            'health_employee' => 204_900,
            'health_employer' => 409_600,
            'advance' => 427_000,
            'cash_income' => 4_000_000,
            'non_cash_income' => 4_000_000,
            'net' => 3_044_900,
        ]);
        // Nepeněžní benefit mimo základ srážek výplatu pro srážky nesnižuje:
        // základem je celá vyplácená čistá mzda (§ 277 odst. 1 o. s. ř.).
        self::assertSame(
            ['garnishable' => 3_044_900, 'excluded' => 0],
            $this->garnishableIncome($run['result']),
        );
    }

    /**
     * Roční koš volnočasových benefitů se čerpá napříč měsíci: v únoru 2026
     * rekreace 20 000 Kč (celá do limitu 24 483,50 Kč), v březnu dalších
     * 10 000 Kč, z nichž je osvobozeno jen zbývajících 4 483,50 Kč a 5 516,50 Kč
     * je zdanitelných (§ 6 odst. 9 písm. d) bod 2 ZDP: v úhrnu za zdaňovací
     * období).
     *
     * Ruční přepočet února: základ 40 000; SP 2 840; ZP 5 400, zaměstnanec
     * 1 800, zaměstnavatel 3 600; záloha 6 000 − 2 570 = 3 430; výplata
     * 40 000 − 2 840 − 1 800 − 3 430 = 31 930.
     * Března: základ 45 516,50; SP ze 45 517 = 3 232; ZP 6 145, zaměstnanec
     * 2 049, zaměstnavatel 4 096; záloha ze 45 600 = 6 840 − 2 570 = 4 270;
     * výplata 40 000 − 3 232 − 2 049 − 4 270 = 30 449.
     */
    public function testLeisureBasketAccumulatesAcrossMonthsOfTheYear(): void
    {
        $person = $this->hire('Syntetická Celoroční', 'female', '1983-04-17', periodStart: '2026-02-01');
        $this->publishShifts($person['employment_id'], self::workdays('2026-03'));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (supplier_id, employee_id, period_start, claim_register_evidence_complete,
                 dependants_evidence_complete, spouse_evidence_complete, pension_evidence, updated_by)
             VALUES (?, ?, "2026-03-01", 1, 1, 1, "none", ?)',
        )->execute([$this->supplierId, $person['employee_id'], $this->actors[0]]);
        $this->classifyAsExemptBasket($this->componentId('REKREACE_VOLNY_CAS'));

        $this->approveMonth($person['employment_id'], self::workdays('2026-02'), '2026-02');
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base-02', '2026-02');
        $february = $this->input($person, 'REKREACE_VOLNY_CAS', 2_000_000, 'rekreace-02', '2026-02');
        self::assertSame([2_000_000, 0], [$february['benefit_exempt_minor'], $february['benefit_taxable_minor']]);
        $run = $this->approvedRun('basket-02', '2026-02-01', '2026-03-13');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_000_000,
            'social_base' => 4_000_000,
            'health_base' => 4_000_000,
            'social' => 284_000,
            'health_employee' => 180_000,
            'health_employer' => 360_000,
            'advance' => 343_000,
            'cash_income' => 4_000_000,
            'non_cash_income' => 2_000_000,
            'net' => 3_193_000,
        ]);

        $this->approveMonth($person['employment_id'], self::workdays('2026-03'), '2026-03');
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base-03', '2026-03');
        $march = $this->input($person, 'REKREACE_VOLNY_CAS', 1_000_000, 'rekreace-03', '2026-03');
        self::assertSame([448_350, 551_650], [$march['benefit_exempt_minor'], $march['benefit_taxable_minor']]);
        $run = $this->approvedRun('basket-03', '2026-03-01', '2026-04-14');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_551_650,
            'social_base' => 4_551_700,
            'health_base' => 4_551_650,
            'social' => 323_200,
            'health_employee' => 204_900,
            'health_employer' => 409_600,
            'advance' => 427_000,
            'cash_income' => 4_000_000,
            'non_cash_income' => 1_000_000,
            'net' => 3_044_900,
        ]);
    }

    /**
     * Přechodné ubytování jako nepeněžní plnění v hodnotě 4 000 Kč měsíčně,
     * výchozí složka PRECHODNE_UBYTOVANI beze změny klasifikace.
     *
     * § 6 odst. 9 písm. i) ZDP osvobozuje hodnotu přechodného ubytování
     * nejvýš do 3 500 Kč měsíčně; 500 Kč je zdanitelný příjem a vyměřovací
     * základ. Nepeněžní plnění se nevyplácí a srážky ze mzdy se ho netýkají
     * (§ 299 o. s. ř. postihuje vyplácený příjem), měsíc se přesto musí dát
     * spočítat a schválit.
     *
     * Ruční přepočet: základ 40 500; SP 40 500 × 7,1 % = 2 875,50 → 2 876;
     * ZP 40 500 × 13,5 % = 5 467,50 → 5 468, zaměstnanec 1 822,67 → 1 823,
     * zaměstnavatel 3 645; záloha 6 075 − 2 570 = 3 505; výplata
     * 40 000 − 2 876 − 1 823 − 3 505 = 31 796.
     */
    public function testTemporaryAccommodationAboveMonthlyLimitCompletesTheRun(): void
    {
        $person = $this->hire('Syntetický Ubytovaný', 'male', '1986-12-05');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $accommodation = $this->input($person, 'PRECHODNE_UBYTOVANI', 400_000, 'ubytovani');
        self::assertSame(
            ['temporary_accommodation', 350_000, 50_000],
            [$accommodation['benefit_basket'], $accommodation['benefit_exempt_minor'], $accommodation['benefit_taxable_minor']],
        );

        $run = $this->approvedRun('accommodation');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_050_000,
            'social_base' => 4_050_000,
            'health_base' => 4_050_000,
            'social' => 287_600,
            'health_employee' => 182_300,
            'health_employer' => 364_500,
            'advance' => 350_500,
            'cash_income' => 4_000_000,
            'non_cash_income' => 400_000,
            'net' => 3_179_600,
        ]);
        // Základem srážek ze mzdy je celá vyplácená čistá mzda 31 796 Kč
        // (§ 277 odst. 1 o. s. ř.). Ubytování v ní není, nesmí ji tedy snížit.
        self::assertSame(
            ['garnishable' => 3_179_600, 'excluded' => 0],
            $this->garnishableIncome($run['result']),
        );
    }
}
