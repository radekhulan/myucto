<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use JsonSerializable;

final readonly class HealthInsuranceMonthResult implements JsonSerializable
{
    /**
     * @param list<HealthPersonMonthResult> $people
     * @param list<HealthInsurerLiabilityResult> $insurerLiabilities
     * @param list<string> $issues
     */
    public function __construct(
        public string $calculationDate,
        public HealthCalculationStatus $status,
        public ?int $assessmentBaseMinorUnits,
        public ?int $employeeContributionMinorUnits,
        public ?int $employerContributionMinorUnits,
        public ?int $totalContributionMinorUnits,
        public array $people,
        public array $insurerLiabilities,
        public array $issues,
        public string $rulesetId,
        public string $rulesetHash,
        /** Úhrn vyměřovacích základů pro PPZ; viz HealthInsurerLiabilityResult. */
        public ?int $ppzAssessmentBaseMinorUnits = null,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'calculation_date' => $this->calculationDate,
            'status' => $this->status->value,
            'assessment_base_minor_units' => $this->assessmentBaseMinorUnits,
            'employee_contribution_minor_units' => $this->employeeContributionMinorUnits,
            'employer_contribution_minor_units' => $this->employerContributionMinorUnits,
            'total_contribution_minor_units' => $this->totalContributionMinorUnits,
            'people' => array_map(
                static fn (HealthPersonMonthResult $person): array => $person->jsonSerialize(),
                $this->people,
            ),
            'insurer_liabilities' => array_map(
                static fn (HealthInsurerLiabilityResult $liability): array =>
                    $liability->jsonSerialize(),
                $this->insurerLiabilities,
            ),
            'issues' => $this->issues,
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
            'ppz_assessment_base_minor_units' => $this->ppzAssessmentBaseMinorUnits,
        ];
    }

    public function toCanonicalJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
