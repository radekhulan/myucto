<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

final class AverageEarningCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    public function calculate(
        string $applicationDate,
        int $grossEarningsMinor,
        int $longerPeriodAllocatedMinor,
        int $workedMinutes,
        int $workedDays,
        ?int $probableHourlyMinor = null,
        ?string $probableRationale = null,
        ?int $weeklyMinutes = null,
    ): AverageEarningResult {
        if ($grossEarningsMinor < 0 || $longerPeriodAllocatedMinor < 0) {
            throw new InvalidArgumentException('Započitatelná mzda nesmí být záporná.');
        }
        if ($workedMinutes < 0 || $workedDays < 0) {
            throw new InvalidArgumentException('Odpracovaná doba nesmí být záporná.');
        }

        $minimumWorkedDays = AbsenceRuleset::forDate($this->rulesets, $applicationDate)
            ->averageEarningMinimumWorkedDays();
        // § 357 odst. 1 ZP — průměrný výdělek nesmí klesnout pod minimální mzdu.
        $floor = MinimumWageFloor::forDate($this->rulesets, $applicationDate, $weeklyMinutes);

        $earningsMinor = $grossEarningsMinor + $longerPeriodAllocatedMinor;
        if ($earningsMinor < $grossEarningsMinor) {
            throw new \OverflowException('Součet započitatelné mzdy překročil celočíselný rozsah.');
        }

        if ($workedDays < $minimumWorkedDays) {
            $rationale = trim((string) $probableRationale);
            if ($probableHourlyMinor === null || $probableHourlyMinor <= 0 || $rationale === '') {
                throw new InvalidArgumentException(
                    "Při méně než {$minimumWorkedDays} odpracovaných dnech zadej pravděpodobný"
                    . ' hodinový výdělek a odůvodnění.'
                );
            }

            return new AverageEarningResult(
                'probable',
                $floor->apply($probableHourlyMinor),
                'manual_review',
                [
                    'worked_days' => $workedDays,
                    'worked_minutes' => $workedMinutes,
                    'minimum_worked_days' => $minimumWorkedDays,
                    'probable_hourly_minor' => $probableHourlyMinor,
                    'rationale' => $rationale,
                    'rule' => 'probable-earning-required-below-minimum-worked-days',
                ] + $floor->trace($probableHourlyMinor),
            );
        }

        if ($workedMinutes <= 0 || $earningsMinor <= 0) {
            throw new InvalidArgumentException(
                'Skutečný průměr vyžaduje kladnou započitatelnou mzdu a odpracované minuty.'
            );
        }
        if ($earningsMinor > intdiv(PHP_INT_MAX, 60)) {
            throw new \OverflowException('Výpočet hodinového průměru překročil celočíselný rozsah.');
        }

        $computedHourlyMinor = RoundingMode::HalfUp->roundFraction(
            $earningsMinor * 60,
            $workedMinutes,
        );
        $hourlyMinor = $floor->apply($computedHourlyMinor);

        return new AverageEarningResult(
            'actual',
            $hourlyMinor,
            'manual_review',
            [
                'gross_earnings_minor' => $grossEarningsMinor,
                'longer_period_allocated_minor' => $longerPeriodAllocatedMinor,
                'worked_days' => $workedDays,
                'worked_minutes' => $workedMinutes,
                'minimum_worked_days' => $minimumWorkedDays,
                'average_hourly_minor' => $hourlyMinor,
                'rounding' => 'half-up-to-minor-unit',
                'rule' => 'gross-earnings-divided-by-worked-time',
            ] + $floor->trace($computedHourlyMinor),
        );
    }
}
