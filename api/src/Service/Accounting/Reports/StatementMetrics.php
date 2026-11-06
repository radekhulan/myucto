<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

final class StatementMetrics
{
    public static function accountViewTotals(array $report): array
    {
        if (self::is504($report)) {
            $rows = $report['profit_loss'] ?? [];
            return ['revenue' => self::rowValue($rows, 'UT.61', 'total'),
                'costs' => self::rowValue($rows, 'UT.39', 'total'),
                'profit' => self::rowValue($rows, 'UT.63', 'total')];
        }
        $profit = $report['profit_loss'];
        return ['revenue' => array_sum(array_column($profit['sections'], 'revenue_total')),
            'costs' => array_sum(array_column($profit['sections'], 'expense_total')),
            'profit' => $profit['profit']];
    }

    public static function balanceTotal(array $report, string $key): float
    {
        if (self::is504($report)) {
            $column = match ($key) {
                'assets_net' => 'assets_end',
                'liabilities_total' => 'liabilities_end',
                default => throw new \InvalidArgumentException('Neznámý součet rozvahy.'),
            };
            return self::number($report['totals'][$column] ?? null, $column);
        }
        $value = $report[$key] ?? $report['checks'][$key] ?? null;
        if (is_array($value)) $value = $value['net'] ?? $value['amount'] ?? null;
        return self::number($value, $key);
    }

    public static function balanceProfit(array $report): float
    {
        if (self::is504($report)) return self::rowValue($report['liabilities'] ?? [], 'US.7', 'end');
        foreach ($report['liabilities'] ?? [] as $row) {
            if (str_starts_with((string) ($row['row_code'] ?? ''), 'P.A.V.')) {
                return self::number($row['net'] ?? $row['amount'] ?? null, 'P.A.V.');
            }
        }
        throw new \RuntimeException('Rozvaha nemá řádek P.A.V.');
    }

    public static function incomeProfit(array $report): float
    {
        return self::is504($report)
            ? self::rowValue($report['rows'] ?? [], 'UT.63', 'total')
            : self::rowValue($report['rows'] ?? [], 'VH', 'amount');
    }

    private static function is504(array $report): bool
    {
        return match ($report['accounting_framework'] ?? 'cz_500') {
            'cz_500' => false,
            'cz_504' => true,
            default => throw new \RuntimeException('Neznámý účetní rámec výkazu.'),
        };
    }

    private static function rowValue(array $rows, string $code, string $column): float
    {
        foreach ($rows as $row) {
            if (($row['row_code'] ?? '') === $code) return self::number($row[$column] ?? null, $code . '.' . $column);
        }
        throw new \RuntimeException('Výkaz neobsahuje řádek ' . $code . '.');
    }

    private static function number(mixed $value, string $key): float
    {
        if (!is_numeric($value)) throw new \RuntimeException('Výkaz neobsahuje částku ' . $key . '.');
        return (float) $value;
    }
}
