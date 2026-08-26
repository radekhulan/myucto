<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollVcp
{
    public static function isValid(string $value): bool
    {
        return preg_match('/^6[0-9]{8}$/D', $value) === 1;
    }

    public static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/', '', trim($value));
        if (!is_string($value) || !self::isValid($value)) {
            throw new \InvalidArgumentException(
                'VČP musí obsahovat přesně devět číslic a začínat číslicí 6.',
            );
        }
        return $value;
    }
}
