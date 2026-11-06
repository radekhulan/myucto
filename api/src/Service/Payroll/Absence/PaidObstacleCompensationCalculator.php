<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;

/**
 * Náhrada mzdy za placenou překážku v práci (§ 199, § 203, § 205, § 207 až
 * § 209 ZP).
 *
 * Počítá se z průměrného hodinového výdělku zjištěného k prvnímu dni čtvrtletí
 * (§ 354 odst. 1 ZP) krát sazba druhu překážky ({@see PayrollObstacleKind}).
 * Průměr se neredukuje — redukční hranice zná jen § 192 odst. 2 pro nemoc.
 *
 * Zaokrouhlení je shodné s náhradou za dovolenou ({@see LeaveCompensationCalculator}):
 * mezikroky přesným zlomkem, výsledek na celé koruny nahoru za kalendářní
 * měsíc (§ 142 odst. 2 ZP přes § 144). Sazba je v bazických bodech, aby šla
 * i sjednaná náhrada jako 75,5 %.
 *
 * Svátek uvnitř překážky se nenahrazuje: za svátek se měsíční mzda nekrátí
 * (§ 115 odst. 3 ZP) a krácení mzdy ho z nahrazené doby vynechává
 * ({@see PayrollWageProrationService}). Hodiny i peníze tak stojí na týchž
 * minutách.
 */
final class PaidObstacleCompensationCalculator
{
    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     * @param array<string,mixed> $holidays datum => svátek
     * @return array{minutes:array<string,int>,amounts:array<string,int>,full_rate_amounts:array<string,int>}
     */
    public static function calculate(
        int $averageHourlyMinor,
        int $rateBasisPoints,
        array $segments,
        array $holidays,
        PayrollWageRounding $rounding = PayrollWageRounding::Ceil,
        array $averagesByDate = [],
    ): array {
        if ($averageHourlyMinor <= 0) {
            throw new InvalidArgumentException('Náhrada mzdy za překážku vyžaduje kladný hodinový průměr.');
        }
        if ($rateBasisPoints <= 0 || $rateBasisPoints > PayrollObstacleKind::FULL_RATE_BASIS_POINTS) {
            throw new InvalidArgumentException('Sazba náhrady mzdy musí být 0,01 až 100 % průměru.');
        }
        $minutesByPeriod = [];
        $weightedByPeriod = [];
        foreach ($segments as $segment) {
            $date = (string) $segment['local_date'];
            $eligible = (int) $segment['eligible_minutes'];
            if ($eligible <= 0 || $eligible > (int) $segment['planned_minutes']) {
                throw new InvalidArgumentException('Minuty překážky v práci nejsou platné.');
            }
            if (array_key_exists($date, $holidays)) {
                continue;
            }
            $period = substr($date, 0, 7) . '-01';
            $minutesByPeriod[$period] = ($minutesByPeriod[$period] ?? 0) + $eligible;
            $average = $averagesByDate[$date] ?? $averageHourlyMinor;
            if (!is_int($average) || $average <= 0) throw new InvalidArgumentException('Úsek překážky nemá platný průměrný výdělek.');
            $weightedByPeriod[$period] = ($weightedByPeriod[$period] ?? 0) + $average * $eligible;
        }
        ksort($minutesByPeriod);

        $amounts = [];
        $fullRate = [];
        foreach ($minutesByPeriod as $period => $minutes) {
            $amounts[$period] = $averagesByDate === [] ? self::amount($averageHourlyMinor, $minutes, $rateBasisPoints, $rounding)
                : $rounding->mode()->roundFraction($weightedByPeriod[$period] * $rateBasisPoints, 60 * PayrollObstacleKind::FULL_RATE_BASIS_POINTS * 100) * 100;
            $fullRate[$period] = $averagesByDate === [] ? self::amount($averageHourlyMinor, $minutes, PayrollObstacleKind::FULL_RATE_BASIS_POINTS, $rounding)
                : $rounding->mode()->roundFraction($weightedByPeriod[$period], 60 * 100) * 100;
        }

        return ['minutes' => $minutesByPeriod, 'amounts' => $amounts, 'full_rate_amounts' => $fullRate];
    }

    public static function amount(
        int $averageHourlyMinor,
        int $minutes,
        int $rateBasisPoints,
        PayrollWageRounding $rounding = PayrollWageRounding::Ceil,
    ): int {
        return $rounding->mode()->roundFraction(
            $averageHourlyMinor * $minutes * $rateBasisPoints,
            60 * PayrollObstacleKind::FULL_RATE_BASIS_POINTS * 100,
        ) * 100;
    }
}
