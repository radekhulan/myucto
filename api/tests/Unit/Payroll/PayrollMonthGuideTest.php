<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Run\PayrollMonthGuide;
use PHPUnit\Framework\TestCase;

final class PayrollMonthGuideTest extends TestCase
{
    public function testReadinessFindingsAreSplitIntoInputsPeopleAndCheck(): void
    {
        $guide = PayrollMonthGuide::build(self::facts(readiness: ['findings' => [
            self::finding('time_month_not_approved', 'approval', 'monthly', 40),
            self::finding('draft_inputs_present', 'approval', 'monthly', 3),
            self::finding('person_data_gap', 'anytime', 'setup', 5),
            self::finding('payout_rule_missing', 'revision', 'person', 2),
            self::finding('statutory_calculation_manual_review', 'approval', 'monthly', 1, ['/payroll/quick-inputs?period=2026-10']),
            self::finding('statutory_calculation_manual_review', 'approval', 'monthly', 4, ['/payroll/people?person=1']),
            array_replace(self::finding('employer_policy_missing', 'blocking', 'setup', 1), ['bulk_actions' => [['kind' => 'health_insurer', 'count' => 7]]]),
        ]]));
        $steps = array_column($guide['steps'], null, 'key');

        self::assertSame('todo', $steps['inputs']['state']);
        self::assertSame(44, $steps['inputs']['remaining']);
        self::assertSame(44, $steps['inputs']['facts']['stopping']);
        self::assertSame('attendance_import', $steps['inputs']['action']);
        self::assertSame(11, $steps['people']['remaining']);
        self::assertSame(4, $steps['people']['facts']['stopping']);
        self::assertSame('people_gaps', $steps['people']['action']);
        self::assertSame(['blocking' => 1, 'approval' => 4, 'revision' => 1, 'anytime' => 1], $steps['check']['facts']['by_impact']);
        self::assertSame(5, $steps['check']['remaining']);
        self::assertSame([['employee_ids' => [], 'kind' => 'health_insurer', 'count' => 7]], $steps['check']['facts']['bulk_actions']);
        self::assertCount(3, $steps['check']['facts']['findings']);
        self::assertSame('waiting', $steps['run']['state']);
        self::assertSame('inputs', $guide['current_step']);
    }

    public function testWholeCrownInputsStopTheInputsStepAndOfferRounding(): void
    {
        $guide = PayrollMonthGuide::build(self::facts(readiness: ['findings' => [
            self::finding('inputs_not_whole_crowns', 'approval', 'monthly', 12),
        ]]));
        $steps = array_column($guide['steps'], null, 'key');

        self::assertSame('todo', $steps['inputs']['state']);
        self::assertSame(12, $steps['inputs']['remaining']);
        self::assertSame(12, $steps['inputs']['facts']['stopping']);
        self::assertSame('round_inputs', $steps['inputs']['action']);
        self::assertSame('inputs', $guide['current_step']);
    }

    public function testSummaryFindingsCountTheirPeople(): void
    {
        $summary = array_replace(
            self::finding('tax_declaration_not_signed_summary', 'revision', 'monthly', 1),
            ['subject_type' => 'employee', 'subject_ids' => [3, 5, 8, 13]],
        );
        $guide = PayrollMonthGuide::build(self::facts(readiness: ['findings' => [$summary]]));
        $steps = array_column($guide['steps'], null, 'key');

        self::assertSame([['code' => 'tax_declaration_not_signed_summary', 'count' => 4]], $steps['people']['facts']['codes']);
        self::assertSame(4, $steps['people']['remaining']);
    }

    public function testMonthBeforePayrollStartHasNoStepsAndPointsToTheFirstPeriod(): void
    {
        self::assertSame(
            ['period' => '2026-09', 'before_start' => '2026-10', 'current_step' => null, 'runs' => [], 'steps' => []],
            PayrollMonthGuide::beforeStart('2026-09', '2026-10'),
        );
        self::assertNull(PayrollMonthGuide::build(self::facts())['before_start']);
    }

