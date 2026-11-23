<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Http\IsoDate;

/** Ruční doložení času; sazby, pravidla a disponibilní zůstatky klient neurčuje. */
final class PayrollHourlyEvidenceBody
{
    public static function period(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}$/D', $value) !== 1 || !IsoDate::isValid($value . '-01')) {
            throw new \InvalidArgumentException('Období musí být platný měsíc YYYY-MM.');
        }
        return $value . '-01';
    }

    /** @return array<string,mixed> */
    public static function normalize(array $input, string $periodStart): array
    {
        $last = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $premiums = [];
        $previous = null;
        foreach (self::rows($input, 'premium_segments', 62) as $row) {
            $from = self::date($row['date_from'] ?? null, $periodStart, $last);
            $to = self::date($row['date_to'] ?? null, $from, $last);
            if ($previous !== null && $from <= $previous) throw new \InvalidArgumentException('Příplatkové úseky musí být seřazené a nesmějí se překrývat.');
            $previous = $to;
            $item = ['date_from' => $from, 'date_to' => $to];
            foreach (['night_duration_units', 'weekend_duration_units', 'holiday_work_duration_units'] as $key) {
                $item[$key] = ($row[$key] ?? null) === null ? null : self::integer($row[$key], 0);
            }
            $item['difficult_exposures'] = null;
            if (($row['difficult_exposures'] ?? null) !== null) {
                $item['difficult_exposures'] = [];
                foreach (self::rows($row, 'difficult_exposures', 255) as $exposure) {
                    $item['difficult_exposures'][] = ['duration_units' => self::integer($exposure['duration_units'] ?? null, 1),
                        'factors' => self::integer($exposure['factors'] ?? null, 1, 255)];
                }
            }
            foreach (['holiday_work_days', 'overtime_work_days'] as $key) {
                $item[$key] = [];
                $seen = [];
                foreach (self::rows($row, $key, 31) as $day) {
                    $date = self::date($day['date'] ?? null, $from, $to);
                    if (isset($seen[$date])) throw new \InvalidArgumentException('Den příplatkové práce je uveden vícekrát.');
                    $seen[$date] = true;
                    $item[$key][] = ['date' => $date, 'duration_units' => self::integer($day['duration_units'] ?? null, 1, 72_000)];
                }
            }
            $premiums[] = $item;
        }
        // Neuvedená skupina (null) se posuzuje automaticky při každém výpočtu.
        $assessed = static fn(string $key): bool => ($input[$key] ?? null) !== null;
        $nonworked = [];
        $seen = [];
        foreach ($assessed('nonworked_holidays') ? self::rows($input, 'nonworked_holidays', 31) : [] as $row) {
            $date = self::date($row['date'] ?? null, $periodStart, $last);
            if (isset($seen[$date])) throw new \InvalidArgumentException('Neodpracovaný svátek je uveden vícekrát.');
            $seen[$date] = true;
            $nonworked[] = ['date' => $date, 'duration_units' => self::integer($row['duration_units'] ?? null, 1, 72_000)];
        }
        $grants = [];
        foreach ($assessed('holiday_time_off_grants') ? self::rows($input, 'holiday_time_off_grants', 124) : [] as $row) {
            $grants[] = ['entitlement_id' => self::integer($row['entitlement_id'] ?? null, 1, PHP_INT_MAX),
                'entitlement_revision' => self::integer($row['entitlement_revision'] ?? null, 1, PHP_INT_MAX),
                'granted_on' => self::date($row['granted_on'] ?? null, $periodStart, $last),
                'duration_units' => self::integer($row['duration_units'] ?? null, 1, 72_000),
                'absence_id' => self::integer($row['absence_id'] ?? null, 1, PHP_INT_MAX)];
        }
        $allocations = [];
        foreach ($assessed('overtime_time_off_allocations') ? self::rows($input, 'overtime_time_off_allocations', 124) : [] as $row) {
            $granted = self::date($row['granted_on'] ?? null, $periodStart, $last);
            $allocations[] = ['work_date' => self::date($row['work_date'] ?? null, '1900-01-01', $granted),
                'granted_on' => $granted, 'duration_units' => self::integer($row['duration_units'] ?? null, 1, 72_000),
                'absence_id' => self::integer($row['absence_id'] ?? null, 1, PHP_INT_MAX),
                'reference' => self::text($row['reference'] ?? null)];
        }
        return ['premium_segments' => $premiums, 'nonworked_holidays' => $assessed('nonworked_holidays') ? $nonworked : null,
            'holiday_time_off_grants' => $assessed('holiday_time_off_grants') ? $grants : null,
            'overtime_time_off_allocations' => $assessed('overtime_time_off_allocations') ? $allocations : null];
    }

    public static function integer(mixed $value, int $minimum = 0, int $maximum = 2_232_000): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) throw new \InvalidArgumentException('Čas a odkaz musí být celé číslo v povoleném rozsahu.');
        return $value;
    }

    public static function text(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 500) throw new \InvalidArgumentException('Důvod nebo označení podkladu musí mít 1 až 500 znaků.');
        return trim($value);
    }

    /** @return list<array<string,mixed>> */
    private static function rows(array $input, string $key, int $maximum): array
    {
        $rows = $input[$key] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $maximum) throw new \InvalidArgumentException('Doložte seznam ' . $key . ', nebo výslovně potvrďte prázdný seznam.');
        foreach ($rows as $row) if (!is_array($row) || array_is_list($row)) throw new \InvalidArgumentException('Položka evidence musí být objekt.');
        return $rows;
    }

    private static function date(mixed $value, string $from, string $to): string
    {
        if (!is_string($value) || !IsoDate::isValid($value) || $value < $from || $value > $to) throw new \InvalidArgumentException('Datum evidence musí být platné a patřit do doloženého období.');
        return $value;
    }
}
