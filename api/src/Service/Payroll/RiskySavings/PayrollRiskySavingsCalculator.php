<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\RiskySavings;

use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;

final readonly class PayrollRiskySavingsCalculator
{
    public function __construct(private PayrollRiskySavingsPolicy $policy) {}

    /**
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function calculate(
        int $employmentId,
        string $periodStart,
        int $assessmentBaseMinor,
        array $evidence,
        PayrollRiskySavingsRules $rules,
    ): array {
        if ($employmentId <= 0 || $assessmentBaseMinor < 0) {
            throw new \InvalidArgumentException('Vstup povinného spoření není platný.');
        }
        $issues = $this->policy->issues($evidence, $periodStart);
        if ($issues !== []) {
            return [
                'employment_id' => $employmentId,
                'status' => 'manual_review',
                'issues' => $issues,
                'assessment_base_minor' => $assessmentBaseMinor,
                'contribution_minor' => null,
            ];
        }
        $eighths = (int) $evidence['qualifying_shift_eighths'];
        $arises = $this->policy->obligationArises($evidence, $periodStart, $rules);
        $contributionMinor = $arises
            ? PayrollRounding::ceilToCzk(CalculationStep::calculate(
                'risky_savings.contribution',
                $assessmentBaseMinor,
                $rules->rate,
                RoundingMode::Ceil,
            )->outputMinorUnits)
            : 0;
        return [
            'employment_id' => $employmentId,
            'source_evidence_id' => (int) ($evidence['id'] ?? 0),
            'status' => $arises ? 'calculated' : 'not_due',
            'issues' => [],
            'qualifying_shift_eighths' => $eighths,
            'assessment_base_minor' => $assessmentBaseMinor,
            'contribution_minor' => $contributionMinor,
            'right_claimed_on' => $evidence['right_claimed_on'],
            'employee_informed_on' => $evidence['employee_informed_on'] ?? null,
            'risk_factor' => $evidence['risk_factor'],
            'work_category' => $evidence['work_category'],
            'pension_company' => trim((string) $evidence['pension_company']),
            'product_reference' => trim((string) $evidence['product_reference']),
            'institution_account_id' => $evidence['institution_account_id'],
            'institution_account_row_version' =>
                $evidence['institution_account_row_version'],
            'institution_account_hash' => $evidence['institution_account_hash'],
            'institution_account_masked' =>
                $evidence['institution_account_masked'] ?? null,
            'variable_symbol' => $evidence['variable_symbol'] ?? null,
            'specific_symbol' => $evidence['specific_symbol'] ?? null,
            'payment_message' => $evidence['payment_message'] ?? null,
            'payment_due_on' => $this->policy->dueOn($periodStart, $rules),
            'ruleset' => $rules->toSnapshot(),
        ];
    }
}
