<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * Jediná čtecí cesta k zákonným číslům absencí, průměrů a dovolené.
 *
 * Tahle čísla (60 % náhrada při DPN, 14denní okno § 192, hranice 21 odpracovaných
 * dnů § 355, parametry dovolené § 213) se historicky měnila a mění se dál. Dokud
 * žila jako literály v kalkulátorech, změna v administraci rulesetů se do absencí
 * nepromítla a výsledky se rozešly s mzdovým listem. Proto je čte výhradně tenhle
 * objekt — kdo potřebuje zákonné číslo absence, jde přes něj.
 */
final class AbsenceRuleset
{
    private function __construct(public readonly PayrollRulesetVersion $version) {}

    public static function forDate(PayrollRulesetProvider $rulesets, string $date): self
    {
        return new self(
            $rulesets->forDate(PayrollRulesetDomain::CompensationAverages, $date),
        );
    }

    public static function forYear(PayrollRulesetProvider $rulesets, int $year): self
    {
        return self::forDate($rulesets, sprintf('%04d-01-01', $year));
    }

    public function hourlyBoundaryMinor(int $band): int
    {
        return $this->integer("wage_compensation.hourly_boundary_{$band}_minor");
    }

    public function reductionBandBasisPoints(int $band): int
    {
        return $this->rateBasisPoints("wage_compensation.reduction_band_{$band}_rate");
    }

    public function compensationRateBasisPoints(): int
    {
        return $this->rateBasisPoints('wage_compensation.compensation_rate');
    }

    /** Okno náhrady mzdy při DPN podle § 192 ZP (historicky 21, dnes 14 dnů). */
    public function sicknessWindowCalendarDays(): int
    {
        return $this->integer('wage_compensation.window_calendar_days');
    }

    public function sicknessWindowEnd(\DateTimeImmutable $windowFrom): \DateTimeImmutable
    {
        return $windowFrom->modify('+' . ($this->sicknessWindowCalendarDays() - 1) . ' days');
    }

    /** Podpůrčí doba otcovské podle § 38b odst. 1 z. č. 187/2006 Sb. */
    public function paternitySupportDays(): int
    {
        return $this->integer('sickness_benefit.paternity_support_days');
    }

    /** Podpůrčí doba ošetřovného podle § 40 odst. 1 písm. a) z. č. 187/2006 Sb. */
    public function careSupportDays(): int
    {
        return $this->integer('sickness_benefit.care_support_days');
    }

    /**
     * Podpůrčí doba ošetřovného u osamělého pojištěnce s dítětem do 16 let
     * podle § 40 odst. 1 písm. b) z. č. 187/2006 Sb.
     */
    public function careSupportDaysLoneCarer(): int
    {
        return $this->integer('sickness_benefit.care_support_days_lone_carer');
    }

    /** Hranice odpracovaných dnů pro skutečný průměr podle § 355 ZP. */
    public function averageEarningMinimumWorkedDays(): int
    {
        return $this->integer('average_earning.minimum_worked_days');
    }

    /** Zákonné minimum výměry dovolené podle § 213 odst. 1 ZP. */
    public function leaveStatutoryMinimumWeeks(): int
    {
        return $this->integer('leave.entitlement_weeks.statutory_minimum');
    }

    public function leaveMinimumContinuousCalendarDays(): int
    {
        return $this->integer('leave.minimum_continuous_calendar_days');
    }

    public function leaveMinimumWorkedWeekMultiples(): int
    {
        return $this->integer('leave.minimum_worked_week_multiples');
    }

    /** Strop odpracovaných násobků i jmenovatel ročního poměru — týdny v roce. */
    public function leaveWeeksPerYear(): int
    {
        return $this->integer('leave.weeks_per_year');
    }

    /** Fikce týdenní pracovní doby u DPP/DPČ podle § 213 odst. 6 ZP. */
    public function leaveAgreementWeeklyMinutes(): int
    {
        return $this->integer('leave.agreement_weekly_minutes');
    }

    private function integer(string $key): int
    {
        $value = $this->raw($key);
        if (!is_int($value->value) || $value->value <= 0) {
            throw new \LogicException("Ruleset neobsahuje kladný celočíselný parametr {$key}.");
        }

        return $value->value;
    }

    private function rateBasisPoints(string $key): int
    {
        $text = $this->raw($key)->value;
        if (!is_string($text) || preg_match('/^0\.([0-9]{1,4})$/', $text, $match) !== 1) {
            throw new \LogicException("Ruleset neobsahuje kanonickou desetinnou sazbu {$key}.");
        }
        $basisPoints = (int) str_pad($match[1], 4, '0');
        if ($basisPoints <= 0 || $basisPoints > 10_000) {
            throw new \LogicException("Ruleset obsahuje neplatnou sazbu {$key}.");
        }

        return $basisPoints;
    }

    private function raw(string $key): PayrollRuleValue
    {
        $value = $this->version->parameters[$key] ?? null;
        if (!$value instanceof PayrollRuleValue) {
            throw new \LogicException("Ruleset neobsahuje parametr {$key}.");
        }

        return $value;
    }
}
