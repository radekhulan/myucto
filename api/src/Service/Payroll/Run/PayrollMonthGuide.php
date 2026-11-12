<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final class PayrollMonthGuide
{
    public const STATE_DONE = 'done';
    public const STATE_TODO = 'todo';
    public const STATE_WAITING = 'waiting';
    public const STATE_UNAVAILABLE = 'unavailable';

    private const INPUT_CODES = [
        'time_month_not_approved',
        'time_month_missing',
        'draft_inputs_present',
        'employment_without_inputs',
        'hourly_claim_component_conflict',
        'recurring_component_without_input',
        'absence_pending_decision',
        'inputs_on_unstarted_employment',
    ];

    private const PEOPLE_CODES = [
        'person_data_gap',
        'payout_rule_missing',
        'tax_declaration_evidence_missing',
        'tax_declaration_not_signed_summary',
        'tax_residence_missing_unsigned_summary',
        'health_coverage_missing_summary',
        'social_jurisdiction_missing_summary',
        'annual_accumulator_missing_summary',
        'employments_past_agreed_end',
    ];

    private const STOPPING_IMPACTS = [
        PayrollRunReadinessImpact::IMPACT_BLOCKING,
        PayrollRunReadinessImpact::IMPACT_APPROVAL,
    ];

    private const TOP_FINDINGS = 3;

    private const APPROVED_STATUSES = ['approved', 'posted', 'payment_ready', 'paid', 'closed'];
    private const SETTLED_STATUSES = ['paid', 'closed'];

    /**
     * @param array{
     *   period:string,
     *   locked:bool,
     *   runs:list<array{id:int,status:string,office_id:?int,approve_blocker_count:int,validations:list<array<string,mixed>>,payment_coverage:?array<string,mixed>}>,
     *   readiness:?array<string,mixed>,
     *   attendance_batches:?array{count:int,persons:int},
     *   registration_a1:?array<string,mixed>,
     *   checklist:?array<string,mixed>,
     *   documents_total:?int
     * } $facts
     * @return array<string,mixed>
     */
    public static function build(array $facts): array
    {
        $runs = $facts['runs'];
        $readiness = $facts['readiness'];
        $findings = is_array($readiness['findings'] ?? null) ? $readiness['findings'] : [];
        $locked = $facts['locked'];
        $approved = $runs !== [] && self::allIn($runs, self::APPROVED_STATUSES);

        $steps = [
            self::sourceStep('inputs', $findings, $runs, $locked, $facts['attendance_batches']),
            self::sourceStep('people', $findings, $runs, $locked, null),
            self::registrationStep($facts['registration_a1'], $facts['checklist']),
            self::checkStep($findings, $runs, $readiness, $locked),
            self::runStep($runs, $findings),
            self::submissionStep($facts['checklist'], $approved),
            self::paymentStep($runs, $approved, $facts['documents_total']),
        ];
        $current = null;
        foreach ($steps as $step) {
            if ($step['state'] === self::STATE_TODO) {
                $current = $step['key'];
                break;
            }
        }

        return [
            'period' => $facts['period'],
            'current_step' => $current,
            'runs' => array_map(static fn (array $run): array => [
                'id' => $run['id'],
                'status' => $run['status'],
                'office_id' => $run['office_id'],
                'approve_blocker_count' => $run['approve_blocker_count'],
            ], $runs),
            'steps' => $steps,
        ];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param list<array<string,mixed>> $runs
     * @param array{count:int,persons:int}|null $batches
     * @return array<string,mixed>
     */
    private static function sourceStep(
        string $key,
        array $findings,
        array $runs,
        bool $locked,
        ?array $batches,
    ): array {
        $codes = [];
        $remaining = 0;
        $stopping = 0;
        if ($locked) {
            foreach ($runs as $run) {
                foreach ($run['validations'] as $validation) {
                    if (!self::validationStops($validation)
                        || self::category((string) $validation['code'], [$validation['remediation_path'] ?? null], null) !== $key) {
                        continue;
                    }
                    $code = (string) $validation['code'];
                    $codes[$code] = ($codes[$code] ?? 0) + 1;
                    ++$remaining;
                    ++$stopping;
                }
            }
        } else {
            foreach ($findings as $finding) {
                if (self::findingCategory($finding) !== $key) {
                    continue;
                }
                $count = self::findingCount($finding);
                $code = (string) $finding['code'];
                $codes[$code] = ($codes[$code] ?? 0) + $count;
                $remaining += $count;
                if (in_array($finding['impact'] ?? null, self::STOPPING_IMPACTS, true)) {
                    $stopping += $count;
                }
            }
        }
        $facts = [
            'codes' => array_map(
                static fn (string $code, int $count): array => ['code' => $code, 'count' => $count],
                array_keys($codes),
                array_values($codes),
            ),
            'stopping' => $stopping,
        ];
        if ($key === 'inputs') {
            $facts['attendance_batches'] = $batches;
        }

        return [
            'key' => $key,
            'state' => $remaining === 0 ? self::STATE_DONE : self::STATE_TODO,
            'remaining' => $remaining,
            'action' => self::sourceAction($key, $codes, $locked, $batches),
            'facts' => $facts,
        ];
    }

    /** @param array<string,int> $codes */
    private static function sourceAction(string $key, array $codes, bool $locked, ?array $batches): string
    {
        if ($key === 'people') {
            return $codes === [] ? 'people' : 'people_gaps';
        }
        if ($locked && $codes !== []) {
            return 'runs';
        }
        if (isset($codes['time_month_not_approved']) || isset($codes['time_month_missing'])) {
            return $batches !== null && $batches['count'] === 0 ? 'attendance_import' : 'time';
        }
        if (isset($codes['absence_pending_decision'])) {
            return 'absences';
        }

        return 'quick_inputs';
    }

    /**
     * @param array<string,mixed>|null $a1
     * @param array<string,mixed>|null $checklist
     * @return array<string,mixed>
     */
    private static function registrationStep(?array $a1, ?array $checklist): array
    {
        if ($a1 === null && $checklist === null) {
            return self::unavailable('registrations');
        }
        $a1Total = (int) ($a1['total'] ?? 0);
        $hoz = (int) ($checklist['hoz_open'] ?? 0);
        $remaining = $a1Total + $hoz;

        return [
            'key' => 'registrations',
            'state' => $remaining === 0 ? self::STATE_DONE : self::STATE_TODO,
            'remaining' => $remaining,
            'action' => $a1Total > 0 || $hoz === 0 ? 'registration_a1' : 'health',
            'facts' => [
                'registration_a1' => $a1,
                'hoz_open' => $checklist === null ? null : $hoz,
                'unprepared_overdue' => $checklist === null ? null : (int) ($checklist['unprepared_overdue'] ?? 0),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param list<array<string,mixed>> $runs
     * @param array<string,mixed>|null $readiness
     * @return array<string,mixed>
     */
    private static function checkStep(array $findings, array $runs, ?array $readiness, bool $locked): array
    {
        if ($locked) {
            $blockers = array_sum(array_column($runs, 'approve_blocker_count'));

            return [
                'key' => 'check',
                'state' => $blockers === 0 ? self::STATE_DONE : self::STATE_TODO,
                'remaining' => $blockers,
                'action' => 'runs',
                'facts' => ['source' => 'run', 'by_impact' => null, 'bulk_actions' => [], 'findings' => []],
            ];
        }
        $byImpact = array_fill_keys([
            PayrollRunReadinessImpact::IMPACT_BLOCKING,
            PayrollRunReadinessImpact::IMPACT_APPROVAL,
            PayrollRunReadinessImpact::IMPACT_REVISION,
            PayrollRunReadinessImpact::IMPACT_ANYTIME,
        ], 0);
        $bulk = [];
        $stoppingFindings = [];
        foreach ($findings as $finding) {
            $impact = (string) ($finding['impact'] ?? '');
            if (isset($byImpact[$impact])) {
                ++$byImpact[$impact];
            }
            if (in_array($impact, self::STOPPING_IMPACTS, true) && count($stoppingFindings) < self::TOP_FINDINGS) {
                $stoppingFindings[] = [
                    'code' => (string) ($finding['code'] ?? ''),
                    'impact' => $impact,
                    'message' => (string) ($finding['message'] ?? ''),
                    'count' => self::findingCount($finding),
                ];
            }
            foreach (is_array($finding['bulk_actions'] ?? null) ? $finding['bulk_actions'] : [] as $action) {
                $kind = (string) ($action['kind'] ?? '');
                if ($kind !== '') {
                    $bulk[$kind] = ($bulk[$kind] ?? 0) + (int) ($action['count'] ?? 0);
                }
            }
        }
        $remaining = $byImpact[PayrollRunReadinessImpact::IMPACT_BLOCKING]
            + $byImpact[PayrollRunReadinessImpact::IMPACT_APPROVAL];

        return [
            'key' => 'check',
            'state' => $readiness === null
                ? self::STATE_WAITING
                : ($remaining === 0 ? self::STATE_DONE : self::STATE_TODO),
            'remaining' => $remaining,
            'action' => 'runs',
            'facts' => [
                'source' => $readiness === null ? null : 'readiness',
                'by_impact' => $byImpact,
                'bulk_actions' => array_map(
                    static fn (string $kind, int $count): array => ['kind' => $kind, 'count' => $count],
                    array_keys($bulk),
                    array_values($bulk),
                ),
                'findings' => $stoppingFindings,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $runs
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private static function runStep(array $runs, array $findings): array
    {
        if ($runs === []) {
            $blocking = 0;
            foreach ($findings as $finding) {
                if (($finding['impact'] ?? null) === PayrollRunReadinessImpact::IMPACT_BLOCKING) {
                    ++$blocking;
                }
            }

            return [
                'key' => 'run',
                'state' => $blocking > 0 ? self::STATE_WAITING : self::STATE_TODO,
                'remaining' => $blocking,
                'action' => 'create_run',
                'facts' => ['status' => null],
            ];
        }
        $done = self::allIn($runs, self::APPROVED_STATUSES);
        $blockers = array_sum(array_column($runs, 'approve_blocker_count'));

        return [
            'key' => 'run',
            'state' => $done ? self::STATE_DONE : self::STATE_TODO,
            'remaining' => $done ? 0 : $blockers,
            'action' => 'runs',
            'facts' => ['status' => self::leastAdvancedStatus($runs)],
        ];
    }

    /**
     * @param array<string,mixed>|null $checklist
     * @return array<string,mixed>
     */
    private static function submissionStep(?array $checklist, bool $approved): array
    {
        if ($checklist === null) {
            return self::unavailable('submissions');
        }
        $summary = is_array($checklist['summary'] ?? null) ? $checklist['summary'] : [];
        $total = (int) ($summary['total'] ?? 0);
        $done = (int) ($summary['done'] ?? 0);
        $remaining = max(0, $total - $done);
        $state = match (true) {
            !$approved => self::STATE_WAITING,
            $remaining === 0 => self::STATE_DONE,
            default => self::STATE_TODO,
        };

        return [
            'key' => 'submissions',
            'state' => $state,
            'remaining' => $remaining,
            'action' => 'monthly_checklist',
            'facts' => [
                'summary' => $summary,
                'jmhz' => $checklist['jmhz'] ?? null,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $runs
     * @return array<string,mixed>
     */
    private static function paymentStep(array $runs, bool $approved, ?int $documents): array
    {
        if (!$approved) {
            return [
                'key' => 'payments',
                'state' => self::STATE_WAITING,
                'remaining' => 0,
                'action' => 'payments',
                'facts' => ['coverage' => null, 'documents_total' => $documents],
            ];
        }
        $uncovered = 0;
        $liabilities = 0;
        $coverageKnown = false;
        foreach ($runs as $run) {
            $coverage = $run['payment_coverage'];
            if (!is_array($coverage)) {
                continue;
            }
            $coverageKnown = true;
            $uncovered += (int) ($coverage['uncovered_count'] ?? 0);
            $liabilities += (int) ($coverage['liability_count'] ?? 0);
        }
        $settled = self::allIn($runs, self::SETTLED_STATUSES);

        return [
            'key' => 'payments',
            'state' => $settled ? self::STATE_DONE : self::STATE_TODO,
            'remaining' => $settled ? 0 : $uncovered,
            'action' => 'payments',
            'facts' => [
                'coverage' => $coverageKnown
                    ? ['liability_count' => $liabilities, 'uncovered_count' => $uncovered]
                    : null,
                'documents_total' => $documents,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function unavailable(string $key): array
    {
        return [
            'key' => $key,
            'state' => self::STATE_UNAVAILABLE,
            'remaining' => 0,
            'action' => null,
            'facts' => null,
        ];
    }

    /** @param array<string,mixed> $finding */
    private static function findingCategory(array $finding): ?string
    {
        $entities = is_array($finding['entities'] ?? null) ? $finding['entities'] : [];
        $paths = $entities === []
            ? [$finding['remediation_path'] ?? null]
            : array_map(static fn (array $entity): mixed => $entity['remediation_path'] ?? null, $entities);

        return self::category((string) ($finding['code'] ?? ''), $paths, $finding['scope'] ?? null);
    }

    /**
     * @param list<mixed> $paths
     */
    private static function category(string $code, array $paths, mixed $scope): ?string
    {
        if (in_array($code, self::INPUT_CODES, true)) {
            return 'inputs';
        }
        if ($code === 'statutory_calculation_manual_review') {
            foreach ($paths as $path) {
                if (!is_string($path) || !str_starts_with($path, '/payroll/quick-inputs')) {
                    return 'people';
                }
            }

            return $paths === [] ? 'people' : 'inputs';
        }
        if (in_array($code, self::PEOPLE_CODES, true) || $scope === PayrollRunReadinessImpact::SCOPE_PERSON) {
            return 'people';
        }

        return null;
    }

    /** @param array<string,mixed> $finding */
    private static function findingCount(array $finding): int
    {
        return max((int) ($finding['count'] ?? 0), (int) ($finding['entity_total'] ?? 0));
    }

    /** @param array<string,mixed> $validation */
    public static function validationStops(array $validation): bool
    {
        return ($validation['severity'] ?? null) === 'blocker'
            || (($validation['requires_override'] ?? false) && ($validation['overridden_at'] ?? null) === null);
    }

    /**
     * @param list<array<string,mixed>> $runs
     * @param list<string> $statuses
     */
    private static function allIn(array $runs, array $statuses): bool
    {
        foreach ($runs as $run) {
            if (!in_array($run['status'], $statuses, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $runs */
    private static function leastAdvancedStatus(array $runs): string
    {
        $order = array_flip([
            'cancelled', 'draft', 'reopened', 'correction_pending', 'inputs_locked', 'calculated',
            'reviewed', 'approved', 'posted', 'payment_ready', 'paid', 'closed',
        ]);
        $least = (string) $runs[0]['status'];
        foreach ($runs as $run) {
            if (($order[$run['status']] ?? 0) < ($order[$least] ?? 0)) {
                $least = (string) $run['status'];
            }
        }

        return $least;
    }
}
