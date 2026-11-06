<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

use MyInvoice\Http\IsoDate;

/** Rozdělení na sazby pouze podle doložených úseků, bez poměrného odhadu hodin. */
final class PayrollHourlyTimePartitioner
{
    /** @param list<array<string,mixed>> $terms @return list<array<string,mixed>> */
    public function terms(array $terms, string $periodStart): array
    {
        $start = self::date($periodStart);
        if ($start->format('d') !== '01') throw new \InvalidArgumentException('Období musí začínat prvním dnem měsíce.');
        $end = $start->modify('last day of this month')->format('Y-m-d');
        $result = [];
        foreach ($terms as $term) {
            $from = self::date($term['effective_from'] ?? null)->format('Y-m-d');
            $to = ($term['effective_to'] ?? null) === null ? '9999-12-31' : self::date($term['effective_to'])->format('Y-m-d');
            if ($to < $from || !is_int($term['id'] ?? null) || $term['id'] <= 0) throw new \InvalidArgumentException('Účinná verze podmínek není platná.');
            if ($to < $periodStart || $from > $end) continue;
            $result[] = [...$term, 'date_from' => max($periodStart, $from), 'date_to' => min($end, $to)];
        }
        usort($result, static fn(array $a, array $b): int => $a['date_from'] <=> $b['date_from']);
        $last = null;
        $ids = [];
        foreach ($result as $term) {
            if (($last !== null && $term['date_from'] <= $last) || isset($ids[$term['id']])) {
                throw new PayrollHourlyCalculationException('hourly_terms_overlap', 'Účinné verze podmínek se překrývají.');
            }
            $last = $term['date_to'];
            $ids[$term['id']] = true;
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $terms @param list<array<string,mixed>> $segments @return list<array<string,mixed>> */
    public function partition(array $terms, array $segments, bool $allowDateOverlap = false): array
    {
        usort($segments, static fn(array $a, array $b): int => ($a['date_from'] ?? '') <=> ($b['date_from'] ?? ''));
        $last = null;
        $result = [];
        foreach ($segments as $segment) {
            $from = self::date($segment['date_from'] ?? null)->format('Y-m-d');
            $to = self::date($segment['date_to'] ?? null)->format('Y-m-d');
            if ($to < $from || (!$allowDateOverlap && $last !== null && $from <= $last)) {
                throw new PayrollHourlyCalculationException('hourly_time_partition_invalid', 'Časové úseky se překrývají nebo mají neplatný rozsah.');
            }
            $last = $to;
            $worked = $segment['worked_duration_units'] ?? null;
            $overtime = $segment['overtime_duration_units'] ?? null;
            if (!is_int($worked) || $worked < 0 || ($overtime !== null && (!is_int($overtime) || $overtime < 0 || $overtime > $worked))) {
                throw new PayrollHourlyCalculationException('hourly_time_partition_invalid', 'Úsek nemá platnou přesnou pracovní dobu.');
            }
            if ($overtime === null) throw new PayrollHourlyCalculationException('hourly_overtime_not_provided', 'Doplňte přesčasovou část pracovní doby nebo výslovně potvrďte nulu.');
            $matching = array_values(array_filter($terms, static fn(array $term): bool => $term['date_from'] <= $from && $term['date_to'] >= $to));
            if (count($matching) !== 1) {
                throw new PayrollHourlyCalculationException('hourly_segment_crosses_terms', 'Rozdělte doloženou pracovní dobu podle účinných sazeb a způsobu odměňování.', ['date_from' => $from, 'date_to' => $to]);
            }
            $result[] = [...$segment, 'term' => $matching[0]];
        }
        return $result;
    }

    private static function date(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || !IsoDate::isValid($value)) throw new \InvalidArgumentException('Datum úseku není platné.');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Datum úseku není platné.');
        return $date;
    }
}
