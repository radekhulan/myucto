<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

/** Přesná doba: společná celočíselná jednotka minut a tisícin hodiny. */
final class PayrollDuration
{
    public const UNITS_PER_HOUR = 3000;

    public static function fromMinutes(int $minutes): int
    {
        return self::multiply($minutes, 50);
    }

    public static function fromMillihours(int $millihours): int
    {
        return self::multiply($millihours, 3);
    }

    public static function exactMinutes(int $units): ?int
    {
        self::nonNegative($units);
        return $units % 50 === 0 ? intdiv($units, 50) : null;
    }

    public static function exactMillihours(int $units): ?int
    {
        self::nonNegative($units);
        return $units % 3 === 0 ? intdiv($units, 3) : null;
    }

    /** Projekce pro hlášení; zdrojová přesná doba se tím nemění. */
    public static function roundedMillihours(int $units): int
    {
        self::nonNegative($units);
        return intdiv($units, 3) + ($units % 3 >= 2 ? 1 : 0);
    }

    /** Přijímá H:MM nebo desetinné hodiny, nejvýše na tři místa. */
    public static function parseHours(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^([0-9]+):([0-5][0-9])$/D', $value, $parts) === 1) {
            return self::add(self::multiply(self::integer($parts[1]), self::UNITS_PER_HOUR), (int) $parts[2] * 50);
        }
        if (preg_match('/^([0-9]+)(?:[.,]([0-9]{1,3}))?$/D', $value, $parts) === 1) {
            return self::add(self::multiply(self::integer($parts[1]), self::UNITS_PER_HOUR), (int) str_pad($parts[2] ?? '', 3, '0') * 3);
        }
        throw new \InvalidArgumentException('Dobu zadejte jako H:MM nebo hodiny nejvýše na tři desetinná místa.');
    }

    public static function add(int $left, int $right): int
    {
        self::nonNegative($left);
        self::nonNegative($right);
        if ($left > PHP_INT_MAX - $right) {
            throw new \OverflowException('Součet pracovní doby překročil celočíselný rozsah.');
        }
        return $left + $right;
    }

    private static function integer(string $value): int
    {
        $value = ltrim($value, '0');
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            throw new \OverflowException('Pracovní doba překročila celočíselný rozsah.');
        }
        return (int) $value;
    }

    private static function multiply(int $value, int $factor): int
    {
        self::nonNegative($value);
        if ($value > intdiv(PHP_INT_MAX, $factor)) {
            throw new \OverflowException('Pracovní doba překročila celočíselný rozsah.');
        }
        return $value * $factor;
    }

    private static function nonNegative(int $value): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Pracovní doba nesmí být záporná.');
        }
    }
}
