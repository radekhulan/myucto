<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollYearClosedException extends \DomainException
{
    public function __construct(public readonly int $year)
    {
        parent::__construct(
            "Roční uzávěrka mezd za {$year} je uzavřená. Pro změnu mzdových dat rok nejprve znovu otevřete.",
        );
    }
}
