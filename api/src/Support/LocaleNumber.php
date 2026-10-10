<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Čtení čísel z uživatelských importů (CSV, XLSX jako text) bez ohledu na zvyklost
 * zdroje: „128,5", „128.5", „1 234,50", „1.234,50", „1,234.50", „1'234.50",
 * „-3,5", „(12,00)", „1 234,50 Kč".
 *
 * Pravidla:
 *  - mezery (i nezlomitelné) a apostrof jsou oddělovač tisíců,
 *  - obsahuje-li hodnota čárku i tečku, desetinný je ten poslední,
 *  - jediný oddělovač je desetinný; opakovaný („1.234.567") je oddělovač tisíců.
 */
final class LocaleNumber
{
    /** Normalizovaný desetinný řetězec („-1234.5"), nebo null, když hodnota číslo není. */
    public static function parse(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        $s = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\u{2009}", "'", '’', ' ', "\t"], '', trim($value));
        $s = (string) preg_replace('/(kč|czk|eur|€|usd|\$|km|ks)$/iu', '', $s);
        if ($s === '') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $s, $m) === 1) {
            $negative = true;
            $s = $m[1];
        }
        if (str_starts_with($s, '-') || str_starts_with($s, '−')) {
            $negative = !$negative;
            $s = ltrim(ltrim($s, '-'), '−');
        } elseif (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }
        if (str_ends_with($s, '-')) {
            $negative = !$negative;
            $s = substr($s, 0, -1);
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $s = str_replace([$thousands, $decimal], ['', '.'], $s);
        } elseif ($lastComma !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($lastDot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }

        if (preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $parts) !== 1 || ($parts[1] === '' && ($parts[2] ?? '') === '')) {
            return null;
        }
        $integer = ltrim($parts[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($parts[2] ?? '', '0');
        $result = $fraction === '' ? $integer : $integer . '.' . $fraction;

        return $negative && $result !== '0' ? '-' . $result : $result;
    }

    public static function toFloat(string|int|float|null $value): ?float
    {
        $s = self::parse($value);
        return $s === null ? null : (float) $s;
    }

    /** Celé číslo zaokrouhlené half-up („128,5" → 129), nebo null. */
    public static function toInt(string|int|float|null $value): ?int
    {
        $s = self::parse($value);
        return $s === null ? null : (int) round((float) $s, 0, PHP_ROUND_HALF_UP);
    }
}
