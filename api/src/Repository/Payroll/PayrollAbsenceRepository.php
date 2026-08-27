<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\PayrollYearCloseGuard;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PDO;

final class PayrollAbsenceRepository
{
    private readonly PayrollYearCloseGuard $yearClose;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRulesetProvider $rulesets,
    ) {
        $this->yearClose = new PayrollYearCloseGuard($db);
    }

    /** @return list<array<string,mixed>> */
    public function employments(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.employee_id, employment.code,
                    employment.relation_type, employment.status,
                    employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.status NOT IN ('archived', 'no_show')
              ORDER BY employee.full_name, employment.code"
        );
        $stmt->execute([$supplierId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['employee_id'] = (int) $row['employee_id'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Strop stránky seznamu. Absence jsou pracovní tabulka — obrazovka ukazuje
     * pár desítek řádků jednoho období. Počet řádků přitom roste součinem
     * počtu zaměstnanců a délky filtrovaného rozsahu, takže bez stropu je
     * odpověď u větší firmy a ročního filtru neomezená.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function list(
        int $supplierId,
        string $from,
        string $to,
        ?int $employmentId = null,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $where = 'absence.supplier_id = ? AND absence.date_from <= ? AND absence.date_to >= ?';
        $params = [$supplierId, $to, $from];
        if ($employmentId !== null) {
            $where .= ' AND absence.employment_id = ?';
            $params[] = $employmentId;
        }
        $source = "FROM payroll_absences absence
               JOIN payroll_employments employment
                 ON employment.supplier_id = absence.supplier_id
                AND employment.id = absence.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               LEFT JOIN payroll_average_earning_snapshots average
                 ON average.supplier_id = absence.supplier_id
                AND average.id = absence.average_snapshot_id
              WHERE {$where}";

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) {$source}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT absence.*, employment.code AS employment_code,
                    employment.relation_type, employee.full_name,
                    average.average_hourly_minor,
                    average.applicable_year AS average_year,
                    average.applicable_quarter AS average_quarter
               {$source}
              ORDER BY absence.date_from, employee.full_name, absence.id
              LIMIT ? OFFSET ?"
        );
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->yearClose->assertOpenForDateRange(
                $supplierId,
                (string) $data['date_from'],
                (string) $data['date_to'],
            );
            $this->lockEmployment($supplierId, (int) $data['employment_id']);
            $overlap = $pdo->prepare(
                "SELECT id
                   FROM payroll_absences
                  WHERE supplier_id = ? AND employment_id = ?
                    AND status IN ('requested','approved')
                    AND date_from <= ? AND date_to >= ?
                  LIMIT 1 FOR UPDATE"
            );
            $overlap->execute([
                $supplierId,
                $data['employment_id'],
                $data['date_to'],
                $data['date_from'],
            ]);
            if ($overlap->fetchColumn() !== false) {
                throw new PayrollAbsenceOverlapException();
            }

            if ($data['average_snapshot_id'] !== null) {
                $this->assertApprovedAverage(
                    $supplierId,
                    (int) $data['employment_id'],
                    (int) $data['average_snapshot_id'],
                    (string) $data['date_from'],
                );
            }
            $insert = $pdo->prepare(
                'INSERT INTO payroll_absences
                    (supplier_id, employment_id, absence_type, date_from, date_to,
                     timezone_name, partial_first_minutes, partial_last_minutes, note,
                     compensation_policy, compensation_rate_basis_points,
                     average_snapshot_id, support_status, status, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $data['employment_id'],
                $data['absence_type'],
                $data['date_from'],
                $data['date_to'],
                $data['timezone_name'],
                $data['partial_first_minutes'],
                $data['partial_last_minutes'],
                $data['note'],
                $data['compensation_policy'],
                $data['compensation_rate_basis_points'],
                $data['average_snapshot_id'],
                'manual_review',
                'requested',
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Uložená absence nebyla nalezena.');
    }

    /** @return array<string,mixed> */
    public function decide(
        int $supplierId,
        int $id,
        int $expectedVersion,
        string $decision,
        ?int $userId,
    ): array {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Rozhodnutí absence není platné.');
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $absence = $this->find($supplierId, $id);
            if ($absence !== null) {
                $this->yearClose->assertOpenForDateRange(
                    $supplierId,
                    (string) $absence['date_from'],
                    (string) $absence['date_to'],
                );
            }
            $stmt = $pdo->prepare(
                "UPDATE payroll_absences
                    SET status = ?, decided_by = ?, decided_at = NOW(),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ? AND status = 'requested'"
            );
            $stmt->execute([$decision, $userId, $supplierId, $id, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                $this->throwConflictOrInvalid($supplierId, $id, $expectedVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Rozhodnutá absence nebyla nalezena.');
    }

    /** @return array<string,mixed> */
    public function cancel(
        int $supplierId,
        int $id,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $absence = $this->find($supplierId, $id);
            if ($absence !== null) {
                $this->yearClose->assertOpenForDateRange(
                    $supplierId,
                    (string) $absence['date_from'],
                    (string) $absence['date_to'],
                );
            }
            $stmt = $pdo->prepare(
                "UPDATE payroll_absences
                    SET correction_pending = IF(status = 'approved', 1, correction_pending),
                        status = 'cancelled',
                        decided_by = ?, decided_at = NOW(), row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status IN ('requested','approved')"
            );
            $stmt->execute([$userId, $supplierId, $id, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                $this->throwConflictOrInvalid($supplierId, $id, $expectedVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Zrušená absence nebyla nalezena.');
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT absence.*, employment.code AS employment_code,
                    employment.relation_type, employee.full_name,
                    average.average_hourly_minor,
                    average.applicable_year AS average_year,
                    average.applicable_quarter AS average_quarter
               FROM payroll_absences absence
               JOIN payroll_employments employment
                 ON employment.supplier_id = absence.supplier_id
                AND employment.id = absence.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               LEFT JOIN payroll_average_earning_snapshots average
                 ON average.supplier_id = absence.supplier_id
                AND average.id = absence.average_snapshot_id
              WHERE absence.supplier_id = ? AND absence.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    public function publishedShiftSegments(array $absence, bool $firstDayFullyWorked): array
    {
        if (!$this->db->hasTable('payroll_shifts')) {
            return [];
        }
        $timezone = new \DateTimeZone((string) $absence['timezone_name']);
        $windowFrom = new \DateTimeImmutable((string) $absence['date_from'], $timezone);
        if ($firstDayFullyWorked) {
            $windowFrom = $windowFrom->modify('+1 day');
        }
        $absenceTo = new \DateTimeImmutable((string) $absence['date_to'], $timezone);
        $isSickness = in_array(
            $absence['absence_type'],
            ['dpn', 'quarantine'],
            true,
        );
        $windowTo = $absenceTo;
        if ($isSickness) {
            // Délka okna náhrady mzdy podle § 192 ZP se historicky měnila
            // (21 → 14 dnů), proto je v rulesetu, ne v literálu.
            $windowEnd = AbsenceRuleset::forDate($this->rulesets, (string) $absence['date_from'])
                ->sicknessWindowEnd($windowFrom);
            if ($windowEnd < $absenceTo) {
                $windowTo = $windowEnd;
            }
        }
        if ($windowTo < $windowFrom) {
            return [];
        }

        $utc = new \DateTimeZone('UTC');
        $queryFrom = $windowFrom->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $queryTo = $windowTo->modify('+1 day')->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, starts_at_utc, ends_at_utc, timezone_name, break_minutes
               FROM payroll_shifts
              WHERE supplier_id = ? AND employment_id = ? AND status = 'published'
                AND starts_at_utc < ? AND ends_at_utc > ?
              ORDER BY starts_at_utc, id"
        );
        $stmt->execute([
            $absence['supplier_id'],
            $absence['employment_id'],
            $queryTo,
            $queryFrom,
        ]);

        $segments = [];
        $remainingByDate = [];
        if ($absence['partial_first_minutes'] !== null) {
            $remainingByDate[(string) $absence['date_from']] = (int) $absence['partial_first_minutes'];
        }
        if ($absence['partial_last_minutes'] !== null) {
            $lastDate = (string) $absence['date_to'];
            $lastLimit = (int) $absence['partial_last_minutes'];
            $remainingByDate[$lastDate] = isset($remainingByDate[$lastDate])
                ? min($remainingByDate[$lastDate], $lastLimit)
                : $lastLimit;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $shiftTimezone = new \DateTimeZone((string) $row['timezone_name']);
            $start = new \DateTimeImmutable((string) $row['starts_at_utc'], $utc);
            $end = new \DateTimeImmutable((string) $row['ends_at_utc'], $utc);
            $localDate = $start->setTimezone($shiftTimezone)->format('Y-m-d');
            if ($localDate < $windowFrom->format('Y-m-d') || $localDate > $windowTo->format('Y-m-d')) {
                continue;
            }
            $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60)
                - (int) $row['break_minutes'];
            if ($minutes <= 0) {
                continue;
            }
            $eligible = $minutes;
            if (array_key_exists($localDate, $remainingByDate)) {
                $eligible = min($eligible, $remainingByDate[$localDate]);
                $remainingByDate[$localDate] -= $eligible;
            }
            if ($eligible <= 0) {
                continue;
            }
            $segments[] = [
                'shift_id' => (int) $row['id'],
                'local_date' => $localDate,
                'planned_minutes' => $minutes,
                'eligible_minutes' => $eligible,
            ];
        }
        return $segments;
    }

    private function assertApprovedAverage(
        int $supplierId,
        int $employmentId,
        int $snapshotId,
        string $applicationDate,
    ): void
    {
        $date = new \DateTimeImmutable($applicationDate);
        $year = (int) $date->format('Y');
        $quarter = intdiv((int) $date->format('n') - 1, 3) + 1;
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ? AND id = ?
                AND applicable_year = ? AND applicable_quarter = ?
                AND status = 'approved'"
        );
        $stmt->execute([$supplierId, $employmentId, $snapshotId, $year, $quarter]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Náhrada vyžaduje schválený snapshot průměru stejného vztahu a čtvrtletí.'
            );
        }
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

    private function throwConflictOrInvalid(int $supplierId, int $id, int $expectedVersion): never
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_absences WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new \InvalidArgumentException('Absence nebyla nalezena.');
        }
        if ((int) $current !== $expectedVersion) {
            throw new PayrollAbsenceConflictException((int) $current);
        }
        throw new \InvalidArgumentException('Absenci v tomto stavu nelze změnit.');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function cast(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'employment_id', 'partial_first_minutes',
            'partial_last_minutes', 'compensation_rate_basis_points',
            'average_snapshot_id', 'average_hourly_minor', 'average_year',
            'average_quarter', 'row_version',
        ] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['correction_pending'] = (bool) $row['correction_pending'];
        return $row;
    }
}
