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
            'Podmínky platné od tohoto data nejde opravit - za období %d/%d '
            . 'je mzda zaúčtovaná nebo vyplacená. Pro zpětnou opravu otevřete opravu běhu '
            . 'za tento měsíc a za všechny další vyúčtované měsíce opravované verze podmínek. '
            . 'Otevřená oprava jiného měsíce nestačí. Po uložení zvolte Obnovit podklady '
            . 'a mzdy znovu přepočítejte. Změnu platnou až do budoucna zapište jako novou '
            . 'verzi podmínek od data, kdy začíná platit.',
            (int) substr($settledPeriod, 5, 2),
            (int) substr($settledPeriod, 0, 4),
        ));
    }
}
