<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Scénáře měsíčního hlášení JMHZ od založení zaměstnance po testovací
 * sestavení XML: dovolená, souběh vztahů, neplacené volno, náhradní volno,
 * pracovní neschopnost, mateřská, pracující důchodce, dítě s pořadím N,
 * dohoda se srážkovou daní a dohoda bez stanovené týdenní doby.
 *
 * Každý scénář jde cestou účetní: evidence, absence, docházka, běh,
 * příprava hlášení a dry-run s XSD a kontrolami katalogu. Kde se tok
 * zastaví, test vypíše krok a důvod, tedy místo, kde by se zasekl uživatel.
 * Tvar výsledného XML se ověřuje proti tvarům, které JMHZ přijalo.
 *
 * S proměnnou prostředí MYUCTO_JMHZ_SCENARIO_DUMP (adresář) se XML každého
 * scénáře uloží pro soukromé porovnání mimo repozitář.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzScenarioFlowTest extends TestCase
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
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '9990001234');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$mappings instanceof PayrollComponentJmhzMappingRepository) {
            throw new \RuntimeException('Mapování mzdových složek JMHZ není dostupné.');
        }
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testFullTimeEmployeeWithVacation(): void
    {
        $person = $this->hire('Eva Dovolená', 'female', '1988-04-12');
        $vacation = ['2026-07-20', '2026-07-21'];
        $this->createApprovedAbsence(
            $person['employment_id'],
            'vacation',
            $vacation[0],
            $vacation[1],
            $person['average_id'],
        );
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, $vacation));
        $this->pay($person, 4_500_000);

        $xml = $this->submission('vacation');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // Dovolená jde do 10279 a zároveň do placených neodpracovaných 10276.
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>16.000</form:hodinyNeodpracCelkem>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNahrada>16.000</form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracDovol>16.000</form:hodinyNeodpracDovol>', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>0</form:vylouceneDobyCelkem>', $xml);
    }

    public function testConcurrentFullTimeAndAgreementOfOnePerson(): void
    {
        $person = $this->hire('Filip Souběh', 'male', '1979-09-30');
        $agreement = $this->hireAgreement($person, 'dpp');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->approveMonth($agreement['employment_id'], ['2026-07-04', '2026-07-11'], dailyMinutes: 240);
        $this->pay($person, 4_000_000);
        $this->pay($agreement, 800_000);

        $xml = $this->submission('concurrent');

        // Formulář za každý vztah, souhrnná data osoby jen na hlavním.
        self::assertSame(2, substr_count($xml, '</formularOsoby>'));
        self::assertSame(1, substr_count($xml, '<form:souhrnDataZec>'));
        self::assertSame(1, substr_count($xml, '<primarniPpv>true</primarniPpv>'));
        self::assertSame(1, substr_count($xml, '<primarniPpv>false</primarniPpv>'));
    }

    public function testWholeMonthUnpaidLeave(): void
    {
        // Doplatek do minima za neplacené volno hradí zaměstnanec.
        $person = $this->hire('Gita Volno', 'female', '1990-01-15', healthTopUpResponsibility: 'employee');
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-07-01', '2026-07-31');
        $this->approveMonth($person['employment_id'], []);

        $xml = $this->submission('unpaid-leave');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // § 11 odst. 2 zák. 155/1995: měsíc bez příjmu mimo dobu pojištění,
        // kód zůstává, dny i základ nula; § 18 odst. 7 vylučuje celý měsíc.
        self::assertStringContainsString('<form:kod>1++</form:kod>', $xml);
        self::assertStringContainsString('<form:pocetDnu>0</form:pocetDnu>', $xml);
        self::assertStringContainsString('<form:omluvenaNepritomnost>31</form:omluvenaNepritomnost>', $xml);
        // Kontroly 282 a 283: při nulových hodinách a nulovém příjmu se
        // rozpad přesčasu ani osvobozený příjem neuvádějí.
        self::assertStringNotContainsString('<form:rozpad>', $xml);
        self::assertStringNotContainsString('<form:osvobozenoCelkem>', $xml);
        // Doplatek do minima (13,5 % z 22 400 Kč) za neplacené volno hradí zaměstnanec.
        self::assertStringContainsString(
            '<form:zdravPojZamestnavatel><form:zdravotniPojisteni>0</form:zdravotniPojisteni></form:zdravPojZamestnavatel>'
                . '<form:zdravPojZamestnanec><form:zdravotniPojisteni>3024</form:zdravotniPojisteni></form:zdravPojZamestnanec>',
            $xml,
        );
    }

    public function testCompensatoryTimeOff(): void
    {
        $person = $this->hire('Hynek Náhradní', 'male', '1985-05-05');
        $this->createApprovedAbsence($person['employment_id'], 'compensatory_time_off', '2026-07-24', '2026-07-24');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, ['2026-07-24']));
        $this->pay($person, 4_200_000);

        $xml = $this->submission('compensatory');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // Náhradní volno: jen úhrn 10275, bez placených 10276 (§ 114 odst. 1 ZP),
        // celý den je vyloučeným dnem § 18 odst. 7.
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>8.000</form:hodinyNeodpracCelkem>', $xml);
        self::assertStringNotContainsString('<form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString('<form:omluvenaNepritomnost>1</form:omluvenaNepritomnost>', $xml);
    }

    public function testSickLeaveBeyondEmployerCompensationWindow(): void
    {
        $person = $this->hire('Ivan Nemocný', 'male', '1982-11-02');
        $sick = self::dateRange('2026-07-06', '2026-07-31');
        $this->createApprovedAbsence(
            $person['employment_id'],
            'dpn',
            '2026-07-06',
            '2026-07-31',
            $person['average_id'],
            decisionExtra: [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ],
        );
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, $sick));
        $this->pay($person, 900_000);

        $xml = $this->submission('sick-leave');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // Celá DPN je vyloučenou dobou; hodiny se dělí oknem náhrady § 192 ZP.
        self::assertStringContainsString('<form:docasNeschopnost>26</form:docasNeschopnost>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNeschop>80.000</form:hodinyNeodpracNeschop>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracBezNahrady>80.000</form:hodinyNeodpracBezNahrady>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNahrada>80.000</form:hodinyNeodpracNahrada>', $xml);
        // § 3 odst. 9 písm. b) zák. 592/1992: minimum se krátí o 26 dnů DPN
        // na 3 613 Kč, základ 9 000 Kč ho převyšuje, takže doplatek nevzniká:
        // 9 % a 4,5 % z 9 000 Kč.
        self::assertStringContainsString(
            '<form:zdravPojZamestnavatel><form:zdravotniPojisteni>810</form:zdravotniPojisteni></form:zdravPojZamestnavatel>'
                . '<form:zdravPojZamestnanec><form:zdravotniPojisteni>405</form:zdravotniPojisteni></form:zdravPojZamestnanec>',
            $xml,
        );
    }

    public function testPreBirthMaternityForTheWholeMonth(): void
    {
        $person = $this->hire('Jana Mateřská', 'female', '1993-03-08');
        $this->createApprovedAbsence(
            $person['employment_id'],
            'ppm',
            '2026-07-01',
            '2026-12-31',
            extra: ['expected_childbirth_date' => '2026-08-20'],
        );
        $this->approveMonth($person['employment_id'], []);

        $xml = $this->submission('maternity');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // PPM před porodem je omluvný důvod: dobou pojištění zůstává celý
        // měsíc a celý je vyloučenou dobou 10359.
        self::assertStringContainsString('<form:pocetDnu>31</form:pocetDnu>', $xml);
        self::assertStringContainsString('<form:penezitaPomocMaterstvi>31</form:penezitaPomocMaterstvi>', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>31</form:vylouceneDobyCelkem>', $xml);
        self::assertStringNotContainsString('<form:osvobozenoCelkem>', $xml);
        // Za příjemkyni PPM platí pojistné stát (§ 7 odst. 1 písm. d) zák.
        // 48/1997), minimum se po celý měsíc nepoužije: bez doplatku.
        self::assertStringContainsString(
            '<form:zdravPojZamestnavatel><form:zdravotniPojisteni>0</form:zdravotniPojisteni></form:zdravPojZamestnavatel>'
                . '<form:zdravPojZamestnanec><form:zdravotniPojisteni>0</form:zdravotniPojisteni></form:zdravPojZamestnanec>',
            $xml,
        );
    }

    public function testWorkingPensionerDiscount(): void
    {
        $person = $this->hire('Karel Důchodce', 'male', '1958-06-21', socialDiscountStatus: 'verified');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 3_000_000);

        $xml = $this->submission('pensioner');

        // 6,5 % z 30 000 Kč = 1 950 Kč (§ 7e zák. 589/1992).
        self::assertStringContainsString('<form:slevaZamestnanceEvidovana>true</form:slevaZamestnanceEvidovana>', $xml);
        self::assertStringContainsString('<form:vyseSlevy>1950</form:vyseSlevy>', $xml);
    }

    public function testChildClaimedByOtherCaregiverAndOwnSecondChild(): void
    {
        $person = $this->hire('Lucie Rodičovská', 'female', '1987-10-10');
        $first = $this->createChild($person['employee_id'], 'Syntetické Dítě Starší', '2016-02-02', 1);
        $this->claimChild($person['employee_id'], $first, 1, [
            'credit_status' => 'claimed_by_other',
            'other_claimant_excluded' => false,
            'other_household_caregiver_status' => 'present',
            'other_caregiver_given_name' => 'Syntetický',
            'other_caregiver_family_name' => 'Poplatník',
            'other_caregiver_birth_date' => '1980-01-01',
        ]);
        $second = $this->createChild($person['employee_id'], 'Syntetické Dítě Mladší', '2020-06-06', 2);
        $this->claimChild($person['employee_id'], $second, 2);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_800_000);

        $xml = $this->submission('child-n');

        // Dítě uplatňované jinou osobou má pořadí „N“, vlastní dítě pořadí 2
        // a sazbu druhého dítěte (1 860 Kč v 2026).
        self::assertStringContainsString('<form:vyzivujeJinaOsoba>true</form:vyzivujeJinaOsoba>', $xml);
        self::assertStringContainsString('<form:poradi>N</form:poradi>', $xml);
        self::assertStringContainsString('<form:poradi>2</form:poradi>', $xml);
        self::assertStringContainsString('<form:slevaDite>1860</form:slevaDite>', $xml);
    }

    public function testAgreementWithWithholdingTax(): void
    {
        $person = $this->hire(
            'Marek Srážka',
            'male',
            '2001-12-24',
            employmentType: 'dpp',
            relationType: 'dpp',
            weeklyHours: 10,
            workload: 2_500,
            taxDeclarationSigned: false,
        );
        $this->approveMonth($person['employment_id'], ['2026-07-04', '2026-07-11', '2026-07-18'], dailyMinutes: 240);
        $this->pay($person, 600_000);

        $xml = $this->submission('dpp-withholding');

        // DPP do 11 500 Kč bez prohlášení: srážková daň 15 %, bez účasti na
        // pojištění, týdenní doba „99“ (pokyny MPSV k 10261).
        self::assertStringContainsString(
            '<form:zvlastniSazbaDane><form:zakladDane>6000</form:zakladDane><form:srazenaDan>900</form:srazenaDan></form:zvlastniSazbaDane>',
            $xml,
        );
        self::assertStringContainsString('<form:stanovenaTydenniDoba>99.00</form:stanovenaTydenniDoba>', $xml);
        self::assertStringContainsString('<form:eldp><form:pocetDnu>0</form:pocetDnu></form:eldp>', $xml);
    }

    /**
     * Osoba s úplnou evidencí pro JMHZ, zveřejněnými směnami na pracovní dny
     * měsíce a schváleným průměrem za 3. čtvrtletí.
     *
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int}
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
        string $taxRegime = 'advance',
        string $socialDiscountStatus = 'not_claimed',
        string $healthTopUpResponsibility = 'employer_obstacle_verified',
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
            taxRegime: $taxRegime,
            socialDiscountStatus: $socialDiscountStatus,
            healthTopUpResponsibility: $healthTopUpResponsibility,
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

        return $person + ['average_id' => (int) $average['id']];
    }

    /**
     * Další vztah téže osoby (souběh).
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hireAgreement(array $person, string $relationType): array
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
            true,
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
        return sprintf('2%020d', $sequence);
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates, int $dailyMinutes = 480): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates, dailyMinutes: $dailyMinutes);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Zaseknutí: schválení docházky. ' . (string) $response->getBody(),
        );
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput(
            $person,
            $this->baseComponentId,
            $amountMinor,
            'base-' . $person['employment_id'],
            self::PERIOD_START,
        );
    }

    /**
     * Běh, příprava a dry-run. Vrací XML; při zastavení selže s krokem a
     * důvodem.
     */
    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "scenario-{$scenario}");
        self::assertSame(
            [],
            $run['blockers'],
            'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers'])
                . ' Zákonný výpočet: '
                . CanonicalJson::encode($run['calculated']->revision['result_snapshot']['statutory'] ?? null),
        );
        self::assertSame(
            [],
            $run['warnings'],
            'Zaseknutí: varování před schválením běhu. ' . CanonicalJson::encode($run['warnings']),
        );
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "scenario-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );

        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));
        $xml = (string) ($tested['body']['xml'] ?? '');
        $this->dump($scenario, $xml);
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );
        self::assertTrue(
            $tested['body']['controls']['submittable'],
            'Zaseknutí: kontroly katalogu. ' . CanonicalJson::encode($tested['body']['controls']),
        );

        return (string) preg_replace('/>\s+</', '><', $xml);
    }

    private function dump(string $scenario, string $xml): void
    {
        $directory = getenv('MYUCTO_JMHZ_SCENARIO_DUMP');
        if (!is_string($directory) || $directory === '' || $xml === '') {
            return;
        }
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory . DIRECTORY_SEPARATOR . "{$scenario}.xml", $xml);
    }

    private function createChild(int $employeeId, string $name, string $birthDate, int $sequence): int
    {
        $birthNumber = self::syntheticBirthNumber($birthDate, 'male', 100 + $sequence);
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

    /** @param array<string,mixed> $overrides */
    private function claimChild(int $employeeId, int $dependantId, int $order, array $overrides = []): void
    {
        $response = $this->dependants()->createClaim(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants/{$dependantId}/claims")
                ->withParsedBody($overrides + [
                    'child_order' => $order,
                    'claim_reason' => 'own_household',
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'document:child-claim',
                    'shared_household_confirmed' => true,
                    'other_claimant_excluded' => true,
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
        if (!$action instanceof PayrollDependantAction) {
            throw new \RuntimeException('Evidence vyživovaných osob není dostupná.');
        }

        return $action;
    }
}
