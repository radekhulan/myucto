<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollYearCloseBlockedException extends \DomainException
{
    /** @param list<array<string,mixed>> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('Roční uzávěrku mezd blokují neuzavřené podklady.');
    }
}
