<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

final class TimeBilling
{
    private const INT_MIN = -2147483648;
    private const INT_MAX = 2147483647;
    private const INVOICE_MAX_ABS_MINUTES = 599999999;
    private const WORK_REPORT_MAX_MINUTES = 599999;

    private const HOUR_UNITS = [
        'h', 'hod', 'hod.', 'hodina', 'hodiny', 'hour', 'hours', 'hr', 'hrs',
    ];

    public static function isHourUnit(mixed $unit): bool
    {
        return in_array(mb_strtolower(trim((string) $unit)), self::HOUR_UNITS, true);
    }

    public static function durationMinutes(array $item): ?int
    {
        $value = $item['duration_minutes'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value >= self::INT_MIN && $value <= self::INT_MAX) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => self::INT_MIN, 'max_range' => self::INT_MAX],
            ]);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value
            && $value >= self::INT_MIN && $value <= self::INT_MAX
        ) {
            return (int) $value;
        }

        throw new \InvalidArgumentException('Délka práce musí být zadaná v celých minutách.');
    }

    public static function inferDurationMinutes(mixed $quantity, mixed $unit, mixed $stockItemId = null): ?int
    {
        if (!is_numeric($quantity) || !self::isHourUnit($unit)
            || ($stockItemId !== null && $stockItemId !== '' && (int) $stockItemId > 0)
        ) {
            return null;
        }

        $hours = (float) $quantity;
        if (!is_finite($hours)) {
            return null;
        }
        if (abs($hours - round($hours, 3)) <= 0.0000000001) {
            return null;
        }
        $minutes = $hours * 60;
        $rounded = round($minutes);
        if ($rounded == 0.0 || abs($minutes - $rounded) > 0.0000001
            || abs($rounded) > self::INVOICE_MAX_ABS_MINUTES
        ) {
            return null;
        }

        return (int) $rounded;
    }

    public static function invoiceAmountInput(array $item): float
    {
        $price = (float) ($item['unit_price_without_vat'] ?? 0);
        $durationMinutes = self::durationMinutes($item);
        if ($durationMinutes !== null) {
            return (float) self::durationAmountDecimal($durationMinutes, $price);
        }

        return (float) ($item['quantity'] ?? 0) * $price;
    }

    public static function invoiceAmount(array $item): float
    {
        $price = (float) ($item['unit_price_without_vat'] ?? 0);
        $durationMinutes = self::durationMinutes($item);
        if ($durationMinutes !== null) {
            return (float) bcround(self::durationAmountDecimal($durationMinutes, $price), 2);
        }

        return round((float) ($item['quantity'] ?? 0) * $price, 2);
    }

    public static function workAmountInput(array $item): float
    {
        $rate = (float) ($item['rate'] ?? 0);
        $durationMinutes = self::durationMinutes($item);
        if ($durationMinutes !== null) {
            return (float) self::durationAmountDecimal($durationMinutes, $rate);
        }

        return (float) ($item['hours'] ?? 0) * $rate;
    }

    public static function workAmount(array $item): float
    {
        $rate = (float) ($item['rate'] ?? 0);
        $durationMinutes = self::durationMinutes($item);
        if ($durationMinutes !== null) {
            return (float) bcround(self::durationAmountDecimal($durationMinutes, $rate), 2);
        }

        return round((float) ($item['hours'] ?? 0) * $rate, 2);
    }

    public static function normalizeInvoiceItem(array $item): array
    {
        $durationMinutes = self::durationMinutes($item);
        $stockLinked = isset($item['stock_item_id']) && (int) $item['stock_item_id'] > 0;

        if ($durationMinutes !== null) {
            if ($durationMinutes === 0) {
                throw new \InvalidArgumentException('Délka časové položky nesmí být nula minut.');
            }
            if ($stockLinked) {
                throw new \InvalidArgumentException('Skladová položka nemůže mít délku práce v minutách.');
            }
            if (!self::isHourUnit($item['unit'] ?? null)) {
                throw new \InvalidArgumentException('Délku v minutách lze použít jen u položky s hodinovou měrnou jednotkou.');
            }
            if (abs($durationMinutes) > self::INVOICE_MAX_ABS_MINUTES) {
                throw new \InvalidArgumentException('Délka časové položky je příliš vysoká.');
            }

            $item['quantity'] = $durationMinutes / 60;
        }

        $item['duration_minutes'] = $durationMinutes;
        $item['unit_price_without_vat'] = UnitPricePrecision::normalize((float) ($item['unit_price_without_vat'] ?? 0));

        return $item;
    }

    public static function normalizeWorkReportItem(array $item): array
    {
        $durationMinutes = self::durationMinutes($item);
        if ($durationMinutes !== null) {
            if ($durationMinutes <= 0) {
                throw new \InvalidArgumentException('Délka práce musí být větší než nula minut.');
            }
            if ($durationMinutes > self::WORK_REPORT_MAX_MINUTES) {
                throw new \InvalidArgumentException('Délka práce je příliš vysoká.');
            }
            $item['hours'] = $durationMinutes / 60;
        }

        $item['duration_minutes'] = $durationMinutes;
        $item['rate'] = round((float) ($item['rate'] ?? 0), 6);

        return $item;
    }

    private static function durationAmountDecimal(int $durationMinutes, float $rate): string
    {
        $rateDecimal = number_format($rate, 6, '.', '');

        return bcdiv(bcmul((string) $durationMinutes, $rateDecimal, 6), '60', 18);
    }
}
