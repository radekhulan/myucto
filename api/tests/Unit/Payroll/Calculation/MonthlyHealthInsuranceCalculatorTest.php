<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\HealthMinimumTopUpPayer;
use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceInput;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Tests\Fixtures\Payroll\ActivePayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class MonthlyHealthInsuranceCalculatorTest extends TestCase
{
    public function testRoundsTotalPerEmployeeThenSplitsOneThirdAndTwoThirds(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyHealthInsuranceInput(4_790_000, true),
        );

        self::assertSame(646_700, $result->standardContributionMinorUnits);
        self::assertSame(215_600, $result->employeeStandardContributionMinorUnits);
        self::assertSame(431_100, $result->employerStandardContributionMinorUnits);
        self::assertSame(646_700, $result->totalContributionMinorUnits);
    }

    public function testChargesMinimumDifferenceToSelectedPayer(): void
    {
        $employeeTopUp = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyHealthInsuranceInput(
                1_000_000,
                true,
                2_240_000,
                HealthMinimumTopUpPayer::Employee,
            ),
        );

        self::assertSame(135_000, $employeeTopUp->standardContributionMinorUnits);
        self::assertSame(167_400, $employeeTopUp->employeeMinimumTopUpMinorUnits);
        self::assertSame(212_400, $employeeTopUp->employeeContributionMinorUnits);
        self::assertSame(90_000, $employeeTopUp->employerContributionMinorUnits);
        self::assertSame(302_400, $employeeTopUp->totalContributionMinorUnits);

        $employerTopUp = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyHealthInsuranceInput(
                1_000_000,
                true,
                2_240_000,
                HealthMinimumTopUpPayer::Employer,
            ),
        );
        self::assertSame(45_000, $employerTopUp->employeeContributionMinorUnits);
        self::assertSame(257_400, $employerTopUp->employerContributionMinorUnits);
    }

    public function testRoundsMinimumTotalOnceAndUsesTopUpAsDifference(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyHealthInsuranceInput(
                10_100,
                true,
                2_240_000,
                HealthMinimumTopUpPayer::Employee,
            ),
        );

        self::assertSame(1_400, $result->standardContributionMinorUnits);
        self::assertSame(301_000, $result->employeeMinimumTopUpMinorUnits);
        self::assertSame(302_400, $result->totalContributionMinorUnits);
        self::assertSame(
            'monthly-health-insurance-minimum-total',
            $result->minimumContributionStep?->label,
        );
    }

    public function testMatchesAcceptedLowIncomeDirectorHealthContribution(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyHealthInsuranceInput(
                450_000,
                true,
                2_240_000,
                HealthMinimumTopUpPayer::Employee,
            ),
        );

        self::assertSame(60_800, $result->standardContributionMinorUnits);
        self::assertSame(20_300, $result->employeeStandardContributionMinorUnits);
        self::assertSame(40_500, $result->employerStandardContributionMinorUnits);
        self::assertSame(241_600, $result->employeeMinimumTopUpMinorUnits);
        self::assertSame(261_900, $result->employeeContributionMinorUnits);
        self::assertSame(302_400, $result->totalContributionMinorUnits);
    }

    public function testNonParticipationProducesNoContributionAndRejectsMinimum(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyHealthInsuranceInput(1_199_900, false),
        );
        self::assertSame(0, $result->totalContributionMinorUnits);
        self::assertNull($result->standardContributionStep);

        $this->expectException(InvalidArgumentException::class);
        new MonthlyHealthInsuranceInput(
            1_199_900,
            false,
            2_240_000,
            HealthMinimumTopUpPayer::Employee,
        );
    }

    public function testRejectsMinimumAboveStatutoryMonthlyAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyHealthInsuranceInput(
                1_000_000,
                true,
                2_240_100,
                HealthMinimumTopUpPayer::Employee,
            ),
        );
    }

    private function calculator(): MonthlyHealthInsuranceCalculator
    {
        return new MonthlyHealthInsuranceCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::HealthInsurance),
        );
    }
}
