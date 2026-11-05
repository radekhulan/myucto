<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

/** Neúplný nebo rozporný podklad je překážka výpočtu, nikoli nulový nárok. */
final class PayrollHourlyCalculationException extends \DomainException
{
    /** @param array<string,mixed> $context */
    public function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
