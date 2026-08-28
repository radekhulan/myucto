<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use UnexpectedValueException;

final class MonthlyHealthInsuranceCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    public function calculate(
        string $calculationDate,
        MonthlyHealthInsuranceInput $input,
    ): MonthlyHealthInsuranceResult {
        $ruleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::HealthInsurance,
            $calculationDate,
        );
        $statutoryMinimum = $this->moneyParameter(
            $ruleset,
            'minimum_assessment_base.monthly',
        );
        $minimumBase = $input->minimumAssessmentBaseMinorUnits ?? 0;
        if ($minimumBase > $statutoryMinimum) {
            throw new InvalidArgumentException(
                'Effective health insurance minimum cannot exceed the statutory monthly minimum.',
            );
        }

        if (!$input->participates) {
            return new MonthlyHealthInsuranceResult(
                $input->assessmentBaseMinorUnits,
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                null,
                null,
                $ruleset->id,
                $ruleset->canonicalHash,
                null,
            );
        }

        $rate = $this->rateParameter($ruleset, 'total.rate');
        $standardStep = CalculationStep::calculate(
            'monthly-health-insurance-standard',
            $input->assessmentBaseMinorUnits,
            $rate,
            RoundingMode::Ceil,
        );
        $standardContribution = PayrollRounding::ceilToCzk($standardStep->outputMinorUnits);
        $employeeStandard = PayrollRounding::ceilToCzk(
            RoundingMode::Ceil->roundFraction($standardContribution, 3),
        );
        $employerStandard = $standardContribution - $employeeStandard;

        $minimumContributionStep = null;
        $topUpStep = null;
        $topUp = 0;
        if ($minimumBase > $input->assessmentBaseMinorUnits) {
            $topUpStep = CalculationStep::calculate(
                'monthly-health-insurance-minimum-top-up',
                $minimumBase - $input->assessmentBaseMinorUnits,
                $rate,
                RoundingMode::Ceil,
            );
            $minimumContributionStep = CalculationStep::calculate(
                'monthly-health-insurance-minimum-total',
                $minimumBase,
                $rate,
                RoundingMode::Ceil,
            );
            $minimumContribution = PayrollRounding::ceilToCzk(
                $minimumContributionStep->outputMinorUnits,
            );
            $topUp = PayrollRounding::healthMinimumTopUp(
                $standardContribution,
                $minimumContribution,
            );
        }
        $employeeTopUp = $input->minimumTopUpPayer === HealthMinimumTopUpPayer::Employee
            ? $topUp
            : 0;
        $employerTopUp = $input->minimumTopUpPayer === HealthMinimumTopUpPayer::Employer
            ? $topUp
            : 0;

        return new MonthlyHealthInsuranceResult(
            $input->assessmentBaseMinorUnits,
            $minimumBase,
            $standardContribution,
            $employeeStandard,
            $employerStandard,
            $employeeTopUp,
            $employerTopUp,
            $employeeStandard + $employeeTopUp,
            $employerStandard + $employerTopUp,
            $standardContribution + $topUp,
            $standardStep,
            $topUpStep,
            $ruleset->id,
            $ruleset->canonicalHash,
            $minimumContributionStep,
        );
    }

    private function moneyParameter(PayrollRulesetVersion $ruleset, string $key): int
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'money_minor' || !is_int($parameter->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not money.");
        }

        return $parameter->value;
    }

    private function rateParameter(PayrollRulesetVersion $ruleset, string $key): DecimalRate
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'decimal_rate' || !is_string($parameter->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not a rate.");
        }

        return DecimalRate::fromString($parameter->value);
    }
}
