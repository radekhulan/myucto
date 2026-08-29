<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollWorkCalendarSchedule;
use PDO;

final class AutomaticLeaveEntitlementService
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    private readonly PayrollWorkCalendarSchedule $schedule;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly LeaveEntitlementPolicyResolver $policyResolver,
        private readonly LeaveEntitlementCalculator $calculator,
        private readonly PayrollLeaveRepository $leave,
        private readonly PayrollAbsenceRepository $absences,
        private readonly CzechHolidayCalendar $holidayCalendar = new CzechHolidayCalendar(),
    ) {
        $this->schedule = new PayrollWorkCalendarSchedule($db);
    }

    /** @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int} */
    public function page(
        int $supplierId,
        int $year,
        string $through,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $this->assertPeriod($year, $through);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $where = "employment.supplier_id = ?
                  AND employment.status IN ('active','suspended','ended')
                  AND COALESCE(employment.actual_start_date, employment.start_date) <= ?
                  AND (employment.end_date IS NULL OR employment.end_date >= ?)";
        $count = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_employments employment WHERE {$where}",
        );
        $count->execute([$supplierId, $through, $yearStart]);
        $total = (int) $count->fetchColumn();
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.employee_id, employment.code,
                    employment.relation_type, employment.start_date,
                    employment.actual_start_date, employment.end_date,
                    employment.row_version, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE {$where}
              ORDER BY employee.full_name, employment.code, employment.id
              LIMIT ? OFFSET ?",
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $through);
        $stmt->bindValue(3, $yearStart);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->bindValue(5, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $employment) {
            $items[] = $this->candidate($supplierId, $year, $through, $employment);
        }

        return compact('items', 'total', 'limit', 'offset');
    }

    /**
     * @param list<array{employment_id:int,input_version:string}> $requested
     * @return list<array<string,mixed>>
     */
    public function calculateBatch(
        int $supplierId,
        int $year,
        string $through,
        array $requested,
        ?int $userId,
    ): array {
        $this->assertPeriod($year, $through);
        if ($requested === [] || count($requested) > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Dávka musí obsahovat 1 až 100 pracovních vztahů.');
        }
        $ids = [];
        foreach ($requested as $item) {
            if (($item['employment_id'] ?? 0) <= 0
                || !is_string($item['input_version'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $item['input_version']) !== 1
            ) {
                throw new \InvalidArgumentException('Dávka nároků nemá platný výběr a verze vstupů.');
            }
            if (isset($ids[$item['employment_id']])) {
                throw new \InvalidArgumentException('Pracovní vztah je v dávce uveden vícekrát.');
            }
            $ids[$item['employment_id']] = $item['input_version'];
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $candidates = [];
            foreach ($ids as $employmentId => $expectedVersion) {
                $employment = $this->employmentForUpdate($supplierId, (int) $employmentId);
                $candidate = $this->candidate($supplierId, $year, $through, $employment);
                if (!hash_equals($expectedVersion, (string) $candidate['input_version'])) {
                    throw new AutomaticLeaveEntitlementConflictException((int) $employmentId);
                }
                if (!$candidate['ready']) {
                    throw new \InvalidArgumentException(
                        sprintf('%s: %s', $candidate['employee_name'], implode(', ', $candidate['blockers'])),
                    );
                }
                $candidates[] = $candidate;
            }

            $results = [];
            foreach ($candidates as $candidate) {
                $rationale = 'Automaticky z účinné firemní politiky, smluvní týdenní doby a schválené docházky.';
                $calculated = $this->calculator->calculate(
                    sprintf('%04d-01-01', $year),
                    (string) $candidate['relation_type'],
                    (int) $candidate['weekly_minutes'],
                    (int) $candidate['entitlement_weeks'],
                    (int) $candidate['continuous_calendar_days'],
                    (int) $candidate['worked_equivalent_minutes'],
                    $rationale,
                );
                $trace = $calculated->trace;
                $trace['automatic_sources'] = $candidate['sources'];
                $trace['through'] = $through;
                $supported = new LeaveEntitlementResult(
                    $calculated->weeklyMinutes,
                    $calculated->workedWeekMultiples,
                    $calculated->entitlementMinutes,
                    'supported',
                    $trace,
                );
                $results[] = $this->leave->recordEntitlement(
                    $supplierId,
                    (int) $candidate['employment_id'],
                    $year,
                    (string) $candidate['relation_type'],
                    (int) $candidate['entitlement_weeks'],
                    (int) $candidate['continuous_calendar_days'],
                    (int) $candidate['worked_equivalent_minutes'],
                    $rationale,
                    $supported,
                    $userId,
                    'automatic',
                    hex2bin((string) $candidate['input_version']),
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $results;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $employment @return array<string,mixed> */
    private function candidate(int $supplierId, int $year, string $through, array $employment): array
    {
        $start = max(
            sprintf('%04d-01-01', $year),
            (string) ($employment['actual_start_date'] ?? $employment['start_date']),
        );
        $end = min(
            $through,
            (string) ($employment['end_date'] ?? sprintf('%04d-12-31', $year)),
        );
        $terms = $this->terms($supplierId, (int) $employment['id'], $start, $end);
        $policies = $this->policies($supplierId, $start, $end);
        $agreementMinutes = AbsenceRuleset::forYear($this->rulesets, $year)
            ->leaveAgreementWeeklyMinutes();
        $resolved = $this->policyResolver->resolve(
            $start,
            $end,
            (string) $employment['relation_type'],
            $terms,
            $policies,
            $agreementMinutes,
        );
        $months = $this->timeMonths($supplierId, (int) $employment['id'], $start, $end);
        $requiredMonths = $this->requiredMonths($start, $end);
        $approvedPeriods = [];
        foreach ($months as $month) {
            if ($month['status'] === 'approved') {
                $approvedPeriods[(string) $month['period_start']] = true;
            }
        }
        $blockers = array_fill_keys($resolved['blockers'], true);
        foreach ($requiredMonths as $period) {
            if (!isset($approvedPeriods[$period])) {
                $blockers['approved_time_month_missing'] = true;
            }
        }

        // `requiredMonths` žádá schválení jen u měsíců, které se do období
        // vešly CELÉ — u vztahu končícího 15. června se tedy červen nevyžaduje.
        // Filtr níž ale odpracovanou dobu bere výhradně ze schválených měsíců,
        // takže hodiny odpracované v tom posledním neúplném měsíci bez jediného
        // slova zmizely a nárok na dovolenou vyšel nižší. Ticho je tu horší než
        // překážka: účetní nemá jak poznat, že se počítalo z menšího základu.
        //
        // Blokátor se proto zvedá podle SKUTEČNÝCH dat, ne podle kalendáře —
        // existuje-li schválený docházkový záznam v měsíci bez schválené
        // docházky, výpočet se zastaví. Neúplný měsíc BEZ záznamů nikoho
        // neblokuje, takže se průběžný výpočet uprostřed měsíce nerozbije.
        $allTimeEntries = $this->approvedTimeEntries($supplierId, (int) $employment['id'], $start, $end);
        $timeEntries = [];
        foreach ($allTimeEntries as $entry) {
            $period = substr((string) $entry['starts_at_utc'], 0, 7) . '-01';
            if (isset($approvedPeriods[$period])) {
                $timeEntries[] = $entry;
                continue;
            }
            $blockers['approved_time_month_missing'] = true;
        }
        $workedMinutes = array_sum(array_column($timeEntries, 'minutes'));
        $approvedAbsences = $this->approvedAbsences($supplierId, (int) $employment['id'], $start, $end);
        $substituteMinutes = 0;
        $absenceSources = [];
        foreach ($approvedAbsences as $absence) {
            // Neomluvené zameškání není podle § 348 odst. 1 výkon práce ani
            // podle § 216 odst. 2 započitatelná překážka — nemá tedy do
            // odpracované doby přinést nic, a přesto se o něm nedá říct, že by
            // potřebovalo právní posouzení. Odpovídá se na něj nulou, ne
            // překážkou. (Krácení dovolené za ně řeší § 223 odst. 1 samostatně
            // v knize dovolené, ne tady.)
            if ($absence['absence_type'] === 'unexcused') {
                continue;
            }
            if ($absence['absence_type'] !== 'vacation') {
                $blockers['absence_legal_assessment_required'] = true;
                continue;
            }
            if (!$this->allMonthsApproved(
                max($start, (string) $absence['date_from']),
                min($end, (string) $absence['date_to']),
                $approvedPeriods,
            )) {
                $blockers['absence_time_month_missing'] = true;
                continue;
            }
            // § 219 odst. 1 ZP — svátek uvnitř dovolené se nečerpá, takže ho
            // dovolená do odpracované doby nepřináší. Přinese ho svátek sám
            // podle § 348 odst. 1 písm. d) níž, jednou a v plné délce.
            $segments = $this->absences->publishedShiftSegments(
                $absence,
                false,
                AbsenceHolidayTreatment::ExcludeFromLeave,
            );
            $minutes = array_sum(array_column($segments, 'eligible_minutes'));
            $substituteMinutes += $minutes;
            $absenceSources[] = [
                'id' => $absence['id'],
                'row_version' => $absence['row_version'],
                'minutes' => $minutes,
                'segments' => $segments,
            ];
        }
        // § 348 odst. 1 písm. d) ZP — doba, kdy zaměstnanec nepracoval proto,
        // že byl svátek, se považuje za výkon práce. Bez ní by nárok krátil
        // každý svátek, který padl na jeho pracovní den.
        $holidayCredit = $this->holidayWorkEquivalent(
            $supplierId,
            (int) $employment['id'],
            $start,
            $end,
            $approvedPeriods,
            $timeEntries,
        );
        $holidayMinutes = $holidayCredit['minutes'];
        $workedEquivalent = $workedMinutes + $substituteMinutes + $holidayMinutes;
        if ($workedEquivalent <= 0) {
            $blockers['worked_equivalent_time_missing'] = true;
        }

        $existingEntitlement = $this->existingEntitlement(
            $supplierId,
            (int) $employment['id'],
            $year,
        );
        if ($existingEntitlement !== null) {
            $blockers['entitlement_already_exists'] = true;
        }

        $continuousDays = (int) (new \DateTimeImmutable($start))
            ->diff(new \DateTimeImmutable($end))->days + 1;
        $sources = [
            'employment' => [
                'id' => (int) $employment['id'],
                'row_version' => (int) $employment['row_version'],
                'start' => $start,
                'end' => $end,
            ],
            'terms' => $terms,
            'policies' => $policies,
            'time_months' => $months,
            'time_entries' => $timeEntries,
            'approved_vacations' => $absenceSources,
            'holidays' => $holidayCredit['days'],
            'existing_entitlement' => $existingEntitlement,
        ];
        $inputVersion = hash('sha256', CanonicalJson::encode($sources));

        return [
            'employment_id' => (int) $employment['id'],
            'employee_name' => (string) $employment['full_name'],
            'employment_code' => (string) $employment['code'],
            'relation_type' => (string) $employment['relation_type'],
            'period_from' => $start,
            'period_to' => $end,
            'weekly_minutes' => $resolved['weekly_minutes'],
            'entitlement_weeks' => $resolved['entitlement_weeks'],
            'allowance_source' => $resolved['allowance_source'],
            'continuous_calendar_days' => $continuousDays,
            'worked_equivalent_minutes' => $workedEquivalent,
            'ready' => $blockers === [],
            'blockers' => array_keys($blockers),
            'input_version' => $inputVersion,
            'sources' => $sources,
        ];
    }

    /** @return array<string,mixed> */
    private function employmentForUpdate(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.code,
                    employment.relation_type, employment.start_date,
                    employment.actual_start_date, employment.end_date,
                    employment.row_version, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id = ?
              FOR UPDATE',
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function terms(int $supplierId, int $employmentId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, effective_from, effective_to, weekly_hours,
                    leave_entitlement_weeks_override, row_version
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id',
        );
        $stmt->execute([$supplierId, $employmentId, $to, $from]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function policies(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, valid_from, valid_to, leave_entitlement_weeks, row_version
               FROM payroll_employer_policies
              WHERE supplier_id = ? AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from, id',
        );
        $stmt->execute([$supplierId, $to, $from]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function timeMonths(int $supplierId, int $employmentId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, period_start, status, revision_no, row_version
               FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ?
                AND period_start BETWEEN ? AND ?
              ORDER BY period_start',
        );
        $stmt->execute([$supplierId, $employmentId, substr($from, 0, 7) . '-01', substr($to, 0, 7) . '-01']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function approvedTimeEntries(int $supplierId, int $employmentId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            // Přesčas se nezapočítává. Nárok na dovolenou se podle § 213 ZP
            // odvozuje od STANOVENÉ týdenní pracovní doby, ne od skutečně
            // odpracovaných hodin — hodinou navíc si zaměstnanec nárok
            // nezvyšuje a v násobcích stanovené doby by ho posouval nahoru.
            "SELECT id, revision_no, category, starts_at_utc, ends_at_utc,
                    timezone_name, break_minutes, row_version,
                    TIMESTAMPDIFF(MINUTE, starts_at_utc, ends_at_utc) - break_minutes AS minutes
               FROM payroll_time_entries
              WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'
                AND category = 'regular'
                AND starts_at_utc >= ? AND starts_at_utc < DATE_ADD(?, INTERVAL 1 DAY)
              ORDER BY starts_at_utc, id",
        );
        $stmt->execute([$supplierId, $employmentId, $from . ' 00:00:00', $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['minutes'] = (int) $row['minutes'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Svátek jako výkon práce podle § 348 odst. 1 písm. d) ZP.
     *
     * Započítá se jen svátek, který padl na den, kdy by zaměstnanec podle
     * rozvrhu jinak pracoval, a jen v měsíci se schválenou docházkou — stejná
     * podmínka jako u odpracované doby, jinak by nárok stál na neschválených
     * podkladech. Odpracované minuty téhož dne, které už jsou v odpracované
     * době, se odečtou, aby se den nezapočítal dvakrát.
     *
     * @param array<string,bool> $approvedPeriods
     * @param list<array<string,mixed>> $timeEntries už započtená odpracovaná doba
     * @return array{minutes:int,days:list<array{date:string,code:string,planned_minutes:int,credited_minutes:int}>}
     */
    private function holidayWorkEquivalent(
        int $supplierId,
        int $employmentId,
        string $from,
        string $to,
        array $approvedPeriods,
        array $timeEntries,
    ): array {
        $holidays = PayrollWorkCalendarSchedule::holidaysBetween($this->holidayCalendar, $from, $to);
        $dates = [];
        foreach (array_keys($holidays) as $date) {
            if (isset($approvedPeriods[substr((string) $date, 0, 7) . '-01'])) {
                $dates[] = (string) $date;
            }
        }
        if ($dates === []) {
            return ['minutes' => 0, 'days' => []];
        }

        $workedByDate = [];
        foreach ($timeEntries as $entry) {
            $timezone = new \DateTimeZone((string) ($entry['timezone_name'] ?? 'UTC'));
            $localDate = (new \DateTimeImmutable(
                (string) $entry['starts_at_utc'],
                new \DateTimeZone('UTC'),
            ))->setTimezone($timezone)->format('Y-m-d');
            $workedByDate[$localDate] = ($workedByDate[$localDate] ?? 0) + (int) $entry['minutes'];
        }

        $planned = $this->schedule->plannedMinutes($supplierId, $employmentId, $dates);
        $total = 0;
        $days = [];
        foreach ($dates as $date) {
            $plannedMinutes = $planned[$date] ?? 0;
            $credited = max(0, $plannedMinutes - ($workedByDate[$date] ?? 0));
            if ($credited <= 0) {
                continue;
            }
            $total += $credited;
            $days[] = [
                'date' => $date,
                'code' => (string) ($holidays[$date]['code'] ?? ''),
                'planned_minutes' => $plannedMinutes,
                'credited_minutes' => $credited,
            ];
        }

        return ['minutes' => $total, 'days' => $days];
    }

    /** @return list<array<string,mixed>> */
    private function approvedAbsences(int $supplierId, int $employmentId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'
                AND date_from <= ? AND date_to >= ?
              ORDER BY date_from, id",
        );
        $stmt->execute([$supplierId, $employmentId, $to, $from]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return ?array<string,mixed> */
    private function existingEntitlement(int $supplierId, int $employmentId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, revision_no, support_status, calculation_mode, row_version
               FROM payroll_leave_entitlement_snapshots
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?
              ORDER BY revision_no DESC LIMIT 1',
        );
        $stmt->execute([$supplierId, $employmentId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,bool> $approvedPeriods */
    private function allMonthsApproved(string $from, string $to, array $approvedPeriods): bool
    {
        $cursor = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = new \DateTimeImmutable(substr($to, 0, 7) . '-01');
        while ($cursor <= $last) {
            if (!isset($approvedPeriods[$cursor->format('Y-m-d')])) {
                return false;
            }
            $cursor = $cursor->modify('first day of next month');
        }
        return true;
    }

    /** @return list<string> */
    private function requiredMonths(string $from, string $to): array
    {
        $months = [];
        $cursor = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new \DateTimeImmutable($to);
        while ($cursor->modify('last day of this month') <= $end) {
            $months[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('first day of next month');
        }
        return $months;
    }

    private function assertPeriod(int $year, string $through): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $through);
        if ($year < 2000 || $year > 2100 || $date === false
            || $date->format('Y-m-d') !== $through || (int) $date->format('Y') !== $year
        ) {
            throw new \InvalidArgumentException('Rok a datum výpočtu nároku nejsou platné.');
        }
    }
}
