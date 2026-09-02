<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Vyživovaná osoba se podílela na už spočítané měsíční srážce.
 *
 * `$frozenPeriod` je PRVNÍ takový měsíc ve tvaru `RRRR-MM`. Klient ho dostane
 * vedle hlášky, aby uměl nabídnout proklik na ten měsíc — bez něj by účetní
 * věděla jen to, že to nejde, ne kam se podívat.
 */
final class PayrollEnforcementDependantFrozenException extends \DomainException
{
    public function __construct(
        public readonly string $frozenPeriod,
        string $message,
    ) {
        parent::__construct($message);
    }
}
