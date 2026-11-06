<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use PDO;

/**
 * Průměrný týdenní rozsah dohody o pracovní činnosti (§ 76 odst. 2 ZP).
 *
 * „Na základě dohody o pracovní činnosti nemůže být zaměstnanec zaměstnáván
 * v rozsahu překračujícím v průměru polovinu stanovené týdenní pracovní doby.
 * Dodržování sjednaného rozsahu pracovní doby se posuzuje za celou dobu, na
 * kterou byla dohoda o pracovní činnosti uzavřena, nejdéle však za období
 * 52 týdnů.“
 *
 * Stanovená týdenní pracovní doba je 40 hodin (§ 79 odst. 1 ZP); zkrácenou
 * dobu zaměstnavatele aplikace zatím neeviduje, limit je tedy 20 h týdně.
 *
 * Odpracované hodiny existují jen po měsících (potvrzený pracovní souhrn
 * nebo přesný schválený ruční zdroj nezávislý na JMHZ, u převzatých mezd `payroll_migration_reference_totals`), proto se
 * 52 týdnů nahrazuje posledními 12 kalendářními měsíci (365 nebo 366 dnů),
 * zkrácenými na začátek dohody. Limit je poměrný počtu dnů okna.
 *
 * Na rozdíl od DPP (§ 75, součet za osobu přes všechny DPP) se rozsah DPČ
 * posuzuje u každé dohody zvlášť. Překročení je varování, ne závora: práci je
 * potřeba zaplatit, jen smluvní vztah přestal odpovídat zákonu.
 */
final class PayrollAgreementWeeklyAverage
{
    /** § 79 odst. 1 ZP: 40 hodin týdně. */
    public const STANDARD_WEEKLY_MINUTES = 40 * 60;

    /** Polovina stanovené týdenní pracovní doby. */
    public const LIMIT_WEEKLY_MINUTES = self::STANDARD_WEEKLY_MINUTES / 2;

    public function __construct(private readonly Connection $db) {}

    /**
     * Průměr za posuzované období končící posledním dnem měsíce `$periodStart`.
     *
     * @param array<int,string> $startsByEmployment employment_id => den začátku dohody
     * @return array<int,array<string,mixed>>
     */
    public function assess(int $supplierId, array $startsByEmployment, string $periodStart): array
    {
        if ($startsByEmployment === []) {
            return [];
        }
        $periodFirst = new \DateTimeImmutable(substr($periodStart, 0, 7) . '-01');
        $windowTo = $periodFirst->modify('last day of this month');
        $earliestFrom = $periodFirst->modify('-11 months');

        $result = [];
        $windows = [];
        foreach ($startsByEmployment as $employmentId => $start) {
            $from = $earliestFrom;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1) {
                $agreementStart = new \DateTimeImmutable($start);
                if ($agreementStart > $from) {
                    $from = $agreementStart;
                }
            }
            if ($from > $windowTo) {
                continue;
            }
            $days = (int) $from->diff($windowTo)->days + 1;
            $windows[$employmentId] = $from;
            $result[$employmentId] = [
                'window_from' => $from->format('Y-m-d'),
                'window_to' => $windowTo->format('Y-m-d'),
                'days' => $days,
                'own_minutes' => 0,
                'takeover_minutes' => 0,
                'worked_minutes' => 0,
                'limit_minutes' => intdiv($days * self::LIMIT_WEEKLY_MINUTES, 7),
                'average_weekly_minutes' => 0,
            ];
        }
        if ($result === []) {
            return [];
        }
        $ids = array_keys($result);
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $firstMonth = $earliestFrom->format('Y-m-d');
        $lastMonth = $periodFirst->format('Y-m-d');

