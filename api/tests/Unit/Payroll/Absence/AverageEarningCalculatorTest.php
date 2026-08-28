<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AverageEarningCalculator;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class AverageEarningCalculatorTest extends TestCase
{
    public function testActualAverageUsesWorkedMinutesAndMinorUnits(): void
    {
        $result = $this->calculator()->calculate(
            '2026-04-01',
            12_000_000,
            0,
            9_600,
            60,
        );

        self::assertSame('actual', $result->sourceKind);
        self::assertSame(75_000, $result->averageHourlyMinor);
        self::assertSame('manual_review', $result->supportStatus);
    }

    public function testFewerThanTwentyOneDaysFailsClosedWithoutProbableEarning(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator()->calculate('2026-04-01', 2_000_000, 0, 1_200, 20);
    }

    public function testProbableAverageRequiresAndPreservesRationale(): void
    {
        $result = $this->calculator()->calculate(
            '2026-04-01',
            2_000_000,
            0,
            1_200,
            20,
            42_500,
            'Srovnatelná práce a sjednaná mzda.',
        );

        self::assertSame('probable', $result->sourceKind);
        self::assertSame(42_500, $result->averageHourlyMinor);
        self::assertSame('Srovnatelná práce a sjednaná mzda.', $result->trace['rationale']);
    }

    public function testMinimumWorkedDaysComesFromRulesetNotFromLiteral(): void
    {
        $result = $this->calculator()->calculate('2026-04-01', 12_000_000, 0, 9_600, 60);

        self::assertSame(21, $result->trace['minimum_worked_days']);
    }

    public function testYearWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException::class);
        $this->calculator()->calculate('2027-04-01', 12_000_000, 0, 9_600, 60);
    }

    public function testCalendarShiftWorksAsSoonAsNextYearRulesetExists(): void
    {
        $calculator = new AverageEarningCalculator(
            ShiftedYearPayrollRulesetFixture::provider(2027),
        );

        $result = $calculator->calculate('2027-04-01', 12_000_000, 0, 9_600, 60);

        self::assertSame('actual', $result->sourceKind);
        self::assertSame(75_000, $result->averageHourlyMinor);
    }

    /**
     * § 357 odst. 1 ZP. Úkolová mzda 18 000 Kč za 160 hodin dá 112,50 Kč,
     * zákonné minimum je 134,40 Kč — bez floor kalkulátoru vyjde náhrada
     * za sedm osmihodinových směn DPN o 665 Kč nižší.
     */
    public function testAverageBelowMinimumWageIsLiftedToTheMinimum(): void
    {
        $result = $this->calculator()->calculate('2026-04-01', 1_800_000, 0, 9_600, 60);

        self::assertSame(13_440, $result->averageHourlyMinor);
        self::assertSame(11_250, $result->trace['raw_hourly_minor']);
        self::assertTrue($result->trace['minimum_wage_floor_applied']);
        self::assertSame(13_440, $result->trace['minimum_wage_hourly_minor']);
    }

    public function testAverageAboveMinimumWageStaysUntouched(): void
    {
        $result = $this->calculator()->calculate('2026-04-01', 12_000_000, 0, 9_600, 60);

        self::assertSame(75_000, $result->averageHourlyMinor);
        self::assertFalse($result->trace['minimum_wage_floor_applied']);
    }

    public function testShorterWeeklyTimeRaisesTheFloorInTheCalculation(): void
    {
        $result = $this->calculator()->calculate(
            '2026-04-01',
            1_800_000,
            0,
            9_600,
            60,
            null,
            null,
            2_250,
        );

        self::assertSame(14_336, $result->averageHourlyMinor);
        self::assertSame(2_250, $result->trace['minimum_wage_weekly_minutes']);
    }

    public function testProbableEarningIsAlsoLiftedToTheMinimum(): void
    {
        $result = $this->calculator()->calculate(
            '2026-04-01',
            2_000_000,
            0,
            1_200,
            20,
            9_000,
            'Srovnatelná práce a sjednaná mzda.',
        );

        self::assertSame('probable', $result->sourceKind);
        self::assertSame(13_440, $result->averageHourlyMinor);
        self::assertSame(9_000, $result->trace['probable_hourly_minor']);
        self::assertTrue($result->trace['minimum_wage_floor_applied']);
    }

    private function calculator(): AverageEarningCalculator
    {
        return new AverageEarningCalculator(CzechPayrollRulesets2026::provider());
    }
}
