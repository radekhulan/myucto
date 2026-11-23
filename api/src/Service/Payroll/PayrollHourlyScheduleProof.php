<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Time\PayrollDuration;

/** Skutečný publikovaný rozvrh; žádná zkratka přes kalendářní fond nebo osm hodin. */
final class PayrollHourlyScheduleProof
{
    /** @return array<string,mixed> */
    public static function forDate(string $date, array $shifts, array $absences, ?array $source, ?int $selectedAbsenceId = null): array
    {
        $rows = [];
        $scheduled = 0;
        $revision = 0;
        $previousEnd = null;
        foreach ($shifts as $shift) {
            [$start, $end, $minutes] = self::bounds($shift);
            if ($start->format('Y-m-d') > $date || $end->modify('-1 second')->format('Y-m-d') < $date) continue;
            if ($shift['status'] !== 'published' || $start->format('Y-m-d') !== $date || $end->modify('-1 second')->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('Pro tento den chybí jednoznačný publikovaný rozvrh; směnu přes půlnoc nejprve rozdělte včetně přestávky.');
            }
            if ($previousEnd !== null && $start < $previousEnd) throw new \InvalidArgumentException('Publikované směny se překrývají.');
            $previousEnd = $end;
            $scheduled = PayrollDuration::add($scheduled, PayrollDuration::fromMinutes($minutes));
            $revision = max($revision, (int) $shift['id']);
            $rows[] = $shift;
        }
        if ($rows === []) throw new \InvalidArgumentException('Náhrada vyžaduje skutečnou publikovanou směnu pro vybraný den.');
        $worked = self::workedOn($date, $source);
        $other = 0;
        $selected = null;
        $cover = [];
        foreach ($absences as $absence) {
            if ($absence['date_from'] > $date || $absence['date_to'] < $date) continue;
            if ($absence['status'] !== 'approved') continue;
            if ((int) ($absence['correction_pending'] ?? 0) !== 0) throw new \InvalidArgumentException('Nepřítomnost čeká na opravu; nejprve ji dokončete.');
            $units = $scheduled;
            foreach (['first' => 'date_from', 'last' => 'date_to'] as $part => $dateKey) {
                if ($absence[$dateKey] === $date && ($absence['partial_' . $part . '_minutes'] ?? null) !== null) {
                    $units = min($units, PayrollDuration::fromMinutes((int) $absence['partial_' . $part . '_minutes']));
                }
            }
            if ((int) $absence['id'] === $selectedAbsenceId) $selected = $units;
            else $other = PayrollDuration::add($other, $units);
            $cover[] = $absence;
        }
        if ($selectedAbsenceId !== null && $selected === null) throw new \InvalidArgumentException('Čerpání vyžaduje schválenou nepřítomnost ve vybraném dni.');
        return ['schedule_revision_id' => $revision, 'schedule_hash' => hash('sha256', CanonicalJson::encode($rows)),
            'absence_coverage_hash' => hash('sha256', CanonicalJson::encode($cover)),
            'scheduled_duration_units' => $scheduled, 'worked_duration_units' => $worked,
            'other_absence_duration_units' => $other,
            'available_duration_units' => min($selected ?? $scheduled, max(0, $scheduled - $worked - $other))];
    }

    public static function workedOn(string $date, ?array $source): int
    {
        if (($source['work_source'] ?? null) === 'manual_summary') {
            $units = 0;
            $covered = false;
            foreach ($source['manual_summary']['segments'] ?? [] as $row) {
                if ($row['date_from'] > $date || $row['date_to'] < $date) continue;
                $covered = true;
                if ((int) $row['worked_duration_units'] > 0 && $row['date_from'] !== $row['date_to']) throw new \InvalidArgumentException('Rozdělte ruční hodiny po dnech pro doložení náhrady a vyloučení souběhu s prací.');
                $units = PayrollDuration::add($units, (int) $row['worked_duration_units']);
            }
            if (!$covered) throw new \InvalidArgumentException('Pro den náhrady chybí doložená práce nebo výslovná nula.');
            return $units;
        }
        if (($source['work_source'] ?? null) !== 'entries' || ($source['time_entries'] ?? []) === []) throw new \InvalidArgumentException('Pro den náhrady chybí doložená skutečná pracovní doba.');
        $units = 0;
        foreach ($source['time_entries'] as $row) {
            if (!in_array($row['category'], ['regular', 'overtime'], true)) continue;
            [$start, $end, $minutes] = self::bounds($row);
            if ($start->format('Y-m-d') > $date || $end->modify('-1 second')->format('Y-m-d') < $date) continue;
            if ($start->format('Y-m-d') !== $date || $end->modify('-1 second')->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Rozdělte práci přes půlnoc pro doložení náhrady v konkrétním dni.');
            $units = PayrollDuration::add($units, PayrollDuration::fromMinutes($minutes));
        }
        return $units;
    }

    /** @return array{\DateTimeImmutable,\DateTimeImmutable,int} */
    public static function bounds(array $row): array
    {
        $zone = new \DateTimeZone((string) $row['timezone_name']);
        $start = (new \DateTimeImmutable($row['starts_at_utc'], new \DateTimeZone('UTC')))->setTimezone($zone);
        $end = (new \DateTimeImmutable($row['ends_at_utc'], new \DateTimeZone('UTC')))->setTimezone($zone);
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        $minutes = intdiv($seconds, 60) - (int) $row['break_minutes'];
        if ($seconds <= 0 || $seconds % 60 !== 0 || $minutes < 0) throw new \InvalidArgumentException('Pracovní interval nemá platnou přesnou minutovou délku.');
        return [$start, $end, $minutes];
    }
}
