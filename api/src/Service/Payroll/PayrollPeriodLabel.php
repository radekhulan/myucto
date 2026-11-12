<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/** `2026-02` i `2026-02-01` → `02/2026` */
final class PayrollPeriodLabel
{
    public static function monthYear(string $period): string
    {
        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?/', $period, $match) !== 1) {
            return $period;
        }

        return $match[2] . '/' . $match[1];
    }
}
