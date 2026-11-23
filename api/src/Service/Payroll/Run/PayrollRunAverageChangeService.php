<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\LeaveCompensationCalculator;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\PayrollClosedRunGuard;
use PDO;

final class PayrollRunAverageChangeService
{
    public const WATCHED_STATUSES = [
        'inputs_locked', 'calculated', 'reviewed', 'reopened', 'correction_pending',
        ...PayrollClosedRunGuard::FINISHED_STATUSES,
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<array<string,mixed>> $runs řádky běhů (id, status, period_start)
     * @return array<int,array<string,mixed>|null> klíč = id běhu; `null` = beze změny
     */
    public function forRuns(int $supplierId, array $runs): array
    {
        $watched = [];
        foreach ($runs as $run) {
            $id = (int) ($run['id'] ?? 0);
            if ($id > 0 && in_array((string) ($run['status'] ?? ''), self::WATCHED_STATUSES, true)) {
                $watched[$id] = $run;
            }
        }
        $result = array_fill_keys(array_map(static fn (array $run): int => (int) ($run['id'] ?? 0), $runs), null);
        if ($watched === []) {
            return $result;
        }

        $employments = $this->employments($supplierId, array_keys($watched));
        if ($employments === []) {
            return $result;
        }
        $compensations = $this->compensations($supplierId, $employments);
        $rounding = null;

        $byRun = [];
        foreach ($employments as $row) {
            $runId = (int) $row['run_id'];
            $employmentId = (int) $row['employment_id'];
            $currentId = $row['current_average_id'] === null ? null : (int) $row['current_average_id'];
            $currentMinor = $row['current_average_hourly_minor'] === null ? null : (int) $row['current_average_hourly_minor'];
            $frozenId = $row['frozen_average_id'] === null ? null : (int) $row['frozen_average_id'];
            $averageChanged = $frozenId !== null && $frozenId !== $currentId;
            $items = [];
            foreach ($compensations[$runId][$employmentId] ?? [] as $input) {
                $trace = json_decode((string) $input['source_snapshot_json'], true);
                if (!is_array($trace) || !is_int($trace['average_snapshot_id'] ?? null)
                    || $trace['average_snapshot_id'] === $currentId
                ) {
                    continue;
                }
                $expected = null;
                if ($currentMinor !== null && $currentMinor > 0) {
                    $rounding ??= PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId);
                    $expected = self::expectedAmount($trace, $currentMinor, $rounding);
                }
                $items[] = [
                    'input_id' => (int) $input['id'],
                    'component_name' => (string) ($input['component_name'] ?? ''),
                    'quantity_milliunits' => $input['quantity_milliunits'] === null ? null : (int) $input['quantity_milliunits'],
                    'amount_minor' => (int) $input['amount_minor'],
                    'average_hourly_minor' => is_int($trace['average_hourly_minor'] ?? null) ? $trace['average_hourly_minor'] : null,
                    'expected_amount_minor' => $expected,
                ];
            }
            if (!$averageChanged && $items === []) {
                continue;
            }
            $byRun[$runId][] = [
                'employment_id' => $employmentId,
                'employee_id' => (int) $row['employee_id'],
                'full_name' => (string) $row['full_name'],
                'average_changed' => $averageChanged,
                'frozen_average_hourly_minor' => $row['frozen_average_hourly_minor'] === null
                    ? null
                    : (int) $row['frozen_average_hourly_minor'],
                'current_average_hourly_minor' => $currentMinor,
                'compensations' => $items,
            ];
        }

        foreach ($byRun as $runId => $people) {
            $status = (string) $watched[$runId]['status'];
            $changed = count(array_filter($people, static fn (array $person): bool => $person['average_changed']));
            $compensationCount = 0;
            $difference = 0;
            $unknown = 0;
            foreach ($people as $person) {
                foreach ($person['compensations'] as $item) {
                    ++$compensationCount;
                    if ($item['expected_amount_minor'] === null) {
                        ++$unknown;
                    } else {
                        $difference += $item['expected_amount_minor'] - $item['amount_minor'];
                    }
                }
            }
            $result[$runId] = [
                'approved' => in_array($status, PayrollClosedRunGuard::FINISHED_STATUSES, true),
                'action' => self::action($status, $changed),
                'average_changed_count' => $changed,
                'compensation_count' => $compensationCount,
                'compensation_difference_minor' => $difference,
                'compensation_unknown_count' => $unknown,
                'employments' => $people,
            ];
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function forRun(int $supplierId, array $run): ?array
    {
        return $this->forRuns($supplierId, [$run])[(int) ($run['id'] ?? 0)] ?? null;
    }

    private static function action(string $status, int $averageChanged): ?string
    {
        if (in_array($status, PayrollClosedRunGuard::FINISHED_STATUSES, true)) {
            return 'request_correction';
        }
        if ($status === 'correction_pending') {
            return 'reopen';
        }

        return $averageChanged > 0 ? 'refresh_inputs' : null;
    }

    /** @param array<string,mixed> $trace */
    private static function expectedAmount(array $trace, int $averageMinor, \MyInvoice\Service\Payroll\Calculation\PayrollWageRounding $rounding): ?int
    {
        $minutes = $trace['minutes'] ?? null;
        if (!is_int($minutes) || $minutes <= 0 || isset($trace['hourly_average_floor'])) {
            return null;
        }
        $percent = match ($trace['kind'] ?? null) {
            'leave_compensation.v1' => 100,
            'import_absence_compensation.v1' => is_int($trace['rate_percent'] ?? null) ? $trace['rate_percent'] : null,
            default => null,
        };
        if ($percent === null || $percent <= 0 || $percent > 100) {
            return null;
        }

        return LeaveCompensationCalculator::calculateMinutes($averageMinor, $minutes, $percent, $rounding);
    }

    /**
     * @param list<int> $runIds
     * @return list<array<string,mixed>>
     */
    private function employments(int $supplierId, array $runIds): array
    {
        $in = implode(', ', array_fill(0, count($runIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "WITH frozen AS (
                SELECT run.id AS run_id, run.period_start, run_employment.employee_id, run_employment.employment_id,
                       CAST(JSON_VALUE(run_employment.input_json, '$.average_earning.id') AS UNSIGNED) AS frozen_average_id,
                       CAST(JSON_VALUE(run_employment.input_json, '$.average_earning.average_hourly_minor') AS SIGNED)
                           AS frozen_average_hourly_minor
                  FROM payroll_runs run
                  JOIN payroll_run_revisions revision
                    ON revision.supplier_id = run.supplier_id
                   AND revision.run_id = run.id
                   AND revision.revision_no = run.current_revision_no
                  JOIN payroll_run_employments run_employment
                    ON run_employment.supplier_id = revision.supplier_id
                   AND run_employment.revision_id = revision.id
                 WHERE run.supplier_id = ? AND run.id IN ({$in})
            ),
            approved AS (
                SELECT average.employment_id, average.applicable_year, average.applicable_quarter,
                       average.id, average.average_hourly_minor,
                       ROW_NUMBER() OVER (
                           PARTITION BY average.employment_id, average.applicable_year, average.applicable_quarter
                           ORDER BY average.revision_no DESC, average.id DESC
                       ) AS position
                  FROM payroll_average_earning_snapshots average
                  JOIN (SELECT DISTINCT employment_id, period_start FROM frozen) wanted
                    ON wanted.employment_id = average.employment_id
                   AND average.applicable_year = YEAR(wanted.period_start)
                   AND average.applicable_quarter = QUARTER(wanted.period_start)
                 WHERE average.supplier_id = ? AND average.status = 'approved'
            )
            SELECT frozen.run_id, frozen.period_start, frozen.employee_id, frozen.employment_id,
                   frozen.frozen_average_id, frozen.frozen_average_hourly_minor,
                   approved.id AS current_average_id, approved.average_hourly_minor AS current_average_hourly_minor,
                   employee.full_name
              FROM frozen
              JOIN payroll_employees employee
                ON employee.supplier_id = ? AND employee.id = frozen.employee_id
         LEFT JOIN approved
                ON approved.employment_id = frozen.employment_id
               AND approved.applicable_year = YEAR(frozen.period_start)
               AND approved.applicable_quarter = QUARTER(frozen.period_start)
               AND approved.position = 1
             ORDER BY frozen.run_id, employee.full_name, frozen.employment_id",
        );
        $stmt->execute([$supplierId, ...$runIds, $supplierId, $supplierId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $employments
     * @return array<int,array<int,list<array<string,mixed>>>> běh → vztah → vstupy
     */
    private function compensations(int $supplierId, array $employments): array
    {
        $runsByKey = [];
        $periods = [];
        foreach ($employments as $row) {
            $runsByKey[(int) $row['employment_id'] . '|' . (string) $row['period_start']][] = (int) $row['run_id'];
            $periods[(string) $row['period_start']] = true;
        }
        $periodIn = implode(', ', array_fill(0, count($periods), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT input.id, input.employment_id, input.period_start, input.amount_minor,
                    input.quantity_milliunits, input.source_snapshot_json, component.name AS component_name
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ?
                AND input.period_start IN ({$periodIn})
                AND input.status IN ('approved', 'locked')
                AND input.source_kind = 'absence'
                AND input.source_snapshot_json IS NOT NULL
              ORDER BY input.id",
        );
        $stmt->execute([$supplierId, ...array_keys($periods)]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $input) {
            $key = (int) $input['employment_id'] . '|' . (string) $input['period_start'];
            foreach ($runsByKey[$key] ?? [] as $runId) {
                $result[$runId][(int) $input['employment_id']][] = $input;
            }
        }

        return $result;
    }
}