    public function testLockedRunUsesRunValidationsInsteadOfReadiness(): void
    {
        $guide = PayrollMonthGuide::build(self::facts(
            locked: true,
            runs: [self::runRow('calculated', [
                ['code' => 'time_month_not_approved', 'severity' => 'blocker', 'remediation_path' => '/payroll/time'],
                ['code' => 'dpp_annual_hours_exceeded', 'severity' => 'warning', 'requires_override' => true, 'overridden_at' => null],
                ['code' => 'person_data_gap', 'severity' => 'warning', 'requires_override' => false],
            ])],
            checklist: ['summary' => ['total' => 1, 'done' => 0], 'hoz_open' => 0, 'unprepared_overdue' => 0, 'jmhz' => null],
        ));
        $steps = array_column($guide['steps'], null, 'key');

        self::assertSame(1, $steps['inputs']['remaining']);
        self::assertSame('runs', $steps['inputs']['action']);
        self::assertSame('done', $steps['people']['state']);
        self::assertSame(2, $steps['check']['remaining']);
        self::assertSame('run', $steps['check']['facts']['source']);
        self::assertSame('todo', $steps['run']['state']);
        self::assertSame(2, $steps['run']['remaining']);
        self::assertSame('waiting', $steps['submissions']['state']);
        self::assertSame('waiting', $steps['payments']['state']);
    }

    public function testApprovedRunOpensSubmissionsAndPayments(): void
    {
        $guide = PayrollMonthGuide::build(self::facts(
            locked: true,
            runs: [self::runRow('payment_ready', [], ['liability_count' => 5, 'uncovered_count' => 2])],
            registrationA1: ['total' => 3, 'preparable' => 2, 'incomplete' => 1, 'overdue' => 0, 'too_early' => 0],
            checklist: [
                'summary' => ['total' => 4, 'send' => 1, 'generate' => 1, 'manual' => 0, 'await' => 0, 'done' => 2],
                'hoz_open' => 1,
                'unprepared_overdue' => 0,
                'jmhz' => ['total' => 1, 'done' => 0],
            ],
            documents: 12,
        ));
        $steps = array_column($guide['steps'], null, 'key');

        self::assertSame('todo', $steps['registrations']['state']);
        self::assertSame(4, $steps['registrations']['remaining']);
        self::assertSame('done', $steps['check']['state']);
        self::assertSame('done', $steps['run']['state']);
        self::assertSame('todo', $steps['submissions']['state']);
        self::assertSame(2, $steps['submissions']['remaining']);
        self::assertSame('todo', $steps['payments']['state']);
        self::assertSame(2, $steps['payments']['remaining']);
        self::assertSame(12, $steps['payments']['facts']['documents_total']);
        self::assertSame('registrations', $guide['current_step']);
    }

    public function testClosedRunIsDoneAndMissingAccessIsUnavailable(): void
    {
        $guide = PayrollMonthGuide::build(self::facts(locked: true, runs: [self::runRow('closed')]));
        $steps = array_column($guide['steps'], null, 'key');

        self::assertSame('unavailable', $steps['registrations']['state']);
        self::assertSame('unavailable', $steps['submissions']['state']);
        self::assertSame('done', $steps['payments']['state']);
        self::assertNull($guide['current_step']);
    }

    /**
     * @param list<array<string,mixed>> $runs
     * @param array<string,mixed>|null $readiness
     * @param array<string,mixed>|null $registrationA1
     * @param array<string,mixed>|null $checklist
     * @return array<string,mixed>
     */
    private static function facts(
        bool $locked = false,
        array $runs = [],
        ?array $readiness = null,
        ?array $registrationA1 = null,
        ?array $checklist = null,
        ?int $documents = null,
    ): array {
        return [
            'period' => '2026-10',
            'locked' => $locked,
            'runs' => $runs,
            'readiness' => $readiness,
            'attendance_batches' => ['count' => 0, 'persons' => 0],
            'registration_a1' => $registrationA1,
            'checklist' => $checklist,
            'documents_total' => $documents,
        ];
    }

    /**
     * @param list<string> $paths
     * @return array<string,mixed>
     */
    private static function finding(string $code, string $impact, string $scope, int $count, array $paths = []): array
    {
        return [
            'code' => $code,
            'impact' => $impact,
            'scope' => $scope,
            'message' => $code,
            'count' => $count,
            'entity_total' => $count,
            'remediation_path' => null,
            'entities' => array_map(static fn (string $path): array => ['remediation_path' => $path], $paths),
            'bulk_actions' => [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $validations
     * @param array<string,mixed>|null $coverage
     * @return array<string,mixed>
     */
    private static function runRow(string $status, array $validations = [], ?array $coverage = null): array
    {
        return [
            'id' => 1,
            'status' => $status,
            'office_id' => null,
            'approve_blocker_count' => count(array_filter($validations, PayrollMonthGuide::validationStops(...))),
            'validations' => $validations,
            'payment_coverage' => $coverage,
        ];
    }
}
