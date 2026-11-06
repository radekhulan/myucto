<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Run\PayrollRunIssueGuidance;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessImpact;
use MyInvoice\Service\Payroll\Time\PayrollAgreementWeeklyAverage;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 76 odst. 2 zákoníku práce: dohoda o pracovní činnosti nejvýš v průměru
 * polovinu stanovené týdenní pracovní doby (20 h), posuzováno za dobu dohody,
 * nejdéle 52 týdnů. Na rozdíl od DPP 300 h se to nehlídalo vůbec.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollAgreementWeeklyAverageTest extends TestCase
{
    use PayrollFullFlowTrait;

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('DPC', 'Syntetická účtárna DPČ', '9990004323');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testApprovedManualFractionCountsWithoutJmhzAndExceedsWeeklyLimitExactly(): void
    {
        $agreement = $this->agreement(91, '2026-11-24', '2026-11-01');
        $this->manualMonth($agreement['employment_id'], 60003);
        $assessment = $this->service()->assess($this->supplierId, [$agreement['employment_id'] => '2026-11-24'], '2026-11-01')[$agreement['employment_id']];
        self::assertSame(7, $assessment['days']);
        self::assertSame(60003, $assessment['worked_duration_units']);
        self::assertNull($assessment['worked_minutes']);
        $validations = $this->service()->validations($this->supplierId, [$this->row($agreement, '2026-11-24')], '2026-11-01');
        self::assertCount(1, $validations);
        self::assertSame('dpc_weekly_average_exceeded', $validations[0]->code);
        self::assertStringContainsString('20,001 h', $validations[0]->message);
    }

    public function testManualMonthDoesNotDuplicateTakenOverHoursBeforeModuleStart(): void
    {
        $this->moveModuleStart('2026-12-01');
        $agreement = $this->agreement(94, '2026-11-24', '2026-11-01');
        $this->manualMonth($agreement['employment_id'], 60003);
        $this->takeover($agreement, 11, 60);
        $assessment = $this->service()->assess($this->supplierId, [$agreement['employment_id'] => '2026-11-24'], '2026-11-01')[$agreement['employment_id']];
        self::assertSame(60, $assessment['worked_minutes']);
        self::assertSame(60, $assessment['takeover_minutes']);
        self::assertArrayNotHasKey('worked_duration_units', $assessment);
    }

    public function testUnknownManualMonthProducesReviewInsteadOfFalseZeroAverage(): void
    {
        $agreement = $this->agreement(92, '2026-11-24', '2026-11-01');
        $this->manualMonth($agreement['employment_id'], 60003);
        $this->db->pdo()->prepare("UPDATE payroll_time_months SET status = 'open', revision_no = 2, approved_at = NULL, approved_by = NULL WHERE supplier_id = ? AND employment_id = ? AND period_start = '2026-11-01'")->execute([$this->supplierId, $agreement['employment_id']]);
        $assessment = $this->service()->assess($this->supplierId, [$agreement['employment_id'] => '2026-11-24'], '2026-11-01')[$agreement['employment_id']];
        self::assertSame('manual_review_required', $assessment['evidence_status']);
        self::assertNull($assessment['worked_duration_units']);
        self::assertNull($assessment['worked_minutes']);
        self::assertNull($assessment['average_weekly_minutes']);
        $validations = $this->service()->validations($this->supplierId, [$this->row($agreement, '2026-11-24')], '2026-11-01');
        self::assertSame('dpc_weekly_average_manual_review_required', $validations[0]->code);
        self::assertSame('warning', PayrollRunReadinessImpact::describe($validations[0]->code)['severity']);
    }

    public function testSelectedManualZeroDoesNotAddArchivedJmhzHours(): void
    {
        $agreement = $this->agreement(93, '2026-11-01', '2026-11-01');
        $response = $this->approveTimeMonth($agreement['employment_id'], '2026-11', self::workdays('2026-11'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->manualMonth($agreement['employment_id'], 0);
        $assessment = $this->service()->assess($this->supplierId, [$agreement['employment_id'] => '2026-11-01'], '2026-11-01')[$agreement['employment_id']];
        self::assertSame(0, $assessment['worked_duration_units']);
        self::assertSame(0, $assessment['worked_minutes']);
        self::assertSame([], $this->service()->validations($this->supplierId, [$this->row($agreement, '2026-11-01')], '2026-11-01'));
    }

    public function testTakenOverHoursAboveHalfOfTheWeekAreReported(): void
    {
        $this->moveModuleStart('2026-10-01');
        $agreement = $this->agreement(41, '2026-01-01');
        // Leden až září po 100 h = 900 h za 304 dnů (1. 1. až 31. 10.) ≈ 20,7 h týdně.
        for ($month = 1; $month <= 9; ++$month) {
            $this->takeover($agreement, $month, 100 * 60);
        }

        $validations = $this->service()->validations($this->supplierId, [$this->row($agreement, '2026-01-01')], '2026-10-01');

        self::assertCount(1, $validations);
        self::assertSame('dpc_weekly_average_exceeded', $validations[0]->code);
        self::assertStringContainsString('Dohodář Syntetický', $validations[0]->message);
        self::assertStringContainsString('900 h', $validations[0]->message);
        self::assertStringContainsString('převzatých', $validations[0]->message);
        self::assertStringContainsString('§ 76 odst. 2', $validations[0]->message);
        self::assertSame(
            "/payroll/people?person={$agreement['employee_id']}&employment={$agreement['employment_id']}&panel=employment_terms",
            $validations[0]->remediationPath,
        );
        self::assertSame('warning', PayrollRunReadinessImpact::describe('dpc_weekly_average_exceeded')['severity']);
        self::assertStringContainsString(
            'employment_terms',
            PayrollRunIssueGuidance::describe('dpc_weekly_average_exceeded', $agreement['employee_id'], $agreement['employment_id'])['remediation_path'],
        );
    }

    public function testAverageWithinHalfOfTheWeekProducesNoWarning(): void
    {
        $this->moveModuleStart('2026-10-01');
        $agreement = $this->agreement(42, '2026-01-01');
        for ($month = 1; $month <= 9; ++$month) {
            $this->takeover($agreement, $month, 80 * 60);
        }

        self::assertSame([], $this->service()->validations(
            $this->supplierId,
            [$this->row($agreement, '2026-01-01')],
            '2026-10-01',
        ));
    }

    /**
     * Posuzuje se nejdéle 52 týdnů: přetížené měsíce před oknem posledních
     * dvanácti měsíců se nezapočítají.
     */
    public function testHoursOlderThanFiftyTwoWeeksDoNotCount(): void
    {
        $this->moveModuleStart('2026-11-01');
        $agreement = $this->agreement(43, '2025-01-01');
        for ($month = 1; $month <= 10; ++$month) {
            $this->takeover($agreement, $month, 200 * 60, 2025);
        }
        $this->takeover($agreement, 11, 60 * 60, 2025);

        $assessment = $this->service()->assess(
            $this->supplierId,
            [$agreement['employment_id'] => '2025-01-01'],
            '2026-10-01',
        )[$agreement['employment_id']];

        self::assertSame('2025-11-01', $assessment['window_from']);
        self::assertSame('2026-10-31', $assessment['window_to']);
        self::assertSame(60 * 60, $assessment['worked_minutes']);
    }

    /**
     * Celý tok: DPČ nastoupila 1. 7., v červenci odpracovala celý měsíc na
     * plný úvazek. Kontrola červencového běhu hlásí varování s proklikem na
     * kartu vztahu, výpočet mzdy pokračuje.
     */
    public function testJulyRunOfFullTimeAgreementWarns(): void
    {
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $componentId = $this->createComponent('ODMENA_DPC', 'base_wage', 'regular');
        $agreement = $this->agreement(44, '2026-07-01', '2026-07-01');
        $response = $this->approveTimeMonth($agreement['employment_id'], '2026-07', self::workdays('2026-07'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->createApprovedInput($agreement, $componentId, 2_000_000, 'dpc-july', '2026-07-01');

        $run = $this->runPayrollMonth('2026-07-01', '2026-08-15', $this->officeId, 'dpc-limit');

        $statement = $this->db->pdo()->prepare(
            'SELECT severity, message, remediation_path FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ? AND code = "dpc_weekly_average_exceeded"',
        );
        $statement->execute([$this->supplierId, (int) $run['calculated']->revision['id']]);
        $found = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $found);
        self::assertSame('warning', $found[0]['severity']);
        self::assertStringContainsString('1. 7. 2026', $found[0]['message']);
        self::assertStringContainsString("employment={$agreement['employment_id']}", (string) $found[0]['remediation_path']);
    }

    private function manualMonth(int $employmentId, int $units): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, status, revision_no, work_source, approved_at, approved_by) SELECT ?, ?, '2026-11-01', 'approved', 1, 'manual_summary', NOW(), MIN(id) FROM users ON DUPLICATE KEY UPDATE work_source = 'manual_summary'")->execute([$this->supplierId, $employmentId]);
        $statement = $pdo->prepare("SELECT id, revision_no FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ? AND period_start = '2026-11-01'");
        $statement->execute([$this->supplierId, $employmentId]);
        $month = $statement->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO payroll_time_month_manual_summaries (supplier_id, time_month_id, time_month_revision_no, employment_id, period_start, content_sha256, reason) VALUES (?, ?, ?, ?, '2026-11-01', REPEAT('a', 64), 'Syntetický ruční výkaz')")->execute([$this->supplierId, $month['id'], $month['revision_no'], $employmentId]);
        $summary = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payroll_time_month_manual_segments (supplier_id, summary_id, sequence_no, date_from, date_to, worked_duration_units) VALUES (?, ?, 1, '2026-11-24', '2026-11-30', ?)")->execute([$this->supplierId, $summary, $units]);
    }

    private function service(): PayrollAgreementWeeklyAverage
    {
        return new PayrollAgreementWeeklyAverage($this->db);
    }

    private function moveModuleStart(string $start): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_module_state SET start_period = ? WHERE supplier_id = ?')
            ->execute([$start, $this->supplierId]);
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function agreement(int $sequence, string $start, string $periodStart = '2026-06-01'): array
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Dohodář Syntetický',
            $sequence,
            'dpc',
            'dpc',
            10,
            2_500,
            periodStart: $periodStart,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?',
        )->execute([$start, $start, $this->supplierId, $person['employment_id']]);

        return $person;
    }

    /**
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array<string,mixed>
     */
    private function row(array $person, string $start): array
    {
        return [
            'employment_id' => $person['employment_id'],
            'employee_id' => $person['employee_id'],
            'relation_type' => 'dpc',
            'full_name' => $person['name'],
            'start_date' => $start,
            'actual_start_date' => $start,
        ];
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function takeover(array $person, int $month, int $minutes, int $year = 2026): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref, external_relationship_ref,
                 employee_id, employment_id, gross_minor, worked_minutes)
             VALUES (?, "other", ?, ?, ?, ?, ?, 400000, ?)',
        )->execute([
            $this->supplierId,
            sprintf('%04d-%02d-01', $year, $month),
            'employee:' . $person['employee_id'],
            'employment:' . $person['employment_id'],
            $person['employee_id'],
            $person['employment_id'],
            $minutes,
        ]);
    }
}
