<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;

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
 * (§ 142 odst. 2 ZP použitý na náhradu mzdy přes § 144). Měsíc je výplatní
 * období a zároveň hranice mzdového vstupu, takže rozdělovat jinak by dalo
 * jinou částku, než jaká se zúčtuje.
 */
final class LeaveCompensationCalculator
{
    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    public static function calculate(int $averageHourlyMinor, array $segments): LeaveCompensationResult
    {
        if ($averageHourlyMinor <= 0) {
            throw new InvalidArgumentException('Náhrada za dovolenou vyžaduje kladný hodinový průměr.');
        }
        if ($segments === []) {
            throw new InvalidArgumentException('Náhrada za dovolenou vyžaduje alespoň jednu publikovanou směnu.');
        }

        $minutesByPeriod = [];
        foreach ($segments as $segment) {
            $eligible = (int) $segment['eligible_minutes'];
            if ($eligible <= 0 || $eligible > (int) $segment['planned_minutes']) {
                throw new InvalidArgumentException('Minuty čerpané dovolené nejsou platné.');
            }
            $period = substr((string) $segment['local_date'], 0, 7) . '-01';
            $minutesByPeriod[$period] = ($minutesByPeriod[$period] ?? 0) + $eligible;
        }
        ksort($minutesByPeriod);

        $amountsByPeriod = [];
        foreach ($minutesByPeriod as $period => $minutes) {
            $amountsByPeriod[$period] = RoundingMode::Ceil->roundFraction(
                $averageHourlyMinor * $minutes,
                60 * 100,
            ) * 100;
        }

        return new LeaveCompensationResult(
            $averageHourlyMinor,
            $minutesByPeriod,
            $amountsByPeriod,
        );
    }
}
