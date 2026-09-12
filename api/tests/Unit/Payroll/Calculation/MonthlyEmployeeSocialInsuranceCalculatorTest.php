<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use MyInvoice\Service\Payroll\Calculation\MonthlyEmployeeSocialInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployeeSocialInsuranceInput;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Tests\Fixtures\Payroll\ActivePayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class MonthlyEmployeeSocialInsuranceCalculatorTest extends TestCase
{
    public function testCalculatesEmployeeContributionPerPersonAndRoundsUpToCzk(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyEmployeeSocialInsuranceInput(4_790_000, 0, true, false),
        );

        self::assertSame(4_790_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(340_100, $result->employeeContributionMinorUnits);
        self::assertSame(0, $result->workingPensionerDiscountMinorUnits);
    }

    public function testDeductsWorkingPensionerDiscountAsSeparatelyRoundedAmount(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyEmployeeSocialInsuranceInput(4_790_000, 0, true, true),
        );

        self::assertSame(340_100, $result->employeeContributionBeforeDiscountMinorUnits);
        self::assertSame(311_400, $result->workingPensionerDiscountMinorUnits);
        self::assertSame(28_700, $result->employeeContributionMinorUnits);
    }

    /**
     * § 7e odst. 1 ZPSZ: sleva 6,5 % z vyměřovacího základu se zaokrouhluje
     * na celé koruny nahoru i tehdy, když by obchodní zaokrouhlení šlo dolů.
     * 10 001 Kč × 6,5 % = 650,065 Kč, sleva je tedy 651 Kč; pojistné
     * 10 001 Kč × 7,1 % = 710,071 Kč se zaokrouhlí na 711 Kč samostatně.
     */
    public function testWorkingPensionerDiscountRoundsUpToWholeCzk(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyEmployeeSocialInsuranceInput(1_000_100, 0, true, true),
        );

        self::assertSame(71_100, $result->employeeContributionBeforeDiscountMinorUnits);
        self::assertSame(65_100, $result->workingPensionerDiscountMinorUnits);
        self::assertSame(6_000, $result->employeeContributionMinorUnits);
    }

    public function testAppliesRemainingAnnualMaximumAndZeroesNonParticipatingIncome(): void
    {
        $nearMaximum = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyEmployeeSocialInsuranceInput(5_000_000, 234_941_600, true, false),
        );
        self::assertSame(100_000, $nearMaximum->cappedAssessmentBaseMinorUnits);
        self::assertSame(7_100, $nearMaximum->employeeContributionMinorUnits);

        $withoutParticipation = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyEmployeeSocialInsuranceInput(1_199_900, 0, false, false),
        );
        self::assertSame(0, $withoutParticipation->cappedAssessmentBaseMinorUnits);
        self::assertSame(0, $withoutParticipation->employeeContributionMinorUnits);
        self::assertNull($withoutParticipation->contributionStep);
    }

    private function calculator(): MonthlyEmployeeSocialInsuranceCalculator
    {
        return new MonthlyEmployeeSocialInsuranceCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::SocialInsurance),
        );
    }
}
