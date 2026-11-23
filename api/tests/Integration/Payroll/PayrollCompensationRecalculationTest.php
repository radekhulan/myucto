<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollCompensationRecalculationAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Absence\PayrollCompensationRecalculationBlockedException;
use MyInvoice\Service\Payroll\Absence\PayrollCompensationRecalculationService;
use MyInvoice\Service\Payroll\Absence\ProbableEarningBulkService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * „Přepočítat náhrady z nového průměru" (agentura s DPP).
 *
 * Brigádnice má za Q1/2026 schválený průměr 134,40 Kč/h, v lednu 5 h dovolené
 * zadané s daty (5 × 134,40 = 672 Kč) a 5 h náhrady z importu hodin (672 Kč).
 * Účetní schválí pravděpodobný výdělek 180 Kč/h; z nového průměru je náhrada
 * 5 × 180 = 900 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollCompensationRecalculationTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-01';
    private const PERIOD_START = '2026-01-01';
    private const PAYDAY = '2026-02-13';
    private const WORKED = ['2026-01-07', '2026-01-08', '2026-01-09'];
    private const VACATION = '2026-01-14';

    private PayrollCompensationRecalculationService $service;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $service = $this->container->get(PayrollCompensationRecalculationService::class);
        self::assertInstanceOf(PayrollCompensationRecalculationService::class, $service);
        $this->service = $service;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testOpenRunRecalculatesLeaveAndImportFromNewAverageOnce(): void
    {
        $setup = $this->agency('NHA');
        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $setup['office_id'], $this->actors[0]);
        $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'nha-lock', $this->actors[0]);
        $calculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'nha-calc', $this->actors[0]);
        self::assertSame('calculated', $calculated->run['status']);
        self::assertSame('locked', $this->inputStatus($setup['leave_input_id']));

        self::assertSame([], $this->service->preview($this->supplierId, [self::PERIOD])['periods'][0]['items']);
        $newAverage = $this->approveNewAverage($setup['employment_id']);

        $preview = $this->service->preview($this->supplierId, [self::PERIOD])['periods'][0];
        self::assertSame('ready', $preview['availability']);
        self::assertSame(['recalculate' => 2, 'manual' => 0, 'blocked' => 0], $preview['counts']);
        self::assertSame(45_600, $preview['difference_minor']);
        $byKind = array_column($preview['items'], null, 'kind');
        self::assertSame('recalculate', $byKind['leave']['action']);
        self::assertSame('replace', $byKind['import']['action']);
        foreach ($byKind as $item) {
            self::assertSame(67_200, $item['amount_minor']);
            self::assertSame(90_000, $item['new_amount_minor']);
            self::assertSame(13_440, $item['old_average_hourly_minor']);
            self::assertSame(18_000, $item['new_average_hourly_minor']);
            self::assertSame((int) $run['id'], $item['run_id']);
        }

        $applied = $this->apply();
        self::assertCount(2, $applied['recalculated']);
        self::assertSame([], $applied['manual']);
        self::assertSame('inputs_locked', $applied['refreshed_runs'][0]['status']);
        foreach ($applied['recalculated'] as $row) {
            self::assertSame(90_000, $row['new_amount_minor']);
            self::assertSame('approved', $row['new_status']);
            self::assertSame('cancelled', $this->inputStatus($row['input_id']));
            self::assertSame('locked', $this->inputStatus($row['new_input_id']));
            $trace = json_decode((string) $this->scalar(
                'SELECT source_snapshot_json FROM payroll_inputs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $row['new_input_id']],
            ), true);
            self::assertSame($newAverage, $trace['average_snapshot_id']);
            self::assertSame(18_000, $trace['average_hourly_minor']);
            self::assertSame($row['input_id'], $trace['recalculated_from_input_id']);
        }
        self::assertSame(180_000, $this->compensationTotal($setup['employment_id']));
        self::assertSame(
            "leave:{$setup['absence_id']}:" . self::PERIOD_START . ':original',
            $this->scalar(
                'SELECT external_id FROM payroll_inputs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, array_column($applied['recalculated'], 'new_input_id', 'input_id')[$setup['leave_input_id']]],
            ),
        );
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM payroll_run_events WHERE supplier_id = ? AND run_id = ? AND event_type = 'compensation_recalculated'",
            [$this->supplierId, (int) $run['id']],
        ));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = 'payroll.absence_compensation.recalculated'",
            [$this->supplierId],
        ));
        $snapshotIds = json_decode((string) $this->scalar(
            "SELECT JSON_EXTRACT(revision.input_snapshot_json, '$.people[*].employments[*].inputs[*].id')
               FROM payroll_run_revisions revision
               JOIN payroll_runs run ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.id = ?",
            [$this->supplierId, (int) $run['id']],
        ), true);
        foreach ($applied['recalculated'] as $row) {
            self::assertContains($row['new_input_id'], $snapshotIds);
            self::assertNotContains($row['input_id'], $snapshotIds);
        }

        $again = $this->apply('nha-second');
        self::assertSame([], $again['recalculated']);
        self::assertSame([], $again['refreshed_runs']);
        self::assertSame([], $this->service->preview($this->supplierId, [self::PERIOD])['periods'][0]['items']);
        self::assertSame(180_000, $this->compensationTotal($setup['employment_id']));
    }

    public function testApprovedRunRefusesAndOpenCorrectionRecalculates(): void
    {
        $setup = $this->agency('NHB');
        $result = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $setup['office_id'], 'nhb');
        self::assertSame([], $result['blockers'], CanonicalJson::encode($result['blockers']));
        self::assertSame([], $result['warnings'], CanonicalJson::encode($result['warnings']));
        $approved = $result['approved'];
        self::assertNotNull($approved);
        $approvedRevisionId = (int) $approved->revision['id'];
        $this->approveNewAverage($setup['employment_id']);

        $preview = $this->service->preview($this->supplierId, [self::PERIOD])['periods'][0];
        self::assertSame('request_correction', $preview['availability']);
        self::assertSame(2, $preview['counts']['blocked']);
        self::assertStringContainsString('Vyžádat opravu', (string) $preview['reason']);
        self::assertSame(90_000, $preview['items'][0]['new_amount_minor']);
        try {
            $this->apply('nhb-refused');
            self::fail('Schválený běh přepočet náhrad nepustil odmítnout.');
        } catch (PayrollCompensationRecalculationBlockedException $e) {
            self::assertSame('request_correction', $e->nextStep);
            self::assertSame((int) $approved->run['id'], $e->runId);
        }
        $action = $this->container->get(PayrollCompensationRecalculationAction::class);
        $http = $action->apply(
            $this->request('POST', '/api/payroll/time/compensation-recalculation')
                ->withHeader('Idempotency-Key', 'nhb-http-refused')
                ->withParsedBody(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(409, $http->getStatusCode(), (string) $http->getBody());
        $error = $this->json($http);
        self::assertSame('request_correction', $error['error']['next_step'] ?? null, (string) $http->getBody());
        $listed = $action->preview(
            $this->request('GET', '/api/payroll/time/compensation-recalculation')->withQueryParams(['periods' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $listed->getStatusCode(), (string) $listed->getBody());
        self::assertSame('request_correction', $this->json($listed)['periods'][0]['availability']);
        self::assertSame('locked', $this->inputStatus($setup['leave_input_id']));
        self::assertSame(134_400, $this->compensationTotal($setup['employment_id']));

        $requested = $this->runs->requestCorrection(
            $this->supplierId,
            (int) $approved->run['id'],
            (int) $approved->run['row_version'],
            'nhb-request',
            $this->actors[0],
            'Průměr pro náhrady se změnil po schválení',
        );
        self::assertSame('reopen', $this->service->preview($this->supplierId, [self::PERIOD])['periods'][0]['availability']);
        $reopened = $this->runs->reopen(
            $this->supplierId,
            (int) $approved->run['id'],
            (int) $requested->run['row_version'],
            'nhb-reopen',
            $this->actors[0],
            'Průměr pro náhrady se změnil po schválení',
        );
        self::assertSame('reopened', $reopened->run['status']);

        $applied = $this->apply('nhb-correction');
        self::assertCount(2, $applied['recalculated']);
        self::assertSame('reopened', $applied['refreshed_runs'][0]['status']);
        self::assertSame(180_000, $this->compensationTotal($setup['employment_id']));
        $frozen = json_decode((string) $this->scalar(
            "SELECT JSON_EXTRACT(input_snapshot_json, '$.people[*].employments[*].inputs[*].id')
               FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?",
            [$this->supplierId, $approvedRevisionId],
        ), true);
        self::assertContains($setup['leave_input_id'], $frozen, 'Schválená revize se změnila.');
        self::assertSame('approved', $this->scalar(
            'SELECT status FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $approvedRevisionId],
        ));
    }

    public function testWithoutApprovalRightCreatesDraftsAndObstacleStaysManual(): void
    {
        $setup = $this->agency('NHC');
        $obstacle = $this->obstacleInput($setup);
        $this->approveNewAverage($setup['employment_id']);

        $preview = $this->service->preview($this->supplierId, [self::PERIOD], [$setup['employment_id']])['periods'][0];
        self::assertSame(['recalculate' => 2, 'manual' => 1, 'blocked' => 0], $preview['counts']);
        $manual = array_values(array_filter($preview['items'], static fn (array $item): bool => $item['action'] === 'manual'))[0];
        self::assertSame($obstacle, $manual['input_id']);
        self::assertNull($manual['new_amount_minor']);

        $applied = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            [$setup['employment_id']],
            null,
            $this->actors[0],
            false,
            false,
            'nhc-apply',
        );
        self::assertCount(2, $applied['recalculated']);
        self::assertSame([], $applied['refreshed_runs']);
        self::assertSame([$obstacle], array_column($applied['manual'], 'input_id'));
        foreach ($applied['recalculated'] as $row) {
            self::assertSame('draft', $row['new_status']);
            self::assertSame('draft', $this->inputStatus($row['new_input_id']));
        }
        self::assertSame('approved', $this->inputStatus($obstacle));
        self::assertSame(
            [$obstacle],
            array_column($this->service->preview($this->supplierId, [self::PERIOD])['periods'][0]['items'], 'input_id'),
        );
    }

    /** @return array<string,mixed> */
    private function apply(string $key = 'nh-apply'): array
    {
        return $this->service->apply($this->supplierId, self::PERIOD, null, null, $this->actors[0], true, true, $key);
    }

    /**
     * @return array{employee_id:int,employment_id:int,office_id:int,absence_id:int,leave_input_id:int,import_input_id:int}
     */
    private function agency(string $code): array
    {
        $officeId = $this->createOffice($code, 'Syntetická účtárna agentury', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $wage = $this->createComponent('ODMENA_' . $code, 'base_wage', 'regular');
        $mapping = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        $mapping->put($this->supplierId, $wage, '10329', null, $this->actors[0]);

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
        $this->publishVacationShift($person['employment_id']);
        $time = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::WORKED, dailyMinutes: 240);
        self::assertSame(200, $time->getStatusCode(), (string) $time->getBody());
        $this->createApprovedInput($person, $wage, 216_000, 'nh-' . $code, self::PERIOD_START);

        $first = $this->container->get(ProbableEarningBulkService::class)->apply(
            $this->supplierId, 2026, 1, 13_440, 'Hodinová minimální mzda.', [$person['employment_id']], $this->actors[0], null, null,
        );
        self::assertSame(1, $first['counts']['applied'], json_encode($first, JSON_UNESCAPED_UNICODE));
        $averageId = (int) $first['applied'][0]['snapshot_id'];

        $absence = $this->createApprovedAbsence($person['employment_id'], 'vacation', self::VACATION, self::VACATION, $averageId);
        $leaveInputId = (int) $this->scalar(
            'SELECT id FROM payroll_inputs WHERE supplier_id = ? AND external_id = ? AND status = "approved"',
            [$this->supplierId, "leave:{$absence['id']}:" . self::PERIOD_START . ':original'],
        );
        self::assertGreaterThan(0, $leaveInputId, 'Dovolená s daty nezaložila vstup náhrady.');
        self::assertSame(67_200, (int) $this->scalar(
            'SELECT amount_minor FROM payroll_inputs WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $leaveInputId],
        ));
        $importInputId = $this->importInput($person, $averageId);

        return [
            'employee_id' => $person['employee_id'],
            'employment_id' => $person['employment_id'],
            'office_id' => $officeId,
            'absence_id' => (int) $absence['id'],
            'leave_input_id' => $leaveInputId,
            'import_input_id' => $importInputId,
        ];
    }

    private function publishVacationShift(int $employmentId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc, timezone_name,
                 break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", 0, "published", ?, NOW())',
        )->execute([
            $this->supplierId,
            $employmentId,
            "nahr-{$employmentId}-" . self::VACATION,
            self::VACATION . ' 07:00:00',
            self::VACATION . ' 12:00:00',
            $this->actors[0],
        ]);
    }

    /** @param array<string,mixed> $person */
    private function importInput(array $person, int $averageId): int
    {
        return $this->traceInput($person, 'NAHRADA_MZDY_DOVOLENA', 'leave:attendance:' . self::PERIOD . ':' . $person['employment_id'] . ':vacation_hours', [
            'kind' => 'import_absence_compensation.v1',
            'period_start' => self::PERIOD_START,
            'meaning' => 'vacation_hours',
            'millihours' => 5_000,
            'minutes' => 300,
            'average_hourly_minor' => 13_440,
            'average_snapshot_id' => $averageId,
            'rate_percent' => 100,
            'amount_minor' => 67_200,
        ], 67_200);
    }

    /** @param array<string,mixed> $setup */
    private function obstacleInput(array $setup): int
    {
        $averageId = (int) $this->scalar(
            'SELECT average_snapshot_id FROM payroll_absences WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $setup['absence_id']],
        );

        return $this->traceInput($setup, 'NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL', 'leave:obstacle:' . self::PERIOD . ':' . $setup['employment_id'] . ':test', [
            'kind' => 'obstacle_compensation.v1',
            'period_start' => self::PERIOD_START,
            'minutes' => 120,
            'average_hourly_minor' => 13_440,
            'average_snapshot_id' => $averageId,
            'amount_minor' => 16_200,
        ], 16_200);
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $trace
     */
    private function traceInput(array $person, string $code, string $externalId, array $trace, int $amount): int
    {
        $components = $this->container->get(PayrollComponentRepository::class);
        $components->ensureDefaults($this->supplierId);
        $inputs = $this->container->get(PayrollInputRepository::class);
        $snapshot = CanonicalJson::encode($trace);
        $created = $inputs->create($this->supplierId, [
            'employee_id' => $person['employee_id'],
            'employment_id' => $person['employment_id'],
            'component_id' => $this->componentId($code),
            'period_start' => self::PERIOD_START,
            'source_period_start' => null,
            'amount_minor' => $amount,
            'quantity_milliunits' => intdiv($trace['minutes'] * 1000, 60),
            'source_kind' => 'absence',
            'external_id' => $externalId,
            'source_snapshot_json' => $snapshot,
            'source_snapshot_hash' => hash('sha256', $snapshot, true),
        ], $this->actors[0]);
        $approved = $inputs->approve($this->supplierId, (int) $created['id'], (int) $created['row_version'], $this->actors[0]);
        self::assertSame('approved', $approved['status'] ?? null);

        return (int) $created['id'];
    }

    private function approveNewAverage(int $employmentId): int
    {
        $snapshot = $this->container->get(AverageEarningBatchService::class)->approveProbableEarning(
            $this->supplierId,
            $employmentId,
            2026,
            1,
            18_000,
            'Sjednaná odměna 180 Kč za hodinu podle dohody.',
            $this->actors[0],
        );

        return (int) $snapshot['id'];
    }

    private function inputStatus(int $inputId): string
    {
        return (string) $this->scalar(
            'SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $inputId],
        );
    }

    private function compensationTotal(int $employmentId): int
    {
        return (int) $this->scalar(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = 'absence' AND status IN ('approved', 'locked')",
            [$this->supplierId, $employmentId, self::PERIOD_START],
        );
    }
}
