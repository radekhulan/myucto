<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Skončení pracovního poměru uprostřed měsíce (15. 4. 2026) s ručním přepočtem.
 *
 * Společné podklady:
 *  - duben 2026 má 22 pracovních dnů včetně dvou svátků (Velký pátek 3. 4.
 *    a Velikonoční pondělí 6. 4.), fond měsíční mzdy včetně svátků 176 h
 *    při 40 h týdně; do 15. 4. připadá 11 pracovních dnů (9 odpracovaných
 *    a 2 svátky) = 88 h;
 *  - měsíční mzda přísluší v poměru doby, po kterou poměr v měsíci trval,
 *    k fondu měsíce (§ 109 odst. 1 a § 141 odst. 1 ZP); svátek mzdu nekrátí
 *    (§ 115 odst. 3 ZP);
 *  - náhrada za nevyčerpanou dovolenou při skončení poměru (§ 222 odst. 2
 *    ZP) ve výši průměrného výdělku je zdanitelný příjem i vyměřovací
 *    základ pojistného;
 *  - odstupné při výpovědi z organizačních důvodů u poměru kratšího než
 *    1 rok je jednonásobek průměrného výdělku (§ 67 odst. 1 písm. a) ZP),
 *    tedy průměrného měsíčního výdělku: hodinový průměr × týdenní pracovní
 *    doba × 4,348 (§ 67 odst. 3 a § 356 odst. 2 ZP). Je zdanitelným příjmem
 *    (§ 6 odst. 1 ZDP), ale do vyměřovacího základu sociálního (§ 5 odst. 2
 *    písm. b) zák. č. 589/1992 Sb.) ani zdravotního pojištění (§ 3 odst. 2
 *    písm. b) zák. č. 592/1992 Sb.) se nezahrnuje;
 *  - minimální vyměřovací základ ZP se krátí na kalendářní dny, po které
 *    zaměstnání trvalo (§ 3 odst. 9 písm. a) zák. č. 592/1992 Sb.):
 *    22 400 × 15 / 30 = 11 200 Kč;
 *  - sada 2026: SP zaměstnanec 7,1 %, ZP 13,5 % (zaměstnanec třetina),
 *    záloha 15 % ze základu zaokrouhleného na stokoruny nahoru, sleva na
 *    poplatníka 2 570 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2TerminationTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const END_ON = '2026-04-15';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        if (!$this->db->hasTable('payroll_employment_terminations')) {
            self::markTestSkipped('Chybí evidence skončení pracovního poměru.');
        }
        $this->officeId = $this->createOffice('ODCH', 'Syntetická účtárna odchodů', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $components = $this->container->get(PayrollComponentRepository::class);
        self::assertInstanceOf(PayrollComponentRepository::class, $components);
        $components->ensureDefaults($this->supplierId);
        $mappings = $this->container->get(PayrollComponentJmhzMappingDefaults::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingDefaults::class, $mappings);
        $mappings->apply($this->supplierId);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Výpověď z organizačních důvodů k 15. 4. 2026, měsíční mzda 45 000 Kč,
     * nevyčerpaná dovolená 80 h, průměrný výdělek 135 000 Kč / 512 h
     * = 263,671875 → 263,67 Kč/h.
     *
     * Ruční přepočet:
     *  - mzda 45 000 × 88 / 176 = 22 500 Kč;
     *  - náhrada za dovolenou 80 × 263,67 = 21 093,60 → 21 094 Kč;
     *  - odstupné 263,67 × 40 × 4,348 = 45 857,49 → 45 858 Kč (jednonásobek);
     *  - hrubý příjem 22 500 + 21 094 + 45 858 = 89 452 Kč;
     *  - vyměřovací základ pojistného bez odstupného 43 594 Kč: SP 7,1 %
     *    = 3 095,17 → 3 096; ZP 13,5 % = 5 885,19 → 5 886, zaměstnanec 1 962,
     *    zaměstnavatel 3 924; minimum ZP 11 200 Kč je splněné;
     *  - záloha: základ 89 500 × 15 % = 13 425 − 2 570 = 10 855;
     *  - čistá 89 452 − 3 096 − 1 962 − 10 855 = 73 539 Kč.
     *
     * Hlášení: 10286 = 89 452 (včetně odstupného), mzda za práci 10328
     * = 22 500 (odstupné ani náhrada do ní nepatří), náhrada za dovolenou
     * 10338 = 21 094, 15 kalendářních dnů zaměstnání, odpracováno 9 dnů
     * a 72 h, 16 h svátků bez krácení mzdy.
     */
    public function testMidMonthExitWithLeavePayoutAndSeverance(): void
    {
        $person = $this->hire('Syntetická Odcházející', 'female', '1986-03-14', 40, 10_000);
        $id = $person['employment_id'];
        $this->agreeMonthlyWage($id, 4_500_000);
        self::assertSame(26_367, $this->average($id, 13_500_000, 512 * 60));
        $this->leaveEntitlement($id, 80 * 60);
        $this->ensureRegularCalendar($id);
        $this->publishShifts($id, self::workdays(self::PERIOD));

        $overview = $this->terminate($id, 'employer_notice', 'organizational');
        self::assertSame([], $overview['issues'], CanonicalJson::encode($overview['issues']));
        self::assertSame('payout', $overview['leave_settlement']['state']);
        self::assertSame(1, $overview['severance']['multiple'], 'Poměr trval méně než rok.');
        self::assertSame(4_585_749, $overview['severance']['monthly_average_minor']);
        $overview = $this->termination()->settleLeave($this->supplierId, $id, $this->actors[0]);
        self::assertSame(2_109_400, $overview['leave_settlement']['amount_minor']);
        $overview = $this->termination()->createSeveranceInput($this->supplierId, $id, $this->actors[0]);
        self::assertSame('created', $overview['severance']['state'], CanonicalJson::encode($overview['severance']));
        self::assertSame(4_585_800, $overview['severance']['amount_minor']);

        // Rychlý vstup navrhne mzdu krácenou na dobu trvání poměru.
        $item = $this->quickItem($id);
        self::assertTrue($item['partial_month']);
        self::assertSame([], $item['blockers']);
        self::assertSame(
            [2_250_000, 176 * 60, 88 * 60],
            [$item['base_proration']['amount_minor'], $item['base_proration']['fund_minutes'], $item['base_proration']['replaced_minutes']],
        );
        self::assertSame(2_250_000, $item['base_amount_minor']);
        $saved = $this->saveQuick($item, 2_250_000);
        self::assertSame(200, $saved->getStatusCode(), 'Zaseknutí: rychlý vstup. ' . (string) $saved->getBody());
        self::assertSame([], $this->json($saved)['failures'] ?? [], (string) $saved->getBody());
        $approved = $this->approveTimeMonth($id, self::PERIOD, self::workedUntilExit());
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());
        self::assertSame(
            ['MZDA_MESICNI' => 2_250_000, 'NAHRADA_MZDY_DOVOLENA' => 2_109_400, 'ODSTUPNE' => 4_585_800],
            $this->inputs($id),
        );

        $result = $this->submission('exit-severance');

        $this->assertPayroll($result['person'], [
            'taxable' => 8_945_200,
            'social_base' => 4_359_400,
            'social' => 309_600,
            'health_base' => 4_359_400,
            'health_minimum' => 1_120_000,
            'health_employee' => 196_200,
            'health_employer' => 392_400,
            'health_top_up' => 0,
            'advance' => 1_085_500,
            'net' => 7_353_900,
        ]);
        foreach ([
            '<form:zuctovanoCelkem>89452</form:zuctovanoCelkem>',
            '<form:zakladDane>89452</form:zakladDane>',
            '<form:danZalohaPoSleve>10855</form:danZalohaPoSleve>',
            '<form:mzdaCista>73539</form:mzdaCista>',
            '<form:mzdaZuctovana>22500</form:mzdaZuctovana>',
            '<form:tarif>22500</form:tarif>',
            '<form:dovolena>21094</form:dovolena>',
            '<form:pocetDnu>15</form:pocetDnu>',
            '<form:dnyOdpracovanePocet>9</form:dnyOdpracovanePocet>',
            '<form:pocet>72.000</form:pocet>',
            '<form:hodinyNeodpracCelkem>16.000</form:hodinyNeodpracCelkem>',
            '<form:hodinyNeodpracNahrada>16.000</form:hodinyNeodpracNahrada>',
            '<form:vydelekPrumernyHod>263.67</form:vydelekPrumernyHod>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $result['xml']);
        }
    }

    /**
     * Kratší pracovní doba 20 h týdně za 11 200 Kč, výpověď zaměstnance
     * k 15. 4. 2026 bez odstupného; doplatek do minima ZP nese zaměstnanec.
     *
     * Ruční přepočet:
     *  - fond měsíce 22 × 4 = 88 h, do 15. 4. 44 h: mzda 11 200 × 44 / 88
     *    = 5 600 Kč;
     *  - SP 5 600 × 7,1 % = 397,60 → 398;
     *  - ZP ze skutečného základu 5 600 × 13,5 % = 756 (zaměstnanec 252,
     *    zaměstnavatel 504); minimální základ jen za 15 dnů zaměstnání
     *    22 400 × 15 / 30 = 11 200 Kč, doplatek (11 200 − 5 600) × 13,5 %
     *    = 756 Kč jde celý k tíži zaměstnance → 1 008 Kč;
     *  - záloha 5 600 × 15 % = 840 − 2 570 → 0;
     *  - čistá 5 600 − 398 − 1 008 = 4 194 Kč.
     */
    public function testPartTimeExitProratesWageAndHealthMinimumToEmploymentDays(): void
    {
        $person = $this->hire('Syntetický Zkrácený', 'male', '1991-10-02', 20, 5_000, 'employee');
        $id = $person['employment_id'];
        $this->agreeMonthlyWage($id, 1_120_000);
        self::assertSame(15_000, $this->average($id, 3_840_000, 256 * 60));
        $this->ensureRegularCalendar($id, 240);

        $overview = $this->terminate($id, 'employee_notice', 'none');
        self::assertSame('not_applicable', $overview['severance']['state'], CanonicalJson::encode($overview['severance']));

        $item = $this->quickItem($id);
        self::assertSame(560_000, $item['base_amount_minor']);
        $saved = $this->saveQuick($item, 560_000);
        self::assertSame(200, $saved->getStatusCode(), 'Zaseknutí: rychlý vstup. ' . (string) $saved->getBody());
        self::assertSame([], $this->json($saved)['failures'] ?? [], (string) $saved->getBody());
        $approved = $this->approveTimeMonth($id, self::PERIOD, self::workedUntilExit(), dailyMinutes: 240);
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());

        $result = $this->submission('exit-part-time');

        $this->assertPayroll($result['person'], [
            'taxable' => 560_000,
            'social_base' => 560_000,
            'social' => 39_800,
            'health_base' => 560_000,
            'health_minimum' => 1_120_000,
            'health_employee' => 100_800,
            'health_employer' => 50_400,
            'health_top_up' => 75_600,
            'advance' => 0,
            'net' => 419_400,
        ]);
        foreach ([
            '<form:zuctovanoCelkem>5600</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>5600</form:mzdaZuctovana>',
            '<form:mzdaCista>4194</form:mzdaCista>',
            '<form:pocetDnu>15</form:pocetDnu>',
            '<form:dnyOdpracovanePocet>9</form:dnyOdpracovanePocet>',
            '<form:pocet>36.000</form:pocet>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $result['xml']);
        }
    }

    /** @return list<string> odpracované dny do skončení poměru (bez svátků) */
    private static function workedUntilExit(): array
    {
        return array_values(array_filter(
            self::workdays(self::PERIOD),
            static fn (string $day): bool => $day <= self::END_ON,
        ));
    }

    /**
     * @param array<string,mixed> $person zákonný výsledek osoby
     * @param array<string,int> $expected
     */
    private function assertPayroll(array $person, array $expected): void
    {
        self::assertSame($expected, [
            'taxable' => $person['income_tax']['advance_tax']['taxable_income_minor_units'],
            'social_base' => $person['social_insurance']['capped_assessment_base_minor_units'],
            'social' => $person['social_insurance']['employee_contribution_minor_units'],
            'health_base' => $person['health_insurance']['assessment_base_minor_units'],
            'health_minimum' => $person['health_insurance']['effective_minimum_minor_units'],
            'health_employee' => $person['health_insurance']['employee_contribution_minor_units'],
            'health_employer' => $person['health_insurance']['employer_contribution_minor_units'],
            'health_top_up' => $person['health_insurance']['employee_minimum_top_up_minor_units'],
            'advance' => $person['income_tax']['advance_tax']['tax_after_credits_minor_units'],
            'net' => $person['net_payable_minor_units'],
        ], 'Výpočet nesouhlasí s ručním přepočtem.');
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string,sequence:int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        int $weeklyHours,
        int $workload,
        string $healthTopUpResponsibility = 'employer_obstacle_verified',
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
            true,
            self::PERIOD_START,
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

        return $person + ['sequence' => $sequence];
    }

    private function agreeMonthlyWage(int $employmentId, int $monthlyMinor): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET remuneration_type = "monthly", monthly_gross_minor = ?,
                    remuneration_basis_kind = "contract", remuneration_basis = "Syntetická pracovní smlouva"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$monthlyMinor, $this->supplierId, $employmentId]);
    }

    /** Průměrný výdělek pro 2. čtvrtletí 2026 z rozhodného období 1. čtvrtletí. */
    private function average(int $employmentId, int $grossMinor, int $workedMinutes): int
    {
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => 2,
                'decisive_from' => '2026-01-01',
                'decisive_to' => '2026-03-31',
                'gross_earnings_minor' => $grossMinor,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => $workedMinutes,
                'worked_days' => 63,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        return (int) $average['average_hourly_minor'];
    }

    private function leaveEntitlement(int $employmentId, int $minutes): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_leave_ledger
                (supplier_id, employment_id, leave_year, effective_date, entry_type,
                 minutes_delta, reason, support_status, source_hash, created_by)
             VALUES (?, ?, 2026, "2026-01-01", "entitlement", ?, "Syntetický nárok", "supported", ?, ?)',
        )->execute([$this->supplierId, $employmentId, $minutes, random_bytes(32), $this->actors[0]]);
    }

    /** @return array<string,mixed> přehled skončení */
    private function terminate(int $employmentId, string $method, ?string $legalGround): array
    {
        $employments = $this->container->get(PayrollEmploymentRepository::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $employments);
        $version = (int) $this->scalar(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $employmentId],
        );
        $employments->transition($this->supplierId, $employmentId, 'ended', $version, self::END_ON, null, $this->actors[0], null, null);

        return $this->termination()->save($this->supplierId, $employmentId, [
            'termination_method' => $method,
            'legal_ground' => $legalGround,
        ], $this->actors[0]);
    }

    private function termination(): PayrollEmploymentTerminationService
    {
        $service = $this->container->get(PayrollEmploymentTerminationService::class);
        self::assertInstanceOf(PayrollEmploymentTerminationService::class, $service);

        return $service;
    }

    /** @return array<string,mixed> řádek rychlého měsíčního vstupu */
    private function quickItem(int $employmentId): array
    {
        $response = $this->quickInputs()->list(
            $this->request('GET', '/api/payroll/quick-inputs')->withQueryParams(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        foreach ($this->json($response)['month']['items'] as $item) {
            if ($item['employment_id'] === $employmentId) {
                return $item;
            }
        }
        self::fail("Rychlý vstup neobsahuje vztah {$employmentId}.");
    }

    /** @param array<string,mixed> $item */
    private function saveQuick(array $item, int $baseMinor): ResponseInterface
    {
        return $this->quickInputs()->save(
            $this->request('PUT', '/api/payroll/quick-inputs')->withParsedBody([
                'period' => self::PERIOD,
                'rows' => [[
                    'employment_id' => $item['employment_id'],
                    'employment_row_version' => $item['employment_row_version'],
                    'base_amount_minor' => $baseMinor,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'overtime_average_snapshot_id' => $item['overtime_average_snapshot_id'],
                    'overtime_average_snapshot_version' => $item['overtime_average_snapshot_version'],
                    'bonus_amount_minor' => 0,
                    'surcharges' => [],
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null, 'surcharges' => []],
                ]],
            ]),
            new Response(),
        );
    }

    private function quickInputs(): PayrollQuickInputsAction
    {
        $action = $this->container->get(PayrollQuickInputsAction::class);
        self::assertInstanceOf(PayrollQuickInputsAction::class, $action);

        return $action;
    }

    /** @return array<string,int> kód složky => součet platných vstupů v haléřích */
    private function inputs(int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT component.code, SUM(input.amount_minor)
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND input.period_start = ?
                AND input.status IN ("approved", "locked")
              GROUP BY component.code ORDER BY component.code',
        );
        $statement->execute([$this->supplierId, $employmentId, self::PERIOD_START]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /**
     * Běh, příprava JMHZ a dry-run; vrací zákonný výsledek první osoby a XML.
     *
     * @return array{person:array<string,mixed>,xml:string,revision_id:int}
     */
    private function submission(string $scenario): array
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "hpp2-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];
        $people = $run['approved']->revision['result_snapshot']['statutory']['people'];

        $preparation = $this->prepareJmhz($revisionId, "hpp2-{$scenario}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']));
        self::assertTrue($tested['body']['controls']['submittable']);

        return [
            'person' => $people[0],
            'xml' => (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']),
            'revision_id' => $revisionId,
        ];
    }
}
