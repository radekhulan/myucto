<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/** Složky, které smí vzniknout jen z auditovatelného výpočtu hodinové mzdy. */
final class PayrollHourlyWageComponents
{
    public const ORDINARY_BASE = 'MZDA_HODINOVA_ZAKLAD';
    public const ACHIEVED_OVERTIME = 'MZDA_DOSAZENA_PRESCAS';
    public const HOLIDAY_TIME_OFF = 'NAHRADA_MZDY_VOLNO_ZA_SVATEK';
    public const RESERVED = [self::ORDINARY_BASE, self::ACHIEVED_OVERTIME, self::HOLIDAY_TIME_OFF];
    public const GENERATED = [
        ...self::RESERVED, 'NAHRADA_MZDY_SVATEK', 'PRIPLATEK_PRESCAS', 'PRIPLATEK_SVATEK',
        'PRIPLATEK_NOCNI', 'PRIPLATEK_VIKEND', 'PRIPLATEK_ZTIZENE_PROSTREDI',
    ];

    public static function isReserved(string $code): bool
    {
        return in_array($code, self::RESERVED, true);
    }
}
