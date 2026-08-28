<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;

/**
 * Mezikroky (`standardContributionStep`, `minimumTopUpStep`,
 * `minimumContributionStep`) tu nejsou pro ozdobu:
 * bez nich zůstane po výpočtu jen výsledná částka a sazba ani způsob zaokrouhlení
 * se z uloženého výsledku už nedají doložit. Účetní pak nemá čím obhájit, proč
 * systém spočítal zrovna tolik. Kroky vznikají v `MonthlyHealthInsuranceCalculator`
 * a jen se sem propíšou — nic se nepočítá podruhé.
 *
 * U revizí spočtených dřív, než se kroky začaly ukládat, zůstanou `null`. To se
 * NESMÍ dopočítat z aktuální sady pravidel: vysvětlení by pak popisovalo jiný
 * výpočet, než jaký dal uloženou částku.
 */
final readonly class HealthPersonMonthResult implements JsonSerializable
{
    /**
     * @param list<HealthRelationshipResult> $relationships
     * @param list<array{from:string,to:string,reason:string,evidence_reference:?string}> $minimumReductionEvidence
     * @param list<array{employer_reference:string,assessment_base_minor_units:int,employment_from:string,employment_to:?string,evidence_reference:?string}> $otherEmployerEvidence
     * @param list<string> $issues
     */
    public function __construct(
        public string $personId,
        public HealthCalculationStatus $status,
        public HealthJurisdictionEvidence $jurisdiction,
        public ?string $jurisdictionEvidenceReference,
        public HealthInsurerSnapshotStatus $insurerStatus,
        public ?string $insurerCode,
        public ?string $insurerEvidenceReference,
        public int $assessmentBaseMinorUnits,
        public int $otherEmployerAssessmentBaseMinorUnits,
        public int $combinedAssessmentBaseMinorUnits,
        public int $employmentCalendarDays,
        public int $minimumExcludedCalendarDays,
        public int $minimumApplicableCalendarDays,
        public int $statutoryMonthlyMinimumMinorUnits,
        public int $effectiveMinimumMinorUnits,
        public HealthMinimumTopUpResponsibility $topUpResponsibility,
        public ?string $topUpResponsibilityEvidenceReference,
        public ?string $selectedTopUpEmployerEvidenceReference,
        public ?int $standardContributionMinorUnits,
        public ?int $employeeStandardContributionMinorUnits,
        public ?int $employerStandardContributionMinorUnits,
        public ?int $employeeMinimumTopUpMinorUnits,
        public ?int $employerMinimumTopUpMinorUnits,
        public ?int $employeeContributionMinorUnits,
        public ?int $employerContributionMinorUnits,
        public ?int $totalContributionMinorUnits,
        public array $relationships,
        public array $minimumReductionEvidence,
        public array $otherEmployerEvidence,
        public array $issues,
        public HealthMinimumTopUpEmployerSelection $topUpEmployerSelection =
            HealthMinimumTopUpEmployerSelection::Unverified,
        public bool $ppzCounted = false,
        public ?CalculationStep $standardContributionStep = null,
        public ?CalculationStep $minimumTopUpStep = null,
        public HealthMinimumTopUpResponsibilitySource $topUpResponsibilitySource =
            HealthMinimumTopUpResponsibilitySource::Declared,
        public ?CalculationStep $minimumContributionStep = null,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'person_id' => $this->personId,
            'status' => $this->status->value,
            'jurisdiction' => $this->jurisdiction->value,
            'jurisdiction_evidence_reference' => $this->jurisdictionEvidenceReference,
            'insurer_status' => $this->insurerStatus->value,
            'insurer_code' => $this->insurerCode,
            'insurer_evidence_reference' => $this->insurerEvidenceReference,
            'assessment_base_minor_units' => $this->assessmentBaseMinorUnits,
            'other_employer_assessment_base_minor_units' =>
                $this->otherEmployerAssessmentBaseMinorUnits,
            'combined_assessment_base_minor_units' =>
                $this->combinedAssessmentBaseMinorUnits,
            'employment_calendar_days' => $this->employmentCalendarDays,
            'minimum_excluded_calendar_days' => $this->minimumExcludedCalendarDays,
            'minimum_applicable_calendar_days' => $this->minimumApplicableCalendarDays,
            'statutory_monthly_minimum_minor_units' =>
                $this->statutoryMonthlyMinimumMinorUnits,
            'effective_minimum_minor_units' => $this->effectiveMinimumMinorUnits,
            'top_up_responsibility' => $this->topUpResponsibility->value,
            // Bez tohohle klíče by ze snímku nešlo poznat, jestli je plátce
            // doplatku doložený prohlášením, nebo odvozený z § 3 odst. 10
            // z. č. 592/1992 Sb. Viz HealthMinimumTopUpResponsibilitySource.
            'top_up_responsibility_source' => $this->topUpResponsibilitySource->value,
            'top_up_responsibility_evidence_reference' =>
                $this->topUpResponsibilityEvidenceReference,
            'selected_top_up_employer_evidence_reference' =>
                $this->selectedTopUpEmployerEvidenceReference,
            'top_up_employer_selection' => $this->topUpEmployerSelection->value,
            'ppz_counted' => $this->ppzCounted,
            'standard_contribution_minor_units' => $this->standardContributionMinorUnits,
            'employee_standard_contribution_minor_units' =>
                $this->employeeStandardContributionMinorUnits,
            'employer_standard_contribution_minor_units' =>
                $this->employerStandardContributionMinorUnits,
            'employee_minimum_top_up_minor_units' =>
                $this->employeeMinimumTopUpMinorUnits,
            'employer_minimum_top_up_minor_units' =>
                $this->employerMinimumTopUpMinorUnits,
            'employee_contribution_minor_units' => $this->employeeContributionMinorUnits,
            'employer_contribution_minor_units' => $this->employerContributionMinorUnits,
            'total_contribution_minor_units' => $this->totalContributionMinorUnits,
            'standard_contribution_step' => $this->standardContributionStep?->jsonSerialize(),
            'minimum_top_up_step' => $this->minimumTopUpStep?->jsonSerialize(),
            'minimum_contribution_step' => $this->minimumContributionStep?->jsonSerialize(),
            'relationships' => array_map(
                static fn (HealthRelationshipResult $relationship): array =>
                    $relationship->jsonSerialize(),
                $this->relationships,
            ),
            'minimum_reduction_evidence' => $this->minimumReductionEvidence,
            'other_employer_evidence' => $this->otherEmployerEvidence,
            'issues' => $this->issues,
        ];
    }
}
