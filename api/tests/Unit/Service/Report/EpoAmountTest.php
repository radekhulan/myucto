<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoAmount;
use PHPUnit\Framework\TestCase;

/**
 * Issue #141 — zaokrouhlení haléřových součtů na celé Kč ve výkazech DPH.
 */
final class EpoAmountTest extends TestCase
{
    /** @param list<float> $parts */
    private static function floatSum(array $parts): float
    {
        $sum = 0.0;
        foreach ($parts as $p) {
            $sum += $p;
        }
        return $sum;
    }

    public function testWholeCrownSumIsNotRoundedUpByFloatError(): void
    {
        $sum = self::floatSum([842.86, 219.06, 858.84, 907.32, 206.92]);
        $this->assertGreaterThan(3035.0, $sum, 'předpoklad testu: float součet přesahuje 3 035');
        $this->assertSame(3035, EpoAmount::wholeCzkUp($sum));
        $this->assertSame(3035, EpoAmount::wholeCzk($sum));
    }

    public function testHalfCrownSumRoundsAwayFromZeroDespiteFloatError(): void
    {
        $sum = self::floatSum([6349.68, 2426.39, 2211.95, 2787.57, 1025.40, 5854.79, 8711.17, 1882.55]);
        $this->assertLessThan(31249.5, $sum, 'předpoklad testu: float součet je pod 31 249,50');
        $this->assertSame(31250, EpoAmount::wholeCzk($sum));
        $this->assertSame(31250, EpoAmount::wholeCzkUp($sum));
        $this->assertSame(-31250, EpoAmount::wholeCzk(-$sum));
    }

    public function testRoundUp(): void
    {
        $this->assertSame(101, EpoAmount::wholeCzkUp(100.01));
        $this->assertSame(100, EpoAmount::wholeCzkUp(100.0));
        $this->assertSame(0, EpoAmount::wholeCzkUp(0.0));
        $this->assertSame(1, EpoAmount::wholeCzkUp(0.001 + 0.009));
    }

    public function testMathematicalRounding(): void
    {
        $this->assertSame(100, EpoAmount::wholeCzk(100.49));
        $this->assertSame(101, EpoAmount::wholeCzk(100.50));
        $this->assertSame(-101, EpoAmount::wholeCzk(-100.50));
        $this->assertSame(-100, EpoAmount::wholeCzk(-100.49));
    }
}
