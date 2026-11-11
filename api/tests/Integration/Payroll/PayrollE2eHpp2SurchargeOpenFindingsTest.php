<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentSurchargePolicyAction;
use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * OTEVŘENÝ NÁLEZ příplatků: test popisuje správné chování a nad současným
 * kódem PADÁ. Oprava vyžaduje rozhodnutí, kde mají hodiny přesčasu zadané
 * rychlým měsíčním vstupem vzniknout (viz docblok testu a zpráva A-hpp2).
 * Podklady a pomocné metody jsou shodné s {@see PayrollE2eHpp2SurchargeTest}.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2SurchargeOpenFindingsTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('PRI', 'Syntetická účtárna příplatků', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        // Firma má výchozí číselník složek a jejich zařazení do JMHZ jako po zapnutí mezd.
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
     * Přesčas 6 h zadaný hodinami v rychlém měsíčním vstupu u firmy, která
     * docházku potvrzuje jen souhrnem odpracovaných dnů: mzda i příplatek se
     * vyplatí (1 432 + 375 Kč) a v hlášení jsou v 10329 a 10333, ale počet
     * odpracovaných hodin 10268 zůstane 160 a přesčasové hodiny 10269 nula.
     * Hlášení tak nese příplatek za přesčas bez jediné přesčasové hodiny.
     *
     * Správně: 10268 = 160 + 6 = 166 h, 10269 = 6 h (stejně jako když je
     * týž přesčas veden v docházce, viz
     * PayrollE2eHpp2SurchargeTest::testAttendanceOvertimeAlsoPaysTheAchievedWage).
     * Návrh: běh má na rozdíl mezi hodinami přesčasu ve vstupech a v potvrzené
     * docházce upozornit, nebo má rychlý vstup hodiny přesčasu do pracovního
     * souhrnu měsíce zapsat. Místo: PayrollRunSnapshotBuilder (validace běhu),
     * PayrollQuickInputRepository::upsertOvertimeParts, pracovní souhrn JMHZ.
     */
    public function testQuickInputOvertimeHoursReachTheMonthlyReport(): void
    {
        $person = $this->hire('Syntetický Přesčasový', 'male', '1984-03-12');
        $this->agreeMonthlyWage($person['employment_id'], 4_200_000);
        $this->ensureRegularCalendar($person['employment_id']);
        $item = $this->quickItem($person['employment_id']);
        $saved = $this->saveQuick($item, 4_200_000, 6_000, []);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $approved = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        $result = $this->submission('quick-overtime-hours');

        self::assertStringContainsString('<form:prescas>375</form:prescas>', $result['xml']);
        self::assertStringContainsString('<form:prescas>6.000</form:prescas>', $result['xml'], 'Přesčasové hodiny 10269 chybí.');
        self::assertStringContainsString('<form:pocet>166.000</form:pocet>', $result['xml'], 'Odpracované hodiny 10268 neobsahují přesčas.');
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string,average_id:int,sequence:int}
     */
    private function hire(string $name, string $sex, string $birthDate, int $averageGrossMinor = 12_600_000, int $averageMinutes = 30_240): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $averageId = $this->average($person['employment_id'], $averageGrossMinor, $averageMinutes);

        return $person + ['average_id' => $averageId, 'sequence' => $sequence];
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

        return (int) $average['id'];
    }

    /** @return array<string,mixed> */
    private function cell(int $employmentId, string $category, string $localStart, string $localEnd, int $breakMinutes): array
    {
        $zone = new \DateTimeZone('Europe/Prague');

        return [
            'employment_id' => $employmentId,
            'starts_at' => (new \DateTimeImmutable($localStart, $zone))->format('Y-m-d\TH:i:sP'),
            'ends_at' => (new \DateTimeImmutable($localEnd, $zone))->format('Y-m-d\TH:i:sP'),
            'timezone' => 'Europe/Prague',
            'category' => $category,
            'break_minutes' => $breakMinutes,
            'row_version' => 0,
            'month_row_version' => 0,
            'supersedes_id' => null,
        ];
    }

    /** @param list<array<string,mixed>> $cells */
    private function approveCells(int $employmentId, array $cells): ResponseInterface
    {
        $this->ensureRegularCalendar($employmentId);
        $version = $this->timeMonthRowVersion($employmentId, self::PERIOD);
        foreach ($cells as &$cell) {
            $cell['month_row_version'] = $version;
        }
        unset($cell);
        $batch = $this->time->entryBatch(
            $this->request('POST', '/api/payroll/time/entries/batch')
                ->withQueryParams(['period' => self::PERIOD, 'employment_id' => (string) $employmentId])
                ->withParsedBody(['period' => self::PERIOD, 'cells' => $cells]),
            new Response(),
        );
        self::assertSame(200, $batch->getStatusCode(), (string) $batch->getBody());
        $body = $this->json($batch);
        self::assertSame([], $body['failures'], CanonicalJson::encode($body['failures']));

        return $this->approveTimeMonth($employmentId, self::PERIOD, []);
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

    private function agreeMonthlyWage(int $employmentId, int $monthlyMinor): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET remuneration_type = "monthly", monthly_gross_minor = ?,
                    remuneration_basis_kind = "contract", remuneration_basis = "Syntetická pracovní smlouva"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$monthlyMinor, $this->supplierId, $employmentId]);
    }

    private function agreeSurchargePolicy(int $employmentId, string $holidayMode): void
    {
        $policies = $this->container->get(PayrollEmploymentSurchargePolicyAction::class);
        self::assertInstanceOf(PayrollEmploymentSurchargePolicyAction::class, $policies);
        $response = $policies->create(
            $this->request('POST', "/api/payroll/employments/{$employmentId}/surcharge-policies")->withParsedBody([
                'valid_from' => '2026-01-01',
                'overtime_mode' => 'surcharge',
                'holiday_mode' => $holidayMode,
                'agreement_reference' => 'Syntetická pracovní smlouva čl. IV',
            ]),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * Odpracovaný měsíc včetně Velikonočního pondělí 6. 4. (8 h práce ve svátek).
     *
     * @return list<array<string,mixed>>
     */
    private function holidayWorkCells(int $employmentId): array
    {
        $cells = [];
        foreach ([...self::workdays(self::PERIOD), '2026-04-06'] as $day) {
            $cells[] = $this->cell($employmentId, 'regular', "{$day} 08:00", "{$day} 16:30", 30);
        }
        $cells[] = $this->cell($employmentId, 'holiday', '2026-04-06 08:00', '2026-04-06 16:00', 0);

        return $cells;
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

    /**
     * @param array<string,mixed> $item
     * @param array<string,array<string,mixed>> $surcharges
     */
    private function saveQuick(array $item, int $baseMinor, ?int $overtimeHoursMilli, array $surcharges): ResponseInterface
    {
        return $this->quickInputs()->save(
            $this->request('PUT', '/api/payroll/quick-inputs')->withParsedBody([
                'period' => self::PERIOD,
                'rows' => [[
                    'employment_id' => $item['employment_id'],
                    'employment_row_version' => $item['employment_row_version'],
                    'base_amount_minor' => $baseMinor,
                    'overtime_mode' => $overtimeHoursMilli === null ? 'amount' : 'hours',
                    'overtime_hours_milli' => $overtimeHoursMilli,
                    'overtime_amount_minor' => $overtimeHoursMilli === null ? 0 : null,
                    'overtime_average_snapshot_id' => $item['overtime_average_snapshot_id'],
                    'overtime_average_snapshot_version' => $item['overtime_average_snapshot_version'],
                    'bonus_amount_minor' => 0,
                    'surcharges' => $surcharges,
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

    /**
     * @param array<string,mixed> $person zákonný výsledek osoby
     * @param array{taxable:int,social:int,health_employee:int,health_employer:int,advance:int,net:int} $expected
     */
    private function assertPayroll(array $person, array $expected): void
    {
        self::assertSame($expected, [
            'taxable' => $person['income_tax']['advance_tax']['taxable_income_minor_units'],
            'social' => $person['social_insurance']['employee_contribution_minor_units'],
            'health_employee' => $person['health_insurance']['employee_contribution_minor_units'],
            'health_employer' => $person['health_insurance']['employer_contribution_minor_units'],
            'advance' => $person['income_tax']['advance_tax']['tax_after_credits_minor_units'],
            'net' => $person['net_payable_minor_units'],
        ], 'Výpočet nesouhlasí s ručním přepočtem.');
    }
}
