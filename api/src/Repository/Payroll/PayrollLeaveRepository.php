<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\LeaveEntitlementResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollLeaveRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollLeaveLedgerDeletionRepository $ledgerDeletion,
        private readonly PayrollLeaveEntitlementDeletionRepository $entitlementDeletion,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, int $employmentId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ledger.*, employee.full_name, employment.code AS employment_code
               FROM payroll_leave_ledger ledger
               JOIN payroll_employments employment
                 ON employment.supplier_id = ledger.supplier_id
                AND employment.id = ledger.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE ledger.supplier_id = ? AND ledger.employment_id = ? AND ledger.leave_year = ?
              ORDER BY ledger.effective_date, ledger.id'
        );
        $stmt->execute([$supplierId, $employmentId, $year]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = self::cast($row);
        }

        return $this->ledgerDeletion->decorate($supplierId, $rows);
    }

    /**
     * Revize nároku na dovolenou za rok. Bez tohohle výpisu by uživatel neměl
     * kde smazaný nárok najít — dosud se snapshot vracel jen v odpovědi na jeho
     * vytvoření.
     *
     * @return list<array<string,mixed>>
     */
    public function entitlements(int $supplierId, int $employmentId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, employment_id, leave_year, revision_no,
                    relation_type, weekly_minutes, entitlement_weeks,
                    continuous_calendar_days, worked_equivalent_minutes,
                    worked_week_multiples, entitlement_minutes, rationale,
                    support_status, leave_ledger_entry_id, row_version,
                    created_by, created_at
               FROM payroll_leave_entitlement_snapshots
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?
              ORDER BY revision_no DESC'
        );
        $stmt->execute([$supplierId, $employmentId, $year]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ([
                'id', 'supplier_id', 'employment_id', 'leave_year', 'revision_no',
                'weekly_minutes', 'entitlement_weeks', 'continuous_calendar_days',
                'worked_equivalent_minutes', 'worked_week_multiples',
                'entitlement_minutes', 'row_version',
            ] as $key) {
                $row[$key] = (int) $row[$key];
            }
            foreach (['leave_ledger_entry_id', 'created_by'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
            $rows[] = $row;
        }

        return $this->entitlementDeletion->decorate($supplierId, $rows);
    }

    public function balance(int $supplierId, int $employmentId, int $year, ?string $asOf = null): int
    {
        $sql = 'SELECT COALESCE(SUM(minutes_delta), 0)
                  FROM payroll_leave_ledger
                 WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?';
        $params = [$supplierId, $employmentId, $year];
        if ($asOf !== null) {
            $sql .= ' AND effective_date <= ?';
            $params[] = $asOf;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function employmentRelationType(int $supplierId, int $employmentId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $relationType = $stmt->fetchColumn();
        if (!is_string($relationType) || $relationType === '') {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
        return $relationType;
    }

    /** @return array<string,mixed> */
    public function appendManual(
        int $supplierId,
        int $employmentId,
        int $year,
        string $effectiveDate,
        string $entryType,
        int $minutesDelta,
        string $reason,
        ?int $userId,
    ): array {
        if (!in_array($entryType, ['carryover', 'adjustment', 'shortening', 'overdrawn', 'payout'], true)) {
            throw new \InvalidArgumentException('Typ ruční položky dovolené není platný.');
        }
        if ($entryType === 'carryover' && $minutesDelta < 0) {
            throw new \InvalidArgumentException('Převod dovolené musí být kladný.');
        }
        if (in_array($entryType, ['shortening', 'overdrawn', 'payout'], true) && $minutesDelta > 0) {
            throw new \InvalidArgumentException('Krácení, přečerpání a proplacení musí snižovat zůstatek.');
        }
        return $this->append(
            $supplierId,
            $employmentId,
            $year,
            $effectiveDate,
            $entryType,
            $minutesDelta,
            null,
            null,
            $reason,
            $userId,
        );
    }

    /** @return array<string,mixed> */
    public function recordEntitlement(
        int $supplierId,
        int $employmentId,
        int $year,
        string $relationType,
        int $entitlementWeeks,
        int $continuousCalendarDays,
        int $workedEquivalentMinutes,
        string $rationale,
        LeaveEntitlementResult $result,
        ?int $userId,
        string $calculationMode = 'manual',
        ?string $sourceSnapshotHash = null,
    ): array {
        if (!in_array($calculationMode, ['manual', 'automatic'], true)) {
            throw new \InvalidArgumentException('Režim výpočtu nároku není platný.');
        }
        if ($calculationMode === 'automatic' && ($sourceSnapshotHash === null || strlen($sourceSnapshotHash) !== 32)) {
            throw new \InvalidArgumentException('Automatický výpočet vyžaduje platný otisk schválených podkladů.');
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->lockEmployment($supplierId, $employmentId);
            $revisionStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(revision_no), 0) + 1
                   FROM payroll_leave_entitlement_snapshots
                  WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?'
            );
            $revisionStmt->execute([$supplierId, $employmentId, $year]);
            $revision = (int) $revisionStmt->fetchColumn();
            $previous = $pdo->prepare(
                'SELECT snapshot.leave_ledger_entry_id, ledger.minutes_delta
                   FROM payroll_leave_entitlement_snapshots snapshot
                   JOIN payroll_leave_ledger ledger
                     ON ledger.supplier_id = snapshot.supplier_id
                    AND ledger.id = snapshot.leave_ledger_entry_id
                  WHERE snapshot.supplier_id = ? AND snapshot.employment_id = ?
                    AND snapshot.leave_year = ?
                  ORDER BY snapshot.revision_no DESC
                  LIMIT 1 FOR UPDATE'
            );
            $previous->execute([$supplierId, $employmentId, $year]);
            $previousRow = $previous->fetch(PDO::FETCH_ASSOC);
            if (is_array($previousRow)) {
                $this->append(
                    $supplierId,
                    $employmentId,
                    $year,
                    sprintf('%04d-01-01', $year),
                    'reversal',
                    -(int) $previousRow['minutes_delta'],
                    null,
                    (int) $previousRow['leave_ledger_entry_id'],
                    "Reverze nároku nahrazeného revizí {$revision}.",
                    $userId,
                );
            }
            $input = [
                'continuous_calendar_days' => $continuousCalendarDays,
                'entitlement_weeks' => $entitlementWeeks,
                'relation_type' => $relationType,
                'rationale' => $rationale,
                'worked_equivalent_minutes' => $workedEquivalentMinutes,
                'year' => $year,
                'calculation_mode' => $calculationMode,
                'source_snapshot_hash' => $sourceSnapshotHash === null ? null : bin2hex($sourceSnapshotHash),
            ];
            $inputHash = hash('sha256', CanonicalJson::encode($input), true);
            $entry = $this->append(
                $supplierId,
                $employmentId,
                $year,
                sprintf('%04d-01-01', $year),
                'entitlement',
                $result->entitlementMinutes,
                null,
                null,
                "Nárok dovolené – revize {$revision}: {$rationale}",
                $userId,
                $result->supportStatus,
            );
            $insert = $pdo->prepare(
                'INSERT INTO payroll_leave_entitlement_snapshots
                    (supplier_id, employment_id, leave_year, revision_no, relation_type,
                     weekly_minutes, entitlement_weeks, continuous_calendar_days,
                     worked_equivalent_minutes, worked_week_multiples, entitlement_minutes,
                     rationale, support_status, calculation_mode, source_snapshot_hash,
                     input_hash, calculation_trace,
                     leave_ledger_entry_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId, $employmentId, $year, $revision, $relationType,
                $result->weeklyMinutes, $entitlementWeeks, $continuousCalendarDays,
                $workedEquivalentMinutes, $result->workedWeekMultiples,
                $result->entitlementMinutes, $rationale, $result->supportStatus,
                $calculationMode, $sourceSnapshotHash, $inputHash,
                CanonicalJson::encode($result->trace), $entry['id'], $userId,
            ]);
            $snapshotId = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return [
            'snapshot_id' => $snapshotId,
            'ledger_entry' => $entry,
            'balance_minutes' => $this->balance($supplierId, $employmentId, $year),
            'support_status' => $result->supportStatus,
            'calculation_trace' => $result->trace,
        ];
    }

    /** @param array<string,mixed> $absence */
    public function recordTaken(array $absence, int $minutes, ?int $userId): array
    {
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('Čerpání dovolené vyžaduje publikované směny.');
        }
        return $this->append(
            (int) $absence['supplier_id'],
            (int) $absence['employment_id'],
            (int) substr((string) $absence['date_from'], 0, 4),
            (string) $absence['date_from'],
            'taken',
            -$minutes,
            (int) $absence['id'],
            null,
            'Schválené čerpání dovolené.',
            $userId,
        );
    }

    /** @param array<string,mixed> $absence */
    public function reverseTaken(array $absence, ?int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM payroll_leave_ledger
              WHERE supplier_id = ? AND source_absence_id = ? AND entry_type = 'taken'
              FOR UPDATE"
        );
        $stmt->execute([$absence['supplier_id'], $absence['id']]);
        $taken = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($taken)) {
            return null;
        }
        return $this->append(
            (int) $absence['supplier_id'],
            (int) $absence['employment_id'],
            (int) $taken['leave_year'],
            (string) $absence['date_from'],
            'reversal',
            -(int) $taken['minutes_delta'],
            (int) $absence['id'],
            (int) $taken['id'],
            'Reverze zrušeného čerpání dovolené.',
            $userId,
        );
    }

    /** @return array<string,mixed> */
    private function append(
        int $supplierId,
        int $employmentId,
        int $year,
        string $effectiveDate,
        string $entryType,
        int $minutesDelta,
        ?int $absenceId,
        ?int $reversalOfId,
        string $reason,
        ?int $userId,
        string $supportStatus = 'manual_review',
    ): array {
        if ($minutesDelta === 0 || trim($reason) === '') {
            throw new \InvalidArgumentException('Položka dovolené vyžaduje nenulové minuty a důvod.');
        }
        $this->lockEmployment($supplierId, $employmentId);
        $hash = hash('sha256', CanonicalJson::encode([
            'effective_date' => $effectiveDate,
            'employment_id' => $employmentId,
            'entry_type' => $entryType,
            'leave_year' => $year,
            'minutes_delta' => $minutesDelta,
            'reason' => trim($reason),
            'reversal_of_id' => $reversalOfId,
            'source_absence_id' => $absenceId,
            'supplier_id' => $supplierId,
            'support_status' => $supportStatus,
        ]), true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_leave_ledger
                (supplier_id, employment_id, leave_year, effective_date, entry_type,
                 minutes_delta, source_absence_id, reversal_of_id, reason,
                 support_status, source_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId, $employmentId, $year, $effectiveDate, $entryType,
            $minutesDelta, $absenceId, $reversalOfId, trim($reason),
            $supportStatus, $hash, $userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $find = $this->db->pdo()->prepare('SELECT * FROM payroll_leave_ledger WHERE supplier_id = ? AND id = ?');
        $find->execute([$supplierId, $id]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : throw new \RuntimeException('Položka dovolené nebyla nalezena.');
    }

    private function lockEmployment(int $supplierId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'employment_id', 'leave_year', 'minutes_delta',
            'source_absence_id', 'reversal_of_id'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        unset($row['source_hash']);
        return $row;
    }
}
