<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Verzi podmínek už nejde opravit na místě — bylo z ní zúčtováno.
 *
 * Nese období nejstaršího uzavřeného běhu, aby uživatel z hlášky poznal,
 * OD KDY má novou verzi založit, a nemusel to hledat v přehledu běhů.
 */
final class PayrollTermsSettledException extends \DomainException
{
    public function __construct(public readonly string $settledPeriod)
    {
        parent::__construct(sprintf(
            'Podmínky platné od tohoto data už nejde opravit — za období %d/%d '
            . 'je mzda zaúčtovaná nebo vyplacená. Změnu zapište jako novou verzi '
            . 'podmínek od data, kdy začíná platit.',
            (int) substr($settledPeriod, 5, 2),
            (int) substr($settledPeriod, 0, 4),
        ));
    }
}
