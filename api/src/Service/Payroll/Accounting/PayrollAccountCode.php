<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Accounting;

final class PayrollAccountCode
{
    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9]{3}[.A-Z0-9]{0,13}$/D', $value) === 1;
    }
}
