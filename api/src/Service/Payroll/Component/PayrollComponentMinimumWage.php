<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/** Započitatelná mzda podle § 111; daňové a pojistné zařazení ji neurčuje. */
final class PayrollComponentMinimumWage
{
    /** @return array{non_monetary:string,codes:array<string,string>,kinds:array<string,string>,fallback:string} */
    public static function catalog(): array
    {
        return [
            // § 119: minimum musí zaměstnanec obdržet v penězích i při naturální mzdě.
            'non_monetary' => 'excluded',
            'codes' => [
                'MZDA_HODINOVA_ZAKLAD' => 'included',
                'DOPLATEK_MINIMALNI_MZDA' => 'included',
                'MZDA_DOSAZENA_PRESCAS' => 'excluded',
                'PRIPLATEK_PRESCAS' => 'excluded',
                'PRIPLATEK_SVATEK' => 'excluded',
                'PRIPLATEK_NOCNI' => 'excluded',
                'PRIPLATEK_VIKEND' => 'excluded',
                'PRIPLATEK_ZTIZENE_PROSTREDI' => 'excluded',
                'ODMENA_POHOTOVOST' => 'excluded',
                'NAHRADA_VYDAJU_PREVZATA' => 'excluded',
            ],
            'kinds' => [
                'base_wage' => 'included',
                'task_wage' => 'included',
                'bonus' => 'included',
                'commission' => 'included',
                'compensation' => 'excluded',
                'severance' => 'excluded',
                'competitive_clause' => 'excluded',
                'non_cash' => 'excluded',
                'benefit_meal' => 'excluded',
                'benefit_vehicle' => 'excluded',
                'benefit_pension' => 'excluded',
                'benefit_care' => 'excluded',
                'benefit_education' => 'excluded',
                'benefit_recreation' => 'excluded',
                'benefit_health' => 'excluded',
                'benefit_accommodation' => 'excluded',
                'risky_savings' => 'excluded',
                'travel_reimbursement' => 'excluded',
            ],
            // Legacy hourly_wage může být základ i dosažená mzda za přesčas;
            // obecné prémie, příplatky a doplatky zase nesou různé právní tituly.
            'fallback' => 'manual_review',
        ];
    }

    /** @param array<string,mixed> $component
     * @return array{treatment:string,editable:bool}
     */
    public static function resolve(array $component): array
    {
        $default = self::defaultTreatment($component);
        if ($default !== 'manual_review') {
            return ['treatment' => $default, 'editable' => false];
        }
        $selected = $component['minimum_wage_treatment'] ?? null;
        $reason = $component['minimum_wage_reason'] ?? null;
        return [
            'treatment' => in_array($selected, ['included', 'excluded'], true)
                && is_string($reason) && trim($reason) !== ''
                    ? $selected : 'manual_review',
            'editable' => true,
        ];
    }

    /** @param array<string,mixed> $component */
    public static function assertValid(array $component): void
    {
        $selected = $component['minimum_wage_treatment'] ?? null;
        $reason = $component['minimum_wage_reason'] ?? null;
        if ($selected !== null && (!is_string($selected)
            || PayrollComponentInclusion::tryFrom($selected) === null)) {
            throw new \InvalidArgumentException('Zařazení složky do minimální mzdy není platné.');
        }
        if ($reason !== null && (!is_string($reason) || mb_strlen($reason) > 500)) {
            throw new \InvalidArgumentException('Důvod zařazení do minimální mzdy smí mít nejvýše 500 znaků.');
        }
        $default = self::defaultTreatment($component);
        if ($default !== 'manual_review' && $selected !== null && $selected !== $default) {
            throw new \InvalidArgumentException('Zákonné zařazení této složky do minimální mzdy nelze změnit.');
        }
        if ($default === 'manual_review' && in_array($selected, ['included', 'excluded'], true)
            && (!is_string($reason) || trim($reason) === '')) {
            throw new \InvalidArgumentException('U vlastního zařazení složky do minimální mzdy uveďte důvod.');
        }
    }

    /** @param array<string,mixed> $component */
    private static function defaultTreatment(array $component): string
    {
        $catalog = self::catalog();
        if (($component['value_kind'] ?? null) === 'non_monetary') {
            return $catalog['non_monetary'];
        }
        return $catalog['codes'][strtoupper((string) ($component['code'] ?? ''))]
            ?? $catalog['kinds'][(string) ($component['component_kind'] ?? '')]
            ?? $catalog['fallback'];
    }
}
