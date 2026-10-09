<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Sociální (důchodové) pojištění OSVČ + dobrovolné nemocenské — Epic DP (issue #18).
 *
 * ČISTÁ třída. Vyměřovací základ = max(social_assessment_pct × daňový základ §7,
 * roční minimum hlavní/vedlejší). Vedlejší činnost pod rozhodnou částkou →
 * pojistné 0 (bez povinné účasti na důchodovém pojištění). Pojistné = VZ × 29,2 %.
 * Nová měsíční záloha = pojistné/12, minimálně dle zákona. Nemocenské (dobrovolné):
 * měsíční pojistné = max(min. VZ, zvolený VZ) × 2,7 % — informativní, do doplatku
 * přehledu nevstupuje.
 */
final class SocialInsuranceCalculator
{
    /**
     * @param array<string,mixed> $c
     * @return array{
     *   assessment_base:float, min_base:float, insurance:float,
     *   advances_paid:float, balance_due:float, monthly_advance:float,
     *   participates:bool, is_secondary:bool,
     *   sickness:array{insured:bool,monthly_base:float,monthly_premium:float,annual:float},
     *   note:string
     * }
     */
    public function compute(
        float $taxBase7,
        bool $isSecondary,
        float $advancesPaid,
        bool $sicknessInsured,
        ?int $sicknessMonthlyBase,
        array $c,
        array $months = [],
    ): array {
        $rate = (float) ($c['social_rate'] ?? 0.292);
        $assessmentPct = (float) ($c['social_assessment_pct'] ?? 0.55);
        $minMain = (float) ($c['social_min_base_main'] ?? 0);
        $minSecondary = (float) ($c['social_min_base_secondary'] ?? 0);
        $maxBase = (float) ($c['social_max_base'] ?? PHP_INT_MAX);
        $threshold = (float) ($c['social_secondary_participation_threshold'] ?? 0);

        $activeMonths = 12;
        $mainMonths = $isSecondary ? 0 : 12;
        $secondaryMonths = $isSecondary ? 12 : 0;
        if ($months !== []) {
            $mainMonths = count(array_filter($months, static fn (array $m): bool => ($m['activity_status'] ?? '') === 'main'));
            $secondaryMonths = count(array_filter($months, static fn (array $m): bool => ($m['activity_status'] ?? '') === 'secondary'));
            $activeMonths = $mainMonths + $secondaryMonths;
            $isSecondary = $mainMonths === 0 && $secondaryMonths > 0;
        }
        $minBase = round($minMain / 12 * $mainMonths + $minSecondary / 12 * $secondaryMonths, 2);
        $participates = true;
        $note = '';

        $effectiveThreshold = $secondaryMonths > 0 && $mainMonths === 0 ? $threshold / 12 * $secondaryMonths : $threshold;
        if ($activeMonths === 0) {
            $participates = false;
            $assessmentBase = 0.0;
            $insurance = 0.0;
            $note = 'Samostatná výdělečná činnost nebyla v žádném měsíci aktivní.';
        } elseif ($isSecondary && $taxBase7 < $effectiveThreshold) {
            // Vedlejší SVČ pod rozhodnou částkou → bez povinné účasti (pojistné 0).
            $participates = false;
            $assessmentBase = 0.0;
            $insurance = 0.0;
            $note = 'Vedlejší činnost: daňový základ §7 (' . number_format($taxBase7, 0, ',', ' ')
                . ' Kč) nedosáhl rozhodné částky ' . number_format($effectiveThreshold, 0, ',', ' ')
                . ' Kč — povinné důchodové pojištění se neplatí.';
        } else {
            // Zaokrouhlení na celé Kč nahoru (§ 5c z. 589/1992 Sb.) — STEJNÁ derivace jako
            // CsszPrehledXmlBuilder (jeden zdroj pravdy, aby PDF přehled = podávané ČSSZ XML):
            // VZ z celokorunového daňového základu §7, vyměřovací i minimální VZ ceil, pojistné ceil.
            $pri = round($taxBase7);
            $vvz = $this->ceilKc($pri * $assessmentPct); // vypočtený VZ
            $mvz = $this->ceilKc($minBase);              // minimální VZ
            $assessmentBase = min(max($vvz, $mvz), $maxBase); // určený VZ se zákonným stropem
            $insurance = $this->ceilKc($assessmentBase * $rate);
        }

        $balanceDue = round($insurance - max(0.0, $advancesPaid), 2);

        // Nová měsíční záloha = pojistné/12, min. dle zákona (min. VZ/12 × sazba).
        $minMonthlyAdvance = $participates && $activeMonths > 0 ? $this->ceilKc($minBase / $activeMonths * $rate) : 0.0;
        $monthlyAdvance = $participates ? max($this->ceilKc($insurance / 12), $minMonthlyAdvance) : 0.0;

        // Nemocenské (dobrovolné) — informativní měsíční pojistné.
        $sicknessRate = (float) ($c['sickness_rate'] ?? 0.027);
        $sicknessMin = (float) ($c['sickness_min_monthly_base'] ?? 0);
        $sMonthlyBase = $sicknessInsured ? max($sicknessMin, (float) ($sicknessMonthlyBase ?? 0)) : 0.0;
        $sMonthlyPremium = $sicknessInsured ? $this->ceilKc($sMonthlyBase * $sicknessRate) : 0.0;

        return [
            'assessment_base' => $assessmentBase,
            'min_base' => $minBase,
            'max_base' => $maxBase,
            'insurance' => $insurance,
            'advances_paid' => round(max(0.0, $advancesPaid), 2),
            'balance_due' => $balanceDue,
            'monthly_advance' => $monthlyAdvance,
            'participates' => $participates,
            'is_secondary' => $isSecondary,
            'sickness' => [
                'insured' => $sicknessInsured,
                'monthly_base' => $sMonthlyBase,
                'monthly_premium' => $sMonthlyPremium,
                'annual' => round($sMonthlyPremium * $activeMonths, 2),
            ],
            'active_months' => $activeMonths,
            'main_months' => $mainMonths,
            'secondary_months' => $secondaryMonths,
            'note' => $note,
        ];
    }

    private function ceilKc(float $v): float
    {
        return TaxFormAmount::ceilKc($v);
    }
}
