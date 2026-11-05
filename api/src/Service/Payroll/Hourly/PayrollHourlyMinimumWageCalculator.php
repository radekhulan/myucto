<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

use MyInvoice\Http\IsoDate;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Time\PayrollDuration;

/** Doplatek se porovnává s dosaženou splatnou mzdou a zaokrouhlí jednou za vztah a měsíc. */
final class PayrollHourlyMinimumWageCalculator
{
    public function calculate(PayrollHourlyMinimumWageContext $context): PayrollHourlyMinimumWageResult
    {
        $hash = hash('sha256', CanonicalJson::encode([...get_object_vars($context), 'rounding' => $context->rounding->value, 'calculator_version' => 'hourly-minimum.v1']));
        $evidence = ['assessment' => $context->assessment, 'threshold' => null, 'eligible' => null,
            'excluded' => [], 'parts' => [], 'ordinary_payable_minor' => $context->ordinaryPayableMinor,
            'company_rounding' => $context->rounding->value, 'statutory_floor_rounding' => 'ceil', 'policy_payable_minor' => null];
        $claim = ['purpose' => 'minimum_wage_top_up', 'component_code' => 'DOPLATEK_MINIMALNI_MZDA',
            'ready' => false, 'duration_units' => null, 'worked_duration_units' => 0,
            'raw_numerator_minor' => null, 'raw_denominator' => null, 'analytical_minor' => null, 'payable_minor' => null,
            'rounding' => 'ceil', 'segments' => []];
        try {
            $this->validateContext($context);
            $parts = $context->parts;
            usort($parts, static fn(array $a, array $b): int => ($a['date_from'] ?? '') <=> ($b['date_from'] ?? ''));
            if ($context->assessment === 'monthly') $this->assertMonthlyProof($context, $parts);
            $threshold = new PayrollExactFraction(0);
            $units = 0;
            $lastDate = null;
            foreach ($parts as $part) {
                $from = self::date($part['date_from'] ?? null);
                $to = self::date($part['date_to'] ?? null);
                if ($from > $to || substr($from, 0, 7) !== substr($context->periodStart, 0, 7)
                    || substr($to, 0, 7) !== substr($context->periodStart, 0, 7) || ($lastDate !== null && $lastDate >= $from)) {
                    throw new PayrollHourlyCalculationException('hourly_minimum_partition_invalid', 'Úseky posouzení minima se překrývají nebo nepatří do zpracovávaného měsíce.');
                }
                $lastDate = $to;
                self::positive($part['term_id'] ?? null);
                $worked = self::units($part['regular_worked_duration_units'] ?? null);
                $standard = ($part['stated_weekly_minutes'] ?? null) === null ? null : self::positive($part['stated_weekly_minutes']);
                $base = ($part['minimum_base40_minor'] ?? null) === null ? null : self::positive($part['minimum_base40_minor']);
                if ($standard !== null && $standard > 2400) throw new \InvalidArgumentException('Stanovená týdenní doba nesmí přesahovat 40 hodin.');
                $rate = $base !== null && $standard !== null ? MinimumHourlyRate::calculate($base, $standard) : null;
                if ($rate === null && !($context->assessment === 'hourly' && $worked === 0)) throw new PayrollHourlyCalculationException('hourly_minimum_floor_missing', 'Pro kladnou odpracovanou dobu doložte účinné minimum a stanovenou týdenní dobu.');
                if ($context->assessment === 'hourly') {
                    // Výslovná nula je nezávislá na sazbě; neznámá doba již výše neprošla validací.
                    $amount = $worked === 0 ? new PayrollExactFraction(0) : PayrollExactFraction::product([$rate, $worked], [PayrollDuration::UNITS_PER_HOUR]);
                } else {
                    $contract = self::positive($part['contractual_weekly_duration_units'] ?? null);
                    $fund = self::positive($part['full_month_fund_duration_units'] ?? null);
                    if ($contract > $standard * 50 || $worked > $fund) throw new PayrollHourlyCalculationException('hourly_minimum_monthly_fund_invalid', 'Doložený měsíční fond nebo sjednaná doba neodpovídá posuzované běžné práci.');
                    $amount = PayrollExactFraction::product([self::positive($part['minimum_monthly_minor'] ?? null), $contract, $worked], [$standard, 50, $fund]);
                }
                $threshold = $threshold->plus($amount);
                $units = PayrollDuration::add($units, $worked);
                $evidence['parts'][] = [...$part, 'minimum_hourly_minor' => $rate, ...self::fraction($amount)];
            }
            $eligible = new PayrollExactFraction(self::units($context->ordinaryPayableMinor));
            foreach ($context->otherEarnings as $row) {
                $amount = $row['amount_minor'] ?? null;
                if (!is_int($amount)) throw new \InvalidArgumentException('Čistá částka mzdové složky musí být celé číslo v haléřích.');
                if ($amount === 0) continue;
                $treatment = $row['minimum_wage_treatment'] ?? null;
                if (!in_array($treatment, ['included', 'excluded'], true)) throw new PayrollHourlyCalculationException('hourly_minimum_component_treatment_missing', 'Určete, zda se další mzdová složka započítává do minimální mzdy.', ['component_id' => $row['component_id'] ?? null]);
                if ($treatment === 'excluded') {
                    // Vrácení vyloučené náhrady nemění započitatelnou mzdu ani neblokuje její minimum.
                    $evidence['excluded'][] = [...$row, 'reason' => 'component_excluded', 'raw_numerator_minor' => $amount, 'raw_denominator' => 1, 'analytical_minor' => $amount];
                    continue;
                }
                if ($amount < 0) throw new PayrollHourlyCalculationException('hourly_minimum_negative_net_earning', 'Čistá opravená částka započitatelné mzdové složky je záporná; doložte její správné přiřazení před posouzením minima.', ['component_id' => $row['component_id'] ?? null]);
                $eligible = $eligible->plus(new PayrollExactFraction($amount));
            }
            $embedded = new PayrollExactFraction(0);
            foreach ($context->includedAllocations as $row) {
                // Tarif zahrnutého přesčasu již v ordinary_base není; nesmí se odečíst podruhé.
                if (($row['exclude_from_minimum_wage'] ?? false) !== true) continue;
                $amount = new PayrollExactFraction(self::units($row['raw_numerator_minor'] ?? null), self::positive($row['raw_denominator'] ?? null));
                $embedded = $embedded->plus($amount);
                $evidence['excluded'][] = [...$row, 'reason' => 'embedded_agreement_premium', ...self::fraction($amount)];
            }
            if ($embedded->compare($eligible) > 0) throw new PayrollHourlyCalculationException('hourly_minimum_embedded_exceeds_wage', 'Doložené příplatky zahrnuté v odměně převyšují dosaženou započitatelnou mzdu.');
            $eligible = $eligible->minus($embedded);
            $difference = $threshold->compare($eligible) > 0 ? $threshold->minus($eligible) : new PayrollExactFraction(0);
            $evidence['threshold'] = self::fraction($threshold);
            $evidence['eligible'] = self::fraction($eligible);
            $evidence['policy_payable_minor'] = $difference->payableMinor($context->rounding);
            // § 111: ani historické firemní half_up nesmí ponechat úhrn pod zákonným minimem.
            // § 142 odst. 2 (u dohod přes § 144): korunové zaokrouhlení nahoru přímo z přesného rozdílu.
            $payable = max($evidence['policy_payable_minor'], $difference->payableMinor(PayrollWageRounding::Ceil));
            $claim = [...$claim, 'ready' => true, 'duration_units' => $units, ...self::fraction($difference),
                'payable_minor' => $payable, 'segments' => $evidence['parts']];
            return new PayrollHourlyMinimumWageResult(true, $claim, [], $evidence, $hash);
        } catch (PayrollHourlyCalculationException|\InvalidArgumentException|\OverflowException $exception) {
            return new PayrollHourlyMinimumWageResult(false, $claim, [[
                'code' => $exception instanceof PayrollHourlyCalculationException ? $exception->reason : ($exception instanceof \OverflowException ? 'hourly_amount_overflow' : 'hourly_minimum_context_invalid'),
                'message' => $exception->getMessage(), 'context' => $exception instanceof PayrollHourlyCalculationException ? $exception->context : [],
            ]], $evidence, $hash);
        }
    }

