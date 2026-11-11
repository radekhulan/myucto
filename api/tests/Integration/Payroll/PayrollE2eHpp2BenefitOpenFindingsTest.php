<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Otevřené nálezy u benefitů: testy popisují správné chování a PADAJÍ, dokud
 * se nález neopraví. Oprava leží buď v souborech přípravy hlášení, nebo
 * vyžaduje rozhodnutí o zaokrouhlení, proto zde nejsou opravené. Ke každému
 * testu je v docbloku místo a návrh opravy.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2BenefitOpenFindingsTest extends TestCase
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
     * Stravenkový paušál 150 Kč × 20 směn = 3 000 Kč, osvobozeno 2 590 Kč,
     * 410 Kč zdaněno (viz PayrollE2eHpp2BenefitTest). Osvobozené příjmy 10289
     * jsou jen osvobozená část: základ zálohy 10297 = 40 410 = 10286 (43 000)
     * − 10289 (2 590). Pokyny k 10289: „úhrn osvobozených příjmů“.
     *
     * Skutečnost: 10289 = 3 000, hlášení vykáže zdaněných 410 Kč jako
     * osvobozené. Místo: JmhzPreparationSnapshotBuilder, součet
     * `$exemptIncome` přičítá celé `amount_minor` vstupu; u vstupu se
     * zmrazeným rozpadem koše má přičíst jen `benefit_exempt_minor`.
     */
    public function testMealAllowanceExcessIsNotReportedAsExemptIncome(): void
    {
        $person = $this->hire('Syntetická Strávnice', 'female', '1992-06-21');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $this->input($person, 'PRISPEVEK_STRAVOVANI', 300_000, 'stravne');

        $run = $this->approvedRun('open-meal');
        $xml = $this->dryRunXml($run['revision_id'], 'open-meal');

        self::assertStringContainsString('<form:zuctovanoCelkem>43000</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString('<form:zakladDane>40410</form:zakladDane>', $xml);
        self::assertStringContainsString('<form:osvobozenoCelkem>2590</form:osvobozenoCelkem>', $xml);
    }

    /**
     * Stravenkový paušál přesně na zákonném limitu 129,50 Kč za směnu při
     * lichém počtu směn: 19 směn (jeden den neplaceného volna) = 2 460,50 Kč,
     * celé osvobozené (§ 6 odst. 9 písm. b) ZDP). Mzda 40 000 × 19/20
     * = 38 000 Kč; SP 2 698; ZP 5 130, zaměstnanec 1 710; záloha 5 700
     * − 2 570 = 3 130; výplata 38 000 + 2 460,50 − 7 538 = 32 922,50 Kč.
     *
     * Skutečnost: běh zastaví `inputs_not_whole_crowns` s radou zaokrouhlit
     * vstupy nahoru, hromadné zaokrouhlení ale schválený benefit přeskočí
     * (čerpá koš) a vstup 2 461 Kč nejde schválit (částku nelze rozdělit
     * rovnoměrně na směny). Měsíc s příspěvkem ve výši limitu tak při lichém
     * počtu směn nejde uzavřít. Místo: PayrollRunWholeCrownInputs (kontrola),
     * PayrollInputRepository::roundUpToWholeCrowns, rozpad koše
     * `meal_per_shift`, a zaokrouhlení 10286/10289 v hlášení. Vyžaduje
     * rozhodnutí, jak haléře příspěvku vykázat (viz zpráva).
     */
    public function testMealAllowanceAtStatutoryLimitForOddShiftCountClosesTheMonth(): void
    {
        $person = $this->hire('Syntetický Lichý', 'male', '1980-02-02');
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-04-15', '2026-04-15');
        $worked = self::workdays(self::PERIOD, ['2026-04-15']);
        self::assertCount(19, $worked);
        $this->approveMonth($person['employment_id'], $worked);
        $this->input($person, 'MZDA_MESICNI', 3_800_000, 'base');
        $meal = $this->input($person, 'PRISPEVEK_STRAVOVANI', 19 * 12_950, 'stravne');
        self::assertSame([246_050, 0], [$meal['benefit_exempt_minor'], $meal['benefit_taxable_minor']]);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'open-meal-odd');

        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $person = $run['approved']->revision['result_snapshot']['statutory']['people'][0];
        self::assertSame(3_292_250, $person['net_payable_minor_units']);
        $this->dryRunXml((int) $run['approved']->revision['id'], 'open-meal-odd');
    }

    /**
     * Rekreace 30 000 Kč: limit osvobození je polovina průměrné mzdy 48 967 Kč
     * = 24 483,50 Kč (§ 6 odst. 9 písm. d) bod 2 ZDP), zdanitelných je
     * 5 516,50 Kč. Výpočet mzdy projde (PayrollE2eHpp2BenefitTest), měsíční
     * hlášení se ale musí dát sestavit.
     *
     * Skutečnost: test hlášení skončí `jmhz_scenario1_whole_czk_required`
     * (10297, 10344, 10535, 10476) s radou zaokrouhlit haléřovou částku mzdové
     * složky. Vstup je přitom v celých korunách, haléře vznikají ze zákonného
     * limitu a účetní je odstranit nemůže. Vyžaduje rozhodnutí o zaokrouhlení
     * (viz zpráva).
     */
    public function testLeisureBenefitAboveAnnualLimitReachesValidMonthlyReport(): void
    {
        $person = $this->hire('Syntetický Rekreant', 'male', '1984-09-09');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $this->classifyAsExemptBasket($this->componentId('REKREACE_VOLNY_CAS'));
        $this->input($person, 'REKREACE_VOLNY_CAS', 3_000_000, 'rekreace');

        $run = $this->approvedRun('open-leisure');
        $xml = $this->dryRunXml($run['revision_id'], 'open-leisure');

        self::assertStringContainsString('<form:zuctovanoCelkem>70000</form:zuctovanoCelkem>', $xml);
    }

    /**
     * Příspěvek zaměstnavatele na doplňkové penzijní spoření 2 000 Kč
     * výchozí složkou PRISPEVEK_PENZE_ZIVOTNI, kterou účetní posoudí jako
     * osvobozenou (§ 6 odst. 9 písm. m) ZDP: příspěvek uhrazený
     * zaměstnavatelem NA produkt spoření na stáří, do 50 000 Kč ročně).
     * Zaměstnavatel ho platí penzijní společnosti, zaměstnanci se nevyplácí.
     *
     * Ruční přepočet: základ 40 000; SP 2 840; ZP 5 400, zaměstnanec 1 800;
     * záloha 6 000 − 2 570 = 3 430; výplata 40 000 − 8 070 = 31 930.
     *
     * Skutečnost: výchozí složka je vedena jako peněžní plnění, příspěvek se
     * přičte k výplatě (33 930 Kč) a zaměstnanec ho dostane na účet.
     * Místo: PayrollComponentDefaults, řádek PRISPEVEK_PENZE_ZIVOTNI (a
     * PRISPEVEK_DLOUHODOBA_PECE) má mít povahu `non_monetary` jako
     * PRISPEVEK_RIZIKOVE_SPORENI; existující firmy potřebují migraci.
     */
    public function testEmployerPensionContributionIsNotPaidOutToTheEmployee(): void
    {
        $person = $this->hire('Syntetický Spořivý', 'male', '1980-02-02');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $pensionId = $this->componentId('PRISPEVEK_PENZE_ZIVOTNI');
        $this->classifyAsExemptBasket($pensionId);
        $this->mappings()->put($this->supplierId, $pensionId, '10293', null, $this->actors[0]);
        $pension = $this->input($person, 'PRISPEVEK_PENZE_ZIVOTNI', 200_000, 'penze');
        self::assertSame([200_000, 0], [$pension['benefit_exempt_minor'], $pension['benefit_taxable_minor']]);

        $run = $this->approvedRun('open-pension');

        self::assertSame(4_000_000, $run['person']['income_tax']['advance_tax']['taxable_income_minor_units']);
        self::assertSame(4_000_000, $run['person']['social_insurance']['capped_assessment_base_minor_units']);
        self::assertSame(3_193_000, $run['person']['net_payable_minor_units'], 'Příspěvek penzijní společnosti se zaměstnanci nevyplácí.');
    }
}
