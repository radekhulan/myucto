<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;

/**
 * Náhrada mzdy za dobu čerpání dovolené (§ 222 odst. 1 ZP).
 *
 * Na rozdíl od náhrady při DPN se průměrný výdělek NEREDUKUJE — redukční
 * hranice zná jen § 192 odst. 2 pro nemoc. Dovolená se platí plným průměrným
 * hodinovým výdělkem zjištěným podle § 353 a násl. z předchozího kalendářního
 * čtvrtletí, který je do absence zmrazený jako schválený snapshot.
 *
 * Částka se proto NEROVNÁ té části základní mzdy, která se za tytéž hodiny
 * odebrala. Je to správně: základní mzda je sjednaná částka aktuálního měsíce,
 * kdežto náhrada vychází z výdělku minulého čtvrtletí.
 *
 * Zaokrouhlení kopíruje {@see SicknessCompensationCalculator}: mezikroky
 * přesným zlomkem, výsledek na celé koruny nahoru za KALENDÁŘNÍ MĚSÍC
 * (§ 142 odst. 2 ZP použitý na náhradu mzdy přes § 144), u firmy s volbou
 * matematického zaokrouhlení matematicky. Měsíc je výplatní
 * období a zároveň hranice mzdového vstupu, takže rozdělovat jinak by dalo
 * jinou částku, než jaká se zúčtuje.
 */
final class LeaveCompensationCalculator
{
    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    public static function calculate(
        int $averageHourlyMinor,
        array $segments,
        PayrollWageRounding $rounding = PayrollWageRounding::Ceil,
        array $averagesByDate = [],
    ): LeaveCompensationResult {
        if ($averageHourlyMinor <= 0) {
            throw new InvalidArgumentException('Náhrada za dovolenou vyžaduje kladný hodinový průměr.');
        }
        if ($segments === []) {
            throw new InvalidArgumentException('Náhrada za dovolenou vyžaduje alespoň jednu publikovanou směnu.');
        }

        $minutesByPeriod = [];
        $weightedByPeriod = [];
        foreach ($segments as $segment) {
            $eligible = (int) $segment['eligible_minutes'];
            if ($eligible <= 0 || $eligible > (int) $segment['planned_minutes']) {
                throw new InvalidArgumentException('Minuty čerpané dovolené nejsou platné.');
            }
            $period = substr((string) $segment['local_date'], 0, 7) . '-01';
            $minutesByPeriod[$period] = ($minutesByPeriod[$period] ?? 0) + $eligible;
            $date = (string) $segment['local_date'];
            $rate = $averagesByDate[$date] ?? $averageHourlyMinor;
            if (!is_int($rate) || $rate <= 0) throw new InvalidArgumentException('Úsek dovolené nemá platný průměrný výdělek.');
            $weightedByPeriod[$period] = ($weightedByPeriod[$period] ?? 0) + $rate * $eligible;
        }
        ksort($minutesByPeriod);

        $amountsByPeriod = [];
        foreach ($minutesByPeriod as $period => $minutes) {
            $amountsByPeriod[$period] = $averagesByDate === []
                ? self::calculateMinutes($averageHourlyMinor, $minutes, 100, $rounding)
                : $rounding->mode()->roundFraction($weightedByPeriod[$period], 60 * 100) * 100;
        }

        return new LeaveCompensationResult(
            $averageHourlyMinor,
            $minutesByPeriod,
            $amountsByPeriod,
            $rounding,
        );
    }

    /**
     * Náhrada za úhrn minut JEDNOHO výplatního období, volitelně jen procentem
     * průměru (překážky na straně zaměstnavatele § 207 a § 209 ZP). Stejné
     * zaokrouhlení jako výše: přesný zlomek a na celé koruny nahoru až z úhrnu.
     */
    public static function calculateMinutes(
        int $averageHourlyMinor,
        int $minutes,
        int $ratePercent = 100,
        PayrollWageRounding $rounding = PayrollWageRounding::Ceil,
    ): int {
        if ($averageHourlyMinor <= 0) {
            throw new InvalidArgumentException('Náhrada mzdy vyžaduje kladný hodinový průměr.');
        }
        if ($minutes <= 0) {
            throw new InvalidArgumentException('Náhrada mzdy vyžaduje kladný počet minut.');
        }
        if ($ratePercent <= 0 || $ratePercent > 100) {
            throw new InvalidArgumentException('Sazba náhrady mzdy musí být 1 až 100 % průměru.');
        }

        return $rounding->mode()->roundFraction(
            $averageHourlyMinor * $minutes * $ratePercent,
            60 * 100 * 100,
        ) * 100;
    }
}
