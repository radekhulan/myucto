<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollCalendarFundService;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use PDO;

/** Jen část měsíční mzdy, kterou dokládá úplný pravidelný rozvrh bez absencí. */
final class PayrollHourlyMonthlyParts
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<int,array{parts:list<array<string,mixed>>,source:array<string,mixed>}> */
    public function forMany(int $supplierId, string $periodStart, array $employments, array $termsByEmployment, bool $lock = false): array
    {
        $end = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $mixed = [];
        foreach ($termsByEmployment as $id => $terms) {
            foreach ($terms as $term) {
                if (($term['remuneration_type'] ?? null) !== 'hourly') {
                    $mixed[(int) $id] = $terms;
                    break;
                }
            }
        }
        if ($mixed === []) return [];
        $ids = array_keys($mixed);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT calendar.id, calendar.employment_id, calendar.schedule_type, calendar.week_pattern,
                calendar.valid_from, calendar.valid_to, calendar.row_version,
                EXISTS (SELECT 1 FROM payroll_calendar_days day_row WHERE day_row.supplier_id = calendar.supplier_id
                    AND day_row.calendar_id = calendar.id AND day_row.day_date >= ? AND day_row.day_date <= ?) AS has_overrides,
                EXISTS (SELECT 1 FROM payroll_absences absence FORCE INDEX (idx_payroll_absence_period)
                    WHERE absence.supplier_id = calendar.supplier_id AND absence.employment_id = calendar.employment_id
                      AND absence.date_from <= ? AND absence.date_to >= ? AND absence.status IN ('requested','approved')) AS has_absences
            FROM payroll_work_calendars calendar FORCE INDEX (idx_payroll_calendar_effective)
            WHERE calendar.supplier_id = ? AND calendar.employment_id IN ($in)
              AND calendar.valid_from <= ? AND (calendar.valid_to IS NULL OR calendar.valid_to >= ?)
            ORDER BY calendar.employment_id, calendar.valid_from, calendar.id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$periodStart, $end, $end, $periodStart, $supplierId, ...$ids, $end, $periodStart]);
        $calendars = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $calendars[(int) $row['employment_id']][] = $row;
        $employmentMap = [];
        foreach ($employments as $row) $employmentMap[(int) $row['employment_id']] = $row;
        $result = [];
        $fund = new PayrollCalendarFundService(new CzechHolidayCalendar());
        foreach ($mixed as $id => $terms) {
            $rows = $calendars[$id] ?? [];
            $source = ['status' => 'unavailable', 'calendars' => $rows];
            $result[$id] = ['parts' => [], 'source' => $source];
            if (count($rows) !== 1) continue;
            $calendar = $rows[0];
            if ($calendar['schedule_type'] !== 'regular' || $calendar['valid_from'] > $periodStart
                || ($calendar['valid_to'] !== null && $calendar['valid_to'] < $end)
                || (int) $calendar['has_overrides'] !== 0 || (int) $calendar['has_absences'] !== 0) continue;
            $pattern = json_decode((string) $calendar['week_pattern'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($pattern)) continue;
            $days = $fund->month(substr($periodStart, 0, 7), $pattern)['days'];
            $full = 0;
            $daily = [];
            foreach ($days as $day) {
                $minutes = (int) $day['planned_minutes'];
                if ($day['is_holiday']) $minutes += max(0, (int) ($pattern[(string) $day['weekday']] ?? $pattern[$day['weekday']] ?? 0));
                $daily[(string) $day['date']] = $minutes;
                $full += $minutes;
            }
            if ($full <= 0) continue;
            $employment = $employmentMap[$id] ?? [];
            $start = max($periodStart, (string) ($employment['actual_start_date'] ?? $employment['start_date'] ?? $periodStart));
            $finish = min($end, (string) ($employment['end_date'] ?? $end));
            $parts = [];
            foreach ($terms as $term) {
                if (($term['remuneration_type'] ?? null) === 'hourly') continue;
                $from = max($periodStart, (string) $term['effective_from']);
                $to = min($end, (string) ($term['effective_to'] ?? $end));
                $retained = 0;
                foreach ($daily as $date => $minutes) if ($date >= $from && $date <= $to && $date >= $start && $date <= $finish) $retained += $minutes;
                $parts[] = ['term_id' => (int) $term['id'], 'date_from' => $from, 'date_to' => $to,
                    'fund_duration_units' => PayrollDuration::fromMinutes($full), 'retained_duration_units' => PayrollDuration::fromMinutes($retained)];
            }
            $result[$id] = ['parts' => $parts, 'source' => ['status' => 'supported', 'calendar' => $calendar, 'parts' => $parts]];
        }
        return $result;
    }
}
