<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\Pricing\PriceRounding as R;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PriceRoundingTest extends TestCase
{
    public function testNoneKeepsUnitPricePrecision(): void
    {
        self::assertSame('196.50', R::apply('196.5', 'none'));
        self::assertSame('196.50', R::apply('196.500000', 'none'));
        self::assertSame('0.004', R::apply('0.004', 'none'));
        self::assertSame('0.0108', R::apply('0.010800', 'none'));
        self::assertSame('196.554', R::apply('196.554', 'none'));
        self::assertSame('0.000001', R::apply('0.0000005', 'none')); // half-up na šesté místo
        self::assertSame('0.00', R::apply('0.0000004', 'none'));
    }

    public function testRoundToHeller(): void
    {
        self::assertSame('196.50', R::apply('196.50', '0.01'));
        self::assertSame('0.00', R::apply('0.004', '0.01'));
        self::assertSame('196.55', R::apply('196.554', '0.01')); // half-up na halíř
        self::assertSame('196.55', R::apply('196.545', '0.01'));
    }

    public function testRoundToTenHellers(): void
    {
        self::assertSame('196.50', R::apply('196.54', '0.10'));
        self::assertSame('196.60', R::apply('196.55', '0.10')); // half-up
        self::assertSame('197.00', R::apply('196.96', '0.10'));
    }

    public function testRoundToHalfCrown(): void
    {
        self::assertSame('196.50', R::apply('196.74', '0.50'));
        self::assertSame('197.00', R::apply('196.75', '0.50')); // half-up
        self::assertSame('196.50', R::apply('196.25', '0.50'));
    }

    public function testRoundToWholeCrown(): void
    {
        self::assertSame('197.00', R::apply('196.50', '1'));   // half-up
        self::assertSame('196.00', R::apply('196.49', '1'));
        self::assertSame('200.00', R::apply('199.99', '1'));
    }

    public function testNineEndingChoosesNearestNineUpOnTie(): void
    {
        self::assertSame('199.00', R::apply('197.00', '9_ending')); // blíž k 199
        self::assertSame('189.00', R::apply('192.00', '9_ending')); // blíž k 189
        self::assertSame('199.00', R::apply('194.00', '9_ending')); // shoda → nahoru
        self::assertSame('1299.00', R::apply('1301.50', '9_ending'));
    }

    public function testZeroAndNegativeGuarded(): void
    {
        self::assertSame('0.00', R::apply('0', 'none'));
        self::assertSame('0.00', R::apply('-50', '1'));
        self::assertSame('0.00', R::apply('', 'none'));
        self::assertSame('0.00', R::apply('abc', '0.10'));
    }

    public function testNineEndingSmallValueFloorsToNine(): void
    {
        self::assertSame('9.00', R::apply('5.00', '9_ending'));
        self::assertSame('9.00', R::apply('1.00', '9_ending'));
    }

    public function testUnknownModeIsPassthrough(): void
    {
        self::assertSame('196.54', R::apply('196.54', 'bogus'));
    }
}
