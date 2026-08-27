<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollYearCloseRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $year): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, calendar_year, status, row_version, closed_at,
                    closed_by, reopened_at, reopened_by, created_at, updated_at
               FROM payroll_year_closures
              WHERE supplier_id = ? AND calendar_year = ?',
        );
        $statement->execute([$supplierId, $year]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::cast($row);
    }

    /** Volat pouze uvnitř transakce. @return array<string,mixed>|null */
    public function findForUpdate(int $supplierId, int $year): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, calendar_year, status, row_version, closed_at,
                    closed_by, reopened_at, reopened_by, created_at, updated_at
               FROM payroll_year_closures
              WHERE supplier_id = ? AND calendar_year = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $year]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::cast($row);
    }

    /** Volat pouze uvnitř transakce. */
    public function insertClosed(int $supplierId, int $year, ?int $userId): int
    {
        $statement = $this->db->pdo()->prepare(
            "INSERT INTO payroll_year_closures
                (supplier_id, calendar_year, status, row_version, closed_at, closed_by)
             VALUES (?, ?, 'closed', 1, NOW(), ?)",
        );
        $statement->execute([$supplierId, $year, $userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Volat pouze uvnitř transakce; false znamená CAS konflikt. */
    public function setStatusCas(
        int $supplierId,
        int $year,
        string $status,
        int $expectedRowVersion,
        ?int $userId,
    ): bool {
        if ($status === 'closed') {
            $sql = "UPDATE payroll_year_closures
                       SET status = 'closed', row_version = row_version + 1,
                           closed_at = NOW(), closed_by = ?,
                           reopened_at = NULL, reopened_by = NULL
                     WHERE supplier_id = ? AND calendar_year = ?
                       AND status = 'open' AND row_version = ?";
        } else {
            $sql = "UPDATE payroll_year_closures
                       SET status = 'open', row_version = row_version + 1,
                           closed_at = NULL, closed_by = NULL,
                           reopened_at = NOW(), reopened_by = ?
                     WHERE supplier_id = ? AND calendar_year = ?
                       AND status = 'closed' AND row_version = ?";
        }
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute([$userId, $supplierId, $year, $expectedRowVersion]);

        return $statement->rowCount() === 1;
    }

    /** @return list<string> */
    public function missingMonths(int $supplierId, int $year): array
    {
        $startStatement = $this->db->pdo()->prepare(
            'SELECT start_period
               FROM payroll_module_state
              WHERE supplier_id = ?',
        );
        $startStatement->execute([$supplierId]);
        $startPeriod = $startStatement->fetchColumn();
        $firstMonth = 1;
        if (is_string($startPeriod) && $startPeriod !== '') {
            $moduleStart = new \DateTimeImmutable($startPeriod);
            $yearStart = new \DateTimeImmutable("{$year}-01-01");
            $yearEnd = new \DateTimeImmutable(($year + 1) . '-01-01');
            if ($moduleStart >= $yearEnd) {
                return [];
            }
            if ($moduleStart > $yearStart) {
                $firstMonth = (int) $moduleStart->format('n');
            }
        }
        $statement = $this->db->pdo()->prepare(
            "SELECT DISTINCT DATE_FORMAT(period_start, '%Y-%m') AS period
               FROM payroll_runs
              WHERE supplier_id = ?
                AND period_start >= ? AND period_start < ?
                AND status = 'closed'",
        );
        $statement->execute([$supplierId, "{$year}-01-01", ($year + 1) . '-01-01']);
        $present = array_fill_keys(
            array_map(static fn (array $row): string => (string) $row['period'], $statement->fetchAll(PDO::FETCH_ASSOC)),
            true,
        );

        $missing = [];
        for ($month = $firstMonth; $month <= 12; ++$month) {
            $period = sprintf('%04d-%02d', $year, $month);
            if (!isset($present[$period])) {
                $missing[] = $period;
            }
        }

        return $missing;
    }

    public function openCorrectionCount(int $supplierId, int $year): int
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT run.id)
               FROM payroll_runs run
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id AND revision.run_id = run.id
              WHERE run.supplier_id = ?
                AND run.period_start >= ? AND run.period_start < ?
                AND (
                  run.status IN ('correction_pending', 'reopened')
                  OR (revision.revision_kind = 'correction'
                      AND revision.status IN ('snapshot', 'calculated', 'reviewed'))
                )",
        );
        $statement->execute([$supplierId, "{$year}-01-01", ($year + 1) . '-01-01']);
        return (int) $statement->fetchColumn();
    }

    public function openSubmissionCount(int $supplierId, int $year): int
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM payroll_obligations obligation
              WHERE obligation.supplier_id = ?
                AND obligation.environment = 'production'
                AND obligation.status NOT IN ('fulfilled', 'cancelled')
                AND obligation.period_start < ?
                AND obligation.period_end >= ?",
        );
        $start = "{$year}-01-01";
        $next = ($year + 1) . '-01-01';
        $statement->execute([$supplierId, $next, $start]);
        return (int) $statement->fetchColumn();
    }

    public function openLiabilityCount(int $supplierId, int $year): int
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE liability.supplier_id = ?
                AND run.period_start >= ? AND run.period_start < ?
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_payment_liabilities newer
                     WHERE newer.supplier_id = liability.supplier_id
                       AND newer.previous_liability_id = liability.id
                )
                AND liability.amount_minor > CASE liability.direction
                    WHEN 'incoming' THEN (
                        SELECT COALESCE(SUM(payment_match.amount_minor), 0)
                          FROM payroll_payment_matches payment_match
                         WHERE payment_match.supplier_id = liability.supplier_id
                           AND payment_match.liability_id = liability.id
                           AND payment_match.allocation_id IS NULL
                    )
                    ELSE (
                        SELECT COALESCE(SUM(payment_match.amount_minor), 0)
                          FROM payroll_payment_allocations allocation
                          JOIN payroll_payment_matches payment_match
                            ON payment_match.supplier_id = allocation.supplier_id
                           AND payment_match.allocation_id = allocation.id
                         WHERE allocation.supplier_id = liability.supplier_id
                           AND allocation.liability_id = liability.id
                    )
                END",
        );
        $statement->execute([$supplierId, "{$year}-01-01", ($year + 1) . '-01-01']);
        return (int) $statement->fetchColumn();
    }

    public function openLeaveCount(int $supplierId, int $year): int
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM payroll_absences
              WHERE supplier_id = ?
                AND absence_type = 'vacation'
                AND date_from < ? AND date_to >= ?
                AND (status = 'requested' OR correction_pending = 1)",
        );
        $statement->execute([$supplierId, ($year + 1) . '-01-01', "{$year}-01-01"]);
        return (int) $statement->fetchColumn();
    }

    public function unresolvedEnforcementCount(int $supplierId, int $year): int
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM payroll_enforcement_cases enforcement_case
              WHERE enforcement_case.supplier_id = ?
                AND enforcement_case.case_kind = 'enforcement'
                AND enforcement_case.status NOT IN ('paid', 'stopped')
                AND enforcement_case.effective_from < ?
                AND (enforcement_case.effective_to IS NULL OR enforcement_case.effective_to >= ?)
                AND (
                    enforcement_case.status = 'received'
                    OR
                    enforcement_case.evidence_complete = 0
                    OR enforcement_case.recipient_verified = 0
                    OR 0 < (
                        SELECT GREATEST(
                            0,
                            COALESCE(SUM(CASE WHEN ledger.entry_kind = 'held'
                                THEN ledger.amount_minor_units ELSE 0 END), 0)
                            - COALESCE(SUM(CASE WHEN ledger.entry_kind = 'released_to_employee'
                                THEN ledger.amount_minor_units ELSE 0 END), 0)
                            - GREATEST(
                                COALESCE(SUM(CASE WHEN ledger.entry_kind = 'released_for_remittance'
                                    THEN ledger.amount_minor_units ELSE 0 END), 0),
                                COALESCE(SUM(CASE WHEN ledger.entry_kind = 'remitted'
                                    THEN ledger.amount_minor_units ELSE 0 END), 0)
                            )
                        )
                          FROM payroll_enforcement_ledger ledger
                          JOIN payroll_enforcement_month_results month_result
                            ON month_result.supplier_id = ledger.supplier_id
                           AND month_result.id = ledger.month_result_id
                     LEFT JOIN payroll_run_revisions revision
                            ON revision.supplier_id = month_result.supplier_id
                           AND revision.id = month_result.revision_id
                     LEFT JOIN payroll_runs run
                            ON run.supplier_id = revision.supplier_id
                           AND run.id = revision.run_id
                         WHERE ledger.supplier_id = enforcement_case.supplier_id
                           AND ledger.case_id = enforcement_case.id
                           AND month_result.period_start < ?
                           AND (month_result.revision_id IS NULL
                                OR revision.revision_no = run.current_revision_no)
                    )
                )",
        );
        $nextYear = ($year + 1) . '-01-01';
        $statement->execute([$supplierId, $nextYear, "{$year}-01-01", $nextYear]);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'calendar_year', 'row_version'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['closed_by', 'reopened_by'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        return $row;
    }
}
