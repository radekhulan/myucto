<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use JsonSerializable;

final readonly class MonthlyHealthInsuranceResult implements JsonSerializable
{
    public function __construct(
        public int $assessmentBaseMinorUnits,
        public int $minimumAssessmentBaseMinorUnits,
        public int $standardContributionMinorUnits,
        public int $employeeStandardContributionMinorUnits,
        public int $employerStandardContributionMinorUnits,
        public int $employeeMinimumTopUpMinorUnits,
        public int $employerMinimumTopUpMinorUnits,
        public int $employeeContributionMinorUnits,
        public int $employerContributionMinorUnits,
        public int $totalContributionMinorUnits,
        public ?CalculationStep $standardContributionStep,
        public ?CalculationStep $minimumTopUpStep,
        public string $rulesetId,
        public string $rulesetHash,
        public ?CalculationStep $minimumContributionStep = null,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'assessment_base_minor_units' => $this->assessmentBaseMinorUnits,
            'minimum_assessment_base_minor_units' => $this->minimumAssessmentBaseMinorUnits,
            'standard_contribution_minor_units' => $this->standardContributionMinorUnits,
            'employee_standard_contribution_minor_units' => $this->employeeStandardContributionMinorUnits,
            'employer_standard_contribution_minor_units' => $this->employerStandardContributionMinorUnits,
            'employee_minimum_top_up_minor_units' => $this->employeeMinimumTopUpMinorUnits,
            'employer_minimum_top_up_minor_units' => $this->employerMinimumTopUpMinorUnits,
            'employee_contribution_minor_units' => $this->employeeContributionMinorUnits,
            'employer_contribution_minor_units' => $this->employerContributionMinorUnits,
            'total_contribution_minor_units' => $this->totalContributionMinorUnits,
            'standard_contribution_step' => $this->standardContributionStep?->jsonSerialize(),
            'minimum_top_up_step' => $this->minimumTopUpStep?->jsonSerialize(),
            'minimum_contribution_step' => $this->minimumContributionStep?->jsonSerialize(),
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
        ];
    }
}
