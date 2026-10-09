<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

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
     * Úhrn 10275 tu záměrně netestujeme: svátek uvnitř nepřítomnosti, za kterou
     * se mzda krátí, v něm chybí (176 h místo 184 h, Pokyny MH k 10275),
     * viz řádek JMHZ-SV-10275-05 a defekt G3-2.
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
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
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
