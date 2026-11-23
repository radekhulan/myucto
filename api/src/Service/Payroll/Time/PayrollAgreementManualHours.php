<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Přesný ruční zdroj pro oba limity dohod; nepotřebuje potvrzený výkaz JMHZ. */
final class PayrollAgreementManualHours
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $employeeIds @param list<int> $excludedEmploymentIds @return list<array<string,mixed>> */
    public function forEmployees(int $supplierId, array $employeeIds, string $from, string $to, ?string $excludedMonth = null, array $excludedEmploymentIds = []): array
    {
        return $this->load($supplierId, $employeeIds, $from, $to, true, $excludedMonth, $excludedEmploymentIds);
    }

    /** @param list<int> $employmentIds @return list<array<string,mixed>> */
    public function forEmployments(int $supplierId, array $employmentIds, string $from, string $to): array
    {
        return $this->load($supplierId, $employmentIds, $from, $to, false, null, []);
    }

    /** @param list<int> $ids @param list<int> $excludedEmploymentIds @return list<array<string,mixed>> */
    private function load(int $supplierId, array $ids, string $from, string $to, bool $byEmployee, ?string $excludedMonth, array $excludedEmploymentIds): array
    {
        if ($ids === []) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $column = $byEmployee ? 'employee_id' : 'id';
        $index = $byEmployee ? 'idx_payroll_employment_employee' : 'PRIMARY';
        $relation = $byEmployee ? " AND employment.relation_type = 'dpp'" : '';
        $exclusion = '';
        $parameters = [$supplierId, ...$ids, $from, $to];
        if ($excludedMonth !== null && $excludedEmploymentIds !== []) {
            $excluded = implode(',', array_fill(0, count($excludedEmploymentIds), '?'));
            $exclusion = " AND NOT (month_row.period_start = ? AND month_row.employment_id IN ($excluded))";
            array_push($parameters, $excludedMonth, ...$excludedEmploymentIds);
        }
        // Nejprve firma a omezené vztahy, potom rozsah měsíců a jejich aktuální
        // revize. Denní zápisy ani starší souhrny se k ručnímu zdroji nepřičítají.
        $statement = $this->db->pdo()->prepare(
            "SELECT employment.employee_id, employment.id AS employment_id, month_row.period_start,
                    CASE WHEN month_row.status = 'approved' AND COUNT(segment.sequence_no) > 0
                         THEN SUM(segment.worked_duration_units) ELSE NULL END AS worked_duration_units
               FROM payroll_employments employment FORCE INDEX ($index)
               JOIN payroll_time_months month_row FORCE INDEX (uq_payroll_time_month_period)
                 ON month_row.supplier_id = employment.supplier_id AND month_row.employment_id = employment.id
               LEFT JOIN payroll_time_month_manual_summaries summary
                 ON summary.supplier_id = month_row.supplier_id AND summary.time_month_id = month_row.id
                AND summary.time_month_revision_no = month_row.revision_no
               LEFT JOIN payroll_time_month_manual_segments segment
                 ON segment.supplier_id = summary.supplier_id AND segment.summary_id = summary.id
              WHERE employment.supplier_id = ? AND employment.$column IN ($in)$relation
                AND month_row.period_start >= ? AND month_row.period_start <= ?
                AND month_row.work_source = 'manual_summary'$exclusion
              GROUP BY employment.employee_id, employment.id, month_row.id, month_row.period_start, month_row.status
              ORDER BY employment.id, month_row.period_start",
        );
        $statement->execute($parameters);
        return array_map(static function (array $row): array {
            $row['employee_id'] = (int) $row['employee_id'];
            $row['employment_id'] = (int) $row['employment_id'];
            $row['worked_duration_units'] = $row['worked_duration_units'] === null ? null : (int) $row['worked_duration_units'];
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Přesné hodiny pro text nálezu; neztrácí ani smíšený součet minut a tisícin. */
    public static function hours(int $units): string
    {
        $whole = intdiv($units, PayrollDuration::UNITS_PER_HOUR);
        $remainder = $units % PayrollDuration::UNITS_PER_HOUR;
        if ($remainder === 0) return (string) $whole;
        if ($units % 3 === 0) {
            return $whole . ',' . rtrim(str_pad((string) intdiv($remainder, 3), 3, '0', STR_PAD_LEFT), '0');
        }
        return ($whole > 0 ? $whole . ' + ' : '') . $remainder . '/3000';
    }
}
