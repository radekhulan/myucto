<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Hodnota atributu JMHZ podle scénáře (brána G3, řádky scenario_value v
 * api/resources/payroll/norms/jmhz.json). Každý test spočítá měsíc celým tokem
 * (evidence, nepřítomnosti, docházka, běh, příprava, dry-run s XSD a kontrolami
 * katalogu) a ověří hodnotu, kterou pro danou kombinaci určují Pokyny MH 1.4.14
 * a Datový slovník 1.4.1.6. Kombinace jsou ty, na kterých 9. 10. 2026 selhala
 * ekvivalence s jiným mzdovým systémem: srážková daň, dohoda, souběh, svátek
 * uvnitř nepřítomnosti a pravidelnost odměny.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzScenarioValueFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_SV', 'base_wage', 'regular');
        $this->mappings()->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * DPP do 12 000 Kč bez prohlášení: srážková daň (§ 6 odst. 4 ZDP).
     * 10535 = základ srážky vztahu (DS 1.4.1.6: „částka základu pro výpočet
     * zálohy na daň nebo částka základu pro výpočet daně podle srážkové daně“,
     * vada A7), 10307 = úhrn zaokrouhlený dolů na koruny (§ 36 odst. 3 ZDP),
     * blok zálohy 10297 a dál se nevyplňuje (kontrola 245). Dohoda nemá
     * evidenční stav (10265 = 0) ani týdenní dobu (10261 = 99), pod hranicí
     * nevzniká účast (ELDP bez kódu, pojistné nula).
     */
    public function testAgreementWithWithholdingReportsWithholdingBaseInBothPlaces(): void
    {
        $person = $this->hire('Marek Srážka', 'male', '2001-12-24', employmentType: 'dpp', relationType: 'dpp',
            weeklyHours: 10, workload: 2_500, taxDeclarationSigned: false);
        $this->approveMonth($person['employment_id'], ['2026-07-04', '2026-07-11', '2026-07-18'], dailyMinutes: 240);
        $this->pay($person, 600_000);

        $xml = $this->submission('dpp-withholding');

        self::assertStringContainsString('<form:zuctovanoCelkem>6000</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString(
            '<form:zvlastniSazbaDane><form:zakladDane>6000</form:zakladDane><form:srazenaDan>900</form:srazenaDan></form:zvlastniSazbaDane>',
            $xml,
        );
        self::assertStringNotContainsString('<form:zalohaNaDan>', $xml);
        self::assertSame(['6000'], $this->relationshipTaxBases($xml));
        self::assertStringContainsString('<form:mzdaCista>5100</form:mzdaCista>', $xml);
        self::assertStringContainsString('<form:stanovenaTydenniDoba>99.00</form:stanovenaTydenniDoba>', $xml);
        // 10259 u dohody: pracovní dny trvání dohody včetně svátku 6. 7. po 8 h.
        self::assertStringContainsString('<form:stanovenyFond>184.000</form:stanovenyFond>', $xml);
        self::assertStringContainsString('<form:dnyEvidencniStav>0</form:dnyEvidencniStav>', $xml);
        self::assertStringContainsString('<form:odpracovaneHodiny><form:pocet>12.000</form:pocet>', $xml);
        self::assertStringContainsString('<form:eldp><form:pocetDnu>0</form:pocetDnu></form:eldp>', $xml);
        self::assertStringNotContainsString('<form:castkaOdvodPojistneho>', $xml);
    }

    /**
     * Souběh HPP bez prohlášení (nad hranicí ZMR, záloha) a DPP do 12 000 Kč
     * (srážka). Každý formulář nese 10535 svého vztahu, záloha 10297 je jen
     * z HPP, srážka 10307 jen z DPP a zúčtovaný příjem 10286 je úhrn osoby.
     * Souhrnná data jen na primárním vztahu.
     */
    public function testConcurrentEmploymentAndAgreementWithoutDeclarationSplitAdvanceAndWithholding(): void
    {
        $person = $this->hire('Filip Souběh', 'male', '1979-09-30', taxDeclarationSigned: false);
        $agreement = $this->hireAgreement($person, 'dpp', taxDeclarationSigned: false);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->approveMonth($agreement['employment_id'], ['2026-07-04', '2026-07-11'], dailyMinutes: 240);
        $this->pay($person, 3_000_000);
        $this->pay($agreement, 500_000);

        $xml = $this->submission('concurrent-without-declaration');

        self::assertSame(1, substr_count($xml, '<form:souhrnDataZec>'));
        self::assertStringContainsString('<form:zuctovanoCelkem>35000</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>30000</form:zakladDane><form:vypoctenaZaloha>4500</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>4500</form:danZalohaPoSleve></form:zalohaNaDan>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:zvlastniSazbaDane><form:zakladDane>5000</form:zakladDane><form:srazenaDan>750</form:srazenaDan></form:zvlastniSazbaDane>',
            $xml,
        );
        self::assertSame(['30000', '5000'], $this->relationshipTaxBases($xml));
        // Bez prohlášení žádná sleva ani zvýhodnění (kontrola 244).
        self::assertStringNotContainsString('<form:prohlaseniPoplatnikaDane>', $xml);
        // 35 000 - 4 500 - 750 - 2 130 (7,1 % z 30 000) - 1 350 (4,5 % z 30 000).
        self::assertStringContainsString('<form:mzdaCista>26270</form:mzdaCista>', $xml);
    }

    /**
     * Souběh HPP a DPP s prohlášením: prohlášení platí u zaměstnavatele pro
     * všechny příjmy, takže i DPP pod 12 000 Kč jde do zálohy (§ 6 odst. 4
     * ZDP se nepoužije). 10297 = úhrn obou vztahů = Σ 10535, srážka není.
     */
    public function testConcurrentEmploymentAndAgreementWithDeclarationTaxBothByAdvance(): void
    {
        $person = $this->hire('Filip Prohlášení', 'male', '1979-09-30');
        $agreement = $this->hireAgreement($person, 'dpp');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->approveMonth($agreement['employment_id'], ['2026-07-04', '2026-07-11'], dailyMinutes: 240);
        $this->pay($person, 3_000_000);
        $this->pay($agreement, 500_000);

        $xml = $this->submission('concurrent-with-declaration');

        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>35000</form:zakladDane><form:vypoctenaZaloha>5250</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>2680</form:danZalohaPoSleve>',
            $xml,
        );
        self::assertStringContainsString('<form:zakladniSleva>2570</form:zakladniSleva>', $xml);
        self::assertStringNotContainsString('<form:zvlastniSazbaDane>', $xml);
        self::assertSame(['30000', '5000'], $this->relationshipTaxBases($xml));
    }

    /**
     * DPP od 12 000 Kč bez prohlášení: nad hranicí se nesráží (kontrola 325),
     * zdaní se zálohou a vznikne účast na pojištění (ELDP kód T++ za celý
     * měsíc, vyměřovací základ = příjem).
     */
    public function testAgreementAtThresholdWithoutDeclarationIsAdvanceTaxedAndInsured(): void
    {
        $person = $this->hire('Dana Hranice', 'female', '1995-02-02', employmentType: 'dpp', relationType: 'dpp',
            weeklyHours: 10, workload: 2_500, taxDeclarationSigned: false);
        $this->approveMonth($person['employment_id'], ['2026-07-04', '2026-07-11', '2026-07-18'], dailyMinutes: 240);
        $this->pay($person, 1_300_000);

        $xml = $this->submission('dpp-above-threshold');

        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>13000</form:zakladDane><form:vypoctenaZaloha>1950</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>1950</form:danZalohaPoSleve></form:zalohaNaDan>',
            $xml,
        );
        self::assertStringNotContainsString('<form:zvlastniSazbaDane>', $xml);
        self::assertSame(['13000'], $this->relationshipTaxBases($xml));
        self::assertStringContainsString(
            '<form:kod>T++</form:kod><form:platnostOd>2026-07-01</form:platnostOd><form:platnostDo>2026-07-31</form:platnostDo>'
                . '<form:pocetDnu>31</form:pocetDnu><form:vymerovaciZaklad>13000</form:vymerovaciZaklad>',
            $xml,
        );
        // 7,1 % a 24,8 % z 13 000 Kč.
        self::assertStringContainsString(
            '<form:pojisteniZamestnanec><form:socialniPojisteni>923</form:socialniPojisteni></form:pojisteniZamestnanec>'
                . '<form:pojisteniZamestnavatel><form:socialniPojisteni>3224</form:socialniPojisteni></form:pojisteniZamestnavatel>',
            $xml,
        );
    }

    /**
     * Rodičovská dovolená celý měsíc (svátek 6. 7. uvnitř): zaměstnankyně
     * není v evidenčním stavu (10265 = 0), celý měsíc je omluvenou
     * nepřítomností bez náhrady (10473 = 31) a vyloučenými dny § 18 odst. 7,
     * nic se neodpracovalo a hodiny s náhradou 10276 nejsou.
     *
     * Úhrn 10275 nese i svátek uvnitř rodičovské (Pokyny MH k 10275), takže
     * 10268 + 10275 = 10260 = 184 h; dřív v něm chyběl (176 h, defekt G3-2,
     * řádek JMHZ-SV-10275-05).
     */
    public function testParentalLeaveForWholeMonthIsExcusedAbsenceWithoutPaidHours(): void
    {
        $person = $this->hire('Rita Rodičovská', 'female', '1990-03-03');
        $this->createApprovedAbsence($person['employment_id'], 'parental', '2026-07-01', '2026-07-31');
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 4_000_000);

        $xml = $this->submission('parental-leave');

        self::assertStringContainsString('<form:dnyEvidencniStav>0</form:dnyEvidencniStav>', $xml);
        self::assertStringContainsString('<form:odpracovaneHodiny><form:pocet>0.000</form:pocet>', $xml);
        self::assertStringContainsString(
            '<form:vyloucenePar18>31</form:vyloucenePar18><form:omluvenaNepritomnost>31</form:omluvenaNepritomnost>',
            $xml,
        );
        self::assertStringNotContainsString('<form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString('<form:sjednanyFond>184.000</form:sjednanyFond>', $xml);
        self::assertMatchesRegularExpression('#<form:hodinyNeodpracCelkem>(<form:pocet>)?184\.000<#', $xml);
    }

    /**
     * Prémie s pravidelnou četností (měsíční pevná částka): DS 1.4.1.6 a Pokyny
     * MH kap. 3.5.1 ji řadí do 10330 „prémie a odměny pravidelně měsíčně
     * zúčtované“, ne do nepravidelných 10331 (vada A10). Tarif 10329 nese jen
     * základní mzdu a 10328 úhrn.
     */
    public function testRegularBonusIsReportedAsRegularReward(): void
    {
        $person = $this->hire('Bohdan Prémie', 'male', '1985-05-05');
        $bonus = $this->createComponent('PREMIE_PEVNA_SV', 'bonus', 'regular');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 3_000_000);
        $this->createApprovedInput($person, $bonus, 500_000, 'bonus-' . $person['employment_id'], self::PERIOD_START);

        $xml = $this->submission('regular-bonus');

        self::assertStringContainsString(
            '<form:mzdaZuctovana>35000</form:mzdaZuctovana><form:mzdaRozpad><form:tarif>30000</form:tarif>'
                . '<form:odmenyPravidelne>5000</form:odmenyPravidelne><form:odmenyNepravidelne>0</form:odmenyNepravidelne>',
            $xml,
        );
    }

    /**
     * Jednatel (druh činnosti S, formulář cinnostKS) s prohlášením, 30 000 Kč:
     * záloha jako u zaměstnance (10297 = 30 000, 10305 = 4 500 - 2 570), účast
     * na pojištění s kódem S++ a vyměřovacím základem = příjem, fondy 10259
     * a 10260 nulové a týdenní doba 99 (Pokyny MH k 10259 až 10261 pro K–S).
     * Formulář cinnostKS zdravotní pojistné zaměstnavatele nenese (XSD).
     */
    public function testBoardMemberIsTaxedByAdvanceAndInsuredWithoutWorkingTimeFund(): void
    {
        $person = $this->hire('Radim Jednatel', 'male', '1975-05-15', employmentType: 'statutory_body',
            relationType: 'statutory_body', activityCode: 'S', taxpayerType: 'managing_partner');
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 3_000_000);

        $xml = $this->submission('board-member');

        self::assertStringContainsString('<form:cinnostKS', $xml);
        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>30000</form:zakladDane><form:vypoctenaZaloha>4500</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>1930</form:danZalohaPoSleve>',
            $xml,
        );
        self::assertSame(['30000'], $this->relationshipTaxBases($xml));
        self::assertStringContainsString(
            '<form:kod>S++</form:kod><form:platnostOd>2026-07-01</form:platnostOd><form:platnostDo>2026-07-31</form:platnostDo>'
                . '<form:pocetDnu>31</form:pocetDnu><form:vymerovaciZaklad>30000</form:vymerovaciZaklad>',
            $xml,
        );
        self::assertStringContainsString('<form:zdravPojZamestnanec><form:zdravotniPojisteni>1350</form:zdravotniPojisteni>', $xml);
        self::assertStringNotContainsString('<form:zdravPojZamestnavatel>', $xml);
        self::assertStringContainsString(
            '<form:stanovenyFond>0.000</form:stanovenyFond><form:sjednanyFond>0.000</form:sjednanyFond>'
                . '<form:stanovenaTydenniDoba>99.00</form:stanovenaTydenniDoba>',
            $xml,
        );
    }

    /**
     * Odsouzený zařazený do práce (formulář vezen) s prohlášením, 20 000 Kč:
     * záloha 3 000 - 2 570 = 430, 10535 = příjem, ELDP 1++ s vyměřovacím
     * základem = příjem. Čistá mzda 10344 = 20 000 - 430 - 1 420 (7,1 %)
     * - 900 (4,5 % zdravotního, formulář ho nevykazuje, ale zaměstnanec ho platí).
     */
    public function testPrisonerValuesOnVezenForm(): void
    {
        $person = $this->hire('Petr Odsouzený', 'male', '1990-03-15');
        $this->classify($person['employment_id'], '1', '2');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 2_000_000);

        $xml = $this->submission('prisoner');

        self::assertStringContainsString('<form:vezen', $xml);
        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>20000</form:zakladDane><form:vypoctenaZaloha>3000</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>430</form:danZalohaPoSleve>',
            $xml,
        );
        self::assertSame(['20000'], $this->relationshipTaxBases($xml));
        self::assertStringContainsString('<form:mzdaCista><form:mzdaCista>17250</form:mzdaCista>', $xml);
        self::assertStringContainsString('<form:kod>1++</form:kod>', $xml);
        self::assertStringContainsString('<form:vymerovaciZaklad>20000</form:vymerovaciZaklad>', $xml);
    }

    /**
     * Jiný příjem ze závislé činnosti (druh 13, formulář jinyPrijem) s prohlášením,
     * 8 000 Kč: záloha 1 200 po slevě 2 570 klesne na 0, bonus 0 (bez dětí).
     * Pojištění formulář nemá, 10535 = příjem.
     */
    public function testOtherIncomeValuesOnJinyPrijemForm(): void
    {
        $person = $this->hire('Olga Provize', 'female', '1982-11-02');
        $this->classify($person['employment_id'], '13', '1');
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 800_000);

        $xml = $this->submission('other-income');

        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>8000</form:zakladDane><form:vypoctenaZaloha>1200</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>0</form:danZalohaPoSleve><form:danBonus>0</form:danBonus></form:zalohaNaDan>',
            $xml,
        );
        self::assertSame(['8000'], $this->relationshipTaxBases($xml));
        self::assertStringNotContainsString('<form:pojisteni>', $xml);
    }

    /**
     * Mezinárodní pronájem pracovní síly (druh 12) s prohlášením, 50 000 Kč:
     * záloha 7 500 - 2 570 = 4 930, bez bonusu 10306 (formulář ho nevede)
     * a bez pojištění; pojistná část je nulová.
     */
    public function testInternationalHireValues(): void
    {
        $person = $this->hire('Jan Pronajatý', 'male', '1975-06-20');
        $this->classify($person['employment_id'], '12', '1');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 5_000_000);

        $xml = $this->submission('international-hire');

        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>50000</form:zakladDane><form:vypoctenaZaloha>7500</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>4930</form:danZalohaPoSleve></form:zalohaNaDan>',
            $xml,
        );
        self::assertSame(['50000'], $this->relationshipTaxBases($xml));
        self::assertStringContainsString('<pvpoj:pojistneUhrada>0</pvpoj:pojistneUhrada>', $xml);
    }

    /**
     * Daňový nerezident s prohlášením (kontrola 243): uplatní jen základní slevu
     * na poplatníka, jiné slevy (10300 až 10304) ve formuláři nejsou. Uplatněná
     * sleva na průkaz ZTP/P výpočet mzdy zastaví (nonresident-monthly-credit-not-supported),
     * takže zakázaný atribut do hlášení nedojde.
     *
     * Element danBonus (10306) formulář nerezidenta nenese ani s nulou: kontrola
     * 243 ho zakazuje stejně jako kontrola 244 u zaměstnance bez prohlášení,
     * kde ČSSZ nulový element odmítla (40244).
     */
    public function testNonResidentWithDeclarationClaimsOnlyTaxpayerCredit(): void
    {
        $person = $this->hire('Jana Nerezidentka', 'female', '1988-08-08');
        $this->makeNonResident($person['employee_id']);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_000_000);

        $xml = $this->submission('nonresident');

        self::assertStringContainsString(
            '<form:prohlaseniPoplatnikaDane><form:zakladniSleva>2570</form:zakladniSleva></form:prohlaseniPoplatnikaDane>',
            $xml,
        );
        self::assertStringContainsString('<form:danZalohaPoSleve>3430</form:danZalohaPoSleve>', $xml);
        self::assertSame(['40000'], $this->relationshipTaxBases($xml));
        // Kontrola 243: 10306 se nerezidentovi s prohlášením nevyplňuje ani nulou.
        self::assertStringNotContainsString('<form:danBonus>', $xml);
        self::assertStringNotContainsString('<form:zvlastniSazbaDane>', $xml);
    }

    /** Nerezident se slevou na průkaz ZTP/P: výpočet mzdy se zastaví (kontrola 243 nad zdrojem). */
    public function testNonResidentWithOtherTaxCreditStopsThePayrollRun(): void
    {
        $other = $this->hire('Juraj Nerezident', 'male', '1984-04-04');
        $this->makeNonResident($other['employee_id']);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status, effective_from, effective_to, evidence_reference)
             VALUES (?, ?, "ztp-p", "verified", "2026-01-01", NULL, "document:synthetic-ztpp")',
        )->execute([$this->supplierId, $other['employee_id']]);
        $this->approveMonth($other['employment_id'], self::workdays(self::PERIOD));
        $this->pay($other, 4_000_000);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'sv-nonresident-ztpp');

        self::assertNull($run['approved']);
        self::assertContains('statutory_calculation_manual_review', array_column($run['blockers'], 'code'));
        self::assertStringContainsString(
            'U daňového nerezidenta je uplatněna sleva nebo zvýhodnění nepodporované',
            CanonicalJson::encode($run['blockers']),
        );
    }

    /**
     * Daňový bonus (10306): HPP 12 000 Kč s prohlášením a dvěma dětmi. Záloha
     * 1 800 Kč pokryje sleva na poplatníka, na slevu na děti 10304 nezbude nic
     * a celé zvýhodnění 1 267 + 1 860 = 3 127 Kč (10303) se vyplatí jako bonus
     * (§ 35d ZDP; příjem přesahuje polovinu minimální mzdy).
     */
    public function testChildCreditAboveTheAdvanceIsPaidAsTaxBonus(): void
    {
        $person = $this->hire('Tereza Bonusová', 'female', '1991-01-11');
        $this->claimChild($person['employee_id'], $this->createChild($person['employee_id'], 'Syntetické Dítě První', '2018-03-03', 1), 1);
        $this->claimChild($person['employee_id'], $this->createChild($person['employee_id'], 'Syntetické Dítě Druhé', '2021-04-04', 2), 2);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 1_200_000);

        $xml = $this->submission('tax-bonus');

        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>12000</form:zakladDane><form:vypoctenaZaloha>1800</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>0</form:danZalohaPoSleve><form:danBonus>3127</form:danBonus></form:zalohaNaDan>',
            $xml,
        );
        self::assertStringContainsString('<form:danoveZvyhodneniDetiMesic>3127</form:danoveZvyhodneniDetiMesic>', $xml);
        self::assertStringContainsString('<form:slevaDite>0</form:slevaDite>', $xml);
    }

    private function createChild(int $employeeId, string $name, string $birthDate, int $sequence): int
    {
        $birthNumber = self::syntheticBirthNumber($birthDate, 'male', 200 + $sequence);
        [$givenName, $familyName] = explode(' ', $name, 2);
        $response = $this->dependants()->create(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants")->withParsedBody([
                'relation' => 'child_own',
                'full_name' => $name,
                'given_name' => $givenName,
                'family_name' => $familyName,
                'birth_date' => $birthDate,
                'birth_number' => substr($birthNumber, 0, 6) . '/' . substr($birthNumber, 6),
                'ztp_p' => false,
                'student' => false,
                'existence_from' => $birthDate,
                'existence_to' => null,
                'note' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: vyživovaná osoba. ' . (string) $response->getBody());
        foreach ($this->json($response)['dependants'] as $dependant) {
            if ($dependant['full_name'] === $name) {
                return (int) $dependant['id'];
            }
        }
        self::fail("Vyživovaná osoba {$name} chybí.");
    }

    private function claimChild(int $employeeId, int $dependantId, int $order): void
    {
        $response = $this->dependants()->createClaim(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants/{$dependantId}/claims")
                ->withParsedBody([
                    'child_order' => $order,
                    'claim_reason' => 'own_household',
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'document:child-claim',
                    'shared_household_confirmed' => true,
                    'other_claimant_excluded' => true,
                    'other_household_caregiver_status' => 'none',
                    'ztp_p' => false,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]),
            new Response(),
            ['id' => (string) $employeeId, 'dependantId' => (string) $dependantId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: nárok na dítě. ' . (string) $response->getBody());
    }

    private function dependants(): PayrollDependantAction
    {
        $action = $this->container->get(PayrollDependantAction::class);
        self::assertInstanceOf(PayrollDependantAction::class, $action);

        return $action;
    }

    private function classify(int $employmentId, string $activityCode, string $detailCode): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET activity_code = ?, jmhz_relationship_detail_code = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$activityCode, $detailCode, $this->supplierId, $employmentId]);
    }

    /** Daňový nerezident se slovenskou rezidencí; prohlášení zůstává podepsané. */
    private function makeNonResident(int $employeeId): void
    {
        $evidence = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class);
        self::assertInstanceOf(PayrollPersonStatutoryEvidenceRepository::class, $evidence);
        $payload = $this->statutoryEvidence(self::PERIOD_START, true, true, $this->createHealthEvidenceDocument(900 + $employeeId));
        $payload['sections']['tax_residences'] = [[
            'residence' => 'non-resident',
            'country_code' => 'SK',
            'evidence_reference' => 'document:synthetic-tax-residence-sk',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]];
        $evidence->save($this->supplierId, $employeeId, $payload, '2026-07-31', $this->actors[0], null, 'g3-nonresident');
    }

    /**
     * 10535 všech formulářů v pořadí podání.
     *
     * @return list<string>
     */
    private function relationshipTaxBases(string $xml): array
    {
        preg_match_all('#<form:prijem><form:dan><form:zakladDane>(\d+)</form:zakladDane>#', $xml, $matches);

        return $matches[1];
    }

    private function mappings(): PayrollComponentJmhzMappingRepository
    {
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$mappings instanceof PayrollComponentJmhzMappingRepository) {
            throw new \RuntimeException('Mapování mzdových složek JMHZ není dostupné.');
        }

        return $mappings;
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        string $employmentType = 'hpp',
        string $relationType = 'employment',
        int $weeklyHours = 40,
        int $workload = 10_000,
        bool $taxDeclarationSigned = true,
        ?string $activityCode = null,
        string $taxpayerType = 'employee',
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weeklyHours,
            $workload,
            $taxDeclarationSigned,
            self::PERIOD_START,
            taxpayerType: $taxpayerType,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, $activityCode, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), self::syntheticPpv($sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $average = $this->createApprovedAverage($person['employment_id'], 3);

        return $person + ['average_id' => (int) $average['id'], 'sequence' => $sequence];
    }

    /**
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hireAgreement(array $person, string $relationType, bool $taxDeclarationSigned = true): array
    {
        $sequence = ++$this->sequence;
        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            $sequence,
            $relationType,
            $relationType,
            10,
            2_500,
            $taxDeclarationSigned,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, self::syntheticPpv($sequence));
        $this->createApprovedAverage($agreement['employment_id'], 3);

        return $agreement;
    }

    private static function syntheticPpv(int $sequence): string
    {
        return sprintf('3%020d', $sequence);
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates, int $dailyMinutes = 480): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates, dailyMinutes: $dailyMinutes);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $response->getBody());
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'base-' . $person['employment_id'], self::PERIOD_START);
    }

    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "sv-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], "sv-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );

        return (string) preg_replace('/>\s+</', '><', (string) ($tested['body']['xml'] ?? ''));
    }
}
