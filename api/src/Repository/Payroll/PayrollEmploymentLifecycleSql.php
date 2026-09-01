<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollEmploymentLifecycleSql
{
    public static function effectiveStatusAtPlaceholder(): string
    {
        return 'COALESCE(
                    (
                        SELECT lifecycle.to_status
                          FROM payroll_employment_events lifecycle
                         WHERE lifecycle.supplier_id = employment.supplier_id
                           AND lifecycle.employment_id = employment.id
                           AND lifecycle.event_type IN ("created", "status_changed")
                           AND lifecycle.to_status IS NOT NULL
                           AND lifecycle.effective_on <= ?
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
        return 'COALESCE(
                    (
                        SELECT salary_term.monthly_gross_minor
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
                )';
    }
}
