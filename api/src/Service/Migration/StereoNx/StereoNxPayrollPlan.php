<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Kontrola zdrojových mzdových vazeb; neinterpretuje mzdy ani je nezapisuje. */
final class StereoNxPayrollPlan
{
    /** @param array<string,list<array<string,mixed>>> $tables */
    public static function fromTables(array $tables): array
    {
        foreach (['MZAMEST', 'MMzdy'] as $name) {
            if (!array_key_exists($name, $tables)) {
                throw new StereoNxException('payroll_table_missing', 'Chybí zdrojová mzdová tabulka.');
            }
        }
        $diagnostics = [];
        $people = [];
        foreach ($tables['MZAMEST'] as $row) {
            $key = self::text($row['Prac'] ?? null);
            if ($key === '') {
                self::add($diagnostics, 'employee_key_missing');
                continue;
            }
            if (isset($people[$key])) {
                self::add($diagnostics, 'employee_key_duplicate');
            }
            $people[$key] = true;
            foreach (['KrestniJmeno', 'Prijmeni'] as $field) {
                if (self::text($row[$field] ?? null) === '') self::add($diagnostics, 'employee_required_field_missing');
            }
            if (!self::date($row['DatumNastupu'] ?? null)
                || (self::text($row['DatumUkonceni'] ?? null) !== '' && !self::date($row['DatumUkonceni']))) {
                self::add($diagnostics, 'employee_date_invalid');
            } elseif (self::text($row['DatumUkonceni'] ?? null) !== '' && $row['DatumUkonceni'] < $row['DatumNastupu']) {
                self::add($diagnostics, 'employee_date_order_invalid');
            }
        }
        $linked = 0;
        $orphan = 0;
        $periods = [];
        $keys = [];
        foreach ($tables['MMzdy'] as $row) {
            $key = $row['Klic'] ?? null;
            if (!is_int($key) || $key < 0) {
                self::add($diagnostics, 'monthly_key_invalid');
            } elseif (isset($keys[$key])) {
                self::add($diagnostics, 'monthly_key_duplicate');
            } else {
                $keys[$key] = true;
            }
            $person = self::text($row['Prac'] ?? null);
            if ($person === '' || !isset($people[$person])) {
                $orphan++;
                self::add($diagnostics, 'monthly_employee_orphan');
            } else {
                $linked++;
            }
            $year = $row['Rok'] ?? null;
            $month = $row['Mesic'] ?? null;
            if (!is_int($year) || $year < 1900 || $year > 9999 || !is_int($month) || $month < 1 || $month > 12) {
                self::add($diagnostics, 'monthly_period_invalid');
            } else {
                $periods[sprintf('%04d-%02d', $year, $month)] = true;
            }
        }
        ksort($periods);
        return ['ok' => $diagnostics === [], 'counts' => [
            'employees' => count($tables['MZAMEST']), 'employee_keys' => count($people),
            'monthly' => count($tables['MMzdy']), 'monthly_keys' => count($keys),
            'monthly_linked' => $linked, 'monthly_orphan' => $orphan,
        ], 'periods' => array_keys($periods), 'diagnostics' => $diagnostics];
    }

    public static function build(StereoNxBackup $backup): array
    {
        return self::fromTables([
            'MZAMEST' => iterator_to_array($backup->rows('MZAMEST'), false),
            'MMzdy' => iterator_to_array($backup->rows('MMzdy'), false),
        ]);
    }

    private static function add(array &$diagnostics, string $code): void
    {
        $diagnostics[$code] = ($diagnostics[$code] ?? 0) + 1;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function date(mixed $value): bool
    {
        $text = self::text($value);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $text)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        return $date !== false && $date->format('Y-m-d') === $text;
    }
}
