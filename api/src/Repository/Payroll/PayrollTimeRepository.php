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
    /**
     * Interní důvod prvního ručního souhrnu bez zadaného důvodu (vzor
     * {@see PayrollHourlyEvidenceRepository::INITIAL_REASON}). Navenek se
     * vrací jako `reason: null` a obrazovka ukáže přeložený popisek.
     */
    public const MANUAL_SUMMARY_INITIAL_REASON = 'Prvotní zadání hodin';

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
     * `$employmentId` zužuje výpis už v SQL. Dokud se zužovalo až v PHP nad
     * načteným měsícem, tahal se kvůli jednomu člověku celý seznam firmy —
     * u historie po měsících by se ta práce opakovala dvanáctkrát.
     *
     * @param string $periodEnd první den následujícího měsíce (výlučná hranice)
     * @return list<array<string,mixed>>
     */
    public function employments(
        int $supplierId,
        string $periodStart,
        string $periodEnd,
        ?int $employmentId = null,
        /** Hledání podle jména nebo kódu vztahu; mřížka má stovky řádků po 25. */
        ?string $search = null,
    ): array {
        $periodLastDay = (new \DateTimeImmutable($periodEnd))
            ->modify('-1 day')
            ->format('Y-m-d');
        $narrowing = $employmentId === null ? '' : ' AND employment.id = ?';
        $search = $search === null ? '' : trim($search);
        $searchParams = [];
        if ($search !== '') {
            $narrowing .= ' AND (employee.full_name LIKE ? OR employment.code LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $searchParams = [$like, $like];
        }
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
                    )' . $narrowing . '
              ORDER BY employee.full_name, employment.code, employment.id'
        );
        $stmt->execute([
            $periodLastDay,
            $supplierId,
            $periodEnd,
            $periodStart,
            $periodLastDay,
            ...($employmentId === null ? [] : [$employmentId]),
            ...$searchParams,
        ]);
        return self::rows($stmt);
    }

    /**
     * Vztah bez ohledu na období — pro kontrolu příslušnosti k firmě.
     *
     * `actual_start_date` a jméno tu jsou kvůli historii po měsících: rozsah
     * začíná měsícem SKUTEČNÉHO nástupu (sjednaný den se posouvá) a hlavička
     * výpisu ukazuje člověka, ne id.
     *
     * @return array<string,mixed>|null
     */
    public function employment(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.code,
                    employment.relation_type, employment.status,
                    employment.start_date, employment.actual_start_date,
                    employment.end_date, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id = ?'
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
        ?int $employmentId = null,
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
                AND starts_at_utc < ?"
            . ($employmentId === null ? '' : ' AND employment_id = ?') . '
              ORDER BY starts_at_utc, id'
        );
        $stmt->execute([
            $supplierId,
            $candidateStart,
            $candidateEnd,
            ...($employmentId === null ? [] : [$employmentId]),
        ]);
        return self::rows($stmt);
    }

    /** @return list<array<string,mixed>> */
    public function entries(
        int $supplierId,
        string $startsAtUtc,
        string $endsAtUtc,
        ?int $employmentId = null,
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
                AND starts_at_utc < ?"
            . ($employmentId === null ? '' : ' AND employment_id = ?') . '
              ORDER BY starts_at_utc, id'
        );
        $stmt->execute([
            $supplierId,
            $candidateStart,
            $candidateEnd,
            ...($employmentId === null ? [] : [$employmentId]),
        ]);
        return self::rows($stmt);
    }

    /** @return list<array<string,mixed>> */
    public function monthStates(
        int $supplierId,
        string $periodStart,
        ?int $employmentId = null,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_time_months
              WHERE supplier_id = ? AND period_start = ?'
            . ($employmentId === null ? '' : ' AND employment_id = ?')
        );
        $stmt->execute([
            $supplierId,
            $periodStart,
            ...($employmentId === null ? [] : [$employmentId]),
        ]);
        return self::rows($stmt);
    }

    /**
     * Krajní měsíce, ve kterých vztah vůbec něco v docházce má.
     *
     * Historie po měsících jede primárně od nástupu po ukončení, jenže
     * převzatá evidence z migrace umí ležet i mimo ten interval (doklad za
     * měsíc před sjednaným nástupem, doběh po ukončení). Kdyby se rozsah
     * počítal jen z dat vztahu, takový měsíc by ve výpisu tiše chyběl.
     *
     * Měsíc směny a zápisu se určuje z MÍSTNÍHO času (`timezone_name`),
     * stejně jako všude jinde v docházce — proto se vrací krajní řádky
     * a převod dělá volající, ne SQL.
     *
     * @return array{
     *     months:array{min:?string,max:?string},
     *     instants:list<array{starts_at_utc:string,timezone_name:string}>
     * }
     */
    public function dataPeriodEdges(int $supplierId, int $employmentId): array
    {
        $months = $this->db->pdo()->prepare(
            'SELECT MIN(period_start) AS min_period, MAX(period_start) AS max_period
               FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $months->execute([$supplierId, $employmentId]);
        $bounds = $months->fetch(PDO::FETCH_ASSOC);

        $instants = [];
        foreach (['payroll_shifts', 'payroll_time_entries'] as $table) {
            foreach (['ASC', 'DESC'] as $direction) {
                $edge = $this->db->pdo()->prepare(
                    "SELECT starts_at_utc, timezone_name
                       FROM {$table}
                      WHERE supplier_id = ?
                        AND employment_id = ?
                        AND status <> 'superseded'
                      ORDER BY starts_at_utc {$direction}
                      LIMIT 1"
                );
                $edge->execute([$supplierId, $employmentId]);
                $row = $edge->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $instants[] = [
                        'starts_at_utc' => (string) $row['starts_at_utc'],
                        'timezone_name' => (string) $row['timezone_name'],
                    ];
                }
            }
        }

        return [
            'months' => [
                'min' => is_array($bounds) && is_string($bounds['min_period'] ?? null)
                    ? $bounds['min_period']
                    : null,
                'max' => is_array($bounds) && is_string($bounds['max_period'] ?? null)
                    ? $bounds['max_period']
                    : null,
            ],
            'instants' => $instants,
        ];
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
                    summary.worked_millihours, summary.worked_days,
                    summary.overtime_millihours, summary.conditional_blocks_confirmed,
                    summary.unworked_hours_occurred, summary.work_obstacles_occurred,
                    summary.unworked_total_millihours, summary.unworked_paid_millihours,
                    summary.dpn_without_employer_compensation_millihours,
                    summary.dpn_with_employer_compensation_millihours,
                    summary.vacation_millihours, summary.care_millihours,
                    summary.employee_obstacle_paid_millihours,
                    summary.employer_obstacle_millihours, summary.holiday_time_off_millihours, summary.confirmation_note,
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
            // Dny a přesčas nese jen v4; u starších souhrnů zůstává NULL,
            // tedy NEUVEDENO, ne nula.
            'worked_days', 'overtime_millihours',
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
        if (in_array($row['derivation_version'], PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_HOLIDAY_TIME_OFF, true)) {
            $row['holiday_time_off_millihours'] = $row['holiday_time_off_millihours'] === null ? null : (int) $row['holiday_time_off_millihours'];
        } else {
            unset($row['holiday_time_off_millihours']);
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
            if (($month['work_source'] ?? 'entries') !== 'entries') {
                $sourceLabel = $month['work_source'] === 'import_summary' ? 'docházku ze souhrnu importu' : 'hodiny z ručního souhrnu';
                throw new \InvalidArgumentException(
                    "Pracovní měsíc bere {$sourceLabel}. Časový záznam by byl druhým "
                    . 'zdrojem téhož měsíce, proto se neuložil. Chcete-li evidovat docházku, '
                    . 'obnovte zdroj Docházka v panelu Odpracované hodiny.'
                );
            }
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
     * Zrušení chybné směny nebo záznamu času bez náhrady.
     *
     * Zrušená revize dostane stav `superseded` stejně jako revize nahrazená
     * opravou — přehled, fond, plán i souhrny JMHZ ji tím přestanou počítat,
     * a řádek zůstává v historii série. Měsíc musí být otevřený, schválený
     * měsíc se nejdřív znovu otevírá s důvodem.
     *
     * @param 'shift'|'entry' $kind
     * @return array<string,mixed> měsíc po změně
     */
    public function cancelRecord(
        int $supplierId,
        string $kind,
        int $employmentId,
        int $id,
        int $expectedVersion,
        int $monthVersion,
        ?int $userId,
    ): array {
        $table = $kind === 'shift' ? 'payroll_shifts' : 'payroll_time_entries';
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $current = $kind === 'shift'
                ? $this->lockShift($supplierId, $employmentId, $id)
                : $this->lockEntry($supplierId, $employmentId, $id);
            $periodStart = (new \DateTimeImmutable(
                PayrollTimeValue::string($current['starts_at_utc'] ?? null, 'starts_at_utc'),
                new \DateTimeZone('UTC'),
            ))->setTimezone(new \DateTimeZone(
                PayrollTimeValue::string($current['timezone_name'] ?? null, 'timezone_name'),
            ))->format('Y-m-01');
            $month = $this->lockOpenMonth($supplierId, $employmentId, $periodStart, $monthVersion, $userId);
            if ($kind === 'entry' && ($month['work_source'] ?? 'entries') !== 'entries') {
                throw new \InvalidArgumentException(
                    'Časové záznamy lze měnit jen při zvoleném zdroji docházky. Měsíc teď bere hodiny '
                    . 'z jiného zdroje; obnovte zdroj Docházka v panelu Odpracované hodiny a záznam pak upravte.'
                );
            }
            $currentVersion = PayrollTimeValue::int($current['row_version'] ?? null, 'row_version');
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollTimeConflictException($currentVersion);
            }
            $pdo->prepare(
                "UPDATE {$table}
                    SET status = 'superseded', row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?"
            )->execute([$supplierId, $id, $expectedVersion]);
            $month = $this->touchMonth($month, $userId);
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }

        return $month;
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
        /**
         * Způsob potvrzení souhrnu JMHZ; `null` = výslovné potvrzení účetní.
         * Z HTTP se nenastavuje — hromadné schválení importu ho předává samo.
         */
        ?string $jmhzConfirmationKind = null,
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
            $now = gmdate('Y-m-d H:i:s');
            if (($month['work_source'] ?? 'entries') !== 'manual_summary') {
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
            } elseif ($this->manualSummary($supplierId, $employmentId, $periodStart) === null) {
                throw new \InvalidArgumentException('Pracovní měsíc nemá ruční souhrn hodin.');
            } else {
                $this->assertManualSourceHasNoMaterializedAmounts($supplierId, $employmentId, $periodStart);
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
                    $jmhzConfirmationKind,
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
            $previousManual = ($month['work_source'] ?? 'entries') === 'manual_summary'
                ? $this->manualSummary($supplierId, $employmentId, $periodStart) : null;
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
            if ($previousManual !== null) {
                $pdo->prepare('INSERT INTO payroll_time_month_manual_summaries (supplier_id, time_month_id, time_month_revision_no, employment_id, period_start, content_sha256, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$supplierId, $month['id'], $nextRevision, $employmentId, $periodStart, $previousManual['content_sha256'], $reason, $userId]);
                $newSummaryId = (int) $pdo->lastInsertId();
                $copy = $pdo->prepare('INSERT INTO payroll_time_month_manual_segments (supplier_id, summary_id, sequence_no, date_from, date_to, worked_duration_units, overtime_duration_units, worked_days) SELECT supplier_id, ?, sequence_no, date_from, date_to, worked_duration_units, overtime_duration_units, worked_days FROM payroll_time_month_manual_segments WHERE supplier_id = ? AND summary_id = ?');
                $copy->execute([$newSummaryId, $supplierId, $previousManual['id']]);
            }
            $this->insertMonthEvent($month, 'reopened', $reason, $userId);
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }
        return $this->monthState($supplierId, $employmentId, $periodStart)
            ?? throw new \RuntimeException('Otevřený měsíc se nepodařilo načíst.');
    }

    /** Otisk zdrojů posledního schváleného pracovního souhrnu měsíce pro JMHZ, `null` bez souhrnu. */
    public function approvedWorkSummarySha(int $supplierId, int $employmentId, string $periodStart): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT source_snapshot_sha256
               FROM payroll_jmhz_work_month_revisions
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $sha = $stmt->fetchColumn();

        return is_string($sha) && $sha !== '' ? $sha : null;
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

    /** @return array<string,mixed>|null */
    public function manualSummary(int $supplierId, int $employmentId, string $periodStart): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT summary.id, summary.time_month_revision_no, summary.content_sha256, summary.reason
               FROM payroll_time_month_manual_summaries summary
               JOIN payroll_time_months month_row ON month_row.supplier_id = summary.supplier_id
                AND month_row.id = summary.time_month_id AND month_row.revision_no = summary.time_month_revision_no
              WHERE summary.supplier_id = ? AND summary.employment_id = ? AND summary.period_start = ?
                AND month_row.work_source = "manual_summary"'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $segments = $this->db->pdo()->prepare(
            'SELECT date_from, date_to, worked_duration_units, overtime_duration_units, worked_days
               FROM payroll_time_month_manual_segments WHERE supplier_id = ? AND summary_id = ? ORDER BY sequence_no'
        );
        $segments->execute([$supplierId, $row['id']]);
        $items = [];
        foreach ($segments->fetchAll(PDO::FETCH_ASSOC) as $segment) {
            $items[] = [
                'date_from' => (string) $segment['date_from'], 'date_to' => (string) $segment['date_to'],
                'worked_duration_units' => (int) $segment['worked_duration_units'],
                'overtime_duration_units' => $segment['overtime_duration_units'] === null ? null : (int) $segment['overtime_duration_units'],
                'worked_days' => $segment['worked_days'] === null ? null : (int) $segment['worked_days'],
            ];
        }
        return ['id' => (int) $row['id'], 'time_month_revision_no' => (int) $row['time_month_revision_no'],
            'content_sha256' => (string) $row['content_sha256'], 'reason' => self::publicReason((string) $row['reason']), 'segments' => $items];
    }

    /** @param list<int> $employmentIds @param list<string> $periods @return array<int,array<string,array<string,mixed>>> */
    public function selectedManualSummaries(int $supplierId, array $employmentIds, array $periods): array
    {
        if ($employmentIds === [] || $periods === []) return [];
        $ids = implode(',', array_fill(0, count($employmentIds), '?'));
        $dates = implode(',', array_fill(0, count($periods), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT summary.id, summary.employment_id, summary.period_start, summary.time_month_revision_no,
                summary.content_sha256, summary.reason, segment.date_from, segment.date_to,
                segment.worked_duration_units, segment.overtime_duration_units, segment.worked_days
            FROM payroll_time_months month_row FORCE INDEX (uq_payroll_time_month_period)
            JOIN payroll_time_month_manual_summaries summary ON summary.supplier_id = month_row.supplier_id
                AND summary.time_month_id = month_row.id AND summary.time_month_revision_no = month_row.revision_no
            LEFT JOIN payroll_time_month_manual_segments segment ON segment.supplier_id = summary.supplier_id AND segment.summary_id = summary.id
            WHERE month_row.supplier_id = ? AND month_row.employment_id IN ($ids)
                AND month_row.period_start IN ($dates) AND month_row.work_source = 'manual_summary'
            ORDER BY summary.id, segment.sequence_no");
        $stmt->execute([$supplierId, ...$employmentIds, ...$periods]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['employment_id'];
            $period = (string) $row['period_start'];
            $result[$id][$period] ??= ['id' => (int) $row['id'], 'time_month_revision_no' => (int) $row['time_month_revision_no'],
                'content_sha256' => (string) $row['content_sha256'], 'reason' => (string) $row['reason'], 'segments' => []];
            if ($row['date_from'] !== null) {
                $result[$id][$period]['segments'][] = ['date_from' => (string) $row['date_from'], 'date_to' => (string) $row['date_to'],
                    'worked_duration_units' => (int) $row['worked_duration_units'],
                    'overtime_duration_units' => $row['overtime_duration_units'] === null ? null : (int) $row['overtime_duration_units'],
                    'worked_days' => $row['worked_days'] === null ? null : (int) $row['worked_days']];
            }
        }
        return $result;
    }

    /** @param list<int> $employmentIds @param list<string> $periods @return array<int,array<string,array<string,mixed>>> */
    public function selectedImportSummaries(int $supplierId, array $employmentIds, array $periods): array
    {
        if ($employmentIds === [] || $periods === []) return [];
        $ids = implode(',', array_fill(0, count($employmentIds), '?'));
        $dates = implode(',', array_fill(0, count($periods), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT summary.employment_id, month_row.period_start, summary.values_json, summary.worked_days
            FROM payroll_time_months month_row FORCE INDEX (uq_payroll_time_month_period)
            JOIN payroll_time_month_import_summaries summary ON summary.supplier_id = month_row.supplier_id
                AND summary.time_month_id = month_row.id AND summary.time_month_revision_no = month_row.revision_no
            WHERE month_row.supplier_id = ? AND month_row.employment_id IN ($ids)
                AND month_row.period_start IN ($dates) AND month_row.work_source = 'import_summary'");
        $stmt->execute([$supplierId, ...$employmentIds, ...$periods]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][(string) $row['period_start']] = [
                'values' => json_decode((string) $row['values_json'], true, flags: JSON_THROW_ON_ERROR),
                'worked_days' => $row['worked_days'] === null ? null : (int) $row['worked_days'],
            ];
        }
        return $result;
    }

    /**
     * Účinnost hodinových podmínek vztahů v rozsahu dnů, jedním dotazem.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<array{effective_from:string,effective_to:?string}>>
     */
    public function hourlyRemunerationRanges(int $supplierId, array $employmentIds, string $from, string $to): array
    {
        if ($employmentIds === []) return [];
        $ids = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT employment_id, effective_from, effective_to
            FROM payroll_employment_terms FORCE INDEX (idx_payroll_employment_term_effective)
            WHERE supplier_id = ? AND employment_id IN ($ids) AND remuneration_type = 'hourly'
              AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)");
        $stmt->execute([$supplierId, ...$employmentIds, $to, $from]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][] = [
                'effective_from' => (string) $row['effective_from'],
                'effective_to' => $row['effective_to'] === null ? null : (string) $row['effective_to'],
            ];
        }
        return $result;
    }

    /** @param list<string> $periods @return array<string,list<array<string,mixed>>> */
    public function manualSummaryHistory(int $supplierId, int $employmentId, array $periods, int $offset = 0): array
    {
        if ($periods === []) return [];
        $in = implode(',', array_fill(0, count($periods), '?'));
        $stmt = $this->db->pdo()->prepare("WITH ranked AS (
            SELECT summary.*, ROW_NUMBER() OVER (PARTITION BY summary.period_start ORDER BY summary.time_month_revision_no DESC, summary.id DESC) AS position
              FROM payroll_time_month_manual_summaries summary
             WHERE summary.supplier_id = ? AND summary.employment_id = ? AND summary.period_start IN ($in)
        ) SELECT ranked.id, ranked.period_start, ranked.time_month_revision_no, ranked.content_sha256,
                 ranked.reason, ranked.created_by, ranked.created_at, actor.name AS created_by_name,
                 segment.date_from, segment.date_to, segment.worked_duration_units, segment.overtime_duration_units, segment.worked_days
            FROM ranked LEFT JOIN users actor ON actor.id = ranked.created_by
            LEFT JOIN payroll_time_month_manual_segments segment ON segment.supplier_id = ranked.supplier_id AND segment.summary_id = ranked.id
           WHERE ranked.position > ? AND ranked.position <= ? ORDER BY ranked.period_start, ranked.position, segment.sequence_no");
        $stmt->execute([$supplierId, $employmentId, ...$periods, $offset, $offset + 21]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $period = (string) $row['period_start'];
            $id = (int) $row['id'];
            $groups[$period][$id] ??= [
                'id' => $id, 'time_month_revision_no' => (int) $row['time_month_revision_no'],
                'content_sha256' => (string) $row['content_sha256'], 'reason' => self::publicReason((string) $row['reason']),
                'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
                'created_by_name' => $row['created_by_name'] === null ? null : (string) $row['created_by_name'],
                'created_at' => (string) $row['created_at'], 'segments' => [],
            ];
            if ($row['date_from'] !== null) {
                $groups[$period][$id]['segments'][] = [
                    'date_from' => (string) $row['date_from'], 'date_to' => (string) $row['date_to'],
                    'worked_duration_units' => (int) $row['worked_duration_units'],
                    'overtime_duration_units' => $row['overtime_duration_units'] === null ? null : (int) $row['overtime_duration_units'],
                    'worked_days' => $row['worked_days'] === null ? null : (int) $row['worked_days'],
                ];
            }
        }
        foreach ($groups as $period => $summaries) $groups[$period] = array_values($summaries);
        return $groups;
    }

    /** @param list<array<string,mixed>> $segments @return array<string,mixed> */
    public function saveManualSummary(int $supplierId, int $employmentId, string $periodStart, int $expectedVersion, ?string $reason, bool $replaceSource, array $segments, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $month = $this->lockOpenMonth($supplierId, $employmentId, $periodStart, $expectedVersion, $userId);
            $source = (string) ($month['work_source'] ?? 'entries');
            $terms = $this->monthTerms($supplierId, $employmentId, $periodStart);
            self::assertHourlyTerms($terms);
            if ($source !== 'manual_summary' && !$replaceSource
                && ($source === 'import_summary' || $this->hasEntriesInPeriod($supplierId, $employmentId, $periodStart))) {
                throw new \InvalidArgumentException('Měsíc již má jiný zdroj hodin; jeho nahrazení je nutné výslovně potvrdit.');
            }
            if ($source === 'import_summary') {
                $this->assertNoActiveImportInputs($supplierId, $employmentId, $periodStart);
            }
            $this->assertManualSourceHasNoMaterializedAmounts($supplierId, $employmentId, $periodStart);
            $required = [];
            foreach ($terms as $term) {
                foreach (self::monthDays($periodStart) as $day) {
                    if ($term['started_on'] !== null && $term['started_on'] <= $day
                        && ($term['end_date'] === null || $term['end_date'] >= $day)
                        && $term['effective_from'] <= $day
                        && ($term['effective_to'] === null || $term['effective_to'] >= $day)) {
                        $required[$day] = true;
                    }
                }
            }
            $covered = [];
            foreach ($segments as $segment) {
                $matching = 0;
                foreach ($terms as $term) {
                    if ($term['started_on'] !== null && $term['started_on'] <= $segment['date_from']
                        && ($term['end_date'] === null || $term['end_date'] >= $segment['date_to'])
                        && $term['effective_from'] <= $segment['date_from']
                        && ($term['effective_to'] === null || $term['effective_to'] >= $segment['date_to'])) {
                        ++$matching;
                    }
                }
                if ($matching !== 1) {
                    throw new \InvalidArgumentException('Každý úsek musí ležet v trvání vztahu a jedné verzi jeho podmínek.');
                }
                foreach (self::monthDays($periodStart) as $day) {
                    if ($day >= $segment['date_from'] && $day <= $segment['date_to']) {
                        $covered[$day] = true;
                    }
                }
            }
            $missing = array_keys(array_diff_key($required, $covered));
            if ($missing !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Úseky musí bez mezer pokrýt celé trvání vztahu v měsíci; chybí %s. Doplňte i dny bez práce (0 h).',
                    self::dayRanges($missing),
                ));
            }
            $existing = $this->manualSummary($supplierId, $employmentId, $periodStart);
            if ($reason === null) {
                // Důvod chce jen změna: oprava uloženého souhrnu nebo nahrazení
                // jiného zdroje. První zadání do prázdného měsíce ho nemá.
                if ($existing !== null || $source === 'import_summary'
                    || ($source !== 'manual_summary' && $this->hasEntriesInPeriod($supplierId, $employmentId, $periodStart))) {
                    throw new \InvalidArgumentException('Uveďte důvod změny hodin (nejvýše ' . \MyInvoice\Service\Payroll\Time\PayrollTimeService::SOURCE_REASON_MAX_LENGTH . ' znaků).');
                }
                $reason = self::MANUAL_SUMMARY_INITIAL_REASON;
            }
            // Souhrn importu se váže na revizi měsíce: bez nové revize by ho
            // čtení aktuálního importu dál vidělo vedle ručního souhrnu.
            if ($existing !== null || $source === 'import_summary') {
                $month['revision_no'] = (int) $month['revision_no'] + 1;
            }
            $version = (int) $month['row_version'];
            $stmt = $pdo->prepare('UPDATE payroll_time_months SET work_source = "manual_summary", revision_no = ?, row_version = row_version + 1, last_changed_by = ? WHERE supplier_id = ? AND id = ? AND row_version = ?');
            $stmt->execute([$month['revision_no'], $userId, $supplierId, $month['id'], $version]);
            if ($stmt->rowCount() !== 1) throw new PayrollTimeConflictException($version);
            $month['row_version'] = $version + 1;
            $month['work_source'] = 'manual_summary';
            $hash = hash('sha256', CanonicalJson::encode($segments));
            $pdo->prepare('INSERT INTO payroll_time_month_manual_summaries (supplier_id, time_month_id, time_month_revision_no, employment_id, period_start, content_sha256, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $month['id'], $month['revision_no'], $employmentId, $periodStart, $hash, $reason, $userId]);
            $summaryId = (int) $pdo->lastInsertId();
            $insert = $pdo->prepare('INSERT INTO payroll_time_month_manual_segments (supplier_id, summary_id, sequence_no, date_from, date_to, worked_duration_units, overtime_duration_units, worked_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($segments as $index => $segment) {
                $insert->execute([$supplierId, $summaryId, $index + 1, $segment['date_from'], $segment['date_to'], $segment['worked_duration_units'], $segment['overtime_duration_units'], $segment['worked_days']]);
            }
            $eventReason = $source === 'manual_summary' ? $reason : "Zdroj {$source} → manual_summary: {$reason}";
            $this->insertMonthEvent($month, 'changed', $eventReason, $userId);
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }
        return ['month' => $this->monthState($supplierId, $employmentId, $periodStart), 'manual_summary' => $this->manualSummary($supplierId, $employmentId, $periodStart)];
    }

    /**
     * Chybějící dny jako souvislé rozsahy („5.–7. 5., 20. 5."), aby účetní
     * nemusela hledat mezeru v kalendáři sama.
     *
     * @param list<string> $days dny Y-m-d
     */
    private static function dayRanges(array $days): string
    {
        sort($days);
        $ranges = [];
        foreach ($days as $day) {
            $last = array_key_last($ranges);
            if ($last !== null
                && (new \DateTimeImmutable($ranges[$last][1]))->modify('+1 day')->format('Y-m-d') === $day) {
                $ranges[$last][1] = $day;
                continue;
            }
            $ranges[] = [$day, $day];
        }
        $format = static fn (string $day): string => (int) substr($day, 8, 2) . '. ' . (int) substr($day, 5, 2) . '.';
        $parts = array_map(
            static fn (array $range): string => $range[0] === $range[1]
                ? $format($range[0])
                : (int) substr($range[0], 8, 2) . '.–' . $format($range[1]),
            $ranges,
        );

        return (count($days) === 1 ? 'den ' : 'dny ') . implode(', ', $parts);
    }

    private static function publicReason(string $reason): ?string
    {
        return $reason === self::MANUAL_SUMMARY_INITIAL_REASON ? null : $reason;
    }

    private function assertManualSourceHasNoMaterializedAmounts(int $supplierId, int $employmentId, string $periodStart): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT component_id FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ? AND period_start = ? AND source_kind = "time" AND status <> "cancelled" AND (external_id LIKE ? OR external_id LIKE ?) GROUP BY component_id HAVING SUM(amount_minor) <> 0 LIMIT 1');
        $stmt->execute([$supplierId, $employmentId, $periodStart, 'surcharge:%:' . $periodStart . ':%', 'standby:' . $periodStart . ':%']);
        if ($stmt->fetchColumn() !== false) {
            throw new PayrollTimeLockedException('Zdroj má navázané vypočtené příplatky nebo pohotovost; nejdříve je opravte nebo stornujte.');
        }
    }

    /**
     * Podmínky vztahu účinné v měsíci spolu s trváním vztahu.
     *
     * @return list<array<string,mixed>>
     */
    private function monthTerms(int $supplierId, int $employmentId, string $periodStart): array
    {
        $days = self::monthDays($periodStart);
        $stmt = $this->db->pdo()->prepare(
            'SELECT terms.effective_from, terms.effective_to, terms.remuneration_type,
                    COALESCE(employment.actual_start_date, employment.start_date) AS started_on, employment.end_date
               FROM payroll_employment_terms terms JOIN payroll_employments employment
               ON employment.supplier_id = terms.supplier_id AND employment.id = terms.employment_id
              WHERE terms.supplier_id = ? AND terms.employment_id = ?
                AND terms.effective_from <= ? AND (terms.effective_to IS NULL OR terms.effective_to >= ?)'
        );
        $stmt->execute([$supplierId, $employmentId, $days[count($days) - 1], $days[0]]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ruční souhrn a změna zdroje hodin slouží jen hodinovému výpočtu. Měsíčně
     * odměňovaný vztah drží zdroj docházky beze změny, jinak by se mu přepnulo
     * krácení mzdy a zůstaly by aktivní vstupy z importu.
     *
     * @param list<array<string,mixed>> $terms
     */
    private static function assertHourlyTerms(array $terms): void
    {
        foreach ($terms as $term) {
            if (($term['remuneration_type'] ?? null) === 'hourly') {
                return;
            }
        }
        throw new \InvalidArgumentException(
            'Ruční souhrn hodin a změnu zdroje hodin lze použít jen u vztahu s hodinovým odměňováním v tomto měsíci.'
        );
    }

    /**
     * Náhrady a částky založené importem docházky (`attendance:` a
     * `leave:attendance:`) vycházejí ze souhrnu importu. Po změně zdroje by
     * zůstaly aktivní vedle hodin z jiného zdroje a vyplatily se dvakrát.
     */
    private function assertNoActiveImportInputs(int $supplierId, int $employmentId, string $periodStart): void
    {
        $month = substr($periodStart, 0, 7);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ? AND status <> 'cancelled'
                AND (external_id LIKE ? OR external_id LIKE ?)
              LIMIT 1"
        );
        $stmt->execute([
            $supplierId, $employmentId, $periodStart,
            'attendance:' . $month . ':' . $employmentId . ':%',
            'leave:attendance:' . $month . ':' . $employmentId . ':%',
        ]);
        if ($stmt->fetchColumn() !== false) {
            throw new \InvalidArgumentException(
                'Měsíc má nestornované mzdové vstupy z importu docházky (náhrady nebo částky). '
                . 'Před změnou zdroje hodin je v Mzdových vstupech stornujte.'
            );
        }
    }

    /** @return list<string> */
    private static function monthDays(string $periodStart): array
    {
        $day = new \DateTimeImmutable(substr($periodStart, 0, 7) . '-01');
        $month = $day->format('m');
        $days = [];
        while ($day->format('m') === $month) {
            $days[] = $day->format('Y-m-d');
            $day = $day->modify('+1 day');
        }
        return $days;
    }

    /** @return array<string,mixed> */
    public function restoreEntries(int $supplierId, int $employmentId, string $periodStart, int $expectedVersion, string $reason, string $source, ?int $userId): array
    {
        $scope = $this->beginTransactionScope();
        try {
            $this->lockEmployment($supplierId, $employmentId);
            $current = $this->db->pdo()->prepare('SELECT work_source FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ? AND period_start = ? FOR UPDATE');
            $current->execute([$supplierId, $employmentId, $periodStart]);
            $currentSource = $current->fetchColumn();
            $currentSource = $currentSource === false ? 'entries' : (string) $currentSource;
            if ($currentSource !== $source) {
                if ($currentSource !== 'manual_summary') {
                    self::assertHourlyTerms($this->monthTerms($supplierId, $employmentId, $periodStart));
                }
                $month = $this->lockOpenMonth($supplierId, $employmentId, $periodStart, $expectedVersion, $userId);
                $this->assertManualSourceHasNoMaterializedAmounts($supplierId, $employmentId, $periodStart);
                if ($currentSource === 'import_summary') {
                    $this->assertNoActiveImportInputs($supplierId, $employmentId, $periodStart);
                }
                $import = null;
                if ($source === 'import_summary') {
                    if ($this->hasEntriesInPeriod($supplierId, $employmentId, $periodStart)) {
                        throw new \InvalidArgumentException(
                            'Pracovní měsíc má zapsané časové záznamy docházky. Souhrn importu by byl druhým '
                            . 'zdrojem téhož měsíce, proto ho nelze obnovit.'
                        );
                    }
                    $query = $this->db->pdo()->prepare('SELECT * FROM payroll_time_month_import_summaries WHERE supplier_id = ? AND time_month_id = ? ORDER BY time_month_revision_no DESC, id DESC LIMIT 1 FOR UPDATE');
                    $query->execute([$supplierId, $month['id']]);
                    $import = $query->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($import)) throw new \InvalidArgumentException('Pro tento měsíc není dřívější souhrn importu.');
                }
                $month['revision_no'] = (int) $month['revision_no'] + 1;
                $month['row_version'] = (int) $month['row_version'] + 1;
                $month['work_source'] = $source;
                $this->db->pdo()->prepare('UPDATE payroll_time_months SET work_source = ?, revision_no = ?, row_version = ?, last_changed_by = ? WHERE supplier_id = ? AND id = ?')
                    ->execute([$source, $month['revision_no'], $month['row_version'], $userId, $supplierId, $month['id']]);
                if ($import !== null) {
                    $this->db->pdo()->prepare('INSERT INTO payroll_time_month_import_summaries (supplier_id, time_month_id, time_month_revision_no, employment_id, period_start, attendance_import_id, values_json, worked_days, sources_json, content_sha256, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$supplierId, $month['id'], $month['revision_no'], $employmentId, $periodStart, $import['attendance_import_id'], $import['values_json'], $import['worked_days'], $import['sources_json'], $import['content_sha256'], $userId]);
                }
                $this->insertMonthEvent($month, 'changed', "Změna zdroje na {$source}: {$reason}", $userId);
            }
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }
        return ['month' => $this->monthState($supplierId, $employmentId, $periodStart), 'changed' => $currentSource !== $source];
    }

    /**
     * Má vztah v měsíci časové záznamy docházky? Rozhoduje místní měsíc
     * začátku záznamu, stejně jako při zápisu souhrnu z importu.
     */
    public function hasEntriesInPeriod(int $supplierId, int $employmentId, string $periodStart): bool
    {
        [$startsAtUtc, $endsAtUtc] = self::utcMonthBounds($periodStart);
        [$candidateStart, $candidateEnd] = self::candidateBounds($startsAtUtc, $endsAtUtc);
        $stmt = $this->db->pdo()->prepare(
            "SELECT starts_at_utc, timezone_name
               FROM payroll_time_entries
              WHERE supplier_id = ? AND employment_id = ?
                AND status <> 'superseded'
                AND starts_at_utc >= ? AND starts_at_utc < ?"
        );
        $stmt->execute([$supplierId, $employmentId, $candidateStart, $candidateEnd]);
        foreach (self::rows($stmt) as $entry) {
            if (self::startsInPeriod($entry, $periodStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Údaje pro kalendář podle úvazku: druh vztahu, první den vztahu
     * v období a týdenní doba z podmínek účinných právě ten den.
     *
     * `first_day` je null, když vztah v období netrvá ani den (nebo nemá
     * datum nástupu). Legacy projekce bez data nástupu platí od začátku
     * období, stejně jako při párování osob v importu docházky.
     *
     * @param string $periodEnd první den následujícího měsíce (výlučná hranice)
     * @return array{relation_type:string,first_day:?string,weekly_hours:?string}|null
     */
    public function calendarProvisioningFacts(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type, is_legacy_projection,
                    COALESCE(actual_start_date, start_date) AS started_on, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $lastDay = (new \DateTimeImmutable($periodEnd))->modify('-1 day')->format('Y-m-d');
        $startedOn = is_string($row['started_on'] ?? null) ? $row['started_on'] : null;
        if ($startedOn === null && (int) ($row['is_legacy_projection'] ?? 0) === 1) {
            $startedOn = $periodStart;
        }
        $endDate = is_string($row['end_date'] ?? null) ? $row['end_date'] : null;
        $firstDay = $startedOn === null ? null : max($periodStart, $startedOn);
        if ($firstDay !== null && ($firstDay > $lastDay || ($endDate !== null && $endDate < $firstDay))) {
            $firstDay = null;
        }

        $weeklyHours = null;
        if ($firstDay !== null) {
            $terms = $this->db->pdo()->prepare(
                'SELECT weekly_hours
                   FROM payroll_employment_terms
                  WHERE supplier_id = ? AND employment_id = ?
                    AND effective_from <= ?
                    AND (effective_to IS NULL OR effective_to >= ?)
                  ORDER BY effective_from DESC, id DESC
                  LIMIT 1'
            );
            $terms->execute([$supplierId, $employmentId, $firstDay, $firstDay]);
            $value = $terms->fetchColumn();
            $weeklyHours = $value === false || $value === null ? null : (string) $value;
        }

        return [
            'relation_type' => (string) ($row['relation_type'] ?? ''),
            'first_day' => $firstDay,
            'weekly_hours' => $weeklyHours,
        ];
    }

    /**
     * Souhrn aktuální revize pracovního měsíce z importu docházky.
     *
     * @return array{id:int,time_month_id:int,time_month_revision_no:int,attendance_import_id:int,values:array<string,int>,worked_days:?int,sources:array<string,mixed>,content_sha256:string,created_by:?int,created_at:string}|null
     */
    public function importSummary(int $supplierId, int $employmentId, string $periodStart): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT summary.id, summary.time_month_id, summary.time_month_revision_no,
                    summary.attendance_import_id, summary.values_json, summary.worked_days,
                    summary.sources_json, summary.content_sha256, summary.created_by,
                    summary.created_at
               FROM payroll_time_month_import_summaries summary
               INNER JOIN payroll_time_months month_row
                 ON month_row.supplier_id = summary.supplier_id
                AND month_row.id = summary.time_month_id
                AND month_row.revision_no = summary.time_month_revision_no
              WHERE summary.supplier_id = ?
                AND summary.employment_id = ?
                AND summary.period_start = ?
                AND month_row.work_source = "import_summary"
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $values = json_decode((string) $row['values_json'], true, flags: JSON_THROW_ON_ERROR);
        $sources = json_decode((string) $row['sources_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($values) || !is_array($sources)) {
            throw new \UnexpectedValueException('Souhrn pracovního měsíce z importu je neplatný.');
        }
        $typedValues = [];
        foreach ($values as $meaning => $millihours) {
            $typedValues[(string) $meaning] = PayrollTimeValue::int($millihours, 'values');
        }

        return [
            'id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
            'time_month_id' => PayrollTimeValue::int($row['time_month_id'] ?? null, 'time_month_id'),
            'time_month_revision_no' => PayrollTimeValue::int(
                $row['time_month_revision_no'] ?? null,
                'time_month_revision_no',
            ),
            'attendance_import_id' => PayrollTimeValue::int(
                $row['attendance_import_id'] ?? null,
                'attendance_import_id',
            ),
            'values' => $typedValues,
            'worked_days' => $row['worked_days'] === null ? null : PayrollTimeValue::int($row['worked_days'], 'worked_days'),
            'sources' => $sources,
            'content_sha256' => (string) $row['content_sha256'],
            'created_by' => $row['created_by'] === null ? null : PayrollTimeValue::int($row['created_by'], 'created_by'),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Zapíše souhrn měsíce z dávky importu docházky k aktuální revizi měsíce
     * a přepne měsíc na zdroj `import_summary`. Měsíc, který neexistuje,
     * založí stejnou cestou jako první zápis času.
     *
     * Souhrn je neměnný. Stejná dávka se stejným obsahem je opakování (nic se
     * nezapíše podruhé), stejná dávka s jiným obsahem skončí výjimkou.
     * Novější (opravná) dávka do OTEVŘENÉHO měsíce souhrn nepřepíše: založí
     * novou revizi měsíce s vlastním souhrnem a původní souhrn zůstane u své
     * revize jako auditní stopa. Starší dávka novější souhrn nenahradí.
     * Schválený měsíc se tu nemění nikdy, cesta vede přes znovuotevření
     * s důvodem ({@see reopenMonth()}). Časové záznamy v měsíci znamenají
     * druhý zdroj téže doby — souhrn se pak nezapíše vůbec, nic se
     * nepřepisuje ani neslučuje.
     *
     * @param array<string,int> $values význam → millihodiny
     * @param array<string,array<string,mixed>> $sources význam → původ hodnoty
     * @return array{status:string,summary_id:int,time_month_id:int,revision_no:int}
     */
    public function saveImportSummary(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        int $attendanceImportId,
        array $values,
        array $sources,
        string $contentSha256,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $scope = $this->beginTransactionScope();
        try {
            $this->lockEmployment($supplierId, $employmentId);
            $stmt = $pdo->prepare(
                'SELECT *
                   FROM payroll_time_months
                  WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                  FOR UPDATE'
            );
            $stmt->execute([$supplierId, $employmentId, $periodStart]);
            $month = self::row($stmt);
            if ($month !== null && PayrollTimeValue::string($month['status'] ?? null, 'status') !== 'open') {
                throw new PayrollTimeLockedException(
                    'Pracovní měsíc je schválený, souhrn z importu docházky se do něj nezapíše. '
                    . 'Nejdřív měsíc auditovaně znovu otevřete.'
                );
            }
            $month ??= $this->lockOpenMonth($supplierId, $employmentId, $periodStart, 0, $userId);
            if (($month['work_source'] ?? 'entries') === 'manual_summary') {
                throw new \InvalidArgumentException('Měsíc používá ruční souhrn; import jej nesmí bez výslovné změny zdroje přepsat.');
            }
            $monthId = PayrollTimeValue::int($month['id'] ?? null, 'id');
            $revisionNo = PayrollTimeValue::int($month['revision_no'] ?? null, 'revision_no');

            $existingStmt = $pdo->prepare(
                'SELECT id, attendance_import_id, content_sha256
                   FROM payroll_time_month_import_summaries
                  WHERE supplier_id = ? AND time_month_id = ? AND time_month_revision_no = ?
                  FOR UPDATE'
            );
            $existingStmt->execute([$supplierId, $monthId, $revisionNo]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            $replacedImportId = null;
            if (is_array($existing)) {
                $existingImportId = PayrollTimeValue::int($existing['attendance_import_id'] ?? null, 'attendance_import_id');
                if ($existingImportId === $attendanceImportId) {
                    if (!hash_equals((string) $existing['content_sha256'], $contentSha256)) {
                        throw new \InvalidArgumentException(sprintf(
                            'Pracovní měsíc už má souhrn z dávky importu docházky č. %d s jiným obsahem. '
                            . 'Souhrn je neměnný.',
                            $existingImportId,
                        ));
                    }
                    $this->commitTransactionScope($scope);

                    return [
                        'status' => 'replayed',
                        'summary_id' => PayrollTimeValue::int($existing['id'] ?? null, 'id'),
                        'time_month_id' => $monthId,
                        'revision_no' => $revisionNo,
                    ];
                }
                if ($attendanceImportId < $existingImportId) {
                    throw new \InvalidArgumentException(sprintf(
                        'Pracovní měsíc už má souhrn z novější dávky importu docházky č. %d. Starší dávka č. %d '
                        . 'ho nenahradí; platí-li její podklady, importujte je z docházkového systému znovu.',
                        $existingImportId,
                        $attendanceImportId,
                    ));
                }
                $replacedImportId = $existingImportId;
            }

            [$startsAtUtc, $endsAtUtc] = self::utcMonthBounds($periodStart);
            [$candidateStart, $candidateEnd] = self::candidateBounds($startsAtUtc, $endsAtUtc);
            $entries = $pdo->prepare(
                "SELECT starts_at_utc, timezone_name
                   FROM payroll_time_entries
                  WHERE supplier_id = ? AND employment_id = ?
                    AND status <> 'superseded'
                    AND starts_at_utc >= ? AND starts_at_utc < ?
                  FOR UPDATE"
            );
            $entries->execute([$supplierId, $employmentId, $candidateStart, $candidateEnd]);
            foreach (self::rows($entries) as $entry) {
                if (self::startsInPeriod($entry, $periodStart)) {
                    throw new \InvalidArgumentException(
                        'Pracovní měsíc už má zapsané časové záznamy docházky. Souhrn z importu by byl '
                        . 'druhým zdrojem téhož měsíce, proto se nezapsal a záznamy zůstaly beze změny.'
                    );
                }
            }

            if ($replacedImportId !== null) {
                $month = $this->startImportSummaryRevision(
                    $month,
                    $attendanceImportId,
                    $replacedImportId,
                    $userId,
                );
                $revisionNo = PayrollTimeValue::int($month['revision_no'] ?? null, 'revision_no');
            }

            $pdo->prepare(
                'INSERT INTO payroll_time_month_import_summaries
                    (supplier_id, time_month_id, time_month_revision_no, employment_id,
                     period_start, attendance_import_id, values_json, worked_days,
                     sources_json, content_sha256, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
            )->execute([
                $supplierId,
                $monthId,
                $revisionNo,
                $employmentId,
                $periodStart,
                $attendanceImportId,
                CanonicalJson::encode($values),
                CanonicalJson::encode($sources),
                $contentSha256,
                $userId,
            ]);
            $summaryId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "UPDATE payroll_time_months
                    SET work_source = 'import_summary'
                  WHERE supplier_id = ? AND id = ?"
            )->execute([$supplierId, $monthId]);
            if ($replacedImportId === null) {
                $this->touchMonth($month, $userId);
            }
            $this->commitTransactionScope($scope);
        } catch (\Throwable $e) {
            $this->rollBackTransactionScope($scope);
            throw $e;
        }

        return [
            'status' => 'written',
            'summary_id' => $summaryId,
            'time_month_id' => $monthId,
            'revision_no' => $revisionNo,
        ];
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

    /**
     * Platná (nenahrazená) revize časového zápisu bez zámku — oprava z ní
     * přebírá hodnoty, které klient neposlal.
     *
     * @return array<string,mixed>|null
     */
    public function currentEntryRevision(int $supplierId, int $employmentId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT category, starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                    difficulty_factor_count
               FROM payroll_time_entries
              WHERE supplier_id = ? AND employment_id = ? AND id = ?
                AND status <> 'superseded'"
        );
        $stmt->execute([$supplierId, $employmentId, $id]);
        return self::row($stmt);
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
     * Nová revize OTEVŘENÉHO měsíce pro opravný souhrn z importu docházky.
     *
     * Obdoba {@see reopenMonth()} pro měsíc, který schválený nebyl: souhrny
     * i pracovní souhrny JMHZ se vážou na revizi měsíce, takže nová revize
     * nechá původní souhrn nedotčený a čtení aktuální revize vidí jen opravu.
     *
     * @param array<string,mixed> $month
     * @return array<string,mixed>
     */
    private function startImportSummaryRevision(
        array $month,
        int $attendanceImportId,
        int $replacedImportId,
        ?int $userId,
    ): array {
        $currentVersion = PayrollTimeValue::int($month['row_version'] ?? null, 'row_version');
        $nextVersion = $currentVersion + 1;
        $nextRevision = PayrollTimeValue::int($month['revision_no'] ?? null, 'revision_no') + 1;
        $stmt = $this->db->pdo()->prepare(
            "UPDATE payroll_time_months
                SET revision_no = ?, row_version = ?, last_changed_by = ?
              WHERE supplier_id = ? AND id = ? AND row_version = ? AND status = 'open'"
        );
        $stmt->execute([
            $nextRevision,
            $nextVersion,
            $userId,
            PayrollTimeValue::int($month['supplier_id'] ?? null, 'supplier_id'),
            PayrollTimeValue::int($month['id'] ?? null, 'id'),
            $currentVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new PayrollTimeConflictException($nextVersion);
        }
        $month['revision_no'] = $nextRevision;
        $month['row_version'] = $nextVersion;
        $this->insertMonthEvent(
            $month,
            'changed',
            sprintf(
                'Opravný souhrn z dávky importu docházky č. %d nahradil souhrn z dávky č. %d.',
                $attendanceImportId,
                $replacedImportId,
            ),
            $userId,
        );

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
                 worked_millihours, worked_days, overtime_millihours,
                 conditional_blocks_confirmed,
                 unworked_hours_occurred, work_obstacles_occurred,
                 unworked_total_millihours, unworked_paid_millihours,
                 dpn_without_employer_compensation_millihours,
                 dpn_with_employer_compensation_millihours, vacation_millihours,
                 care_millihours, employee_obstacle_paid_millihours,
                 employer_obstacle_millihours,
                 maternity_millihours, paternity_millihours, parental_millihours,
                 unpaid_leave_millihours, unexcused_millihours,
                 compensatory_time_off_millihours, holiday_millihours, holiday_unpaid_millihours,
                 holiday_time_off_millihours, confirmation_note, provenance_json, summary_sha256,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            // Rozpad odpracované doby nese jen v4; starší souhrn ho v `values`
            // nemá vůbec a do sloupců jde NULL. Přesčas smí chybět i u v4,
            // když ho nejde vyjádřit na celé millihodiny.
            $values['worked_days'] ?? null,
            $values['overtime_millihours'] ?? null,
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
            // Hodiny bez atributu hlášení nese jen v3; starší souhrn je nemá
            // v `values` vůbec a do sloupců jde NULL.
            $values['maternity_millihours'] ?? null,
            $values['paternity_millihours'] ?? null,
            $values['parental_millihours'] ?? null,
            $values['unpaid_leave_millihours'] ?? null,
            $values['unexcused_millihours'] ?? null,
            // Náhradní volno nese až v5; starší souhrn ho v `values` nemá.
            $values['compensatory_time_off_millihours'] ?? null,
            // Svátky nese až v7 (z importu v8).
            $values['holiday_millihours'] ?? null,
            // Svátky uvnitř nepřítomnosti bez mzdy nese až v9.
            $values['holiday_unpaid_millihours'] ?? null,
            $values['holiday_time_off_millihours'] ?? null,
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
