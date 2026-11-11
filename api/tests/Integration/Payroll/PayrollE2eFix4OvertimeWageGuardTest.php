<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentSurchargePolicyAction;
use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Pojistky k dosažené mzdě za přesčas, kterou u měsíční mzdy nově zakládá
 * schválení docházky (§ 114 odst. 1 zákoníku práce): upozornění na
 * automatický vstup, varování na ruční duplicitu a nález, když se mzda
 * nezaložila, protože chybí sjednaná měsíční mzda.
 *
 * Ruční přepočet (sjednaná mzda 42 000 Kč, fond dubna 176 h, průměr 250 Kč/h,
 * přesčas 6 h): dosažená mzda 42 000 / 176 × 6 = 1 431,82 → 1 432 Kč,
 * příplatek 25 % × 250 × 6 = 375 Kč. Podklady a pomocné metody jsou shodné
 * s {@see PayrollE2eHpp2SurchargeTest}.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eFix4OvertimeWageGuardTest extends TestCase
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
     * Dosaženou mzdu založilo schválení docházky. Kontrola před během i běh
     * to oznámí jmenovitě (osoba, 6 h, 1 432 Kč) s větou o ručním vstupu
     * a odkazem na vstupy vztahu a měsíce. Upozornění běh nezastaví.
     */
    public function testAutomaticOvertimeWageIsAnnouncedByNameAndDoesNotStopTheRun(): void
    {
        $person = $this->overtimeFromAttendance('Syntetický Oznámený', '1979-02-17');
        $id = $person['employment_id'];
        $this->createApprovedInput($person, $this->componentId('MZDA_MESICNI'), 4_200_000, 'base-' . $id, self::PERIOD_START);
        self::assertSame(143_200, $this->inputs($id)['MZDA_HODINOVA'] ?? 0);

        $readiness = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $readiness);
        $findings = array_column($readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId)['findings'], null, 'code');
        self::assertArrayHasKey('overtime_wage_from_attendance', $findings, CanonicalJson::encode(array_keys($findings)));
        $finding = $findings['overtime_wage_from_attendance'];
        self::assertNotSame('blocker', $finding['severity']);
        self::assertTrue($finding['hideable']);
        $expectedPath = '/payroll/components?tab=inputs&period=' . self::PERIOD . '&employment=' . $id;
        self::assertSame($expectedPath, $finding['entities'][0]['remediation_path']);
        self::assertStringContainsString('Syntetický Oznámený: schválení docházky založilo dosaženou mzdu za 6 h přesčasu 1 432 Kč', $finding['entities'][0]['message']);
        self::assertStringContainsString('ruční vstup zrušte, jinak se vyplatí dvakrát', $finding['entities'][0]['message']);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'fix4-wage-notice');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved'], 'Upozornění nesmí zastavit schválení.');
        self::assertSame(
            [['code' => 'overtime_wage_from_attendance', 'severity' => 'warning', 'requires_override' => 0, 'remediation_path' => $expectedPath]],
            $this->overtimeValidations((int) $run['calculated']->revision['id']),
        );
    }

    /**
     * Účetní dosaženou mzdu dál doplnila ručním vstupem (jako před změnou):
     * vedle automatického vstupu 1 432 Kč je ruční vstup téže složky. Běh
     * to nesmí tiše vyplatit dvakrát: varování s povinným potvrzením jmenuje
     * oba vstupy a schválení čeká.
     */
    public function testManualOvertimeWageNextToTheAutomaticOneNeedsConfirmation(): void
    {
        $person = $this->overtimeFromAttendance('Syntetická Zdvojená', '1985-07-07', 'female');
        $id = $person['employment_id'];
        $this->createApprovedInput($person, $this->componentId('MZDA_MESICNI'), 4_200_000, 'base-' . $id, self::PERIOD_START);
        $this->createApprovedInput($person, $this->componentId('MZDA_HODINOVA'), 143_200, 'rucne-' . $id, self::PERIOD_START);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'fix4-wage-duplicate');

        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertNull($run['approved'], 'Dvojí dosažená mzda se nesmí schválit bez potvrzení.');
        self::assertSame(['overtime_wage_manual_duplicate'], array_column($run['warnings'], 'code'), CanonicalJson::encode($run['warnings']));
        $message = $run['warnings'][0]['message'];
        self::assertStringContainsString('Syntetická Zdvojená: dosaženou mzdu za 6 h přesčasu 1 432 Kč založilo schválení docházky', $message);
        self::assertStringContainsString('1 432 Kč)', $message);
        self::assertStringContainsString('ruční vstup zrušte, jinak se vyplatí dvakrát', $message);
        self::assertSame(
            [['code' => 'overtime_wage_manual_duplicate', 'severity' => 'warning', 'requires_override' => 1, 'remediation_path' => '/payroll/components?tab=inputs&period=' . self::PERIOD . '&employment=' . $id]],
            $this->overtimeValidations((int) $run['calculated']->revision['id']),
        );
    }

    /**
     * V podmínkách vztahu chybí sjednaná měsíční mzda: schválení docházky
     * založí jen příplatek a dosaženou mzdu ne (nález vrací jen v odpovědi).
     * Běh proto nese varování s odkazem na podmínky vztahu; mzda za přesčas
     * se nesmí ztratit potichu.
     */
    public function testMissingAgreedWageIsReportedWithLinkToEmploymentTerms(): void
    {
        $person = $this->overtimeFromAttendance('Syntetický Nesjednaný', '1991-01-21', 'male', null);
        $id = $person['employment_id'];
        $this->createApprovedInput($person, $this->componentId('MZDA_MESICNI'), 4_200_000, 'base-' . $id, self::PERIOD_START);
        $inputs = $this->inputs($id);
        self::assertSame(37_500, $inputs['PRIPLATEK_PRESCAS'] ?? 0);
        self::assertArrayNotHasKey('MZDA_HODINOVA', $inputs, 'Bez sjednané mzdy se dosažená mzda neodhaduje.');

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'fix4-wage-basis');

        self::assertNull($run['approved']);
        self::assertSame(['overtime_achieved_wage_basis_missing'], array_column($run['warnings'], 'code'), CanonicalJson::encode($run['warnings']));
        self::assertStringContainsString('Syntetický Nesjednaný: docházka eviduje 6 h přesčasu', $run['warnings'][0]['message']);
        self::assertStringContainsString('chybí sjednaná měsíční mzda', $run['warnings'][0]['message']);
        self::assertSame(
            [[
                'code' => 'overtime_achieved_wage_basis_missing',
                'severity' => 'warning',
                'requires_override' => 1,
                'remediation_path' => "/payroll/people?person={$person['employee_id']}&employment={$id}&panel=employment_terms&field=monthly_gross_minor",
            ]],
            $this->overtimeValidations((int) $run['calculated']->revision['id']),
        );
    }

    /**
     * Docházka dubna s 6 h přesčasu (2 h v úterý 14. 4., 4 h v sobotu 18. 4.)
     * schválená u vztahu se sjednanou měsíční mzdou (nebo bez ní).
     *
     * @return array{employee_id:int,employment_id:int,name:string,average_id:int,sequence:int}
     */
    private function overtimeFromAttendance(string $name, string $birthDate, string $sex = 'male', ?int $agreedMonthlyMinor = 4_200_000): array
    {
        $person = $this->hire($name, $sex, $birthDate);
        $id = $person['employment_id'];
        if ($agreedMonthlyMinor !== null) {
            $this->agreeMonthlyWage($id, $agreedMonthlyMinor);
        } else {
            $this->db->pdo()->prepare(
                'UPDATE payroll_employment_terms SET remuneration_type = "monthly", monthly_gross_minor = NULL
                  WHERE supplier_id = ? AND employment_id = ?',
            )->execute([$this->supplierId, $id]);
            $this->db->pdo()->prepare(
                'UPDATE payroll_employments SET monthly_gross_minor = NULL WHERE supplier_id = ? AND id = ?',
            )->execute([$this->supplierId, $id]);
        }
        $cells = [];
        foreach (self::workdays(self::PERIOD) as $day) {
            $cells[] = $this->cell($id, 'regular', "{$day} 08:00", "{$day} 16:30", 30);
        }
        $cells[] = $this->cell($id, 'overtime', '2026-04-14 16:30', '2026-04-14 18:30', 0);
        $cells[] = $this->cell($id, 'overtime', '2026-04-18 08:00', '2026-04-18 12:00', 0);
        $approved = $this->approveCells($id, $cells);
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());

        return $person;
    }

    /** @return list<array{code:string,severity:string,requires_override:int,remediation_path:string}> */
    private function overtimeValidations(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT code, severity, requires_override, remediation_path
               FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ?
                AND code IN ("overtime_wage_from_attendance", "overtime_wage_manual_duplicate",
                             "overtime_achieved_wage_basis_missing", "overtime_achieved_wage_missing")
              ORDER BY code',
        );
        $statement->execute([$this->supplierId, $revisionId]);

        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'severity' => (string) $row['severity'],
            'requires_override' => (int) $row['requires_override'],
            'remediation_path' => (string) $row['remediation_path'],
        ], $statement->fetchAll(\PDO::FETCH_ASSOC));
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
                    // Verze řádků tak, jak je posílá prohlížeč (první zadání = null).
                    'versions' => [
                        'base' => $item['inputs']['base']['row_version'] ?? null,
                        'overtime' => $item['overtime_row_version'] ?? null,
                        'bonus' => $item['inputs']['bonus']['row_version'] ?? null,
                        'surcharges' => [],
                    ],
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
