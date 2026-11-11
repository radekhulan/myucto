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
 * HPP za duben 2026 (první měsíc JMHZ): ručně přepočítané vzorky výpočtu
 * podle ZDP a zákonů o pojistném s hodnotami legislativní sady 2026
 * (`CzechPayrollRulesets2026`), běh až po dry-run hlášení.
 *
 * Konstanty sady 2026 použité v ručních přepočtech:
 *  - sleva na poplatníka 2 570 Kč, zvýhodnění 1. dítě 1 267 Kč, 2. dítě 1 860 Kč;
 *  - záloha 15 %, nad 146 901 Kč (3násobek průměrné mzdy 48 967 Kč) 23 %,
 *    základ nad 100 Kč zaokrouhlený na celé stokoruny nahoru, záloha na koruny nahoru;
 *  - SP zaměstnanec 7,1 %, zaměstnavatel 24,8 %; ZP celkem 13,5 %, zaměstnanec
 *    třetina, minimální vyměřovací základ ZP 22 400 Kč (minimální mzda).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHppCalculationTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('HPP', 'Syntetická účtárna HPP', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_HPP', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * HPP 45 000 Kč, dvě děti, podepsané prohlášení.
     *
     * Ruční přepočet: SP 45 000 × 7,1 % = 3 195; ZP 45 000 × 13,5 % = 6 075,
     * zaměstnanec třetina 2 025, zaměstnavatel 4 050; záloha 45 000 × 15 %
     * = 6 750 − 2 570 (poplatník) = 4 180 − 1 267 − 1 860 (děti) = 1 053;
     * čistá 45 000 − 3 195 − 2 025 − 1 053 = 38 727.
     */
    public function testFullTimeWithTwoChildrenAndDeclaration(): void
    {
        $person = $this->hire('Syntetická Dvojdětná', 'female', '1987-05-11');
        $this->claimTwoChildren($person['employee_id']);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_500_000);

        $result = $this->submission('two-children');

        $this->assertPayroll($result['person'], [
            'social' => 319_500,
            'health_employee' => 202_500,
            'health_employer' => 405_000,
            'health_top_up' => 0,
            'advance' => 105_300,
            'child_credit' => 312_700,
            'bonus' => 0,
            'net' => 3_872_700,
        ]);
        // 10305 nese úhrn zvýhodnění v celých korunách, 10297 čistou mzdu.
        self::assertStringContainsString('<form:slevaDite>3127</form:slevaDite>', $result['xml']);
        self::assertStringContainsString('<form:mzdaCista>38727</form:mzdaCista>', $result['xml']);
        self::assertStringContainsString('<form:poradi>1</form:poradi>', $result['xml']);
        self::assertStringContainsString('<form:poradi>2</form:poradi>', $result['xml']);
    }

    /**
     * Nízký příjem se dvěma dětmi: zvýhodnění převýší daň a vznikne bonus.
     *
     * Ruční přepočet: 25 000 × 15 % = 3 750 − 2 570 = 1 180; zvýhodnění
     * 3 127 sníží daň na 0 a zbytek 1 947 je bonus (≥ 50 Kč, příjem
     * ≥ 11 200 Kč = polovina minimální mzdy). SP 1 775, ZP 3 375 / 3 = 1 125;
     * čistá 25 000 − 1 775 − 1 125 + 1 947 = 24 047.
     */
    public function testLowIncomeWithTwoChildrenReceivesTaxBonus(): void
    {
        $person = $this->hire('Syntetický Bonusový', 'male', '1985-01-20');
        $this->claimTwoChildren($person['employee_id']);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 2_500_000);

        $result = $this->submission('bonus');

        $this->assertPayroll($result['person'], [
            'social' => 177_500,
            'health_employee' => 112_500,
            'health_employer' => 225_000,
            'health_top_up' => 0,
            'advance' => 0,
            // Ze zvýhodnění 3 127 se jako sleva uplatní 1 180, zbytek je bonus.
            'child_credit' => 118_000,
            'bonus' => 194_700,
            'net' => 2_404_700,
        ]);
    }

    /**
     * HPP bez prohlášení nad hranicí 23 %.
     *
     * Ruční přepočet: základ 160 000; 15 % ze 146 901 = 22 035,15, 23 %
     * z 13 099 = 3 012,77, záloha 25 047,92 → 25 048 (bez slev, nepodepsáno);
     * SP 160 000 × 7,1 % = 11 360; ZP 21 600 / 3 = 7 200; čistá 116 392.
     */
    public function testWithoutDeclarationAboveHighRateThreshold(): void
    {
        $person = $this->hire('Syntetický Nepodepsaný', 'male', '1979-08-08', taxDeclarationSigned: false);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 16_000_000);

        $result = $this->submission('high-rate');

        $this->assertPayroll($result['person'], [
            'social' => 1_136_000,
            'health_employee' => 720_000,
            'health_employer' => 1_440_000,
            'health_top_up' => 0,
            'advance' => 2_504_800,
            'child_credit' => 0,
            'bonus' => 0,
            'net' => 11_639_200,
        ]);
        $advance = $result['person']['income_tax']['advance_tax'];
        self::assertSame(14_690_100, $advance['low_rate_base_minor_units']);
        self::assertSame(1_309_900, $advance['high_rate_base_minor_units']);
        self::assertSame(0, $advance['non_refundable_credits_minor_units']);
    }

    /**
     * Zkrácený úvazek 20 h týdně za 15 000 Kč bez důvodu výjimky z minima ZP:
     * rozdíl do minima doplatí zaměstnanec celý.
     *
     * Ruční přepočet: ZP ze skutečného základu 15 000 × 13,5 % = 2 025
     * (zaměstnanec 675, zaměstnavatel 1 350); dopočet (22 400 − 15 000)
     * × 13,5 % = 999 jde celý na zaměstnance → 1 674. SP 1 065; záloha
     * 2 250 − 2 570 → 0; čistá 15 000 − 1 065 − 1 674 = 12 261.
     */
    public function testPartTimeBelowHealthMinimumEmployeeTopsUp(): void
    {
        $person = $this->hire(
            'Syntetická Zkrácená',
            'female',
            '1990-02-02',
            weeklyHours: 20,
            workload: 5_000,
            healthTopUpResponsibility: 'employee',
            publishShifts: false,
        );
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD), 240);
        $this->pay($person, 1_500_000);

        $result = $this->submission('part-time-minimum');

        $this->assertPayroll($result['person'], [
            'social' => 106_500,
            'health_employee' => 167_400,
            'health_employer' => 135_000,
            'health_top_up' => 99_900,
            'advance' => 0,
            'child_credit' => 0,
            'bonus' => 0,
            'net' => 1_226_100,
        ]);
    }

    /**
     * Zdravotně postižený (invalidita 3. stupně): sleva 420 Kč měsíčně
     * a jako státní pojištěnec bez dopočtu do minima ZP.
     *
     * Ruční přepočet: 21 000 × 15 % = 3 150 − 2 570 − 420 = 160; SP 1 491;
     * ZP 2 835 / 3 = 945 bez dopočtu; čistá 21 000 − 1 491 − 945 − 160 = 18 404.
     */
    public function testDisabledEmployeeGetsCreditAndNoHealthTopUp(): void
    {
        $person = $this->hire(
            'Syntetický Invalidní',
            'male',
            '1975-06-06',
            weeklyHours: 30,
            workload: 7_500,
            healthTopUpResponsibility: 'employee',
            publishShifts: false,
        );
        $this->amendEvidence($person['employee_id'], [
            'tax_credit_claims' => [[
                'credit_kind' => 'disability-extended',
                'evidence_status' => 'verified',
                'evidence_reference' => 'document:synthetic-disability-decision',
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]],
            'health_minimum_reductions' => [[
                'reason' => 'state_insured',
                'evidence_reference' => 'document:synthetic-state-insured',
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]],
        ]);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD), 360);
        $this->pay($person, 2_100_000);

        $result = $this->submission('disabled');

        $this->assertPayroll($result['person'], [
            'social' => 149_100,
            'health_employee' => 94_500,
            'health_employer' => 189_000,
            'health_top_up' => 0,
            'advance' => 16_000,
            'child_credit' => 0,
            'bonus' => 0,
            'net' => 1_840_400,
        ]);
        self::assertSame(299_000, $result['person']['income_tax']['applied_non_refundable_credits_minor_units']);
    }

    /**
     * Pracující poživatel starobního důchodu se slevou na důchodovém pojištění.
     *
     * Ruční přepočet: SP 30 000 × 7,1 % = 2 130, sleva 30 000 × 6,5 % = 1 950,
     * zaměstnanec platí 180 (jen nemocenské 0,6 %); ZP 4 050 / 3 = 1 350;
     * záloha 4 500 − 2 570 = 1 930; čistá 30 000 − 180 − 1 350 − 1 930 = 26 540.
     */
    public function testWorkingPensionerSocialDiscount(): void
    {
        $person = $this->hire('Syntetický Důchodce', 'male', '1958-03-03', socialDiscountStatus: 'verified');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 3_000_000);

        $result = $this->submission('pensioner');

        $this->assertPayroll($result['person'], [
            'social' => 18_000,
            'health_employee' => 135_000,
            'health_employer' => 270_000,
            'health_top_up' => 0,
            'advance' => 193_000,
            'child_credit' => 0,
            'bonus' => 0,
            'net' => 2_654_000,
        ]);
        self::assertSame(195_000, $result['person']['social_insurance']['working_pensioner_discount_minor_units']);
    }

    /**
     * Student na zkrácený úvazek: v roce 2026 sleva na studenta neexistuje
     * (§ 35bc ZDP až od 2027), za studenta platí ZP stát, takže minimum se
     * nedopočítává.
     *
     * Ruční přepočet: SP 10 000 × 7,1 % = 710; ZP 1 350 / 3 = 450; záloha
     * 1 500 − 2 570 → 0; čistá 10 000 − 710 − 450 = 8 840.
     */
    public function testStudentPartTimeWithoutHealthTopUp(): void
    {
        $person = $this->hire(
            'Syntetická Studentka',
            'female',
            '2004-10-10',
            weeklyHours: 10,
            workload: 2_500,
            healthTopUpResponsibility: 'employee',
            publishShifts: false,
        );
        $this->amendEvidence($person['employee_id'], [
            'health_minimum_reductions' => [[
                'reason' => 'state_insured',
                'evidence_reference' => 'document:synthetic-study-confirmation',
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]],
        ]);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD), 120);
        $this->pay($person, 1_000_000);

        $result = $this->submission('student');

        $this->assertPayroll($result['person'], [
            'social' => 71_000,
            'health_employee' => 45_000,
            'health_employer' => 90_000,
            'health_top_up' => 0,
            'advance' => 0,
            'child_credit' => 0,
            'bonus' => 0,
            'net' => 884_000,
        ]);
    }

    /**
     * Nástup 15. 4. 2026 na zkrácený úvazek 20 h, mzda za duben 10 000 Kč:
     * minimum ZP se krátí na dny trvání zaměstnání (§ 3 odst. 8 zák.
     * č. 592/1992 Sb.), tedy 22 400 × 16/30 = 11 946,67 Kč.
     *
     * Ruční přepočet: ZP celkem 13,5 % z 11 946,67 = 1 612,80 → 1 613;
     * zaměstnavatel 2/3 ze skutečného základu 1 350 = 900, zaměstnanec
     * zbytek 713 (450 + dopočet 263); SP 710; záloha 1 500 − 2 570 → 0;
     * čistá 10 000 − 710 − 713 = 8 577.
     */
    public function testMidMonthEntryProratesHealthMinimum(): void
    {
        $person = $this->hire(
            'Syntetická Nástupní',
            'female',
            '1992-09-09',
            weeklyHours: 20,
            workload: 5_000,
            healthTopUpResponsibility: 'employee',
            publishShifts: false,
        );
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = "2026-04-15", actual_start_date = "2026-04-15" WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $person['employment_id']]);
        $pdo->prepare('UPDATE payroll_employment_terms SET effective_from = "2026-04-15", planned_start_on = "2026-04-15", actual_start_on = "2026-04-15" WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$this->supplierId, $person['employment_id']]);
        $worked = array_values(array_filter(self::workdays(self::PERIOD), static fn (string $day): bool => $day >= '2026-04-15'));
        $this->approveMonth($person['employment_id'], $worked, 240);
        $this->pay($person, 1_000_000);

        $result = $this->submission('mid-month-entry');

        $this->assertPayroll($result['person'], [
            'social' => 71_000,
            'health_employee' => 71_300,
            'health_employer' => 90_000,
            'health_top_up' => 26_300,
            'advance' => 0,
            'child_credit' => 0,
            'bonus' => 0,
            'net' => 857_700,
        ]);
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        int $weeklyHours = 40,
        int $workload = 10_000,
        bool $taxDeclarationSigned = true,
        string $socialDiscountStatus = 'not_claimed',
        string $healthTopUpResponsibility = 'employer_obstacle_verified',
        bool $publishShifts = true,
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            $weeklyHours,
            $workload,
            $taxDeclarationSigned,
            self::PERIOD_START,
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
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        if ($publishShifts) {
            $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        }
        $average = $this->createApprovedAverage($person['employment_id'], 2);

        return $person + ['average_id' => (int) $average['id'], 'sequence' => $sequence];
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates, int $dailyMinutes = 480): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates, dailyMinutes: $dailyMinutes);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $response->getBody());
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'base-' . $person['employment_id'], self::PERIOD_START);
    }

    /**
     * Běh, příprava JMHZ a dry-run; vrací zákonný výsledek první osoby a XML.
     *
     * @return array{person:array<string,mixed>,people:list<array<string,mixed>>,xml:string,revision_id:int}
     */
    private function submission(string $scenario): array
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "hpp-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];
        $people = $run['approved']->revision['result_snapshot']['statutory']['people'];

        $preparation = $this->prepareJmhz($revisionId, "hpp-{$scenario}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']));
        self::assertTrue($tested['body']['controls']['submittable']);

        return [
            'person' => $people[0],
            'people' => $people,
            'xml' => (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']),
            'revision_id' => $revisionId,
        ];
    }

    private function claimTwoChildren(int $employeeId): void
    {
        $first = $this->createChild($employeeId, 'Syntetický Prvni', '2015-03-02', 1);
        $second = $this->createChild($employeeId, 'Syntetický Druhy', '2018-09-14', 2);
        $this->claimChild($employeeId, $first, 1);
        $this->claimChild($employeeId, $second, 2);
    }

    /**
     * Doplní sekce zákonné evidence osoby přes tentýž zápis jako karta.
     *
     * @param array<string,list<array<string,mixed>>> $sections
     */
    private function amendEvidence(int $employeeId, array $sections): void
    {
        $repository = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class);
        self::assertInstanceOf(PayrollPersonStatutoryEvidenceRepository::class, $repository);
        $view = $repository->editorView($this->supplierId, $employeeId, '2026-04-30');
        self::assertIsArray($view);
        $repository->save(
            $this->supplierId,
            $employeeId,
            ['effective_on' => '2026-04-30', 'sections' => array_replace($view['sections'], $sections)],
            '2026-04-30',
            $this->actors[0],
            null,
            'payroll-e2e-hpp',
        );
    }

    /**
     * @param array<string,mixed> $person zákonný výsledek osoby
     * @param array{social:int,health_employee:int,health_employer:int,health_top_up:int,advance:int,child_credit:int,bonus:int,net:int} $expected
     */
    private function assertPayroll(array $person, array $expected): void
    {
        $actual = [
            'social' => $person['social_insurance']['employee_contribution_minor_units'],
            'health_employee' => $person['health_insurance']['employee_contribution_minor_units'],
            'health_employer' => $person['health_insurance']['employer_contribution_minor_units'],
            'health_top_up' => $person['health_insurance']['employee_minimum_top_up_minor_units'],
            'advance' => $person['income_tax']['advance_tax']['tax_after_credits_minor_units'],
            'child_credit' => $person['income_tax']['applied_child_credit_minor_units'],
            'bonus' => $person['income_tax']['advance_tax']['tax_bonus_minor_units'],
            'net' => $person['net_payable_minor_units'],
        ];
        self::assertSame($expected, $actual, 'Výpočet nesouhlasí s ručním přepočtem.');
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
}
