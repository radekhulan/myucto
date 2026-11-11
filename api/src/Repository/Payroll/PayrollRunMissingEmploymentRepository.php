<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollClosedRunGuard;
use PDO;

/**
 * Vztahy, které do období schváleného běhu patří, ale v jeho revizi nejsou.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Zaměstnanec založený (nebo s nástupem posunutým dozadu) až po schválení
 * měsíce v běhu tiše chyběl: stránka běhu se nezměnila, karta vztahu nic
 * neřekla a osoba zůstala bez mzdy i bez měsíčního hlášení za měsíc nástupu.
 * U otevřené revize to hlídá detektor zastaralých podkladů
 * ({@see \MyInvoice\Service\Payroll\Run\PayrollRunSourceDrift}); schválená
 * revize je ale neměnná a nikdo se neptal.
 *
 * Kdo do období patří, rozhoduje JEDINÉ pravidlo
 * {@see PayrollEmploymentLifecycleSql::payrollPeriodMembershipSql()} — totéž,
 * kterým snímek běhu vybírá vztahy. Tady se jen vyhodnotí proti období
 * připojeného běhu místo proti parametru a odečtou se vztahy aktuální revize.
 * Převzatý běh (zrcadlo předchozího programu) se neposuzuje: nepočítá se
 * a oprava se u něj nevyžaduje.
 */
final class PayrollRunMissingEmploymentRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Chybějící vztahy uzavřených běhů, klíčované během.
     *
     * @param list<int> $runIds
     * @return array<int,list<array{
     *   employment_id:int,employee_id:int,full_name:string,employment_code:string,
     *   relation_type:string,start_date:?string
     * }>>
     */
    public function forRuns(int $supplierId, array $runIds): array
    {
        $runIds = array_values(array_unique(array_filter($runIds, static fn (int $id): bool => $id > 0)));
        if ($runIds === []) {
            return [];
        }
        $rows = $this->rows(
            $supplierId,
            'run.id IN (' . implode(',', array_fill(0, count($runIds), '?')) . ')',
            $runIds,
        );
        $byRun = [];
        foreach ($rows as $row) {
            $byRun[$row['run_id']][] = [
                'employment_id' => $row['employment_id'],
                'employee_id' => $row['employee_id'],
                'full_name' => $row['full_name'],
                'employment_code' => $row['employment_code'],
                'relation_type' => $row['relation_type'],
                'start_date' => $row['start_date'],
            ];
        }

        return $byRun;
    }

    /**
     * Uzavřené běhy, ve kterých vztah chybí, klíčované vztahem a seřazené od
     * nejstaršího období.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<array{run_id:int,period:string,status:string}>>
     */
    public function forEmployments(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique(array_filter(
            $employmentIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($employmentIds === []) {
            return [];
        }
        $rows = $this->rows(
            $supplierId,
            'employment.id IN (' . implode(',', array_fill(0, count($employmentIds), '?')) . ')',
            $employmentIds,
        );
        $byEmployment = [];
        foreach ($rows as $row) {
            $byEmployment[$row['employment_id']][] = [
                'run_id' => $row['run_id'],
                'period' => substr($row['period_start'], 0, 7),
                'status' => $row['status'],
            ];
        }

        return $byEmployment;
    }

    /**
     * @param list<int> $scopeParams
     * @return list<array{
     *   run_id:int,period_start:string,status:string,employment_id:int,employee_id:int,
     *   full_name:string,employment_code:string,relation_type:string,start_date:?string
     * }>
     */
    private function rows(int $supplierId, string $scopeSql, array $scopeParams): array
    {
        $statuses = PayrollClosedRunGuard::FINISHED_STATUSES;
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.run_id, employment.run_period_start, employment.run_status,
                    employment.id AS employment_id, employment.employee_id,
                    employment.code AS employment_code, employment.relation_type,
                    COALESCE(employment.actual_start_date, employment.start_date) AS start_date,
                    employee.full_name
               FROM (
                    SELECT employment.*,
                           run.id AS run_id,
                           run.period_start AS run_period_start,
                           LAST_DAY(run.period_start) AS run_period_end,
                           run.status AS run_status,
                           run.office_id AS run_office_id,
                           run.current_revision_no AS run_revision_no,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAt('LAST_DAY(run.period_start)') . '
                               AS effective_status
                      FROM payroll_runs run
                      JOIN payroll_employments employment
                        ON employment.supplier_id = run.supplier_id
                     WHERE run.supplier_id = ?
                       AND run.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')
                       AND run.run_kind = "calculated"
                       AND ' . $scopeSql . '
                    ) employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN payroll_employee_profiles profile
                 ON profile.supplier_id = employment.supplier_id
                AND profile.employee_id = employment.employee_id
              WHERE ' . PayrollEmploymentLifecycleSql::payrollPeriodMembershipSql(
                  'employment.run_period_start',
                  'employment.run_period_end',
              ) . '
                AND (employment.run_office_id IS NULL OR employment.office_id = employment.run_office_id)
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions revision
                      JOIN payroll_run_employments included
                        ON included.supplier_id = revision.supplier_id
                       AND included.revision_id = revision.id
                     WHERE revision.supplier_id = employment.supplier_id
                       AND revision.run_id = employment.run_id
                       AND revision.revision_no = employment.run_revision_no
                       AND included.employment_id = employment.id
                )
              ORDER BY employment.run_period_start, employee.full_name, employment.id'
        );
        $stmt->execute([$supplierId, ...$statuses, ...$scopeParams]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'run_id' => (int) $row['run_id'],
                'period_start' => (string) $row['run_period_start'],
                'status' => (string) $row['run_status'],
                'employment_id' => (int) $row['employment_id'],
                'employee_id' => (int) $row['employee_id'],
                'full_name' => (string) $row['full_name'],
                'employment_code' => (string) $row['employment_code'],
                'relation_type' => (string) $row['relation_type'],
                'start_date' => $row['start_date'] === null ? null : (string) $row['start_date'],
            ];
        }

        return $rows;
    }
}
