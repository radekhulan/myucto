<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use PDO;

final class PayrollTimeRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollJmhzWorkMonthSummaryBuilder $jmhzWorkSummary,
        private readonly JmhzSpecPackageCatalog $jmhzSpecCatalog,
        private readonly JmhzSpecPackageRepository $jmhzSpecPackages,
    ) {}

    /**
     * Pracovní vztahy, které v období patří do docházky.
     *
     * Stav se čte K OBDOBÍ přes {@see PayrollEmploymentLifecycleSql}, ne k dnešku —
     * stejně jako v {@see PayrollQuickInputRepository} a
     * {@see \MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder}. Projekce
     * `payroll_employments.status` je jen fallback pro vztahy bez událostí.
     *
     * Whitelist (ne `NOT IN`) je záměr: `archived` a `no_show` se nevypisují a
     * jakákoliv budoucí hodnota enumu se musí do docházky přidat vědomě. Migrace
     * 1195 hodnotu `cancelled` z enumu odstranila (přejmenovala na `no_show`),
     * takže filtr na ni byl mrtvý a propouštěl i zrušené vztahy.
     *
     * `planned`/`preregistered` se vypíše jen tehdy, když nástup spadá do období
     * nebo před něj — kdo ještě nenastoupil, v docházce za období nemá co dělat.
     *
     * @param string $periodEnd první den následujícího měsíce (výlučná hranice)
     * @return list<array<string,mixed>>
     */
    public function employments(int $supplierId, string $periodStart, string $periodEnd): array
    {
        $periodLastDay = (new \DateTimeImmutable($periodEnd))
            ->modify('-1 day')
            ->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            'WITH effective_employment AS (
                    SELECT employment.*,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                               AS effective_status
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = ?
                 )
             SELECT employment.id,
                    employment.employee_id,
                    employment.code,
                    employment.relation_type,
                    employment.status,
                    employment.effective_status,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    employee.full_name
               FROM effective_employment employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.effective_status IN (
                        "planned", "preregistered", "active", "suspended", "ended"
                    )
                AND (employment.start_date IS NULL OR employment.start_date < ?)
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
                AND (
                        employment.effective_status
                            NOT IN ("planned", "preregistered")
                     OR (
                            COALESCE(
                                employment.actual_start_date,
                                employment.start_date
                            ) IS NOT NULL
                        AND COALESCE(
                                employment.actual_start_date,
                                employment.start_date
                            ) <= ?
                        )
                    )
              ORDER BY employee.full_name, employment.code, employment.id'
        );
        $stmt->execute([
            $periodLastDay,
            $supplierId,
            $periodEnd,
            $periodStart,
            $periodLastDay,
        ]);
        return self::rows($stmt);
    }

    /** @return array<string,mixed>|null */
    public function employment(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, code, relation_type, status, start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        return self::row($stmt);
    }

    /** @return array<string,mixed>|null */
    public function calendar(int $supplierId, int $employmentId, string $effectiveOn): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_work_calendars
              WHERE supplier_id = ?
                AND employment_id = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $effectiveOn, $effectiveOn]);
        $row = self::row($stmt);
        if ($row !== null) {
            $row['week_pattern'] = self::decodeWeekPattern($row['week_pattern'] ?? null);
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function calendars(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_work_calendars
              WHERE supplier_id = ?
                AND employment_id = ?
                AND valid_from < ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from, id'
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        $rows = self::rows($stmt);
        foreach ($rows as &$row) {
            $row['week_pattern'] = self::decodeWeekPattern($row['week_pattern'] ?? null);
        }
        unset($row);
        return $rows;
    }

    /**
     * Vztahy, které mají v období aspoň jednu verzi kalendáře.
     *
     * Přehled docházky potřebuje vědět „chybí kalendář?" ještě PŘED tím, než
     * pro řádek začne počítat fond a náhledy — jinak by se stránkovaný přehled
     * musel celý postavit, aby se pak zahodil.
     *
     * @param list<int> $employmentIds
     * @return list<int>
     */
    public function employmentIdsWithCalendar(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
        string $periodEnd,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT employment_id
               FROM payroll_work_calendars
              WHERE supplier_id = ?
                AND employment_id IN (' . $placeholders . ')
                AND valid_from < ?
                AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([
            $supplierId,
            ...array_values($employmentIds),
            $periodEnd,
            $periodStart,
        ]);

        $ids = [];
        foreach (self::rows($stmt) as $row) {
            $ids[] = PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id');
        }

        return $ids;
    }

    /** @return list<array<string,mixed>> */
    public function calendarDays(
        int $supplierId,
        int $calendarId,
        string $periodStart,
        string $periodEnd,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_calendar_days
              WHERE supplier_id = ?
                AND calendar_id = ?
                AND day_date >= ?
                AND day_date < ?
              ORDER BY day_date'
        );
        $stmt->execute([$supplierId, $calendarId, $periodStart, $periodEnd]);
        return self::rows($stmt);
    }

    /** @return list<array<string,mixed>> */
    public function shifts(
        int $supplierId,
        string $startsAtUtc,
        string $endsAtUtc,
    ): array {
        [$candidateStart, $candidateEnd] = self::candidateBounds(
            $startsAtUtc,
            $endsAtUtc,
        );
        $stmt = $this->db->pdo()->prepare(
            "SELECT *
               FROM payroll_shifts
              WHERE supplier_id = ?
                AND status <> 'superseded'
                AND starts_at_utc >= ?
                AND starts_at_utc < ?
              ORDER BY starts_at_utc, id"
        );
        $stmt->execute([$supplierId, $candidateStart, $candidateEnd]);
        return self::rows($stmt);
    }

    /** @return list<array<string,mixed>> */
    public function entries(
        int $supplierId,
        string $startsAtUtc,
        string $endsAtUtc,
    ): array {
        [$candidateStart, $candidateEnd] = self::candidateBounds(
            $startsAtUtc,
            $endsAtUtc,
        );
        $stmt = $this->db->pdo()->prepare(
            "SELECT *
               FROM payroll_time_entries
              WHERE supplier_id = ?
                AND status <> 'superseded'
                AND starts_at_utc >= ?
                AND starts_at_utc < ?
              ORDER BY starts_at_utc, id"
        );
        $stmt->execute([$supplierId, $candidateStart, $candidateEnd]);
        return self::rows($stmt);
    }

    /** @return list<array<string,mixed>> */
    public function monthStates(int $supplierId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_time_months
              WHERE supplier_id = ? AND period_start = ?'
        );
        $stmt->execute([$supplierId, $periodStart]);
        return self::rows($stmt);
    }

    /** @return array<string,mixed>|null */
    public function jmhzWorkSummaryRevision(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            "SELECT summary.id, summary.time_month_id,
                    summary.time_month_revision_no, summary.period_start,
                    spec.package_key AS spec_package_key,
                    summary.spec_manifest_sha256, summary.scenario_catalog_key,
                    summary.scenario_manifest_sha256,
                    summary.derivation_version, summary.source_snapshot_sha256,
                    summary.control_catalog_key, summary.control_manifest_sha256,
                    summary.standard_fund_millihours, summary.agreed_fund_millihours,
                    summary.weekly_work_centihours, summary.evidence_days,
                    summary.worked_millihours, summary.conditional_blocks_confirmed,
                    summary.unworked_hours_occurred, summary.work_obstacles_occurred,
                    summary.unworked_total_millihours, summary.unworked_paid_millihours,
                    summary.dpn_without_employer_compensation_millihours,
                    summary.dpn_with_employer_compensation_millihours,
                    summary.vacation_millihours, summary.care_millihours,
                    summary.employee_obstacle_paid_millihours,
                    summary.employer_obstacle_millihours, summary.confirmation_note,
                    summary.provenance_json, summary.summary_sha256,
                    summary.approved_by, summary.approved_at
               FROM payroll_jmhz_work_month_revisions summary
               INNER JOIN payroll_time_months month_row
                 ON month_row.supplier_id = summary.supplier_id
                AND month_row.id = summary.time_month_id
                AND month_row.revision_no = summary.time_month_revision_no
                AND month_row.status = 'approved'
               INNER JOIN payroll_jmhz_spec_packages spec
                 ON spec.id = summary.spec_package_id
              WHERE summary.supplier_id = ?
                AND summary.employment_id = ?
                AND summary.period_start = ?
              ORDER BY summary.time_month_revision_no DESC, summary.id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach ([
            'id', 'time_month_id', 'time_month_revision_no',
            'standard_fund_millihours', 'agreed_fund_millihours',
            'weekly_work_centihours', 'evidence_days', 'worked_millihours',
            'conditional_blocks_confirmed', 'unworked_hours_occurred',
            'work_obstacles_occurred', 'unworked_total_millihours',
            'unworked_paid_millihours',
            'dpn_without_employer_compensation_millihours',
            'dpn_with_employer_compensation_millihours', 'vacation_millihours',
            'care_millihours', 'employee_obstacle_paid_millihours',
            'employer_obstacle_millihours',
            'approved_by',
        ] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $provenance = json_decode((string) $row['provenance_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($provenance)) {
            throw new \UnexpectedValueException('Provenance pracovního souhrnu je neplatná.');
        }
        unset($row['provenance_json']);
        $row['provenance'] = $provenance;
        return $row;
    }

    /**
     * @param array<int,int> $weekPattern
     * @param list<array{
     *   day_date:string,
     *   day_kind:string,
     *   planned_minutes:int,
     *   holiday_code:?string,
     *   holiday_name:?string,
     *   note:?string
     * }> $days
     * @return array<string,mixed>
     */
    public function createCalendarVersion(
        int $supplierId,
        int $employmentId,
        string $name,
        string $timezone,
        string $scheduleType,
        array $weekPattern,
        int $weeklyMinutes,
        string $validFrom,
        ?string $validTo,
        int $expectedVersion,
        int $monthVersion,
        array $days,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $periodStart = substr($validFrom, 0, 7) . '-01';
            $month = $this->lockOpenMonth(
                $supplierId,
                $employmentId,
                $periodStart,
                $monthVersion,
                $userId,
            );
            $this->assertCalendarRangeUnlocked(
                $supplierId,
                $employmentId,
                $periodStart,
                $validTo,
            );
            $currentStmt = $pdo->prepare(
                'SELECT id, valid_from, valid_to, row_version
                   FROM payroll_work_calendars
                  WHERE supplier_id = ?
                    AND employment_id = ?
                    AND valid_from <= ?
                    AND (valid_to IS NULL OR valid_to >= ?)
                  ORDER BY valid_from DESC
                  LIMIT 1
                  FOR UPDATE'
            );
            $currentStmt->execute([$supplierId, $employmentId, $validFrom, $validFrom]);
            $current = self::row($currentStmt);
            if ($current === null) {
                if ($expectedVersion !== 0) {
                    throw new PayrollTimeConflictException(0);
                }
            } else {
                $currentVersion = PayrollTimeValue::int($current['row_version'] ?? null, 'row_version');
                if ($currentVersion !== $expectedVersion) {
                    throw new PayrollTimeConflictException($currentVersion);
                }
                if (PayrollTimeValue::string($current['valid_from'] ?? null, 'valid_from') === $validFrom) {
                    throw new \InvalidArgumentException(
                        'Pro toto datum už verze kalendáře existuje; zvolte datum nové historické změny.'
                    );
                }
                $previousEnd = (new \DateTimeImmutable($validFrom))
                    ->modify('-1 day')
                    ->format('Y-m-d');
                $close = $pdo->prepare(
                    'UPDATE payroll_work_calendars
                        SET valid_to = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ? AND row_version = ?'
                );
                $close->execute([
                    $previousEnd,
                    $supplierId,
                    PayrollTimeValue::int($current['id'] ?? null, 'id'),
                    $currentVersion,
                ]);
                if ($close->rowCount() !== 1) {
                    throw new PayrollTimeConflictException($currentVersion + 1);
                }
            }

            $overlap = $pdo->prepare(
                'SELECT id
                   FROM payroll_work_calendars
                  WHERE supplier_id = ?
                    AND employment_id = ?
                    AND valid_from <= COALESCE(?, ?)
                    AND (valid_to IS NULL OR valid_to >= ?)
                  LIMIT 1
                  FOR UPDATE'
            );
            $overlap->execute([
                $supplierId,
                $employmentId,
                $validTo,
                '9999-12-31',
                $validFrom,
            ]);
            if ($overlap->fetchColumn() !== false) {
                throw new \InvalidArgumentException('Platnost pracovních kalendářů se nesmí překrývat.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO payroll_work_calendars
                    (supplier_id, employment_id, name, timezone_name, schedule_type,
                     week_pattern, weekly_minutes, valid_from, valid_to, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $employmentId,
                $name,
                $timezone,
                $scheduleType,
                json_encode($weekPattern, JSON_THROW_ON_ERROR),
                $weeklyMinutes,
                $validFrom,
                $validTo,
                $userId,
            ]);
            $calendarId = (int) $pdo->lastInsertId();
            $dayInsert = $pdo->prepare(
                'INSERT INTO payroll_calendar_days
                    (supplier_id, calendar_id, day_date, day_kind, planned_minutes,
                     holiday_code, holiday_name, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($days as $day) {
                $dayInsert->execute([
                    $supplierId,
                    $calendarId,
                    $day['day_date'],
                    $day['day_kind'],
                    $day['planned_minutes'],
                    $day['holiday_code'],
                    $day['holiday_name'],
                    $day['note'],
                    $userId,
                ]);
            }
            $this->touchMonth($month, $userId);
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }

        return $this->calendar($supplierId, $employmentId, $validFrom)
            ?? throw new \RuntimeException('Uložený kalendář se nepodařilo načíst.');
    }

    /** @return array{shift:array<string,mixed>,month:array<string,mixed>} */
    public function saveShift(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $startsAtUtc,
        string $endsAtUtc,
        string $timezone,
        int $breakMinutes,
        bool $remoteWork,
        int $standbyMinutes,
        bool $publish,
        ?int $supersedesId,
        ?int $calendarId,
        int $expectedVersion,
        int $monthVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $month = $this->lockOpenMonth(
                $supplierId,
                $employmentId,
                $periodStart,
                $monthVersion,
                $userId,
            );
            if ($calendarId !== null) {
                $this->assertCalendarBelongsToEmployment(
                    $supplierId,
                    $employmentId,
                    $calendarId,
                );
            }
            $seriesKey = bin2hex(random_bytes(16));
            $revision = 1;
            if ($supersedesId !== null) {
                $current = $this->lockShift($supplierId, $employmentId, $supersedesId);
                $currentVersion = PayrollTimeValue::int($current['row_version'] ?? null, 'row_version');
                if ($currentVersion !== $expectedVersion) {
                    throw new PayrollTimeConflictException($currentVersion);
                }
                $seriesKey = PayrollTimeValue::string($current['series_key'] ?? null, 'series_key');
                $revision = PayrollTimeValue::int($current['revision_no'] ?? null, 'revision_no') + 1;
                $pdo->prepare(
                    "UPDATE payroll_shifts
                        SET status = 'superseded', row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ? AND row_version = ?"
                )->execute([$supplierId, $supersedesId, $expectedVersion]);
            } elseif ($expectedVersion !== 0) {
                throw new PayrollTimeConflictException(0);
            }

            $insert = $pdo->prepare(
                'INSERT INTO payroll_shifts
                    (supplier_id, employment_id, calendar_id, series_key, revision_no,
                     supersedes_id, starts_at_utc, ends_at_utc, timezone_name,
                     break_minutes, remote_work, standby_minutes, status,
                     created_by, published_by, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $employmentId,
                $calendarId,
                $seriesKey,
                $revision,
                $supersedesId,
                $startsAtUtc,
                $endsAtUtc,
                $timezone,
                $breakMinutes,
                $remoteWork ? 1 : 0,
                $standbyMinutes,
                $publish ? 'published' : 'draft',
                $userId,
                $publish ? $userId : null,
                $publish ? gmdate('Y-m-d H:i:s') : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            $month = $this->touchMonth($month, $userId);
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }
        return [
            'shift' => $this->findShift($supplierId, $id)
                ?? throw new \RuntimeException('Uloženou směnu se nepodařilo načíst.'),
            'month' => $month,
        ];
    }

    /** @return array{entry:array<string,mixed>,month:array<string,mixed>} */
    public function saveEntry(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $category,
        string $startsAtUtc,
        string $endsAtUtc,
        string $timezone,
        int $breakMinutes,
        string $sourceKind,
        ?string $sourceReference,
        string $sourceHash,
        ?int $supersedesId,
        int $expectedVersion,
        int $monthVersion,
        ?int $userId,
        /**
         * § 117 — počet ztěžujících vlivů tohoto zápisu (migrace 1625).
         * `null` = platí obvyklý počet ze sjednané zásady pracovního vztahu.
         */
        ?int $difficultyFactorCount = null,
    ): array {
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $month = $this->lockOpenMonth(
                $supplierId,
                $employmentId,
                $periodStart,
                $monthVersion,
                $userId,
            );
            $seriesKey = bin2hex(random_bytes(16));
            $revision = 1;
            if ($supersedesId !== null) {
                $current = $this->lockEntry($supplierId, $employmentId, $supersedesId);
                $currentVersion = PayrollTimeValue::int($current['row_version'] ?? null, 'row_version');
                if ($currentVersion !== $expectedVersion) {
                    throw new PayrollTimeConflictException($currentVersion);
                }
                $seriesKey = PayrollTimeValue::string($current['series_key'] ?? null, 'series_key');
                $revision = PayrollTimeValue::int($current['revision_no'] ?? null, 'revision_no') + 1;
                $pdo->prepare(
                    "UPDATE payroll_time_entries
                        SET status = 'superseded', row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ? AND row_version = ?"
                )->execute([$supplierId, $supersedesId, $expectedVersion]);
            } elseif ($expectedVersion !== 0) {
                throw new PayrollTimeConflictException(0);
            }

            $this->assertEntryNoOverlap(
                $supplierId,
                $employmentId,
                $category,
                $startsAtUtc,
                $endsAtUtc,
                $supersedesId,
            );
            $insert = $pdo->prepare(
                'INSERT INTO payroll_time_entries
                    (supplier_id, employment_id, series_key, revision_no, supersedes_id,
                     category, starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                     source_kind, source_reference, source_hash, created_by,
                     difficulty_factor_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $employmentId,
                $seriesKey,
                $revision,
                $supersedesId,
                $category,
                $startsAtUtc,
                $endsAtUtc,
                $timezone,
                $breakMinutes,
                $sourceKind,
                $sourceReference,
                $sourceHash,
                $userId,
                $difficultyFactorCount,
            ]);
            $id = (int) $pdo->lastInsertId();
            $month = $this->touchMonth($month, $userId);
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }
        return [
            'entry' => $this->findEntry($supplierId, $id)
                ?? throw new \RuntimeException('Uložený čas se nepodařilo načíst.'),
            'month' => $month,
        ];
    }

    /**
     * @param array<string,mixed>|null $jmhzWorkSummaryInput
     * @return array<string,mixed>
     */
    public function approveMonth(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        int $expectedVersion,
        ?array $jmhzWorkSummaryInput,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $month = $this->lockOpenMonth(
                $supplierId,
                $employmentId,
                $periodStart,
                $expectedVersion,
                $userId,
            );
            [$startsAtUtc, $endsAtUtc] = self::utcMonthBounds($periodStart);
            [$candidateStart, $candidateEnd] = self::candidateBounds(
                $startsAtUtc,
                $endsAtUtc,
            );
            $draftShifts = $pdo->prepare(
                "SELECT id, starts_at_utc, timezone_name
                   FROM payroll_shifts
                  WHERE supplier_id = ? AND employment_id = ?
                    AND status = 'draft'
                    AND starts_at_utc >= ? AND starts_at_utc < ?
                  FOR UPDATE"
            );
            $draftShifts->execute([
                $supplierId,
                $employmentId,
                $candidateStart,
                $candidateEnd,
            ]);
            foreach (self::rows($draftShifts) as $draftShift) {
                if (self::startsInPeriod($draftShift, $periodStart)) {
                    throw new \InvalidArgumentException(
                        'Před schválením měsíce publikujte všechny plánované směny.'
                    );
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            $draftEntries = $pdo->prepare(
                "SELECT id, starts_at_utc, timezone_name
                   FROM payroll_time_entries
                  WHERE supplier_id = ? AND employment_id = ?
                    AND status = 'draft'
                    AND starts_at_utc >= ? AND starts_at_utc < ?
                  FOR UPDATE"
            );
            $draftEntries->execute([
                $supplierId,
                $employmentId,
                $candidateStart,
                $candidateEnd,
            ]);
            $approveEntry = $pdo->prepare(
                "UPDATE payroll_time_entries
                    SET status = 'approved', approved_by = ?, approved_at = ?
                  WHERE supplier_id = ? AND employment_id = ? AND id = ?
                    AND status = 'draft'"
            );
            foreach (self::rows($draftEntries) as $draftEntry) {
                if (!self::startsInPeriod($draftEntry, $periodStart)) {
                    continue;
                }
                $approveEntry->execute([
                    $userId,
                    $now,
                    $supplierId,
                    $employmentId,
                    PayrollTimeValue::int($draftEntry['id'] ?? null, 'id'),
                ]);
            }
            $confirmedJmhzSummary = null;
            $jmhzSpecPackageId = null;
            if ($jmhzWorkSummaryInput !== null) {
                JmhzScenarioRequirementSourceCatalog::load();
                $jmhzSpecPackageId = $this->jmhzSpecPackages->install(
                    $this->jmhzSpecCatalog->load(
                        JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                        JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                    ),
                );
                $confirmedJmhzSummary = $this->jmhzWorkSummary->confirm(
                    $this->jmhzWorkSummary->preview(
                        $supplierId,
                        $employmentId,
                        $periodStart,
                        true,
                    ),
                    $jmhzWorkSummaryInput,
                );
            }
            $nextVersion = PayrollTimeValue::int($month['row_version'] ?? null, 'row_version') + 1;
            $update = $pdo->prepare(
                "UPDATE payroll_time_months
                    SET status = 'approved',
                        row_version = ?,
                        last_changed_by = ?,
                        approved_by = ?,
                        approved_at = ?,
                        reopened_by = NULL,
                        reopened_at = NULL,
                        reopen_reason = NULL
                  WHERE supplier_id = ? AND id = ? AND row_version = ?"
            );
            $update->execute([
                $nextVersion,
                $userId,
                $userId,
                $now,
                $supplierId,
                PayrollTimeValue::int($month['id'] ?? null, 'id'),
                PayrollTimeValue::int($month['row_version'] ?? null, 'row_version'),
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollTimeConflictException($nextVersion);
            }
            $month['status'] = 'approved';
            $month['row_version'] = $nextVersion;
            $month['approved_by'] = $userId;
            $month['approved_at'] = $now;
            $jmhzSummaryRevision = $confirmedJmhzSummary === null
                ? null
                : $this->insertJmhzWorkSummary(
                    $month,
                    $confirmedJmhzSummary,
                    $jmhzSpecPackageId,
                    $userId,
                    $now,
                );
            $this->insertMonthEvent(
                $month,
                'approved',
                null,
                $userId,
                $jmhzSummaryRevision,
            );
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }
        return $this->monthState($supplierId, $employmentId, $periodStart)
            ?? throw new \RuntimeException('Schválený měsíc se nepodařilo načíst.');
    }

    /** @return array<string,mixed> */
    public function reopenMonth(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        int $expectedVersion,
        string $reason,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $stmt = $pdo->prepare(
                'SELECT *
                   FROM payroll_time_months
                  WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                  FOR UPDATE'
            );
            $stmt->execute([$supplierId, $employmentId, $periodStart]);
            $month = self::row($stmt);
            if ($month === null) {
                throw new \InvalidArgumentException('Měsíc dosud nebyl schválen.');
            }
            $currentVersion = PayrollTimeValue::int($month['row_version'] ?? null, 'row_version');
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollTimeConflictException($currentVersion);
            }
            if (PayrollTimeValue::string($month['status'] ?? null, 'status') !== 'approved') {
                throw new \InvalidArgumentException('Znovu otevřít lze pouze schválený měsíc.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $nextVersion = $expectedVersion + 1;
            $nextRevision = PayrollTimeValue::int($month['revision_no'] ?? null, 'revision_no') + 1;
            $pdo->prepare(
                "UPDATE payroll_time_months
                    SET status = 'open',
                        revision_no = ?,
                        row_version = ?,
                        last_changed_by = ?,
                        approved_by = NULL,
                        approved_at = NULL,
                        reopened_by = ?,
                        reopened_at = ?,
                        reopen_reason = ?
                  WHERE supplier_id = ? AND id = ? AND row_version = ?"
            )->execute([
                $nextRevision,
                $nextVersion,
                $userId,
                $userId,
                $now,
                $reason,
                $supplierId,
                PayrollTimeValue::int($month['id'] ?? null, 'id'),
                $expectedVersion,
            ]);
            $month['status'] = 'open';
            $month['revision_no'] = $nextRevision;
            $month['row_version'] = $nextVersion;
            $month['reopen_reason'] = $reason;
            $this->insertMonthEvent($month, 'reopened', $reason, $userId);
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }
        return $this->monthState($supplierId, $employmentId, $periodStart)
            ?? throw new \RuntimeException('Otevřený měsíc se nepodařilo načíst.');
    }

    /** @return array<string,mixed>|null */
    public function monthState(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        return self::row($stmt);
    }

    /** @return int|null */
    public function resolveEmployment(
        int $supplierId,
        ?int $employmentId,
        ?string $employmentCode,
    ): ?int {
        if ($employmentId !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, code
                   FROM payroll_employments
                  WHERE supplier_id = ? AND id = ?'
            );
            $stmt->execute([$supplierId, $employmentId]);
            $row = self::row($stmt);
            if ($row === null) {
                return null;
            }
            if ($employmentCode !== null
                && PayrollTimeValue::string($row['code'] ?? null, 'code') !== $employmentCode
            ) {
                return null;
            }
            return PayrollTimeValue::int($row['id'] ?? null, 'id');
        }
        if ($employmentCode === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_employments
              WHERE supplier_id = ? AND code = ?
              ORDER BY id
              LIMIT 2'
        );
        $stmt->execute([$supplierId, $employmentCode]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? PayrollTimeValue::int($ids[0], 'id') : null;
    }

    public function hasEntrySourceHash(
        int $supplierId,
        int $employmentId,
        string $sourceHash,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
               FROM payroll_time_entries
              WHERE supplier_id = ?
                AND employment_id = ?
                AND source_kind = 'import'
                AND source_hash = ?
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $employmentId, $sourceHash]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function importByHash(int $supplierId, string $periodStart, string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_time_imports
              WHERE supplier_id = ? AND period_start = ? AND content_hash = ?'
        );
        $stmt->execute([$supplierId, $periodStart, $hash]);
        $import = self::row($stmt);
        if ($import !== null) {
            $import['errors'] = $this->importErrors(
                $supplierId,
                PayrollTimeValue::int($import['id'] ?? null, 'id'),
            );
        }
        return $import;
    }

    /**
     * @param list<array{
     *   row_number:int,
     *   error_code:string,
     *   field_name:?string,
     *   error_message:string,
     *   row_hash:string
     * }> $errors
     * @return array<string,mixed>
     */
    public function recordImport(
        int $supplierId,
        string $periodStart,
        string $format,
        string $originalName,
        string $contentHash,
        string $status,
        int $total,
        int $accepted,
        int $rejected,
        int $duplicates,
        array $errors,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO payroll_time_imports
                    (supplier_id, period_start, format, original_name, content_hash,
                     status, total_rows, accepted_rows, rejected_rows, duplicate_rows,
                     created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $periodStart,
                $format,
                $originalName,
                $contentHash,
                $status,
                $total,
                $accepted,
                $rejected,
                $duplicates,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $errorInsert = $pdo->prepare(
                'INSERT INTO payroll_time_import_errors
                    (supplier_id, import_id, csv_row_number, error_code, field_name,
                     error_message, row_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($errors as $error) {
                $errorInsert->execute([
                    $supplierId,
                    $id,
                    $error['row_number'],
                    $error['error_code'],
                    $error['field_name'],
                    $error['error_message'],
                    $error['row_hash'],
                ]);
            }
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }
        return $this->importByHash($supplierId, $periodStart, $contentHash)
            ?? throw new \RuntimeException('Import se nepodařilo načíst.');
    }

    /** @return list<array<string,mixed>> */
    private function importErrors(int $supplierId, int $importId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT csv_row_number AS `row_number`, error_code, field_name, error_message
               FROM payroll_time_import_errors
              WHERE supplier_id = ? AND import_id = ?
              ORDER BY csv_row_number, id'
        );
        $stmt->execute([$supplierId, $importId]);
        return self::rows($stmt);
    }

    private function beginTransactionScope(): ?string
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            return null;
        }
        $savepoint = 'payroll_time_' . bin2hex(random_bytes(6));
        $pdo->exec("SAVEPOINT {$savepoint}");
        return $savepoint;
    }

    private function commitTransactionScope(?string $savepoint): void
    {
        if ($savepoint === null) {
            $this->db->pdo()->commit();
            return;
        }
        $this->db->pdo()->exec("RELEASE SAVEPOINT {$savepoint}");
    }

    private function rollBackTransactionScope(?string $savepoint): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($savepoint === null) {
            $pdo->rollBack();
            return;
        }
        $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
        $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
    }

    private function lockEmployment(int $supplierId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
    }

    /** @return array<string,mixed> */
    private function lockShift(int $supplierId, int $employmentId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT *
               FROM payroll_shifts
              WHERE supplier_id = ? AND employment_id = ? AND id = ?
                AND status <> 'superseded'
              FOR UPDATE"
        );
        $stmt->execute([$supplierId, $employmentId, $id]);
        return self::row($stmt)
            ?? throw new \InvalidArgumentException('Původní směna nebyla nalezena.');
    }

    /** @return array<string,mixed> */
    private function lockEntry(int $supplierId, int $employmentId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT *
               FROM payroll_time_entries
              WHERE supplier_id = ? AND employment_id = ? AND id = ?
                AND status <> 'superseded'
              FOR UPDATE"
        );
        $stmt->execute([$supplierId, $employmentId, $id]);
        return self::row($stmt)
            ?? throw new \InvalidArgumentException('Původní záznam času nebyl nalezen.');
    }

    private function assertEntryNoOverlap(
        int $supplierId,
        int $employmentId,
        string $category,
        string $startsAtUtc,
        string $endsAtUtc,
        ?int $excludedId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id
               FROM payroll_time_entries
              WHERE supplier_id = ?
                AND employment_id = ?
                AND category = ?
                AND status <> 'superseded'
                AND starts_at_utc < ?
                AND ends_at_utc > ?
                AND (? IS NULL OR id <> ?)
              LIMIT 1
              FOR UPDATE"
        );
        $stmt->execute([
            $supplierId,
            $employmentId,
            $category,
            $endsAtUtc,
            $startsAtUtc,
            $excludedId,
            $excludedId,
        ]);
        if ($stmt->fetchColumn() !== false) {
            throw new \InvalidArgumentException(
                'Čas stejné příplatkové kategorie se nesmí překrývat; zabránilo by to správnému součtu.'
            );
        }
    }

    private function assertCalendarBelongsToEmployment(
        int $supplierId,
        int $employmentId,
        int $calendarId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_work_calendars
              WHERE supplier_id = ? AND employment_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId, $calendarId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Pracovní kalendář nepatří zvolenému pracovnímu vztahu.'
            );
        }
    }

    private function assertCalendarRangeUnlocked(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        ?string $validTo,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
               FROM payroll_time_months
              WHERE supplier_id = ?
                AND employment_id = ?
                AND status = 'approved'
                AND period_start >= ?
                AND (? IS NULL OR period_start <= ?)
              LIMIT 1
              FOR UPDATE"
        );
        $validToPeriod = $validTo === null ? null : substr($validTo, 0, 7) . '-01';
        $stmt->execute([
            $supplierId,
            $employmentId,
            $periodStart,
            $validToPeriod,
            $validToPeriod,
        ]);
        if ($stmt->fetchColumn() !== false) {
            throw new PayrollTimeLockedException(
                'Rozsah kalendáře zasahuje do schváleného měsíce; nejprve jej znovu otevřete.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function lockOpenMonth(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $this->lockEmployment($supplierId, $employmentId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $month = self::row($stmt);
        if ($month === null) {
            if ($expectedVersion !== 0) {
                throw new PayrollTimeConflictException(0);
            }
            $insert = $this->db->pdo()->prepare(
                'INSERT INTO payroll_time_months
                    (supplier_id, employment_id, period_start, last_changed_by)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$supplierId, $employmentId, $periodStart, $userId]);
            $id = (int) $this->db->pdo()->lastInsertId();
            $month = [
                'id' => $id,
                'supplier_id' => $supplierId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'status' => 'open',
                'revision_no' => 1,
                'row_version' => 1,
            ];
            $this->insertMonthEvent($month, 'created', null, $userId);
            return $month;
        }
        $currentVersion = PayrollTimeValue::int($month['row_version'] ?? null, 'row_version');
        if ($currentVersion !== $expectedVersion) {
            throw new PayrollTimeConflictException($currentVersion);
        }
        if (PayrollTimeValue::string($month['status'] ?? null, 'status') !== 'open') {
            throw new PayrollTimeLockedException();
        }
        return $month;
    }

    /**
     * @param array<string,mixed> $month
     * @return array<string,mixed>
     */
    private function touchMonth(array $month, ?int $userId): array
    {
        $currentVersion = PayrollTimeValue::int($month['row_version'] ?? null, 'row_version');
        $nextVersion = $currentVersion + 1;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_time_months
                SET row_version = ?, last_changed_by = ?
              WHERE supplier_id = ? AND id = ? AND row_version = ?'
        );
        $stmt->execute([
            $nextVersion,
            $userId,
            PayrollTimeValue::int($month['supplier_id'] ?? null, 'supplier_id'),
            PayrollTimeValue::int($month['id'] ?? null, 'id'),
            $currentVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new PayrollTimeConflictException($nextVersion);
        }
        $month['row_version'] = $nextVersion;
        $this->insertMonthEvent($month, 'changed', null, $userId);
        return $month;
    }

    /**
     * @param array<string,mixed> $month
     * @param array<string,mixed>|null $jmhzSummaryRevision
     */
    private function insertMonthEvent(
        array $month,
        string $action,
        ?string $reason,
        ?int $userId,
        ?array $jmhzSummaryRevision = null,
    ): void {
        $snapshot = [
            'employment_id' => PayrollTimeValue::int($month['employment_id'] ?? null, 'employment_id'),
            'period_start' => PayrollTimeValue::string($month['period_start'] ?? null, 'period_start'),
            'status' => PayrollTimeValue::string($month['status'] ?? null, 'status'),
            'revision_no' => PayrollTimeValue::int($month['revision_no'] ?? null, 'revision_no'),
            'row_version' => PayrollTimeValue::int($month['row_version'] ?? null, 'row_version'),
        ];
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_month_events
                (supplier_id, time_month_id, revision_no, action, reason,
                 snapshot_hash, jmhz_work_summary_revision_id,
                 jmhz_work_summary_hash, actor_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            PayrollTimeValue::int($month['supplier_id'] ?? null, 'supplier_id'),
            PayrollTimeValue::int($month['id'] ?? null, 'id'),
            PayrollTimeValue::int($month['revision_no'] ?? null, 'revision_no'),
            $action,
            $reason,
            hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), true),
            $jmhzSummaryRevision['id'] ?? null,
            $jmhzSummaryRevision['summary_sha256'] ?? null,
            $userId,
        ]);
    }

    /**
     * @param array<string,mixed> $month
     * @param array<string,mixed> $summary
     * @return array{id:int,summary_sha256:string}
     */
    private function insertJmhzWorkSummary(
        array $month,
        array $summary,
        int $specPackageId,
        ?int $userId,
        string $approvedAt,
    ): array {
        $values = PayrollTimeValue::row($summary['values'] ?? null, 'values');
        $provenance = PayrollTimeValue::row($summary['provenance'] ?? null, 'provenance');
        $interactions = PayrollTimeValue::row(
            $summary['interactions'] ?? null,
            'interactions',
        );
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_work_month_revisions
                (supplier_id, employment_id, time_month_id, time_month_revision_no,
                 period_start, spec_package_id, spec_manifest_sha256,
                 scenario_catalog_key, scenario_manifest_sha256,
                 control_catalog_key, control_manifest_sha256,
                 derivation_version, source_snapshot_json,
                 source_snapshot_sha256, standard_fund_millihours,
                 agreed_fund_millihours, weekly_work_centihours, evidence_days,
                 worked_millihours, conditional_blocks_confirmed,
                 unworked_hours_occurred, work_obstacles_occurred,
                 unworked_total_millihours, unworked_paid_millihours,
                 dpn_without_employer_compensation_millihours,
                 dpn_with_employer_compensation_millihours, vacation_millihours,
                 care_millihours, employee_obstacle_paid_millihours,
                 employer_obstacle_millihours, confirmation_note, provenance_json, summary_sha256,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $specification = PayrollTimeValue::row(
            $summary['specification'] ?? null,
            'specification',
        );
        $stmt->execute([
            PayrollTimeValue::int($month['supplier_id'] ?? null, 'supplier_id'),
            PayrollTimeValue::int($month['employment_id'] ?? null, 'employment_id'),
            PayrollTimeValue::int($month['id'] ?? null, 'time_month_id'),
            PayrollTimeValue::int($month['revision_no'] ?? null, 'revision_no'),
            PayrollTimeValue::string($month['period_start'] ?? null, 'period_start'),
            $specPackageId,
            PayrollTimeValue::string(
                $specification['spec_manifest_sha256'] ?? null,
                'spec_manifest_sha256',
            ),
            PayrollTimeValue::string(
                $specification['scenario_catalog_key'] ?? null,
                'scenario_catalog_key',
            ),
            PayrollTimeValue::string(
                $specification['scenario_manifest_sha256'] ?? null,
                'scenario_manifest_sha256',
            ),
            PayrollTimeValue::string(
                $specification['control_catalog_key'] ?? null,
                'control_catalog_key',
            ),
            PayrollTimeValue::string(
                $specification['control_manifest_sha256'] ?? null,
                'control_manifest_sha256',
            ),
            PayrollTimeValue::string($summary['derivation_version'] ?? null, 'derivation_version'),
            PayrollTimeValue::string($summary['source_snapshot_json'] ?? null, 'source_snapshot_json'),
            PayrollTimeValue::string($summary['source_snapshot_sha256'] ?? null, 'source_snapshot_sha256'),
            PayrollTimeValue::int($values['standard_fund_millihours'] ?? null, 'standard_fund_millihours'),
            PayrollTimeValue::int($values['agreed_fund_millihours'] ?? null, 'agreed_fund_millihours'),
            PayrollTimeValue::int($values['weekly_work_centihours'] ?? null, 'weekly_work_centihours'),
            PayrollTimeValue::int($values['evidence_days'] ?? null, 'evidence_days'),
            PayrollTimeValue::int($values['worked_millihours'] ?? null, 'worked_millihours'),
            1,
            PayrollTimeValue::bool($interactions['IN07'] ?? null, 'IN07') ? 1 : 0,
            PayrollTimeValue::bool($interactions['IN08'] ?? null, 'IN08') ? 1 : 0,
            $values['unworked_total_millihours'],
            $values['unworked_paid_millihours'],
            $values['dpn_without_employer_compensation_millihours'],
            $values['dpn_with_employer_compensation_millihours'],
            $values['vacation_millihours'],
            $values['care_millihours'],
            $values['employee_obstacle_paid_millihours'],
            $values['employer_obstacle_millihours'],
            PayrollTimeValue::string(
                $summary['confirmation_note'] ?? null,
                'confirmation_note',
            ),
            CanonicalJson::encode($provenance),
            PayrollTimeValue::string($summary['summary_sha256'] ?? null, 'summary_sha256'),
            $userId,
            $approvedAt,
        ]);
        return [
            'id' => (int) $this->db->pdo()->lastInsertId(),
            'summary_sha256' => (string) $summary['summary_sha256'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function findShift(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_shifts WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        return self::row($stmt);
    }

    /** @return array<string,mixed>|null */
    private function findEntry(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_time_entries WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        return self::row($stmt);
    }

    /** @return array<int,int> */
    private static function decodeWeekPattern(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $day => $minutes) {
            if (is_int($day) && is_int($minutes)) {
                $result[$day] = $minutes;
            }
        }
        return $result;
    }

    /** @return array{string,string} */
    private static function utcMonthBounds(string $periodStart): array
    {
        $timezone = new \DateTimeZone('Europe/Prague');
        $start = new \DateTimeImmutable($periodStart . ' 00:00:00', $timezone);
        $end = $start->modify('first day of next month');
        $utc = new \DateTimeZone('UTC');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array{string,string} */
    private static function candidateBounds(string $startsAtUtc, string $endsAtUtc): array
    {
        $timezone = new \DateTimeZone('UTC');
        return [
            (new \DateTimeImmutable($startsAtUtc, $timezone))
                ->modify('-1 day')
                ->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable($endsAtUtc, $timezone))
                ->modify('+1 day')
                ->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function startsInPeriod(array $row, string $periodStart): bool
    {
        $utc = new \DateTimeImmutable(
            PayrollTimeValue::string($row['starts_at_utc'] ?? null, 'starts_at_utc'),
            new \DateTimeZone('UTC'),
        );
        $timezone = new \DateTimeZone(
            PayrollTimeValue::string($row['timezone_name'] ?? null, 'timezone_name'),
        );
        return $utc->setTimezone($timezone)->format('Y-m')
            === substr($periodStart, 0, 7);
    }

    /** @return array<string,mixed>|null */
    private static function row(\PDOStatement $stmt): ?array
    {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? null
            : self::cast(PayrollTimeValue::row($row, 'pdo_row'));
    }

    /** @return list<array<string,mixed>> */
    private static function rows(\PDOStatement $stmt): array
    {
        $result = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'pdo_rows') as $row) {
            $result[] = self::cast($row);
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        $intFields = [
            'id', 'supplier_id', 'employee_id', 'employment_id', 'calendar_id',
            'revision_no', 'supersedes_id', 'break_minutes', 'standby_minutes',
            'row_version', 'weekly_minutes', 'planned_minutes', 'created_by',
            'published_by', 'approved_by', 'reopened_by', 'last_changed_by',
            'total_rows', 'accepted_rows', 'rejected_rows', 'duplicate_rows',
            'row_number',
        ];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = PayrollTimeValue::int($row[$field], $field);
            }
        }
        if (array_key_exists('remote_work', $row)) {
            $row['remote_work'] = PayrollTimeValue::bool($row['remote_work'], 'remote_work');
        }
        unset($row['source_hash'], $row['content_hash'], $row['snapshot_hash'], $row['row_hash']);
        return $row;
    }
}
