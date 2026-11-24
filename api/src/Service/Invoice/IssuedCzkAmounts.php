<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

final class IssuedCzkAmounts
{
    public static function gross(array $invoice, ?float $rate = null): float
    {
        return self::amount($invoice, 'total_with_vat', $rate);
    }

    public static function net(array $invoice, ?float $rate = null): float
    {
        return self::amount($invoice, 'total_without_vat', $rate);
    }

    public static function vat(array $invoice, ?float $rate = null): float
    {
        return self::amount($invoice, 'total_vat', $rate);
    }

    public static function due(array $invoice, ?float $rate = null): float
    {
        return self::amount($invoice, 'amount_to_pay', $rate);
    }

    public static function advancePaid(array $invoice, ?float $rate = null): float
    {
        return self::amount($invoice, 'advance_paid_amount', $rate, 'import_advance_paid_czk');
    }

    public static function grossSql(string $alias, ?string $fallback = null): string
    {
        return self::amountSql($alias, 'total_with_vat', $fallback);
    }

    public static function netSql(string $alias, ?string $fallback = null): string
    {
        return self::amountSql($alias, 'total_without_vat', $fallback);
    }

    public static function vatSql(string $alias, ?string $fallback = null): string
    {
        return self::amountSql($alias, 'total_vat', $fallback);
    }

    public static function dueSql(string $alias, ?string $fallback = null): string
    {
        return self::amountSql($alias, 'amount_to_pay', $fallback);
    }

    public static function remainingSql(string $alias, string $paid, string $fallback): string
    {
        return "CASE WHEN {$alias}.import_amount_to_pay_czk IS NULL THEN {$fallback}
                     WHEN {$alias}.amount_to_pay > 0 THEN {$alias}.import_amount_to_pay_czk
                          - ROUND({$alias}.import_amount_to_pay_czk * LEAST({$alias}.amount_to_pay, GREATEST({$paid}, 0)) / {$alias}.amount_to_pay, 2)
                     ELSE 0 END";
    }

    public static function allocationSql(string $alias, string $amount, string $fallback): string
    {
        return "COALESCE(ROUND({$amount} * ABS({$alias}.import_amount_to_pay_czk) / NULLIF(ABS({$alias}.amount_to_pay), 0), 2), {$fallback})";
    }

    public static function advancePaidSql(string $alias, ?string $fallback = null): string
    {
        return self::amountSql($alias, 'advance_paid_amount', $fallback, 'import_advance_paid_czk');
    }

    public static function itemBase(array $item, float $rate): float
    {
        return self::amount($item, 'total_without_vat', $rate, 'import_tax_base_czk');
    }

    public static function itemVat(array $item, float $rate): float
    {
        return self::amount($item, 'total_vat', $rate, 'import_tax_vat_czk');
    }

    /** Poměr hodnoty předpisu pro porovnání korunové platby s částkou v měně faktury. */
    public static function settlementRate(array $invoice): float
    {
        $nominal = abs((float) ($invoice['amount_to_pay'] ?? 0.0));
        $source = abs((float) ($invoice['import_amount_to_pay_czk'] ?? 0.0));
        return $nominal >= 0.005 && $source >= 0.005 ? $source / $nominal : (float) ($invoice['exchange_rate'] ?? 0.0);
    }

    /** Rozdíl kumulovaných částek v Kč ponechá poslední splátce přesný zbytek předpisu. */
    public static function allocated(array $invoice, float $amount, float $rate, float $previous = 0.0): float
    {
        $nominal = abs((float) ($invoice['amount_to_pay'] ?? 0.0));
        if (($invoice['import_amount_to_pay_czk'] ?? null) === null || $nominal < 0.005) {
            return round($amount * $rate, 2);
        }
        $due = abs((float) $invoice['import_amount_to_pay_czk']);
        $before = round($due * $previous / $nominal, 2);
        $after = round($due * ($previous + $amount) / $nominal, 2);
        return round($after - $before, 2);
    }

    private static function amount(array $values, string $field, ?float $rate, ?string $snapshot = null): float
    {
        $value = $values[$snapshot ?? 'import_' . $field . '_czk'] ?? null;
        if ($value !== null) {
            return (float) $value;
        }
        $rate ??= ($values['currency'] ?? $values['currency_code'] ?? null) === 'CZK'
            ? 1.0 : ((float) ($values['exchange_rate'] ?? 0.0) ?: 1.0);
        return round((float) ($values[$field] ?? 0.0) * $rate, 2);
    }

    private static function amountSql(string $alias, string $field, ?string $fallback, ?string $snapshot = null): string
    {
        $snapshot ??= 'import_' . $field . '_czk';
        $fallback ??= "ROUND({$alias}.{$field} * COALESCE(NULLIF({$alias}.exchange_rate, 0), 1), 2)";
        return "COALESCE({$alias}.{$snapshot}, {$fallback})";
    }
}
