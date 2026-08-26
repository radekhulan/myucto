<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

final class EuropeanEconomicAreaCountries
{
    private const CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MT',
        'NL', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public static function contains(string $countryCode): bool
    {
        return in_array($countryCode, self::CODES, true);
    }
}
