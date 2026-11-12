<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Období mzdy v textech pro účetní: `2026-02` i `2026-02-01` → `02/2026`.
 *
 * Hlášky ze serveru psaly období jednou jako `2026-02`, jindy `2/2026`;
 * rozhraní ho ukazuje jako MM/RRRR, tak ho píšou i texty kontrol a importů.
 */
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
