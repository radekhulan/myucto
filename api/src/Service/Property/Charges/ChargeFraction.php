<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

use MyInvoice\Service\Accounting\Charges\ExactFraction;

final class ChargeFraction
{
    public static function reduce(string $num, string $den): array
    {
        return ExactFraction::reduce($num, $den);
    }
    public static function add(array $a, array $b): array
    {
        return ExactFraction::add($a, $b);
    }
    public static function multiply(array $a, array $b): array
    {
        return ExactFraction::multiply($a, $b);
    }
    public static function decimalCents(mixed $value): array
    {
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', $value)) {
            throw new ChargeException('property_charge_amount_invalid', 'Sazba vyžaduje přesné desetinné číslo zapsané jako text.');
        }
        return ExactFraction::decimalCents($value);
    }
    public static function round(array $a): string
    {
        return ExactFraction::round($a);
    }
}
