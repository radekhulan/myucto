<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Absence\ProbableEarningBulkService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Průměr pro náhrady se změnil po výpočtu nebo schválení běhu (agentura s DPP).
 *
 * Brigádnice má za Q2/2026 schválený průměr 134,40 Kč/h a v dubnu náhradu
 * za 5 h dovolené 5 × 134,40 = 672 Kč. Po schválení běhu účetní schválí
 * pravděpodobný výdělek 180 Kč/h; náhrada z nového průměru je 5 × 180 = 900 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRunAverageChangeTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-15';
    private const WORKED = ['2026-04-04', '2026-04-11', '2026-04-18'];

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testApprovedRunWarnsAndCorrectionTakesNewAverage(): void
    {
        [$person, $averageId, $inputId] = $this->agencyWithCompensation('AVGA');
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $person['office_id'], 'avga');
        self::assertSame([], $run['blockers']);
        self::assertSame([], $run['warnings']);
        self::assertNotNull($run['approved']);
        self::assertNull($this->listedRun()['average_change']);

        $newAverage = $this->approveNewAverage($person['employment_id']);

        $listed = $this->listedRun();
        $change = $listed['average_change'];
        self::assertIsArray($change, 'Schválený běh nehlásí změněný průměr.');
        self::assertTrue($change['approved']);
        self::assertSame('request_correction', $change['action']);
        self::assertContains('request_correction', $listed['available_commands']);
        self::assertSame(1, $change['average_changed_count']);
        self::assertSame(1, $change['compensation_count']);
        self::assertSame(22_800, $change['compensation_difference_minor']);
        $employment = $change['employments'][0];
        self::assertSame($person['employment_id'], $employment['employment_id']);
        self::assertSame(13_440, $employment['frozen_average_hourly_minor']);
        self::assertSame(18_000, $employment['current_average_hourly_minor']);
        self::assertSame([
            'input_id' => $inputId,
            'component_name' => 'Syntetická NAHR_AVGA',
            'quantity_milliunits' => 5_000,
            'amount_minor' => 67_200,
            'average_hourly_minor' => 13_440,
            'expected_amount_minor' => 90_000,
        ], $employment['compensations'][0]);
        self::assertNotSame($averageId, $newAverage);

        $requested = $this->runs->requestCorrection(
            $this->supplierId,
            (int) $listed['id'],
            (int) $listed['row_version'],
            'avga-request',
            $this->actors[0],
            'Průměr pro náhrady se změnil po schválení',
        );
        self::assertSame('reopen', $this->listedRun()['average_change']['action']);
        $reopened = $this->runs->reopen(
            $this->supplierId,
            (int) $listed['id'],
            (int) $requested->run['row_version'],
            'avga-reopen',
            $this->actors[0],
            'Průměr pro náhrady se změnil po schválení',
        );
        self::assertSame('reopened', $reopened->run['status']);
        $frozen = $this->scalar(
            "SELECT JSON_VALUE(input_json, '$.average_earning.average_hourly_minor')
               FROM payroll_run_employments WHERE supplier_id = ? AND revision_id = ? AND employment_id = ?",
            [$this->supplierId, (int) $reopened->revision['id'], $person['employment_id']],
        );
        self::assertSame(18_000, (int) $frozen);

        $afterReopen = $this->listedRun()['average_change'];
        self::assertIsArray($afterReopen, 'Náhrada ze starého průměru po otevření opravy zmizela z upozornění.');
        self::assertFalse($afterReopen['approved']);
        self::assertNull($afterReopen['action']);
        self::assertSame(0, $afterReopen['average_changed_count']);
        self::assertSame(1, $afterReopen['compensation_count']);
        self::assertSame('reopened', $this->listedRun()['status']);
    }

    public function testCalculatedRunAsksForRefreshAndNothingIsApproved(): void
    {
        [$person] = $this->agencyWithCompensation('AVGB');
        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $person['office_id'], $this->actors[0]);
        $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'avgb-lock', $this->actors[0]);
        $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'avgb-calculate', $this->actors[0]);
        self::assertNull($this->listedRun()['average_change']);

        $this->approveNewAverage($person['employment_id']);

        $listed = $this->listedRun();
        self::assertSame('calculated', $listed['status']);
        self::assertIsArray($listed['average_change']);
        self::assertFalse($listed['average_change']['approved']);
        self::assertSame('refresh_inputs', $listed['average_change']['action']);
        self::assertSame(1, $listed['source_drift']['average_earnings_changed']);
    }

    public function testDraftRunAndUnchangedAverageStayQuiet(): void
    {
        [$person] = $this->agencyWithCompensation('AVGC');
        $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $person['office_id'], $this->actors[0]);
        $this->approveNewAverage($person['employment_id']);

        self::assertNull($this->listedRun()['average_change']);
    }

    /**
     * @return array{0:array<string,mixed>,1:int,2:int} osoba, id průměru 134,40, id vstupu náhrady
     */
    private function agencyWithCompensation(string $code): array
    {
        $officeId = $this->createOffice($code, 'Syntetická účtárna agentury', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $wage = $this->createComponent('ODMENA_' . $code, 'base_wage', 'regular');
        $compensation = $this->createComponent('NAHR_' . $code, 'base_wage', 'regular');
        $mapping = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        $mapping->put($this->supplierId, $wage, '10329', null, $this->actors[0]);
        $mapping->put($this->supplierId, $compensation, '10329', null, $this->actors[0]);

        $person = $this->createEmployment($officeId, 'Syntetická Brigádnice', 1, 'dpp', 'dpp', 10, 2_500, true, self::PERIOD_START);
        $birthDate = '1990-03-04';
        $this->completeJmhzEmployment($person, null, [
            'first_name' => 'Syntetická',
            'last_name' => 'Brigádnice',
            'birth_date' => $birthDate,
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber($birthDate, 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $this->publishShifts($person['employment_id'], self::WORKED);
        $time = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::WORKED, dailyMinutes: 240);
        self::assertSame(200, $time->getStatusCode(), (string) $time->getBody());
        $this->createApprovedInput($person, $wage, 216_000, 'avg-' . $code, self::PERIOD_START);

        $first = $this->container->get(ProbableEarningBulkService::class)->apply(
            $this->supplierId, 2026, 2, 13_440, 'Hodinová minimální mzda.', [$person['employment_id']], $this->actors[0], null, null,
        );
        self::assertSame(1, $first['counts']['applied'], json_encode($first, JSON_UNESCAPED_UNICODE));
        $averageId = (int) $first['applied'][0]['snapshot_id'];
        $inputId = $this->compensationInput($person, $compensation, $averageId, $code);
        $person['office_id'] = $officeId;

        return [$person, $averageId, $inputId];
    }

    /** @param array<string,mixed> $person */
    private function compensationInput(array $person, int $componentId, int $averageId, string $code): int
    {
        $component = $this->db->pdo()->prepare('SELECT code, name, component_kind FROM payroll_component_definitions WHERE supplier_id = ? AND id = ?');
        $component->execute([$this->supplierId, $componentId]);
        $row = $component->fetch(\PDO::FETCH_ASSOC);
        $componentJson = CanonicalJson::encode([
            'code' => $row['code'],
            'name' => $row['name'],
            'component_kind' => $row['component_kind'],
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);
        $trace = CanonicalJson::encode([
            'kind' => 'import_absence_compensation.v1',
            'period_start' => self::PERIOD_START,
            'meaning' => 'vacation_hours',
            'millihours' => 5_000,
            'minutes' => 300,
            'average_hourly_minor' => 13_440,
            'average_snapshot_id' => $averageId,
            'rate_percent' => 100,
            'amount_minor' => 67_200,
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start, amount_minor,
                 quantity_milliunits, source_kind, external_id, source_snapshot_json, source_snapshot_hash,
                 status, component_snapshot_json, component_snapshot_hash, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, 67200, 5000, "absence", ?, ?, ?, "approved", ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $person['employee_id'],
            $person['employment_id'],
            $componentId,
            self::PERIOD_START,
            'leave:attendance:' . self::PERIOD . ':' . $person['employment_id'] . ':vacation_hours:' . $code,
            $trace,
            hash('sha256', $trace, true),
            $componentJson,
            hash('sha256', $componentJson, true),
            $this->actors[0],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function approveNewAverage(int $employmentId): int
    {
        $snapshot = $this->container->get(AverageEarningBatchService::class)->approveProbableEarning(
            $this->supplierId,
            $employmentId,
            2026,
            2,
            18_000,
            'Sjednaná odměna 180 Kč za hodinu podle dohody.',
            $this->actors[0],
        );

        return (int) $snapshot['id'];
    }

    /** @return array<string,mixed> */
    private function listedRun(): array
    {
        $action = $this->container->get(PayrollRunsAction::class);
        $response = $action->list(
            $this->request('GET', '/api/payroll/runs')->withQueryParams(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $runs = $this->json($response)['runs'];
        self::assertCount(1, $runs);

        return $runs[0];
    }
}
