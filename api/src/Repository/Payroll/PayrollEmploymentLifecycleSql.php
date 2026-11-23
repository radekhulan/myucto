<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollEmploymentLifecycleSql
{
    public static function effectiveStatusAtPlaceholder(): string
    {
        return self::effectiveStatusAt('?');
    }

    /**
     * Stav vztahu k danému dni; `$effectiveOnSql` je zástupný znak nebo SQL výraz
     * (sloupec období připojeného běhu).
     */
    public static function effectiveStatusAt(string $effectiveOnSql): string
    {
        return 'COALESCE(
                    (
                        SELECT lifecycle.to_status
                          FROM payroll_employment_events lifecycle
                         WHERE lifecycle.supplier_id = employment.supplier_id
                           AND lifecycle.employment_id = employment.id
                           AND lifecycle.event_type IN ("created", "status_changed")
                           AND lifecycle.to_status IS NOT NULL
                           AND lifecycle.effective_on <= ' . $effectiveOnSql . '
                         ORDER BY lifecycle.effective_on DESC, lifecycle.id DESC
                         LIMIT 1
                    ),
                    CASE WHEN NOT EXISTS (
                        SELECT 1
                          FROM payroll_employment_events lifecycle
                         WHERE lifecycle.supplier_id = employment.supplier_id
                           AND lifecycle.employment_id = employment.id
                           AND lifecycle.event_type IN ("created", "status_changed")
                    ) THEN employment.status ELSE NULL END
                )';
    }

    /**
     * Pracovní vztahy, které patří do mzdového období — jediné pravidlo pro
     * snímek mzdového běhu ({@see \MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder::employmentRows()})
     * i Rychlý měsíční vstup. Dokud měl rychlý vstup vlastní kopii (jen
     * aktivní, přerušené a ukončené), běh zahrnul plánovaný vztah s nástupem
     * v měsíci a blokoval ho chybějící mzdou, kterou nebylo kde zadat.
     *
     * Očekává alias `employment` se sloupcem `effective_status` (stav k období,
     * {@see effectiveStatusAtPlaceholder()}). Parametry v pořadí
     * {@see payrollPeriodMembershipParams()}.
     *
     * Místo zástupných znaků jde předat SQL výrazy prvního a posledního dne
     * období (sloupce připojeného běhu); pak dotaz žádné parametry členství
     * nenese. Takhle se totéž pravidlo ptá „které vztahy schválenému běhu
     * chybí" ({@see PayrollRunMissingEmploymentRepository}).
     */
    public static function payrollPeriodMembershipSql(
        string $periodStartSql = '?',
        string $periodEndSql = '?',
    ): string {
        return 'employment.effective_status IS NOT NULL
                AND employment.effective_status NOT IN ("archived", "no_show")
                AND COALESCE(
                    employment.actual_start_date,
                    employment.start_date,
                    "1900-01-01"
                ) <= ' . $periodEndSql . '
                AND (
                    employment.end_date IS NULL
                    OR employment.end_date >= ' . $periodStartSql . '
                    OR EXISTS (
                        SELECT 1
                          FROM payroll_inputs post_termination_input
                         WHERE post_termination_input.supplier_id =
                               employment.supplier_id
                           AND post_termination_input.employment_id =
                               employment.id
                           AND post_termination_input.period_start = ' . $periodStartSql . '
                           AND post_termination_input.status <> "cancelled"
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM payroll_employment_deferred_incomes deferred_without_income
                         WHERE deferred_without_income.supplier_id =
                               employment.supplier_id
                           AND deferred_without_income.employment_id =
                               employment.id
                           AND deferred_without_income.period_start = ' . $periodStartSql . '
                           AND deferred_without_income.deferred_type IN (' . implode(', ', array_map(
                               static fn (string $type): string => '"' . $type . '"',
                               PayrollDeferredIncomeRepository::TYPES_WITHOUT_INCOME,
                           )) . ')
                    )
                )';
    }

    /** @return list<string> */
    public static function payrollPeriodMembershipParams(string $periodStart, string $periodEnd): array
    {
        return [$periodEnd, $periodStart, $periodStart, $periodStart];
    }

    public static function effectiveMonthlyGrossAtPlaceholder(): string
    {
        return self::effectiveMonthlyGrossAt('?');
    }

    public static function effectiveMonthlyGrossToday(): string
    {
        return self::effectiveMonthlyGrossAt('CURDATE()');
    }

    private static function effectiveMonthlyGrossAt(string $effectiveOnSql): string
    {
        // Hodinová verze se značí -1; CAST drží celočíselný typ jako u měsíční mzdy.
        return 'CAST(NULLIF(COALESCE(
                    (
                        SELECT CASE WHEN salary_term.remuneration_type = "hourly" THEN -1
                                    ELSE salary_term.monthly_gross_minor END
                          FROM payroll_employment_terms salary_term
                         WHERE salary_term.supplier_id = employment.supplier_id
                           AND salary_term.employment_id = employment.id
                           AND salary_term.effective_from <= ' . $effectiveOnSql . '
                           AND (
                               salary_term.effective_to IS NULL
                               OR salary_term.effective_to >= ' . $effectiveOnSql . '
                           )
                         ORDER BY salary_term.effective_from DESC, salary_term.id DESC
                         LIMIT 1
                    ),
                    employment.monthly_gross_minor
                ), -1) AS SIGNED)';
    }
}
