<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\PayrollHourlyEvidenceService;
use MyInvoice\Service\Payroll\PayrollHourlyWagePreview;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/** Podmínky převzetí hodinového odměňování (#279, bod 10) přes skutečné schválení běhu. */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollHourlyAcceptanceScenariosTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const HOUR = 3000;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->runs = $this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunCommandService::class);
    }

    protected function tearDown(): void { $this->tearDownPayrollFullFlow(); }

    /** @param list<array{0:string,1:string,2:int,3:int}> $segments od, do, hodiny v jednotkách, sazba */
    #[DataProvider('baseWageRows')]
    public function testBaseWageRows(array $segments, int $expected): void
    {
        [$office, $id] = $this->hourlyEmployment('2026-06-01', array_values(array_unique(array_column($segments, 3))));
        $this->approvedSummary($id, '2026-06-01', array_map(static fn(array $row): array => [$row[0], $row[1], $row[2], 0], $segments));
        $run = $this->approvedMonth('2026-06-01', $office, 'issue-base-' . $expected);
        self::assertSame($expected, $this->amount($id, '2026-06-01', 'MZDA_HODINOVA_ZAKLAD'));
        self::assertSame(0, $this->amount($id, '2026-06-01', 'DOPLATEK_MINIMALNI_MZDA'));
        self::assertSame($expected, $run->revision['result_snapshot']['totals']['source_amount_minor']);
    }

    public static function baseWageRows(): array
    {
        return [
            '160 h × 200 Kč = 32 000 Kč' => [[['2026-06-01', '2026-06-30', 160 * self::HOUR, 20000]], 3200000],
            '7:30 h × 200 Kč = 1 500 Kč' => [[['2026-06-01', '2026-06-30', 22500, 20000]], 150000],
            '80 h × 200 Kč a 80 h × 220 Kč = 33 600 Kč' => [[['2026-06-01', '2026-06-15', 80 * self::HOUR, 20000], ['2026-06-16', '2026-06-30', 80 * self::HOUR, 22000]], 3360000],
        ];
    }

    public function testOvertimeWithStatutoryPremiumFromAverage(): void
    {
        [$office, $id] = $this->hourlyEmployment('2026-06-01', [20000]);
        $this->average($id, 24000);
        // Přesčas po dnech, aby šly ověřit limity § 93 ZP.
        $this->approvedSummary($id, '2026-06-01', [['2026-06-01', '2026-06-29', 160 * self::HOUR, 0], ['2026-06-30', '2026-06-30', 8 * self::HOUR, 8 * self::HOUR]]);
        $run = $this->runPayrollMonth('2026-06-01', '2026-07-10', $office, 'issue-overtime');
        self::assertSame([], $run['blockers'], json_encode($run['blockers']));
        // Ruční souhrn s přesčasem vždy žádá posouzení limitů § 93 ZP účetní; výpočet tím není dotčen.
        self::assertSame(['overtime_manual_summary_review_required'], array_column($run['warnings'], 'code'));
        self::assertSame(3200000, $this->amount($id, '2026-06-01', 'MZDA_HODINOVA_ZAKLAD'));
        self::assertSame(160000, $this->amount($id, '2026-06-01', 'MZDA_DOSAZENA_PRESCAS'));
        self::assertSame(48000, $this->amount($id, '2026-06-01', 'PRIPLATEK_PRESCAS'));
        // Mzda za práci 33 600 Kč a příplatek 480 Kč, bez dalších plnění.
        self::assertSame(3408000, $run['calculated']->revision['result_snapshot']['totals']['source_amount_minor']);
    }

    public function testNonworkedHolidayOnScheduledDay(): void
    {
        [$office, $id] = $this->hourlyEmployment('2026-05-01', [20000]);
        $this->average($id, 24000);
        $this->publishShifts($id, ['2026-05-08']);
        $this->approvedSummary($id, '2026-05-01', [['2026-05-01', '2026-05-07', 32 * self::HOUR, 0], ['2026-05-08', '2026-05-08', 0, 0], ['2026-05-09', '2026-05-31', 120 * self::HOUR, 0]],
            [['date' => '2026-05-08', 'duration_units' => 8 * self::HOUR]]);
        $run = $this->approvedMonth('2026-05-01', $office, 'issue-holiday', '2026-06-10');
        self::assertSame(3040000, $this->amount($id, '2026-05-01', 'MZDA_HODINOVA_ZAKLAD'));
        self::assertSame(192000, $this->amount($id, '2026-05-01', 'NAHRADA_MZDY_SVATEK'));
        self::assertSame(3232000, $run->revision['result_snapshot']['totals']['source_amount_minor']);
    }

    public function testVacationFlowsThroughLeaveMaterializer(): void
    {
        [$office, $id] = $this->hourlyEmployment('2026-06-01', [20000]);
        $average = $this->average($id, 24000);
        $this->publishShifts($id, ['2026-06-10', '2026-06-11']);
        $this->createApprovedAbsence($id, 'vacation', '2026-06-10', '2026-06-11', $average);
        $this->approvedSummary($id, '2026-06-01', [['2026-06-01', '2026-06-30', 144 * self::HOUR, 0]]);
        $run = $this->approvedMonth('2026-06-01', $office, 'issue-vacation');
        self::assertSame(2880000, $this->amount($id, '2026-06-01', 'MZDA_HODINOVA_ZAKLAD'));
        self::assertSame(384000, $this->amount($id, '2026-06-01', 'NAHRADA_MZDY_DOVOLENA'));
        self::assertSame(3264000, $run->revision['result_snapshot']['totals']['source_amount_minor']);
    }

    public function testManualHourlyWageNextToCalculatedHoursIsNeverPaidTwice(): void
    {
        [$office, $id] = $this->hourlyEmployment('2026-06-01', [20000]);
        $this->approvedSummary($id, '2026-06-01', [['2026-06-01', '2026-06-30', 160 * self::HOUR, 0]]);
        // Ručně zadaná hodinová mzda za tytéž hodiny (např. převzatá z docházky mimo aplikaci).
        $this->container->get(\MyInvoice\Repository\Payroll\PayrollComponentRepository::class)->ensureDefaults($this->supplierId);
        $this->db->pdo()->prepare('UPDATE payroll_component_definitions SET minimum_wage_treatment = "included", minimum_wage_reason = "Syntetická hodinová mzda" WHERE supplier_id = ? AND code = "MZDA_HODINOVA"')->execute([$this->supplierId]);
        $this->createApprovedInput(['employee_id' => (int) $this->scalar('SELECT employee_id FROM payroll_employments WHERE id = ?', [$id]), 'employment_id' => $id],
            $this->componentId('MZDA_HODINOVA'), 3200000, 'manual:hourly-duplicate');
        $run = $this->runPayrollMonth('2026-06-01', '2026-07-10', $office, 'issue-duplicate');
        self::assertNull($run['approved']);
        self::assertSame(['hourly_wage_base_conflict'], array_column($run['blockers'], 'code'), json_encode($run['blockers']));
        self::assertSame(0, $this->amount($id, '2026-06-01', 'MZDA_HODINOVA_ZAKLAD'));
        self::assertSame(3200000, $run['calculated']->revision['result_snapshot']['totals']['source_amount_minor']);
    }

    public function testLaterRateChangeKeepsApprovedRunAndPayslip(): void
    {
        [$office, $id] = $this->hourlyEmployment('2026-06-01', [20000]);
        $this->approvedSummary($id, '2026-06-01', [['2026-06-01', '2026-06-30', 160 * self::HOUR, 0]]);
        $run = $this->approvedMonth('2026-06-01', $office, 'issue-rate-change');
        $revisionId = (int) $run->revision['id'];
        $frozen = $this->revision($revisionId);
        $document = $run->revision['result_snapshot']['people'][0]['payslip_document'];
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET hourly_rate_minor = 25000 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $id]);
        $rows = $this->container->get(PayrollRunSnapshotBuilder::class)->employmentRows($this->supplierId, '2026-06-01', '2026-06-30', $office);
        $preview = (new PayrollHourlyWagePreview($this->db))->forMany($this->supplierId, '2026-06-01', $rows)[$id];
        self::assertSame(4000000, array_column($preview['claims'], 'payable_minor', 'purpose')['ordinary_base']);
        self::assertSame($frozen, $this->revision($revisionId));
        $stored = json_decode((string) $frozen['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($document, $stored['people'][0]['payslip_document']);
        self::assertSame(3200000, $document['income_lines'][0]['amount_minor_units']);
    }

    /** @param list<int> $rates @return array{int,int} */
    private function hourlyEmployment(string $period, array $rates): array
    {
        $office = $this->createOffice();
        $this->configureSocialInsuranceOutput($office);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $person = $this->createEmployment($office, 'Syntetické Převzetí', 1, 'hpp', 'employment', 40, 10000, periodStart: $period);
        $id = $person['employment_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employment_terms SET remuneration_type = "hourly", hourly_rate_minor = ?, monthly_gross_minor = NULL, remuneration_basis_kind = "contract", remuneration_basis = "Syntetická smlouva"'
            . (count($rates) > 1 ? ', effective_to = "2026-06-15"' : '') . ' WHERE supplier_id = ? AND employment_id = ?')->execute([$rates[0], $this->supplierId, $id]);
        if (count($rates) > 1) {
            $pdo->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, office_id, effective_from, planned_start_on, actual_start_on,
                weekly_hours, workload_basis_points, social_insurance_participation, health_insurance_participation, tax_regime, tax_declaration_signed, is_primary,
                remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis)
                SELECT supplier_id, employment_id, office_id, "2026-06-16", planned_start_on, actual_start_on, weekly_hours, workload_basis_points,
                    social_insurance_participation, health_insurance_participation, tax_regime, tax_declaration_signed, is_primary, "hourly", ?, "contract", "Syntetický dodatek"
                FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?')->execute([$rates[1], $this->supplierId, $id]);
        }
        $this->ensureRegularCalendar($id);
        $this->container->get(\MyInvoice\Repository\AccountingModeRepository::class)->record($this->supplierId, '2026-01-01', 'double_entry');
        $pdo->prepare('INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")')->execute([$this->supplierId]);
        $this->container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        return [$office, $id];
    }

    private function average(int $id, int $hourly): int
    {
        $response = $this->absences->createAverage($this->request('POST', '/api/payroll/absences/average')->withParsedBody([
            'employment_id' => $id, 'applicable_year' => 2026, 'applicable_quarter' => 2,
            'decisive_from' => '2026-01-01', 'decisive_to' => '2026-03-31', 'gross_earnings_minor' => $hourly * 160,
            'longer_period_allocated_minor' => 0, 'worked_minutes' => 9600, 'worked_days' => 60,
            'probable_hourly_minor' => null, 'rationale' => null,
        ]), new Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $average = $this->json($response)['snapshot'];
        $approved = $this->absences->approveAverage($this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]), new Response(), ['id' => (string) $average['id']]);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        return (int) $average['id'];
    }

    /**
     * Schválený ruční souhrn a výslovně nulové příplatkové vrstvy.
     * @param list<array{0:string,1:string,2:int,3:int}> $segments od, do, odpracováno, z toho přesčas
     * @param list<array{date:string,duration_units:int}> $holidays
     */
    private function approvedSummary(int $id, string $period, array $segments, array $holidays = []): void
    {
        $time = $this->container->get(PayrollTimeRepository::class);
        $time->saveManualSummary($this->supplierId, $id, $period, 0, 'Synteticky doložená skutečná doba', false, array_map(static fn(array $row): array => [
            'date_from' => $row[0], 'date_to' => $row[1], 'worked_duration_units' => $row[2], 'overtime_duration_units' => $row[3], 'worked_days' => null,
        ], $segments), $this->actors[0]);
        $month = $time->monthState($this->supplierId, $id, $period);
        $time->approveMonth($this->supplierId, $id, $period, (int) $month['row_version'], null, $this->actors[0]);
        $evidence = $this->container->get(PayrollHourlyEvidenceService::class);
        $view = $evidence->view($this->supplierId, $id, $period);
        $evidence->save($this->supplierId, $id, $period, [
            'row_version' => $view['row_version'], 'work_source_hash' => $view['work_source_hash'],
            'premium_segments' => array_map(static fn(array $segment): array => [
                'date_from' => $segment['date_from'], 'date_to' => $segment['date_to'],
                'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0,
                'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => [],
            ], $view['segments_suggested']),
            'nonworked_holidays' => $holidays, 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => [],
        ], $this->actors[0]);
    }

    private function approvedMonth(string $period, int $office, string $key, string $payday = '2026-07-10'): \MyInvoice\Service\Payroll\Run\PayrollRunCommandResult
    {
        $run = $this->runPayrollMonth($period, $payday, $office, $key);
        self::assertSame([], $run['blockers'], json_encode($run['blockers']));
        self::assertSame([], $run['warnings'], json_encode($run['warnings']));
        self::assertNotNull($run['approved']);
        return $run['approved'];
    }

    private function amount(int $id, string $period, string $code): int
    {
        return (int) $this->scalar('SELECT COALESCE(SUM(input.amount_minor),0) FROM payroll_inputs input
            JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
            WHERE input.supplier_id = ? AND input.employment_id = ? AND input.period_start = ? AND input.status IN ("approved","locked") AND component.code = ?', [$this->supplierId, $id, $period, $code]);
    }

    /** @return array<string,mixed> */
    private function revision(int $id): array
    {
        $statement = $this->db->pdo()->prepare('SELECT input_snapshot_json, result_snapshot_json, HEX(input_snapshot_hash) AS input_hash, HEX(result_snapshot_hash) AS result_hash FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?');
        $statement->execute([$this->supplierId, $id]);
        return $statement->fetch(\PDO::FETCH_ASSOC);
    }
}
