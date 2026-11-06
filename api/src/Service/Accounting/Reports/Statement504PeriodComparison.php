<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class Statement504PeriodComparison
{
    public static function difference(array $current, array $previous): array
    {
        foreach (['accounting_framework','statement_type','version_code','scope','period'] as $key) {
            if (!isset($current[$key], $previous[$key]) || $current[$key] !== $previous[$key]) self::invalid();
        }
        if ($current['accounting_framework'] !== 'cz_504' || $current['statement_type'] !== 'income_statement'
            || !isset($previous['as_of'], $current['as_of']) || $previous['as_of'] >= $current['as_of']) self::invalid();
        $before = self::indexed($previous['rows'] ?? []);
        $after = self::indexed($current['rows'] ?? []);
        if (array_diff_key($before, $after) !== [] || array_diff_key($after, $before) !== []) self::invalid();
        $out = [];
        foreach ($after as $code => $row) {
            if (isset($before[$code]['components']) && !isset($row['components'])) self::invalid();
            if (isset($row['components'])) {
                $old = array_column($before[$code]['components'] ?? [], null, 'key');
                foreach ($row['components'] as &$part) {
                    foreach (['main', 'economic', 'total'] as $column) {
                        $prior = $old === [] ? ($part['key'] === 'current_tax' ? $before[$code][$column] : 0) : $old[$part['key']][$column];
                        $part[$column] = (self::cents($part[$column]) - self::cents($prior)) / 100.0;
                    }
                }
                unset($part);
            }
            foreach (['main','economic','total'] as $column) {
                $row[$column] = (self::cents($row[$column]) - self::cents($before[$code][$column])) / 100.0;
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function indexed(array $rows): array
    {
        if ($rows === []) self::invalid();
        $out = [];
        foreach ($rows as $row) {
            $code = $row['row_code'] ?? '';
            if ($code === '' || isset($out[$code])) self::invalid();
            foreach (['main','economic','total'] as $column) {
                if (!isset($row[$column]) || !is_numeric($row[$column]) || !is_finite((float) $row[$column])) self::invalid();
            }
            if (self::cents($row['main']) + self::cents($row['economic']) !== self::cents($row['total'])) self::invalid();
            if (isset($row['components'])) {
                if (!is_array($row['components']) || array_column($row['components'], 'key') !== ['current_tax', 'deferred_tax']) self::invalid();
                foreach (['main', 'economic', 'total'] as $column) {
                    $sum = 0;
                    foreach ($row['components'] as $part) {
                        if (!isset($part[$column]) || !is_numeric($part[$column]) || !is_finite((float) $part[$column])) self::invalid();
                        $sum += self::cents($part[$column]);
                    }
                    if ($sum !== self::cents($row[$column])) self::invalid();
                }
                foreach ($row['components'] as $part) if (self::cents($part['main']) + self::cents($part['economic']) !== self::cents($part['total'])) self::invalid();
            }
            $out[$code] = $row;
        }
        return $out;
    }

    private static function cents(float|int|string $amount): int
    {
        return JournalLineAmount::signedCents(['amount' => $amount]);
    }

    private static function invalid(): never
    {
        throw new ReportException('statement_period_comparison_invalid', 'Výkazy 504 nelze porovnat: liší se období, rozsah nebo členění řádků.');
    }
}
