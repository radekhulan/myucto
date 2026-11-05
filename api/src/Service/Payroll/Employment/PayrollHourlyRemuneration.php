<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Employment;

/** Společné hranice hodinového odměňování a připravenosti mzdového běhu. */
final class PayrollHourlyRemuneration
{
    public const BLOCKER = 'hourly_remuneration_calculation_unsupported';

    public const MESSAGE = 'Vztah má v tomto měsíci sjednané hodinové odměňování. '
        . 'Náhled vypočítá mzdu z doložených hodin včetně kontroly minimální mzdy; schválení běhu zatím vyžaduje dokončení výkaznictví.';

    /**
     * Překryv kterékoliv hodinové revize s obdobím, nikoli jen stav na konci měsíce.
     * Dva parametry: konec a začátek období. Alias employment je interní kontrakt dotazů.
     */
    public static function overlapsPeriodSql(): string
    {
        return 'EXISTS (
            SELECT 1 FROM payroll_employment_terms hourly_term FORCE INDEX (idx_payroll_employment_term_effective)
             WHERE hourly_term.supplier_id = employment.supplier_id
               AND hourly_term.employment_id = employment.id
               AND hourly_term.remuneration_type = "hourly"
               AND hourly_term.effective_from <= ?
               AND (hourly_term.effective_to IS NULL OR hourly_term.effective_to >= ?)
        )';
    }

    /** @param array<string,mixed> $row */
    public static function blocksMonthlyComponent(array $row): bool
    {
        return (bool) ($row['hourly_remuneration_in_period'] ?? false)
            && (($row['component_kind'] ?? null) === 'base_wage'
                || ($row['calculation_kind'] ?? null) === 'employment_gross_basis_points');
    }
}
