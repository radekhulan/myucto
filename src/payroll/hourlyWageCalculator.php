<?php
declare(strict_types=1);

namespace MyUcto\Payroll;

/**
 * Výpočet hodinové mzdy a hodinové odměny z dohody (DPP / DPČ) (#279).
 */
final class HourlyWageCalculator
{
    public static function calculateGrossReward(
        float $workedHours,
        float $hourlyRateCzk,
        float $bonusCzk = 0.0
    ): array {
        $safeHours = max(0.0, $workedHours);
        $safeRate = max(0.0, $hourlyRateCzk);
        $rawGross = ($safeHours * $safeRate) + max(0.0, $bonusCzk);
        $roundedGrossCzk = (int) ceil($rawGross);

        return [
            'worked_hours' => round($safeHours, 2),
            'hourly_rate_czk' => round($safeRate, 2),
            'gross_amount_czk' => $roundedGrossCzk,
        ];
    }
}