        $own = $this->db->pdo()->prepare(
            "SELECT month_row.employment_id, month_row.period_start,
                    SUM(summary.worked_millihours) AS millihours
               FROM payroll_time_months month_row
               JOIN payroll_jmhz_work_month_revisions summary
                 ON summary.supplier_id = month_row.supplier_id
                AND summary.time_month_id = month_row.id
                AND summary.time_month_revision_no = month_row.revision_no
              WHERE month_row.supplier_id = ?
                AND month_row.employment_id IN ({$in})
                AND month_row.period_start >= ? AND month_row.period_start <= ?
                AND month_row.work_source <> 'manual_summary'
              GROUP BY month_row.employment_id, month_row.period_start",
        );
        $own->execute([$supplierId, ...$ids, $firstMonth, $lastMonth]);
        foreach ($own->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            if (!$this->monthInWindow((string) $row['period_start'], $windows[$employmentId])) {
                continue;
            }
            $result[$employmentId]['own_minutes'] += intdiv((int) $row['millihours'] * 60, 1000);
        }


        $startPeriod = $this->startPeriod($supplierId);
        $takeover = $this->db->pdo()->prepare(
            "SELECT totals.employment_id, totals.period_start, SUM(totals.worked_minutes) AS minutes
               FROM payroll_migration_reference_totals totals
              WHERE totals.supplier_id = ?
                AND totals.employment_id IN ({$in})
                AND totals.period_start >= ? AND totals.period_start <= ?
              GROUP BY totals.employment_id, totals.period_start",
        );
        $takeover->execute([$supplierId, ...$ids, $firstMonth, $lastMonth]);
        $takenOverMonths = [];
        foreach ($takeover->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            $month = (string) $row['period_start'];
            if (!PayrollHistoricalPeriodService::precedesStart($startPeriod, $month)
                || !$this->monthInWindow($month, $windows[$employmentId])
            ) {
                continue;
            }
            $result[$employmentId]['takeover_minutes'] += (int) $row['minutes'];
            $takenOverMonths[$employmentId][$month] = true;
        }

        // Před začátkem vedení mezd je stejně jako u DPP autoritativní
        // převzatý součet; tentýž měsíc se ručním zdrojem nepřičte podruhé.
        foreach ((new PayrollAgreementManualHours($this->db))->forEmployments($supplierId, $ids, $firstMonth, $lastMonth) as $month) {
            $id = $month['employment_id'];
            if (!$this->monthInWindow($month['period_start'], $windows[$id]) || isset($takenOverMonths[$id][$month['period_start']])) continue;
            if (!array_key_exists('own_duration_units', $result[$id])) $result[$id]['own_duration_units'] = PayrollDuration::fromMinutes($result[$id]['own_minutes']);
            if ($month['worked_duration_units'] === null) {
                $result[$id]['evidence_status'] = 'manual_review_required';
            } else {
                $result[$id]['own_duration_units'] = PayrollDuration::add($result[$id]['own_duration_units'], $month['worked_duration_units']);
            }
        }


        foreach ($result as $employmentId => $row) {
            if (array_key_exists('own_duration_units', $row)) {
                $unknown = ($row['evidence_status'] ?? null) === 'manual_review_required';
                $workedUnits = PayrollDuration::add($row['own_duration_units'], PayrollDuration::fromMinutes($row['takeover_minutes']));
                $result[$employmentId]['own_duration_units'] = $unknown ? null : $row['own_duration_units'];
                $result[$employmentId]['worked_duration_units'] = $unknown ? null : $workedUnits;
                $result[$employmentId]['own_minutes'] = $unknown ? null : PayrollDuration::exactMinutes($row['own_duration_units']);
                $result[$employmentId]['worked_minutes'] = $unknown ? null : PayrollDuration::exactMinutes($workedUnits);
                // Průměr může být racionální i při přesně doložené době. Limit
                // se níže porovnává křížovým násobením, nikoli zaokrouhlením.
                $numerator = $workedUnits * 7;
                $denominator = max(1, $row['days']) * 50;
                $result[$employmentId]['average_weekly_minutes'] = !$unknown && $numerator % $denominator === 0 ? intdiv($numerator, $denominator) : null;
                continue;
            }
            $worked = $row['own_minutes'] + $row['takeover_minutes'];
            $result[$employmentId]['worked_minutes'] = $worked;
            $result[$employmentId]['average_weekly_minutes'] = intdiv($worked * 7, max(1, $row['days']));
        }

