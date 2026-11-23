<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Odkaz na formulář průměrného výdělku (Absence a dovolená → Průměrný výdělek)
 * s předvyplněným vztahem a čtvrtletím. Jediné místo, kde vzniká, aby kontrola
 * běhu i náhled hodinové mzdy vedly na stejnou adresu.
 */
final class PayrollHourlyAverageEarningLink
{
    public static function path(int $employmentId, int $year, int $quarter, ?string $periodStart = null): string
    {
        return '/payroll/absences?' . http_build_query(array_filter([
            'tab' => 'averages',
            'employment' => $employmentId,
            'year' => $year,
            'quarter' => $quarter,
            'period' => $periodStart === null ? null : substr($periodStart, 0, 7),
        ], static fn ($value): bool => $value !== null));
    }
}
