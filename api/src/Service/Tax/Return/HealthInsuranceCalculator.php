<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Zdravotní pojištění OSVČ — Epic DP (issue #18).
 *
 * ČISTÁ třída. Vyměřovací základ = max(health_assessment_pct × daňový základ §7,
 * roční minimum). U vedlejší činnosti při souběhu se zaměstnáním se minimální VZ
 * NEUPLATNÍ (VZ = 50 % základu bez spodní hranice). Pojistné = VZ × 13,5 %.
 * Nová měsíční záloha = pojistné/12, minimálně dle zákona (u souběhu bez minima).
 */
final class HealthInsuranceCalculator
{
    /**
     * @param array<string,mixed> $c
     * @return array{
     *   assessment_base:float, min_base:float, insurance:float,
     *   advances_paid:float, balance_due:float, monthly_advance:float,
     *   min_applies:bool, is_secondary:bool, note:string
     * }
     */
    public function compute(float $taxBase7, bool $isSecondary, float $advancesPaid, array $c, array $months = []): array
    {
        $rate = (float) ($c['health_rate'] ?? 0.135);
        $assessmentPct = (float) ($c['health_assessment_pct'] ?? 0.50);
        $minBaseYear = (float) ($c['health_min_base'] ?? 0);

        // Souběh se zaměstnáním (vedlejší) → minimální VZ se neuplatní.
        $activeMonths = 12;
        $minimumMonths = $isSecondary ? 0 : 12;
        if ($months !== []) {
            $activeMonths = count(array_filter($months, static fn (array $m): bool => ($m['activity_status'] ?? '') !== 'inactive'));
            $minimumMonths = count(array_filter($months, static fn (array $m): bool => !empty($m['health_minimum_applies'])));
            $isSecondary = count(array_filter($months, static fn (array $m): bool => ($m['activity_status'] ?? '') === 'main')) === 0
                && $activeMonths > 0;
        }
        $minApplies = $minimumMonths > 0;

        // Zaokrouhlení na celé Kč nahoru (§ 3a z. 592/1992 Sb.) — VZ z celokorunového daňového
        // základu §7, vyměřovací i minimální VZ ceil, pojistné ceil (jeden zdroj: PDF = XML).
        $pri = round($taxBase7);
        $vvz = $this->ceilKc($pri * $assessmentPct);
        $floor = $minApplies ? $this->ceilKc($minBaseYear / 12 * $minimumMonths) : 0.0;
        $assessmentBase = max($vvz, $floor);
        $insurance = $this->ceilKc($assessmentBase * $rate);
        $balanceDue = round($insurance - max(0.0, $advancesPaid), 2);

        // Minimální měsíční záloha z minimálního VZ (jen když se minimum uplatní).
        $minMonthlyAdvance = $minApplies ? $this->ceilKc($minBaseYear / 12 * $rate) : 0.0;
        $monthlyAdvance = max($this->ceilKc($insurance / 12), $minMonthlyAdvance);

        $note = $minApplies
            ? ''
            : 'Vedlejší činnost při souběhu se zaměstnáním — minimální vyměřovací základ se neuplatní.';

        return [
            'assessment_base' => $assessmentBase,
            'min_base' => $floor,
            'insurance' => $insurance,
            'advances_paid' => round(max(0.0, $advancesPaid), 2),
            'balance_due' => $balanceDue,
            'monthly_advance' => $monthlyAdvance,
            'min_applies' => $minApplies,
            'is_secondary' => $isSecondary,
            'active_months' => $activeMonths,
            'minimum_months' => $minimumMonths,
            'note' => $note,
        ];
    }

    private function ceilKc(float $v): float
    {
        return TaxFormAmount::ceilKc($v);
    }
}
