<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * Minimum průměrného výdělku podle § 357 odst. 1 zákoníku práce.
 *
 * Je-li průměrný výdělek nižší než minimální mzda, zvýší se na ni. Ruleset drží
 * jen sazbu pro čtyřicetihodinový týden (`minimum_wage.hourly_40h_week`), protože
 * to je jediné číslo, které nařízení vlády publikuje. Při kratší stanovené týdenní
 * pracovní době se hodinová minimální mzda úměrně zvyšuje, aby týdenní výdělek
 * zůstal stejný — proto přepočet 40 h / stanovená týdenní doba. Měsíční minimální
 * mzda se přitom nemění, takže se odvozovat nedá z ní.
 *
 * Obecná stanovená týdenní pracovní doba (§ 79 odst. 1 ZP, 40 hodin, tedy
 * 2 400 minut) vstupuje přímo do přepočtu, a proto se čte z rulesetu
 * (`minimum_wage.standard_weekly_minutes`), ne z konstanty v kódu.
 */
final class MinimumWageFloor
{
    private function __construct(
        public readonly int $hourlyMinor,
        public readonly int $baseHourlyMinor,
        public readonly int $weeklyMinutes,
        public readonly string $rulesetId,
    ) {}

    public static function forDate(
        PayrollRulesetProvider $rulesets,
        string $date,
        ?int $weeklyMinutes = null,
    ): self {
        $version = $rulesets->forCalculation(PayrollRulesetDomain::EmploymentThresholds, $date);

        return self::fromVersion($version, $weeklyMinutes);
    }

    public static function fromVersion(
        PayrollRulesetVersion $version,
        ?int $weeklyMinutes = null,
    ): self {
        $parameter = $version->parameter('minimum_wage.hourly_40h_week');
        if ($parameter->type !== 'money_minor' || !is_int($parameter->value) || $parameter->value <= 0) {
            throw new \UnexpectedValueException(
                'Ruleset neobsahuje kladnou hodinovou minimální mzdu při 40hodinovém týdnu.',
            );
        }
        $base = $parameter->value;
        $standardWeekly = self::standardWeeklyMinutes($version);

        $weekly = $weeklyMinutes ?? $standardWeekly;
        if ($weekly <= 0 || $weekly > 10_080) {
            throw new InvalidArgumentException('Stanovená týdenní pracovní doba není platná.');
        }

        // Delší než čtyřicetihodinový týden zákon nepřipouští (§ 79 odst. 1 ZP);
        // kdyby přesto v datech byl, minimum se nesnižuje.
        $hourly = $weekly >= $standardWeekly
            ? $base
            : RoundingMode::HalfUp->roundFraction($base * $standardWeekly, $weekly);

        return new self($hourly, $base, $weekly, $version->id);
    }

    private static function standardWeeklyMinutes(PayrollRulesetVersion $version): int
    {
        $parameter = $version->parameter('minimum_wage.standard_weekly_minutes');
        if ($parameter->type !== 'integer' || !is_int($parameter->value) || $parameter->value <= 0) {
            throw new \UnexpectedValueException(
                'Ruleset neobsahuje kladnou stanovenou týdenní pracovní dobu.',
            );
        }

        return $parameter->value;
    }

    /** @return array<string,mixed> */
    public function trace(int $rawHourlyMinor): array
    {
        return [
            'minimum_wage_hourly_minor' => $this->hourlyMinor,
            'minimum_wage_hourly_40h_week_minor' => $this->baseHourlyMinor,
            'minimum_wage_weekly_minutes' => $this->weeklyMinutes,
            'minimum_wage_ruleset_id' => $this->rulesetId,
            'minimum_wage_floor_applied' => $rawHourlyMinor < $this->hourlyMinor,
            'raw_hourly_minor' => $rawHourlyMinor,
        ];
    }

    public function apply(int $hourlyMinor): int
    {
        return max($hourlyMinor, $this->hourlyMinor);
    }
}
