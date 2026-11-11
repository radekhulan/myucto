<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Benefity se zákonným limitem v haléřích a jejich vykázání v měsíčním
 * hlášení (dříve otevřené nálezy C3 až C6 scénářů HPP).
 *
 * Pravidla vykázání drží {@see \MyInvoice\Service\Payroll\Component\PayrollWholeCrowns}:
 * osvobozená část 10289 dolů na koruny, základy 10297, 10535, 10476 a čistý
 * příjem 10344 nahoru (§ 38h odst. 1 ZDP, § 5d zák. č. 589/1992 Sb.), úhrn
 * 10286 = zdanitelná část + vykázaná osvobozená část.
 *
 * Ruční přepočty (sada 2026): SP zaměstnanec 7,1 % z vyměřovacího základu
 * zaokrouhleného nahoru (§ 5d), zaměstnavatel 24,8 %; ZP 13,5 % nahoru,
 * zaměstnanec třetina nahoru; záloha 15 % ze základu na stokoruny nahoru,
 * sleva na poplatníka 2 570 Kč. Duben 2026 má 20 směn.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eFix4BenefitTest extends TestCase
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
     * Stravenkový paušál 150 Kč × 20 směn = 3 000 Kč, osvobozeno 20 × 129,50
     * = 2 590 Kč, 410 Kč zdaněno. Osvobozené příjmy 10289 jsou jen osvobozená
     * část (pokyny 1.4.13: „úhrn osvobozených příjmů“): základ zálohy 10297
     * = 40 410 = 10286 (43 000) − 10289 (2 590). Dřív 10289 = 3 000.
     */
    public function testMealAllowanceExcessIsNotReportedAsExemptIncome(): void
    {
        $person = $this->hire('Syntetická Strávnice', 'female', '1992-06-21');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $this->input($person, 'PRISPEVEK_STRAVOVANI', 300_000, 'stravne');

        $run = $this->approvedRun('fix4-meal');
        $xml = $this->dryRunXml($run['revision_id'], 'fix4-meal');

        self::assertStringContainsString('<form:zuctovanoCelkem>43000</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString('<form:zakladDane>40410</form:zakladDane>', $xml);
        self::assertStringContainsString('<form:osvobozenoCelkem>2590</form:osvobozenoCelkem>', $xml);
    }

    /**
     * Paušál přesně na zákonném limitu 129,50 Kč za směnu při lichém počtu
     * směn: 19 směn (jeden den neplaceného volna) = 2 460,50 Kč, celé
     * osvobozené (§ 6 odst. 9 písm. b) ZDP). Mzda 40 000 × 19/20 = 38 000 Kč;
     * SP 2 698; ZP 5 130, zaměstnanec 1 710; záloha 5 700 − 2 570 = 3 130;
     * výplata 38 000 + 2 460,50 − 7 538 = 32 922,50 Kč.
     *
     * Dřív běh zastavila kontrola celých korun a měsíc nešel uzavřít
     * (zaokrouhlený vstup 2 461 Kč nejde rozdělit na směny a byl by nad
     * limit). Hlášení: 10289 = 2 460 (dolů), 10286 = 38 000 + 2 460 = 40 460,
     * základ zálohy 38 000.
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

        // Hromadné zaokrouhlení se osvobozeného benefitu netýká: nic
        // nezaokrouhlí a nic nevrátí jako přeskočené.
        $rounded = $this->inputs()->roundWholeCrowns(
            $this->approverRequest('POST', '/api/payroll/inputs/round-whole-crowns')
                ->withParsedBody(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $rounded->getStatusCode(), (string) $rounded->getBody());
        self::assertSame([[], []], [$this->json($rounded)['rounded'], $this->json($rounded)['skipped']]);

        $run = $this->approvedRun('fix4-meal-odd');
        $this->assertPayroll($run['person'], [
            'taxable' => 3_800_000,
            'social_base' => 3_800_000,
            'health_base' => 3_800_000,
            'social' => 269_800,
            'health_employee' => 171_000,
            'health_employer' => 342_000,
            'advance' => 313_000,
            'cash_income' => 4_046_050,
            'non_cash_income' => 0,
            'net' => 3_292_250,
        ]);

        $xml = $this->dryRunXml($run['revision_id'], 'fix4-meal-odd');
        foreach ([
            '<form:zuctovanoCelkem>40460</form:zuctovanoCelkem>',
            '<form:osvobozenoCelkem>2460</form:osvobozenoCelkem>',
            '<form:zakladDane>38000</form:zakladDane>',
            '<form:danZalohaPoSleve>3130</form:danZalohaPoSleve>',
            '<form:mzdaZuctovana>38000</form:mzdaZuctovana>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    /**
     * Paušál 150 Kč × 19 směn = 2 850 Kč: osvobozeno 19 × 129,50 = 2 460,50,
     * zdaněno 389,50 Kč. Haléře jsou v osvobozené i ve zdaněné části, vstup
     * i úhrn jsou v celých korunách.
     *
     * Ruční přepočet: mzda 38 000; základ zálohy 38 389,50 → 38 400 × 15 %
     * = 5 760 − 2 570 = 3 190; vyměřovací základ SP 38 390 (§ 5d nahoru),
     * pojistné 7,1 % = 2 725,69 → 2 726; ZP 13,5 % z 38 389,50 = 5 182,58
     * → 5 183, zaměstnanec 1 728, zaměstnavatel 3 455; výplata 38 000 + 2 850
     * − 2 726 − 1 728 − 3 190 = 33 206 Kč.
     *
     * Hlášení: 10297 = 38 390 (nahoru), 10289 = 2 460 (dolů), 10286 = 40 850
     * (= 38 390 + 2 460), čistý příjem 10344 = 38 389,50 − 7 644 = 30 745,50
     * → 30 746. Dřív test hlášení skončil `jmhz_scenario1_whole_czk_required`
     * s radou zaokrouhlit vstup, který v celých korunách je.
     */
    public function testMealAllowanceAboveLimitForOddShiftCountReachesValidMonthlyReport(): void
    {
        $person = $this->hire('Syntetická Lichá', 'female', '1988-08-18');
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-04-15', '2026-04-15');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, ['2026-04-15']));
        $this->input($person, 'MZDA_MESICNI', 3_800_000, 'base');
        $meal = $this->input($person, 'PRISPEVEK_STRAVOVANI', 19 * 15_000, 'stravne');
        self::assertSame([246_050, 38_950], [$meal['benefit_exempt_minor'], $meal['benefit_taxable_minor']]);

        $run = $this->approvedRun('fix4-meal-odd-excess');
        $this->assertPayroll($run['person'], [
            'taxable' => 3_838_950,
            'social_base' => 3_839_000,
            'health_base' => 3_838_950,
            'social' => 272_600,
            'health_employee' => 172_800,
            'health_employer' => 345_500,
            'advance' => 319_000,
            'cash_income' => 4_085_000,
            'non_cash_income' => 0,
            'net' => 3_320_600,
        ]);

        $xml = $this->dryRunXml($run['revision_id'], 'fix4-meal-odd-excess');
        foreach ([
            '<form:zuctovanoCelkem>40850</form:zuctovanoCelkem>',
            '<form:osvobozenoCelkem>2460</form:osvobozenoCelkem>',
            '<form:zakladDane>38390</form:zakladDane>',
            '<form:vypoctenaZaloha>5760</form:vypoctenaZaloha>',
            '<form:mzdaCista>30746</form:mzdaCista>',
            '<form:castkaOdvodPojistneho>38390</form:castkaOdvodPojistneho>',
            '<form:prijemNepojistenaCinnost>38390</form:prijemNepojistenaCinnost>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    /**
     * Rekreace 30 000 Kč: limit osvobození je polovina průměrné mzdy 48 967 Kč
     * = 24 483,50 Kč (§ 6 odst. 9 písm. d) bod 2 ZDP), zdanitelných je
     * 5 516,50 Kč nepeněžního plnění.
     *
     * Ruční přepočet: základ zálohy 45 516,50 → 45 600 × 15 % = 6 840 − 2 570
     * = 4 270; vyměřovací základ SP 45 517, pojistné 3 231,71 → 3 232,
     * zaměstnavatel 24,8 % = 11 288,22 → 11 289; ZP 13,5 % z 45 516,50
     * = 6 144,73 → 6 145, zaměstnanec 2 049, zaměstnavatel 4 096; výplata
     * 40 000 − 3 232 − 2 049 − 4 270 = 30 449 Kč.
     *
     * Hlášení: 10286 = 70 000, 10289 = 24 483, 10297 = 45 517 (součet sedí).
     * Dřív `jmhz_scenario1_whole_czk_required` u 10297, 10344, 10476 a 10535.
     */
    public function testLeisureBenefitAboveAnnualLimitReachesValidMonthlyReport(): void
    {
        $person = $this->hire('Syntetický Rekreant', 'male', '1984-09-09');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $this->classifyAsExemptBasket($this->componentId('REKREACE_VOLNY_CAS'));
        $leisure = $this->input($person, 'REKREACE_VOLNY_CAS', 3_000_000, 'rekreace');
        self::assertSame([2_448_350, 551_650], [$leisure['benefit_exempt_minor'], $leisure['benefit_taxable_minor']]);

        $run = $this->approvedRun('fix4-leisure');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_551_650,
            'social_base' => 4_551_700,
            'health_base' => 4_551_650,
            'social' => 323_200,
            'health_employee' => 204_900,
            'health_employer' => 409_600,
            'advance' => 427_000,
            'cash_income' => 4_000_000,
            'non_cash_income' => 3_000_000,
            'net' => 3_044_900,
        ]);

        $xml = $this->dryRunXml($run['revision_id'], 'fix4-leisure');
        foreach ([
            '<form:zuctovanoCelkem>70000</form:zuctovanoCelkem>',
            '<form:osvobozenoCelkem>24483</form:osvobozenoCelkem>',
            '<form:zakladDane>45517</form:zakladDane>',
            '<form:vypoctenaZaloha>6840</form:vypoctenaZaloha>',
            '<form:castkaOdvodPojistneho>45517</form:castkaOdvodPojistneho>',
            '<form:prijemNepojistenaCinnost>45517</form:prijemNepojistenaCinnost>',
            '<form:mzdaZuctovana>40000</form:mzdaZuctovana>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    /**
     * Haléř ve mzdě zůstává chybou vstupu i vedle osvobozeného benefitu:
     * odměna 1 234,56 Kč zastaví běh, paušál 2 460,50 Kč v hlášce není.
     */
    public function testWageInputWithHalereStillStopsTheRunNextToExemptBenefit(): void
    {
        $person = $this->hire('Syntetický Haléřový', 'male', '1979-11-11');
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-04-15', '2026-04-15');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, ['2026-04-15']));
        $this->input($person, 'MZDA_MESICNI', 3_800_000, 'base');
        $this->input($person, 'ODMENA', 123_456, 'odmena');
        $this->input($person, 'PRISPEVEK_STRAVOVANI', 19 * 12_950, 'stravne');

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'fix4-halere');

        self::assertSame(['inputs_not_whole_crowns'], array_column($run['blockers'], 'code'), CanonicalJson::encode($run['blockers']));
        self::assertStringContainsString('1 234,56 Kč', $run['blockers'][0]['message']);
        self::assertStringNotContainsString('2 460,50', $run['blockers'][0]['message']);
    }

    /**
     * Příspěvek zaměstnavatele na doplňkové penzijní spoření 2 000 Kč
     * výchozí složkou PRISPEVEK_PENZE_ZIVOTNI, kterou účetní posoudí jako
     * osvobozenou (§ 6 odst. 9 písm. m) ZDP: „příjem v podobě příspěvku
     * uhrazeného zaměstnavatelem na daňově podporované produkty spoření na
     * stáří jeho zaměstnance“, do 50 000 Kč ročně). Zaměstnavatel ho platí
     * penzijní společnosti, zaměstnanci se nevyplácí.
     *
     * Ruční přepočet: základ 40 000; SP 2 840; ZP 5 400, zaměstnanec 1 800;
     * záloha 6 000 − 2 570 = 3 430; výplata 40 000 − 8 070 = 31 930 Kč.
     * Dřív byla složka peněžní a zaměstnanec dostal 33 930 Kč.
     * Hlášení: 10286 = 42 000, 10289 = 2 000, 10417 = 2 000.
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

        $run = $this->approvedRun('fix4-pension');

        $this->assertPayroll($run['person'], [
            'taxable' => 4_000_000,
            'social_base' => 4_000_000,
            'health_base' => 4_000_000,
            'social' => 284_000,
            'health_employee' => 180_000,
            'health_employer' => 360_000,
            'advance' => 343_000,
            'cash_income' => 4_000_000,
            'non_cash_income' => 200_000,
            'net' => 3_193_000,
        ]);
        $xml = $this->dryRunXml($run['revision_id'], 'fix4-pension');
        foreach ([
            '<form:zuctovanoCelkem>42000</form:zuctovanoCelkem>',
            '<form:osvobozenoCelkem>2000</form:osvobozenoCelkem>',
            '<form:prispevekZelSporeniOsvob>2000</form:prispevekZelSporeniOsvob>',
            '<form:zakladDane>40000</form:zakladDane>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    /**
     * Jednorázový příspěvek na penzi 60 000 Kč: 50 000 Kč osvobozeno
     * (roční limit § 6 odst. 9 písm. m) ZDP), 10 000 Kč je zdanitelný příjem
     * s odvodem pojistného. Zaměstnanec nedostane nic z příspěvku, daň
     * a pojistné z nadlimitní části se mu srazí z peněžní mzdy.
     *
     * Ruční přepočet: základ 50 000; SP 3 550; ZP 6 750, zaměstnanec 2 250;
     * záloha 7 500 − 2 570 = 4 930; výplata 40 000 − 10 730 = 29 270 Kč.
     * Hlášení: 10286 = 100 000, 10289 = 50 000 a 10417 = 50 000 (pokyny
     * 1.4.13: částky nad limit se v 10417 neevidují; dřív 60 000).
     */
    public function testPensionContributionAboveAnnualLimitReportsOnlyTheExemptPart(): void
    {
        $person = $this->hire('Syntetická Spořivá', 'female', '1983-03-13');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $pensionId = $this->componentId('PRISPEVEK_PENZE_ZIVOTNI');
        $this->classifyAsExemptBasket($pensionId);
        $this->mappings()->put($this->supplierId, $pensionId, '10293', null, $this->actors[0]);
        $pension = $this->input($person, 'PRISPEVEK_PENZE_ZIVOTNI', 6_000_000, 'penze');
        self::assertSame([5_000_000, 1_000_000], [$pension['benefit_exempt_minor'], $pension['benefit_taxable_minor']]);

        $run = $this->approvedRun('fix4-pension-limit');

        $this->assertPayroll($run['person'], [
            'taxable' => 5_000_000,
            'social_base' => 5_000_000,
            'health_base' => 5_000_000,
            'social' => 355_000,
            'health_employee' => 225_000,
            'health_employer' => 450_000,
            'advance' => 493_000,
            'cash_income' => 4_000_000,
            'non_cash_income' => 6_000_000,
            'net' => 2_927_000,
        ]);
        $xml = $this->dryRunXml($run['revision_id'], 'fix4-pension-limit');
        foreach ([
            '<form:zuctovanoCelkem>100000</form:zuctovanoCelkem>',
            '<form:osvobozenoCelkem>50000</form:osvobozenoCelkem>',
            '<form:prispevekZelSporeniOsvob>50000</form:prispevekZelSporeniOsvob>',
            '<form:zakladDane>50000</form:zakladDane>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }
}
