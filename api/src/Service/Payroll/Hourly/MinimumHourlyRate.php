<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

use MyInvoice\Service\Payroll\Calculation\RoundingMode;

/** Hodinové minimum podle § 111 pro skutečnou stanovenou, nikoli sjednanou kratší dobu. */
final class MinimumHourlyRate
{
    /**
     * @param int $standardWeeklyMinutes obecná stanovená týdenní doba, ke které se základní
     *        sazba vztahuje (pravidlo `minimum_wage.standard_weekly_minutes`, § 79 odst. 1)
     */
    public static function calculate(int $base40Minor, int $statedWeeklyMinutes, int $standardWeeklyMinutes = 2400): int
    {
        if ($base40Minor <= 0 || $standardWeeklyMinutes <= 0 || $statedWeeklyMinutes <= 0 || $statedWeeklyMinutes > $standardWeeklyMinutes) {
            throw new \InvalidArgumentException('Pro hodinové minimum doložte kladnou základní sazbu a stanovenou týdenní dobu nejvýše 40 hodin.');
        }
        // Nová hodinová cesta používá desetihaléře nahoru i pro přepočet § 111 odst. 5.
        // Legacy MinimumWageFloor má vlastní historický kontrakt; tato funkce jej nemění.
        $tenths = PayrollExactFraction::product([$base40Minor, $standardWeeklyMinutes], [$statedWeeklyMinutes, 10]);
        $rounded = RoundingMode::Ceil->roundFraction($tenths->numerator, $tenths->denominator);
        if ($rounded > intdiv(PHP_INT_MAX, 10)) throw new \OverflowException('Hodinové minimum překročilo celočíselný rozsah.');
        return $rounded * 10;
    }
}
