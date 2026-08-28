<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time\Surcharge;

use MyInvoice\Service\Payroll\Time\Surcharge\PayrollAchievedWage;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use PHPUnit\Framework\TestCase;

final class PayrollAchievedWageTest extends TestCase
{
    /** 42 000 Kč / 176 h × 2 h = 477,27 Kč. */
    public function testMonthlyWageIsSpreadOverTheCalendarFund(): void
    {
        self::assertSame(
            47_727,
            PayrollAchievedWage::forMilliHours(4_200_000, 10_560, 2_000),
        );
    }

    /**
     * Fond měsíce se liší a paušál by rozdíl rozpustil: týž člověk, týž přesčas,
     * jiný měsíc — a jiná dosažená mzda.
     */
    public function testShorterMonthGivesHigherHourlyAchievedWage(): void
    {
        $june = PayrollAchievedWage::forMilliHours(4_200_000, 10_560, 1_000);
        $february = PayrollAchievedWage::forMilliHours(4_200_000, 9_600, 1_000);
        self::assertGreaterThan($june, $february);
        self::assertSame(26_250, $february);
    }

    /** Jeden zlomek, ne dva kroky: půlhodina se nezaokrouhlí dvakrát. */
    public function testRoundsOnceFromASingleFraction(): void
    {
        self::assertSame(
            11_932,
            PayrollAchievedWage::forMilliHours(4_200_000, 10_560, 500),
        );
    }

    public function testZeroHoursIsZeroWithoutTouchingTheBasis(): void
    {
        self::assertSame(0, PayrollAchievedWage::forMilliHours(4_200_000, 10_560, 0));
    }

    public function testMissingCalendarFundFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/pracovní kalendář/');
        PayrollAchievedWage::forMilliHours(4_200_000, 0, 1_000);
    }

    public function testMissingBaseWageFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        PayrollAchievedWage::forMilliHours(0, 10_560, 1_000);
    }

    public function testHourlyRateIsOnlyForTheWageSheet(): void
    {
        self::assertSame(23_864, PayrollAchievedWage::hourlyMinor(4_200_000, 10_560));
    }
}
