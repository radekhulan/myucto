<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Payment;

use MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceCalculator;
use PHPUnit\Framework\TestCase;

final class PayrollAccidentInsuranceCalculatorTest extends TestCase
{
    private PayrollAccidentInsuranceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PayrollAccidentInsuranceCalculator();
    }

    public function testAppliesRateAndRoundsUpToWholeCrowns(): void
    {
        // 333 333 Kč × 4,20 ‰ = 1 399,9986 Kč → zaokrouhleno nahoru na 1 400 Kč.
        self::assertSame(
            140_000,
            $this->calculator->premiumMinor(33_333_300, '4.20'),
        );
    }

    public function testExactMultipleIsNotRoundedUpFurther(): void
    {
        // 300 000 Kč × 4,20 ‰ = 1 260 Kč přesně.
        self::assertSame(
            126_000,
            $this->calculator->premiumMinor(30_000_000, '4.20'),
        );
    }

    public function testAppliesMinimumQuarterlyPremiumBelowThreshold(): void
    {
        // 1 000 Kč × 4,20 ‰ = 4,20 Kč, minimum je 100 Kč.
        self::assertSame(
            10_000,
            $this->calculator->premiumMinor(100_000, '4.20'),
        );
    }

    public function testMinimumAppliesExactlyAtTheBoundary(): void
    {
        // 100 000 Kč × 1,00 ‰ = 100 Kč přesně — minimum se uplatní, aniž by
        // ho bylo vidět (hranice, ne nad hranicí).
        self::assertSame(
            10_000,
            $this->calculator->premiumMinor(10_000_000, '1.00'),
        );
    }

    public function testZeroAssessmentBaseStillChargesTheMinimum(): void
    {
        self::assertSame(
            10_000,
            $this->calculator->premiumMinor(0, '4.20'),
        );
    }

    public function testRejectsNegativeAssessmentBase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->premiumMinor(-1, '4.20');
    }

    public function testRejectsNonPositiveRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->premiumMinor(1_000_000, '0');
    }
}