        return $result;
    }

    /**
     * Varování pro DPČ běhu, jejichž průměr za posuzované období přesáhl
     * polovinu stanovené týdenní pracovní doby.
     *
     * @param list<array<string,mixed>> $employments řádky běhu s `employment_id`,
     *        `employee_id`, `relation_type`, `full_name`, `start_date`,
     *        `actual_start_date`
     * @return list<PayrollRunValidation>
     */
    public function validations(int $supplierId, array $employments, string $periodStart): array
    {
        $starts = [];
        $rows = [];
        foreach ($employments as $row) {
            if (($row['relation_type'] ?? null) !== 'dpc') {
                continue;
            }
            $employmentId = (int) $row['employment_id'];
            $starts[$employmentId] = (string) ($row['actual_start_date'] ?? $row['start_date'] ?? '');
            $rows[$employmentId] = $row;
        }
        $validations = [];
        foreach ($this->assess($supplierId, $starts, $periodStart) as $employmentId => $assessment) {
            $unknown = ($assessment['evidence_status'] ?? null) === 'manual_review_required';
            $exceeded = isset($assessment['worked_duration_units'])
                ? $assessment['worked_duration_units'] * 7 > $assessment['days'] * PayrollDuration::fromMinutes(self::LIMIT_WEEKLY_MINUTES)
                : $assessment['worked_minutes'] > $assessment['limit_minutes'];
            if (!$unknown && !$exceeded) continue;
            $row = $rows[$employmentId];
            $employeeId = (int) $row['employee_id'];
            $validations[] = new PayrollRunValidation(
                'warning',
                $unknown ? 'dpc_weekly_average_manual_review_required' : 'dpc_weekly_average_exceeded',
                'employment',
                $employmentId,
                $unknown ? 'Průměrný týdenní rozsah DPČ nelze ověřit: ruční podklad pracovní doby je neúplný nebo neschválený. Doplňte a schvalte skutečně odpracované hodiny.' : self::message((string) ($row['full_name'] ?? ''), $assessment),
                $unknown
                    ? '/payroll/quick-inputs?period=' . substr($periodStart, 0, 7) . '&employment=' . $employmentId . '&hours=1'
                    : "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms",
            );
        }

        return $validations;
    }

    /**
     * @param array{window_from:string,window_to:string,days:int,takeover_minutes:int,worked_minutes:int,limit_minutes:int,average_weekly_minutes:int} $assessment
     */
    public static function message(string $name, array $assessment): string
    {
        return sprintf(
            '%s: na dohodě o pracovní činnosti odpracoval(a) od %s do %s celkem %s h%s, v průměru %s h týdně — '
                . 'víc než polovinu stanovené týdenní pracovní doby (%s h), kterou § 76 odst. 2 zákoníku práce '
                . 'dovoluje v průměru za dobu dohody, nejdéle za 52 týdnů. V dalších týdnech rozsah práce snižte, '
                . 'nebo v kartě zaměstnance upravte smluvní vztah (pracovní poměr).',
            $name === '' ? 'Zaměstnanec' : $name,
            self::date($assessment['window_from']),
            self::date($assessment['window_to']),
            isset($assessment['worked_duration_units']) ? PayrollAgreementManualHours::hours($assessment['worked_duration_units']) : self::hours($assessment['worked_minutes']),
            $assessment['takeover_minutes'] > 0
                ? sprintf(' (z toho %s h převzatých z předchozího programu)', self::hours($assessment['takeover_minutes']))
                : '',
            isset($assessment['worked_duration_units']) ? 'přibližně ' . number_format($assessment['worked_duration_units'] * 7 / ($assessment['days'] * PayrollDuration::UNITS_PER_HOUR), 3, ',', ' ') : self::hours($assessment['average_weekly_minutes']),
            self::hours(self::LIMIT_WEEKLY_MINUTES),
        );
    }

    private function monthInWindow(string $monthStart, \DateTimeImmutable $windowFrom): bool
    {
        $monthEnd = (new \DateTimeImmutable(substr($monthStart, 0, 7) . '-01'))->modify('last day of this month');

        return $monthEnd >= $windowFrom;
    }

    private static function date(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }

    private static function hours(int $minutes): string
    {
        return $minutes % 60 === 0
            ? number_format(intdiv($minutes, 60), 0, ',', ' ')
            : number_format($minutes / 60, 2, ',', ' ');
    }

    private function startPeriod(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT start_period FROM payroll_module_state WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
