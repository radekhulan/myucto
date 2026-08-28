<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\MinimumWageFloor;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

final class MinimumWageFloorTest extends TestCase
{
    public function testFullWeekTakesTheRulesetRateAsIs(): void
    {
        $floor = MinimumWageFloor::forDate(CzechPayrollRulesets2026::provider(), '2026-04-01');

        self::assertSame(13_440, $floor->hourlyMinor);
        self::assertSame(2_400, $floor->weeklyMinutes);
    }

    public function testShorterWeeklyHoursRaiseTheHourlyMinimum(): void
    {
        // 37,5 h týdně → 134,40 × 40 / 37,5 = 143,36 Kč.
        $floor = MinimumWageFloor::forDate(
            CzechPayrollRulesets2026::provider(),
            '2026-04-01',
            2_250,
        );

        self::assertSame(14_336, $floor->hourlyMinor);
        self::assertSame(13_440, $floor->baseHourlyMinor);
    }

    public function testThreeShiftWeekRaisesTheHourlyMinimum(): void
    {
        // 37,5 h se používá u dvousměnného provozu, 38,75 h u trojsměnného
        // podle § 79 odst. 2 ZP: 134,40 × 40 / 38,75 = 138,7354… → 138,74 Kč.
        $floor = MinimumWageFloor::forDate(
            CzechPayrollRulesets2026::provider(),
            '2026-04-01',
            2_325,
        );

        self::assertSame(13_874, $floor->hourlyMinor);
    }

    public function testLongerThanStatutoryWeekDoesNotLowerTheMinimum(): void
    {
        $floor = MinimumWageFloor::forDate(
            CzechPayrollRulesets2026::provider(),
            '2026-04-01',
            2_700,
        );

        self::assertSame(13_440, $floor->hourlyMinor);
    }

    public function testApplyLiftsOnlyBelowTheMinimum(): void
    {
        $floor = MinimumWageFloor::forDate(CzechPayrollRulesets2026::provider(), '2026-04-01');

        self::assertSame(13_440, $floor->apply(11_250));
        self::assertSame(20_000, $floor->apply(20_000));
        self::assertTrue($floor->trace(11_250)['minimum_wage_floor_applied']);
        self::assertFalse($floor->trace(20_000)['minimum_wage_floor_applied']);
    }

    public function testInvalidWeeklyTimeFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MinimumWageFloor::forDate(CzechPayrollRulesets2026::provider(), '2026-04-01', 0);
    }
}
