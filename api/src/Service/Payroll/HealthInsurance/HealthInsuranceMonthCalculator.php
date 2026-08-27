<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use DateTimeImmutable;
use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceInput;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use OverflowException;
use UnexpectedValueException;

final class HealthInsuranceMonthCalculator
{
    private readonly HealthAssessmentBaseResolver $assessmentBaseResolver;
    private readonly HealthParticipationResolver $participationResolver;
    private readonly HealthMinimumResolver $minimumResolver;
    private readonly MonthlyHealthInsuranceCalculator $contributionCalculator;

    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
        ?HealthAssessmentBaseResolver $assessmentBaseResolver = null,
        ?HealthParticipationResolver $participationResolver = null,
        ?HealthMinimumResolver $minimumResolver = null,
    ) {
        $this->assessmentBaseResolver = $assessmentBaseResolver
            ?? new HealthAssessmentBaseResolver();
        $this->participationResolver = $participationResolver
            ?? new HealthParticipationResolver();
        $this->minimumResolver = $minimumResolver ?? new HealthMinimumResolver();
        $this->contributionCalculator = new MonthlyHealthInsuranceCalculator($rulesets);
    }

    public function calculate(HealthInsuranceMonthInput $input): HealthInsuranceMonthResult
    {
        $ruleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::HealthInsurance,
            $input->calculationDate,
        );
        $dppThreshold = $this->moneyParameter($ruleset, 'participation.dpp.minimum');
        $dpcThreshold = $this->moneyParameter($ruleset, 'participation.dpc.minimum');
        $statutoryMinimum = $this->moneyParameter(
            $ruleset,
            'minimum_assessment_base.monthly',
        );

        $peopleInputs = $input->people;
        usort(
            $peopleInputs,
            static fn (HealthPersonMonthInput $left, HealthPersonMonthInput $right): int =>
                $left->personId <=> $right->personId,
        );
        $people = [];
        $issues = [];
        foreach ($peopleInputs as $personInput) {
            $person = $this->calculatePerson(
                $input->calculationDate,
                $personInput,
                $dppThreshold,
                $dpcThreshold,
                $statutoryMinimum,
            );
            $people[] = $person;
            foreach ($person->issues as $issue) {
                $issues[] = "person:{$person->personId}:{$issue}";
            }
        }
        sort($issues, SORT_STRING);

        if ($issues !== []) {
            return new HealthInsuranceMonthResult(
                $input->calculationDate,
                HealthCalculationStatus::ManualReview,
                null,
                null,
                null,
                null,
                $people,
                [],
                $issues,
                $ruleset->id,
                $ruleset->canonicalHash,
            );
        }

        $assessmentBase = 0;
        $employeeContribution = 0;
        $employerContribution = 0;
        $totalContribution = 0;
        /** @var array<string,array{people:int,base:int,employee:int,employer:int,total:int}> $insurers */
        $insurers = [];
        foreach ($people as $person) {
            $assessmentBase = $this->add($assessmentBase, $person->assessmentBaseMinorUnits);
            $employeeContribution = $this->add(
                $employeeContribution,
                $person->employeeContributionMinorUnits ?? 0,
            );
            $employerContribution = $this->add(
                $employerContribution,
                $person->employerContributionMinorUnits ?? 0,
            );
            $totalContribution = $this->add(
                $totalContribution,
                $person->totalContributionMinorUnits ?? 0,
            );
            if ($person->insurerCode === null || !$this->personParticipates($person)) {
                continue;
            }
            $insurers[$person->insurerCode] ??= [
                'people' => 0,
                'base' => 0,
                'employee' => 0,
                'employer' => 0,
                'total' => 0,
            ];
            if ($person->ppzCounted) {
                $insurers[$person->insurerCode]['people']++;
            }
            $insurers[$person->insurerCode]['base'] = $this->add(
                $insurers[$person->insurerCode]['base'],
                $person->assessmentBaseMinorUnits,
            );
            $insurers[$person->insurerCode]['employee'] = $this->add(
                $insurers[$person->insurerCode]['employee'],
                $person->employeeContributionMinorUnits ?? 0,
            );
            $insurers[$person->insurerCode]['employer'] = $this->add(
                $insurers[$person->insurerCode]['employer'],
                $person->employerContributionMinorUnits ?? 0,
            );
            $insurers[$person->insurerCode]['total'] = $this->add(
                $insurers[$person->insurerCode]['total'],
                $person->totalContributionMinorUnits ?? 0,
            );
        }
        ksort($insurers, SORT_STRING);
        $liabilities = [];
        foreach ($insurers as $code => $liability) {
            $liabilities[] = new HealthInsurerLiabilityResult(
                (string) $code,
                $liability['people'],
                $liability['base'],
                $liability['employee'],
                $liability['employer'],
                $liability['total'],
            );
        }

        return new HealthInsuranceMonthResult(
            $input->calculationDate,
            HealthCalculationStatus::Calculated,
            $assessmentBase,
            $employeeContribution,
            $employerContribution,
            $totalContribution,
            $people,
            $liabilities,
            [],
            $ruleset->id,
            $ruleset->canonicalHash,
        );
    }

    private function calculatePerson(
        string $calculationDate,
        HealthPersonMonthInput $input,
        int $dppThreshold,
        int $dpcThreshold,
        int $statutoryMinimum,
    ): HealthPersonMonthResult {
        $relationships = $input->relationships;
        usort(
            $relationships,
            static fn (
                HealthInsuranceRelationshipInput $left,
                HealthInsuranceRelationshipInput $right,
            ): int => $left->relationshipId <=> $right->relationshipId,
        );
        $facts = array_map(
            fn (HealthInsuranceRelationshipInput $relationship): HealthRelationshipFacts =>
                $this->assessmentBaseResolver->resolve($relationship),
            $relationships,
        );

        if ($input->jurisdiction !== HealthJurisdictionEvidence::CzechRegimeVerified) {
            return $this->nonCzechPersonResult(
                $calculationDate,
                $input,
                $facts,
                $statutoryMinimum,
            );
        }

        $decisions = $this->participationResolver->resolve(
            $calculationDate,
            $facts,
            $dppThreshold,
            $dpcThreshold,
        );
        $issues = [];
        if ($input->insurerStatus !== HealthInsurerSnapshotStatus::Verified) {
            $issues[] = 'health_insurer_snapshot_unverified';
        }
        $assessmentBase = 0;
        $participates = false;
        foreach ($facts as $fact) {
            $decision = $decisions[$fact->relationship->relationshipId];
            if ($decision->status === HealthParticipationStatus::ManualReview) {
                foreach ($decision->reasonCodes as $reason) {
                    $issues[] = "relationship:{$fact->relationship->relationshipId}:{$reason}";
                }
                continue;
            }
            if ($decision->status === HealthParticipationStatus::Participates) {
                $participates = true;
                $assessmentBase = $this->add(
                    $assessmentBase,
                    $fact->assessmentBaseMinorUnits,
                );
            }
        }

        $minimum = $this->minimumResolver->resolve(
            $calculationDate,
            $input,
            $facts,
            $decisions,
            $assessmentBase,
            $statutoryMinimum,
        );
        $ppzCounted = $this->hasActiveLocalParticipatingRelationship(
            $calculationDate,
            $facts,
            $decisions,
        );
        $issues = [...$issues, ...$minimum->issues];
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);

        if ($issues !== []) {
            return $this->personResult(
                $input,
                HealthCalculationStatus::ManualReview,
                $facts,
                $decisions,
                $assessmentBase,
                $minimum,
                null,
                $issues,
                $ppzCounted,
            );
        }

        $contribution = $this->contributionCalculator->calculate(
            $calculationDate,
            new MonthlyHealthInsuranceInput(
                $assessmentBase,
                $participates,
                $minimum->topUpPayer === null
                    ? null
                    : $minimum->minimumForThisEmployerMinorUnits,
                $minimum->topUpPayer,
            ),
        );

        return $this->personResult(
            $input,
            HealthCalculationStatus::Calculated,
            $facts,
            $decisions,
            $assessmentBase,
            $minimum,
            $contribution,
            [],
            $ppzCounted,
        );
    }

    /**
     * @param list<HealthRelationshipFacts> $facts
     */
    private function nonCzechPersonResult(
        string $calculationDate,
        HealthPersonMonthInput $input,
        array $facts,
        int $statutoryMinimum,
    ): HealthPersonMonthResult {
        $manual = $input->jurisdiction === HealthJurisdictionEvidence::Unverified
            || (
                $input->jurisdiction === HealthJurisdictionEvidence::ForeignRegimeVerified
                && $input->insurerStatus !== HealthInsurerSnapshotStatus::NotApplicable
            );
        $issues = [];
        if ($input->jurisdiction === HealthJurisdictionEvidence::Unverified) {
            $issues[] = 'health_insurance_jurisdiction_unverified';
        }
        if (
            $input->jurisdiction === HealthJurisdictionEvidence::ForeignRegimeVerified
            && $input->insurerStatus !== HealthInsurerSnapshotStatus::NotApplicable
        ) {
            $issues[] = 'foreign_regime_requires_non_applicable_czech_insurer_snapshot';
        }
        $reason = $input->jurisdiction === HealthJurisdictionEvidence::ForeignRegimeVerified
            ? 'foreign_health_insurance_regime_verified'
            : 'health_insurance_jurisdiction_unverified';
        $decisions = [];
        foreach ($facts as $fact) {
            $decisions[$fact->relationship->relationshipId] =
                new HealthParticipationDecision(
                    $fact->relationship->relationshipId,
                    $manual
                        ? HealthParticipationStatus::ManualReview
                        : HealthParticipationStatus::DoesNotParticipate,
                    $fact->participationIncomeMinorUnits,
                    $fact->participationIncomeMinorUnits,
                    null,
                    [$reason],
                );
        }
        $minimum = new HealthMinimumAssessment(
            0,
            0,
            0,
            0,
            $statutoryMinimum,
            0,
            0,
            0,
            0,
            null,
            [],
            $issues,
        );

        return $this->personResult(
            $input,
            $manual ? HealthCalculationStatus::ManualReview : HealthCalculationStatus::Calculated,
            $facts,
            $decisions,
            0,
            $minimum,
            $manual ? null : $this->contributionCalculator->calculate(
                $calculationDate,
                new MonthlyHealthInsuranceInput(0, false),
            ),
            $issues,
            false,
        );
    }

    /**
     * @param list<HealthRelationshipFacts> $facts
     * @param array<string,HealthParticipationDecision> $decisions
     * @param list<string> $issues
     */
    private function personResult(
        HealthPersonMonthInput $input,
        HealthCalculationStatus $status,
        array $facts,
        array $decisions,
        int $assessmentBase,
        HealthMinimumAssessment $minimum,
        ?\MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceResult $contribution,
        array $issues,
        bool $ppzCounted,
    ): HealthPersonMonthResult {
        $relationshipResults = array_map(
            static function (HealthRelationshipFacts $fact) use ($decisions): HealthRelationshipResult {
                $decision = $decisions[$fact->relationship->relationshipId];

                return new HealthRelationshipResult(
                    $fact->relationship->relationshipId,
                    $fact->relationship->kind,
                    $decision,
                    $fact->assessmentBaseMinorUnits,
                    $decision->status === HealthParticipationStatus::Participates
                        ? $fact->assessmentBaseMinorUnits
                        : 0,
                    $fact->includedParticipationComponents,
                    $fact->excludedParticipationComponents,
                    $fact->includedAssessmentBaseComponents,
                    $fact->excludedAssessmentBaseComponents,
                );
            },
            $facts,
        );
        $otherEmployerEvidence = array_map(
            static fn (HealthOtherEmployerBase $otherEmployer): array => [
                'employer_reference' => $otherEmployer->employerReference,
                'assessment_base_minor_units' => $otherEmployer->assessmentBaseMinorUnits,
                'employment_from' => $otherEmployer->employmentFrom,
                'employment_to' => $otherEmployer->employmentTo,
                'evidence_reference' => $otherEmployer->evidenceReference,
            ],
            $input->otherEmployerBases,
        );
        usort(
            $otherEmployerEvidence,
            static fn (array $left, array $right): int =>
                $left['employer_reference'] <=> $right['employer_reference'],
        );

        return new HealthPersonMonthResult(
            $input->personId,
            $status,
            $input->jurisdiction,
            $input->jurisdictionEvidenceReference,
            $input->insurerStatus,
            $input->insurerCode,
            $input->insurerEvidenceReference,
            $assessmentBase,
            $minimum->otherEmployerAssessmentBaseMinorUnits,
            $minimum->combinedAssessmentBaseMinorUnits,
            $minimum->employmentCalendarDays,
            $minimum->excludedCalendarDays,
            $minimum->minimumApplicableCalendarDays,
            $minimum->statutoryMonthlyMinimumMinorUnits,
            $minimum->effectiveMinimumMinorUnits,
            $input->topUpResponsibility,
            $input->topUpResponsibilityEvidenceReference,
            $input->selectedTopUpEmployerEvidenceReference,
            $contribution?->standardContributionMinorUnits,
            $contribution?->employeeStandardContributionMinorUnits,
            $contribution?->employerStandardContributionMinorUnits,
            $contribution?->employeeMinimumTopUpMinorUnits,
            $contribution?->employerMinimumTopUpMinorUnits,
            $contribution?->employeeContributionMinorUnits,
            $contribution?->employerContributionMinorUnits,
            $contribution?->totalContributionMinorUnits,
            $relationshipResults,
            $minimum->reductionEvidence,
            $otherEmployerEvidence,
            $issues,
            $input->topUpEmployerSelection,
            $ppzCounted,
            $contribution?->standardContributionStep,
            $contribution?->minimumTopUpStep,
            $input->topUpResponsibilitySource,
            $contribution?->minimumContributionStep,
        );
    }

    /**
     * @param list<HealthRelationshipFacts> $facts
     * @param array<string,HealthParticipationDecision> $decisions
     */
    private function hasActiveLocalParticipatingRelationship(
        string $calculationDate,
        array $facts,
        array $decisions,
    ): bool {
        $monthStart = new DateTimeImmutable(substr($calculationDate, 0, 7) . '-01');
        $monthEnd = $monthStart->modify('last day of this month');
        foreach ($facts as $fact) {
            $relationship = $fact->relationship;
            if (
                $decisions[$relationship->relationshipId]->status
                !== HealthParticipationStatus::Participates
            ) {
                continue;
            }
            $employmentStart = new DateTimeImmutable($relationship->employmentFrom);
            $employmentEnd = $relationship->employmentTo === null
                ? $monthEnd
                : new DateTimeImmutable($relationship->employmentTo);
            if ($employmentStart <= $monthEnd && $employmentEnd >= $monthStart) {
                return true;
            }
        }

        return false;
    }

    private function moneyParameter(PayrollRulesetVersion $ruleset, string $key): int
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'money_minor' || !is_int($parameter->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not money.");
        }

        return $parameter->value;
    }

    private function personParticipates(HealthPersonMonthResult $person): bool
    {
        foreach ($person->relationships as $relationship) {
            if ($relationship->participation->status === HealthParticipationStatus::Participates) {
                return true;
            }
        }

        return false;
    }

    private function add(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new OverflowException('Health insurance aggregation exceeds the integer range.');
        }

        return $left + $right;
    }
}
