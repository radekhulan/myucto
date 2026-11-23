<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Hourly;

use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Hourly\PayrollExactFraction;
use PHPUnit\Framework\TestCase;

final class PayrollExactFractionTest extends TestCase
{
    public function testAnalyticalHalersAreNotAnIntermediatePayableRounding(): void
    {
        $amount = PayrollExactFraction::product([18750, 455250], [3000]);
        self::assertSame(2845313, $amount->analyticalMinor());
        self::assertSame(2845400, $amount->payableMinor(PayrollWageRounding::Ceil));
        self::assertSame(2845300, $amount->payableMinor(PayrollWageRounding::HalfUp));
        $small = new PayrollExactFraction(3000100, 6000);
        self::assertSame(500, $small->analyticalMinor());
        self::assertSame(600, $small->payableMinor(PayrollWageRounding::Ceil));
    }

    public function testReductionPrecedesMultiplicationAndClaimsRoundOnlyAfterSum(): void
    {
        self::assertSame(PHP_INT_MAX, PayrollExactFraction::product([PHP_INT_MAX, 3000], [3000])->numerator);
        $a = new PayrollExactFraction(10001, 100);
        $total = $a->plus($a);
        self::assertSame(300, $total->payableMinor(PayrollWageRounding::Ceil));
        self::assertSame(400, $a->payableMinor(PayrollWageRounding::Ceil) * 2);
    }

    public function testOverflowFailsInsteadOfConvertingToFloat(): void
    {
        $this->expectException(\OverflowException::class);
        PayrollExactFraction::product([PHP_INT_MAX, 2], [1]);
    }
}
