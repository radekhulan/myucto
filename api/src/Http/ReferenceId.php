<?php

declare(strict_types=1);

namespace MyInvoice\Http;

final class ReferenceId
{
    public static function optional(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            throw new \InvalidArgumentException('Neplatné ID vazby.');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0 || $number !== floor($number) || $number >= (float) PHP_INT_MAX) {
            throw new \InvalidArgumentException('Neplatné ID vazby.');
        }
        return $number == 0 ? null : (int) $value;
    }
}
