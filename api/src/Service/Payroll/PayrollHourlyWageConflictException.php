<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/** Tentýž mzdový nárok současně vlastní více zdrojů. */
final class PayrollHourlyWageConflictException extends \InvalidArgumentException
{
    public const ERROR_CODE = 'hourly_external_amount_conflict';
}
