<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\PayrollHourlyEntitlementLedger;
use MyInvoice\Service\Payroll\PayrollHourlyEvidenceService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/** Skutečné schválení dvou měsíců: sváteční práce zakládá volno, čerpání placenou náhradu. */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollHourlyHolidayFullFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->runs = $this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunCommandService::class);
    }
    protected function tearDown(): void { $this->tearDownPayrollFullFlow(); }

    public function testMonthlyToHourlyChangeMidMonthFlowsThroughApprovalAndPayslip(): void
    {
        $office = $this->createOffice();
        $this->configureSocialInsuranceOutput($office);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $person = $this->createEmployment($office, 'Syntetická Změna Odměňování', 1, 'hpp', 'employment', 40, 10000);
        $id = $person['employment_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employment_terms SET remuneration_type = "monthly", monthly_gross_minor = 4200000,
            remuneration_basis_kind = "contract", remuneration_basis = "Syntetická měsíční smlouva", effective_to = "2026-06-15"
            WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $id]);
        $monthlyTermId = (int) $this->scalar('SELECT id FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?', [$this->supplierId, $id]);
        $pdo->prepare('INSERT INTO payroll_employment_terms
            (supplier_id, employment_id, office_id, effective_from, planned_start_on, actual_start_on,
             weekly_hours, workload_basis_points, social_insurance_participation, health_insurance_participation,
             tax_regime, tax_declaration_signed, is_primary, remuneration_type, hourly_rate_minor,
             remuneration_basis_kind, remuneration_basis)
            SELECT supplier_id, employment_id, office_id, "2026-06-16", planned_start_on, actual_start_on,
                   weekly_hours, workload_basis_points, social_insurance_participation, health_insurance_participation,
                   tax_regime, tax_declaration_signed, is_primary, "hourly", 20000, "contract", "Syntetická hodinová smlouva"
            FROM payroll_employment_terms WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $monthlyTermId]);
        $hourlyTermId = (int) $pdo->lastInsertId();
        $this->ensureRegularCalendar($id);
        $this->average200($id);
        $this->container->get(\MyInvoice\Repository\AccountingModeRepository::class)->record($this->supplierId, '2026-01-01', 'double_entry');
        $pdo->prepare('INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")')->execute([$this->supplierId]);
        $this->container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $time = $this->container->get(PayrollTimeRepository::class);
        $time->saveManualSummary($this->supplierId, $id, '2026-06-01', 0, 'Synteticky doložená práce před změnou a po změně mzdy', false, [
            ['date_from' => '2026-06-01', 'date_to' => '2026-06-15', 'worked_duration_units' => 264000, 'overtime_duration_units' => 0, 'worked_days' => 11],
            ['date_from' => '2026-06-16', 'date_to' => '2026-06-30', 'worked_duration_units' => 264000, 'overtime_duration_units' => 0, 'worked_days' => 11],
        ], $this->actors[0]);
        $month = $time->monthState($this->supplierId, $id, '2026-06-01');
        $time->approveMonth($this->supplierId, $id, '2026-06-01', (int) $month['row_version'], null, $this->actors[0]);
        $evidence = $this->container->get(PayrollHourlyEvidenceService::class);
        $view = $evidence->view($this->supplierId, $id, '2026-06-01');
        $evidence->save($this->supplierId, $id, '2026-06-01', [
            'row_version' => $view['row_version'], 'reason' => 'Synteticky doložená práce bez příplatků', 'work_source_hash' => $view['work_source_hash'],
            'premium_segments' => array_map(static fn(array $segment): array => [
                'date_from' => $segment['date_from'], 'date_to' => $segment['date_to'],
                'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0,
                'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => [],
            ], $view['segments_suggested']),
            'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => [],
        ], $this->actors[0]);

        $run = $this->runPayrollMonth('2026-06-01', '2026-07-10', $office, 'mixed-monthly-hourly');
        self::assertSame([], $run['blockers'], json_encode($run['blockers']));
        self::assertSame([], $run['warnings'], json_encode($run['warnings']));
        self::assertNotNull($run['approved']);
        self::assertSame('approved', $run['approved']->run['status']);
        self::assertSame(3860000, $this->amount($id, '2026-06-01', 'MZDA_HODINOVA_ZAKLAD'));
        $result = $run['approved']->revision['result_snapshot'];
        self::assertSame(3860000, $result['totals']['source_amount_minor']);
        $document = $result['people'][0]['payslip_document'];
        self::assertSame('payroll-payslip-document.v3', $document['schema_version']);
        $ordinary = array_values(array_filter($document['hourly_claims'], static fn(array $claim): bool => $claim['purpose'] === 'ordinary_base'));
        self::assertCount(1, $ordinary);
        self::assertSame(3860000, $ordinary[0]['payable_minor']);
        self::assertSame('176 h', $ordinary[0]['quantity_text']);
        $parts = array_column($ordinary[0]['segments'], null, 'term_id');
        self::assertCount(2, $parts);
        self::assertSame(2100000, $parts[$monthlyTermId]['analytical_minor']);
        self::assertSame(4200000, $parts[$monthlyTermId]['monthly_gross_minor']);
        self::assertSame('88 h', $parts[$monthlyTermId]['quantity_text']);
        self::assertSame(1760000, $parts[$hourlyTermId]['analytical_minor']);
        self::assertSame(20000, $parts[$hourlyTermId]['rate_minor']);
        self::assertSame('88 h', $parts[$hourlyTermId]['quantity_text']);
    }

    public function testApprovedHolidayWorkThenPaidTimeOffKeepsOriginalRevisionImmutable(): void
    {
        $office = $this->createOffice();
        $this->configureSocialInsuranceOutput($office);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $person = $this->createEmployment($office, 'Syntetické Sváteční Volno', 1, 'hpp', 'employment', 40, 10000, periodStart: '2026-05-01');
        $id = $person['employment_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employment_terms SET remuneration_type = "hourly", hourly_rate_minor = 20000, monthly_gross_minor = NULL,
            remuneration_basis_kind = "contract", remuneration_basis = "Syntetická hodinová smlouva" WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $id]);
        $this->ensureRegularCalendar($id);
        $this->publishShifts($id, ['2026-05-01', '2026-06-10']);
        $this->container->get(\MyInvoice\Repository\AccountingModeRepository::class)->record($this->supplierId, '2026-01-01', 'double_entry');
        $pdo->prepare('INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")')->execute([$this->supplierId]);
        $this->container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $average = $this->average200($id);
        $this->manualMonth($id, '2026-05-01', 24000, '2026-05-01');
        $evidence = $this->container->get(PayrollHourlyEvidenceService::class);
        $this->saveEvidence($evidence, $id, '2026-05-01', 24000, [['date' => '2026-05-01', 'duration_units' => 24000]], []);
        $may = $this->runPayrollMonth('2026-05-01', '2026-06-10', $office, 'hourly-holiday-may');
        self::assertSame([], $may['blockers'], json_encode($may['blockers']));
        self::assertSame([], $may['warnings'], json_encode($may['warnings']));
        self::assertNotNull($may['approved']);
        $mayId = (int) $may['approved']->revision['id'];
        $mayFrozen = $this->revisionJson($mayId);
        $ledger = new PayrollHourlyEntitlementLedger($this->db);
        $entitlements = $ledger->loadMany($this->supplierId, [$id], '2026-01-01', '2026-06-30')[$id]['entitlements'];
        self::assertCount(1, $entitlements);
        self::assertSame('holiday', $entitlements[0]['kind']);
        self::assertSame('2026-05-01', $entitlements[0]['work_date']);
        self::assertSame(24000, $entitlements[0]['available_duration_units']);
        self::assertSame(0, $this->amount($id, '2026-05-01', 'PRIPLATEK_SVATEK'));
        self::assertSame(160000, $this->amount($id, '2026-05-01', 'MZDA_HODINOVA_ZAKLAD'));

        $statutory = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class);
        $editor = $statutory->editorView($this->supplierId, $person['employee_id'], '2026-06-30');
        $editor['sections']['health_month_evidence'][] = [
            'period_start' => '2026-06-01', 'top_up_responsibility' => 'employer_obstacle_verified',
            'top_up_responsibility_evidence_reference' => 'document:synthetic-obstacle',
            'selected_top_up_employer_reference' => null, 'selected_top_up_employer_evidence_reference' => null,
        ];
        $statutory->save($this->supplierId, $person['employee_id'], $editor, '2026-06-30', $this->actors[0], null, 'hourly-holiday-test');
        $pdo->prepare('INSERT INTO payroll_enforcement_person_month_evidence (supplier_id, employee_id, period_start,
            claim_register_evidence_complete, dependants_evidence_complete, spouse_evidence_complete, pension_evidence, updated_by)
            VALUES (?, ?, "2026-06-01", 1, 1, 1, "none", ?)')->execute([$this->supplierId, $person['employee_id'], $this->actors[0]]);
        $absence = $this->createApprovedAbsence($id, 'holiday_compensatory_time_off', '2026-06-10', '2026-06-10', $average);
        self::assertSame('hourly_claim', $absence['compensation_policy']);
        $this->manualMonth($id, '2026-06-01', 0);
        $view = $evidence->view($this->supplierId, $id, '2026-06-01');
        $option = $view['options']['holiday_time_off_entitlements'][0];
        self::assertSame(24000, $option['available_duration_units']);
        $this->saveEvidence($evidence, $id, '2026-06-01', 0, [], [[
            'entitlement_id' => $option['entitlement_id'], 'entitlement_revision' => $option['entitlement_revision'],
            'granted_on' => '2026-06-10', 'duration_units' => 24000, 'absence_id' => (int) $absence['id'],
        ]]);
        $june = $this->runPayrollMonth('2026-06-01', '2026-07-10', $office, 'hourly-holiday-june');
        self::assertSame([], $june['blockers'], json_encode($june['blockers']));
        self::assertSame([], $june['warnings'], json_encode($june['warnings']));
        self::assertNotNull($june['approved']);
        self::assertSame(160000, $this->amount($id, '2026-06-01', 'NAHRADA_MZDY_VOLNO_ZA_SVATEK'));
        self::assertSame(0, $this->amount($id, '2026-06-01', 'PRIPLATEK_SVATEK'));
        $component = $this->componentId('NAHRADA_MZDY_VOLNO_ZA_SVATEK');
        $mapping = $this->container->get(PayrollComponentJmhzMappingRepository::class)->find($this->supplierId, $component);
        self::assertSame('10339', (string) $mapping['target_attribute_id']);
        $final = $ledger->loadMany($this->supplierId, [$id], '2026-01-01', '2026-06-30')[$id]['entitlements'][0];
        self::assertSame(24000, $final['allocated_duration_units']);
        self::assertSame(0, $final['available_duration_units']);
        self::assertSame($mayFrozen, $this->revisionJson($mayId));
        $document = $june['approved']->revision['result_snapshot']['people'][0]['payslip_document'];
        self::assertSame('payroll-payslip-document.v3', $document['schema_version']);
        $claim = array_values(array_filter($document['hourly_claims'], static fn(array $row): bool => $row['purpose'] === 'holiday_time_off_compensation'))[0];
        self::assertSame(160000, $claim['payable_minor']);
        self::assertSame('8 h', $claim['quantity_text']);
    }

    public function testHourlyVacationFlowsToWagesAndNextQuarterAverage(): void
    {
        $office = $this->createOffice();
        $this->configureSocialInsuranceOutput($office);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $person = $this->createEmployment($office, 'Syntetická Dovolená', 1, 'hpp', 'employment', 40, 10000, periodStart: '2026-06-01');
        $id = $person['employment_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employment_terms SET remuneration_type = "hourly", hourly_rate_minor = 20000, monthly_gross_minor = NULL, remuneration_basis_kind = "contract", remuneration_basis = "Syntetická smlouva" WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $id]);
        $this->ensureRegularCalendar($id);
        $this->publishShifts($id, ['2026-06-10', '2026-06-11', '2026-06-12']);
        $this->container->get(\MyInvoice\Repository\AccountingModeRepository::class)->record($this->supplierId, '2026-01-01', 'double_entry');
        $pdo->prepare('INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")')->execute([$this->supplierId]);
        $this->container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $average = $this->average200($id, 20500);
        $this->createApprovedAbsence($id, 'vacation', '2026-06-10', '2026-06-12', $average, ['partial_last_minutes' => 120]);
        $time = $this->container->get(PayrollTimeRepository::class);
        $time->saveManualSummary($this->supplierId, $id, '2026-06-01', 0, 'Syntetické skutečně odpracované hodiny', false, [[
            'date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'worked_duration_units' => 450000,
            'overtime_duration_units' => 0, 'worked_days' => 20,
        ]], $this->actors[0]);
        $month = $time->monthState($this->supplierId, $id, '2026-06-01');
        $time->approveMonth($this->supplierId, $id, '2026-06-01', (int) $month['row_version'], null, $this->actors[0]);
        $this->saveEvidence($this->container->get(PayrollHourlyEvidenceService::class), $id, '2026-06-01', 0, [], []);
        $run = $this->runPayrollMonth('2026-06-01', '2026-07-10', $office, 'hourly-vacation');
        self::assertSame([], $run['blockers'], json_encode($run['blockers']));
        self::assertSame([], $run['warnings'], json_encode($run['warnings']));
        self::assertNotNull($run['approved']);
        self::assertSame(3000000, $this->amount($id, '2026-06-01', 'MZDA_HODINOVA_ZAKLAD'));
        self::assertSame(369000, $this->amount($id, '2026-06-01', 'NAHRADA_MZDY_DOVOLENA'));
        self::assertSame(3369000, $run['approved']->revision['result_snapshot']['totals']['source_amount_minor']);
        $suggestion = $this->container->get(\MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService::class)->suggest($this->supplierId, $id, 2026, 3);
        $june = $suggestion['months'][2];
        self::assertSame('2026-06-01', $june['period_start']);
        self::assertSame(3000000, $june['gross_earnings_minor']);
        self::assertSame(450000, $june['worked_duration_units']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hourlyAgreementCases')]
    public function testHourlyAgreementsUseActualIncomeForInsurance(string $type, array $relations, int $expectedGross, bool $insured, bool $weekend): void
    {
        $office = $this->createOffice();
        $this->configureSocialInsuranceOutput($office);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $pdo = $this->db->pdo();
        $this->container->get(\MyInvoice\Repository\AccountingModeRepository::class)->record($this->supplierId, '2026-01-01', 'double_entry');
        $pdo->prepare('INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")')->execute([$this->supplierId]);
        $this->container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $primary = null;
        $expected = [];
        foreach ($relations as $index => $relation) {
            $person = $this->createEmployment($office, 'Syntetická Dohoda', $index + 1, $type, $type, 20, 5000,
                periodStart: '2026-06-01', existingEmployeeId: $primary);
            $primary ??= $person['employee_id'];
            $id = $person['employment_id'];
            $expected[$id] = $relation['base'] + ($weekend ? 18400 : 0);
            $pdo->prepare('UPDATE payroll_employment_terms SET remuneration_type = "hourly", hourly_rate_minor = ?, monthly_gross_minor = NULL, remuneration_basis_kind = "agreement", remuneration_basis = "Syntetická dohoda" WHERE supplier_id = ? AND employment_id = ?')->execute([$relation['rate'], $this->supplierId, $id]);
            $evidence = $this->container->get(PayrollHourlyEvidenceService::class);
            if (!$weekend) {
                $this->manualMonth($id, '2026-06-01', $relation['units']);
                $this->saveEvidence($evidence, $id, '2026-06-01', 0, [], []);
            } else {
                // Doložených 52 hodin mimo víkend a 8 hodin v sobotu 6. června.
                $this->average200($id, 23000);
                $time = $this->container->get(PayrollTimeRepository::class);
                $time->saveManualSummary($this->supplierId, $id, '2026-06-01', 0, 'Doložené hodiny dohody', false, [
                    ['date_from' => '2026-06-01', 'date_to' => '2026-06-05', 'worked_duration_units' => 156000, 'overtime_duration_units' => 0, 'worked_days' => 5],
                    ['date_from' => '2026-06-06', 'date_to' => '2026-06-06', 'worked_duration_units' => 24000, 'overtime_duration_units' => 0, 'worked_days' => 1],
                    ['date_from' => '2026-06-07', 'date_to' => '2026-06-30', 'worked_duration_units' => 0, 'overtime_duration_units' => 0, 'worked_days' => 0],
                ], $this->actors[0]);
                $month = $time->monthState($this->supplierId, $id, '2026-06-01');
                $time->approveMonth($this->supplierId, $id, '2026-06-01', (int) $month['row_version'], null, $this->actors[0]);
                $view = $evidence->view($this->supplierId, $id, '2026-06-01');
                $evidence->save($this->supplierId, $id, '2026-06-01', [
                    'row_version' => $view['row_version'], 'reason' => 'Synteticky doložená sobotní práce', 'work_source_hash' => $view['work_source_hash'],
                    'premium_segments' => [
                        ['date_from' => '2026-06-01', 'date_to' => '2026-06-05', 'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0, 'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => []],
                        ['date_from' => '2026-06-06', 'date_to' => '2026-06-06', 'night_duration_units' => 0, 'weekend_duration_units' => 24000, 'holiday_work_duration_units' => 0, 'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => []],
                        ['date_from' => '2026-06-07', 'date_to' => '2026-06-30', 'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0, 'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => []],
                    ], 'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => [],
                ], $this->actors[0]);
            }
        }
        $run = $this->runPayrollMonth('2026-06-01', '2026-07-10', $office, 'hourly-agreement');
        self::assertSame([], $run['blockers'], json_encode($run['blockers']));
        self::assertSame([], $run['warnings'], json_encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $result = $run['approved']->revision['result_snapshot'];
        self::assertSame($expectedGross, $result['totals']['source_amount_minor']);
        $social = $result['statutory']['people'][0]['social_insurance']['relationships'];
        self::assertCount(count($relations), $social);
        foreach ($social as $row) {
            $id = (int) substr((string) $row['relationship_id'], strlen('employment:'));
            self::assertArrayHasKey($id, $expected);
            self::assertSame($expected[$id], $row['assessment_base_minor_units']);
            self::assertSame($insured ? 'participates' : 'does_not_participate', $row['participation']['status']);
            self::assertSame($insured ? $expected[$id] : 0, $row['capped_assessment_base_minor_units']);
            if ($type === 'dpp') self::assertSame($expectedGross, $row['participation']['group_income_minor_units']);
            if ($weekend) {
                self::assertSame(1320000, $this->amount($id, '2026-06-01', 'MZDA_HODINOVA_ZAKLAD'));
                self::assertSame(18400, $this->amount($id, '2026-06-01', 'PRIPLATEK_VIKEND'));
            }
        }
    }

    public static function hourlyAgreementCases(): array
    {
        return [
            'E1 DPP 250 × 40 h pod limitem' => ['dpp', [['rate' => 25000, 'units' => 120000, 'base' => 1000000]], 1000000, false, false],
            'E2 DPP 250 × 52 h nad limitem' => ['dpp', [['rate' => 25000, 'units' => 156000, 'base' => 1300000]], 1300000, true, false],
            'E3 dvě DPP 6000 + 6600 Kč' => ['dpp', [['rate' => 20000, 'units' => 90000, 'base' => 600000], ['rate' => 30000, 'units' => 66000, 'base' => 660000]], 1260000, true, false],
            'E4 DPČ 220 × 60 h a sobotní příplatek' => ['dpc', [['rate' => 22000, 'units' => 180000, 'base' => 1320000]], 1338400, true, true],
        ];
    }

    private function average200(int $id, int $hourly = 20000): int
    {
        $response = $this->absences->createAverage($this->request('POST', '/api/payroll/absences/average')->withParsedBody([
            'employment_id' => $id, 'applicable_year' => 2026, 'applicable_quarter' => 2,
            'decisive_from' => '2026-01-01', 'decisive_to' => '2026-03-31', 'gross_earnings_minor' => $hourly * 160,
            'longer_period_allocated_minor' => 0, 'worked_minutes' => 9600, 'worked_days' => 60,
            'probable_hourly_minor' => null, 'rationale' => null,
        ]), new Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $average = $this->json($response)['snapshot'];
        self::assertSame($hourly, $average['average_hourly_minor']);
        $approved = $this->absences->approveAverage($this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]), new Response(), ['id' => (string) $average['id']]);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        return (int) $average['id'];
    }

    private function manualMonth(int $id, string $period, int $units, ?string $day = null): void
    {
        $time = $this->container->get(PayrollTimeRepository::class);
        $month = $time->monthState($this->supplierId, $id, $period);
        $last = date('Y-m-t', strtotime($period));
        $segments = [['date_from' => $day ?? $period, 'date_to' => $day ?? $last, 'worked_duration_units' => $units, 'overtime_duration_units' => 0, 'worked_days' => $units === 0 ? 0 : ($day !== null ? 1 : null)]];
        // Úseky musí pokrýt celý měsíc; ostatní dny jsou doložená nula.
        if ($day !== null && $day > $period) {
            array_unshift($segments, ['date_from' => $period, 'date_to' => date('Y-m-d', strtotime($day . ' -1 day')), 'worked_duration_units' => 0, 'overtime_duration_units' => 0, 'worked_days' => 0]);
        }
        if ($day !== null && $day < $last) {
            $segments[] = ['date_from' => date('Y-m-d', strtotime($day . ' +1 day')), 'date_to' => $last, 'worked_duration_units' => 0, 'overtime_duration_units' => 0, 'worked_days' => 0];
        }
        $time->saveManualSummary($this->supplierId, $id, $period, (int) ($month['row_version'] ?? 0), 'Synteticky doložená skutečná doba', false, $segments, $this->actors[0]);
        $month = $time->monthState($this->supplierId, $id, $period);
        $time->approveMonth($this->supplierId, $id, $period, (int) $month['row_version'], null, $this->actors[0]);
    }

    private function saveEvidence(PayrollHourlyEvidenceService $service, int $id, string $period, int $holidayUnits, array $days, array $grants): void
    {
        $view = $service->view($this->supplierId, $id, $period);
        $layers = [];
        foreach ($view['segments_suggested'] as $index => $segment) {
            $own = array_values(array_filter($days, static fn (array $day): bool => $day['date'] >= $segment['date_from'] && $day['date'] <= $segment['date_to']));
            $layers[] = ['date_from' => $segment['date_from'], 'date_to' => $segment['date_to'],
                'night_duration_units' => 0, 'weekend_duration_units' => 0,
                'holiday_work_duration_units' => $own !== [] || ($days === [] && $index === 0) ? $holidayUnits : 0,
                'difficult_exposures' => [], 'holiday_work_days' => $own, 'overtime_work_days' => []];
        }
        $service->save($this->supplierId, $id, $period, ['row_version' => $view['row_version'], 'reason' => 'Syntetické doložení svátku a volna', 'work_source_hash' => $view['work_source_hash'],
            'premium_segments' => $layers,
            'nonworked_holidays' => [], 'holiday_time_off_grants' => $grants, 'overtime_time_off_allocations' => []], $this->actors[0]);
    }

    private function amount(int $id, string $period, string $code): int
    {
        return (int) $this->scalar('SELECT COALESCE(SUM(input.amount_minor),0) FROM payroll_inputs input
            JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
            WHERE input.supplier_id = ? AND input.employment_id = ? AND input.period_start = ? AND input.status IN ("approved","locked") AND component.code = ?', [$this->supplierId, $id, $period, $code]);
    }

    private function revisionJson(int $id): array
    {
        $statement = $this->db->pdo()->prepare('SELECT input_snapshot_json, result_snapshot_json, HEX(input_snapshot_hash) AS input_hash, HEX(result_snapshot_hash) AS result_hash FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?');
        $statement->execute([$this->supplierId, $id]);
        return $statement->fetch(\PDO::FETCH_ASSOC);
    }
}