    private function validateContext(PayrollHourlyMinimumWageContext $context): void
    {
        if ($context->employmentId <= 0 || !in_array($context->employmentType, ['employment', 'small_scale_employment', 'dpp', 'dpc'], true)
            || !IsoDate::isValid($context->periodStart) || substr($context->periodStart, 8) !== '01'
            || !in_array($context->assessment, ['hourly', 'monthly'], true)) throw new \InvalidArgumentException('Posouzení minimální mzdy nemá platný vztah, období nebo způsob výpočtu.');
        if ($context->parts === []) throw new PayrollHourlyCalculationException('hourly_minimum_time_missing', 'Doložte běžnou odpracovanou dobu; chybějící podklady nejsou potvrzenou nulou.');
    }

    private function assertMonthlyProof(PayrollHourlyMinimumWageContext $context, array $parts): void
    {
        if (in_array($context->employmentType, ['dpp', 'dpc'], true)) throw new PayrollHourlyCalculationException('hourly_minimum_agreement_requires_hourly', 'Minimální odměna DPP a DPČ se posuzuje hodinově.');
        $proof = $context->advanceProof;
        $first = self::date($parts[0]['date_from'] ?? null);
        $last = self::date($parts[array_key_last($parts)]['date_to'] ?? null);
        if (!is_array($proof) || !is_string($proof['reference'] ?? null) || trim($proof['reference']) === '') throw new PayrollHourlyCalculationException('hourly_minimum_monthly_proof_missing', 'Měsíční posouzení vyžaduje doložené předchozí určení zaměstnavatele.');
        if (self::date($proof['decided_on'] ?? null) > $first || self::date($proof['valid_from'] ?? null) > $first
            || (($proof['valid_to'] ?? null) !== null && self::date($proof['valid_to']) < $last)) throw new PayrollHourlyCalculationException('hourly_minimum_monthly_proof_invalid', 'Předchozí určení měsíčního posouzení nepokrývá celé posuzované období.');
    }

    private static function fraction(PayrollExactFraction $amount): array
    {
        return ['raw_numerator_minor' => $amount->numerator, 'raw_denominator' => $amount->denominator, 'analytical_minor' => $amount->analyticalMinor()];
    }

    private static function units(mixed $value): int
    {
        if (!is_int($value) || $value < 0) throw new \InvalidArgumentException('Pro minimum doložte nezáporné celé částky a přesné jednotky doby.');
        return $value;
    }

    private static function positive(mixed $value): int
    {
        $value = self::units($value);
        if ($value === 0) throw new \InvalidArgumentException('Sazba, fond a odkaz podkladu minima musí být kladné.');
        return $value;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || !IsoDate::isValid($value)) throw new \InvalidArgumentException('Datum podkladu minimální mzdy není platné.');
        return $value;
    }
}
