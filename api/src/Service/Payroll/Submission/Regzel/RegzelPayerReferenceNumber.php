<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use MyInvoice\Service\Payroll\PayrollVcp;

final class RegzelPayerReferenceNumber
{
    public static function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw self::invalid();
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!self::isValid($value)) {
            throw self::invalid();
        }
        return $value;
    }

    public static function isValid(string $value): bool
    {
        return PayrollVcp::isValid($value);
    }

    private static function invalid(): RegzelValidationException
    {
        return new RegzelValidationException(
            'regzel_payer_reference_invalid',
            'Vlastní číslo plátce REGZEL musí mít devět číslic a začínat číslicí 6.',
        );
    }
}
