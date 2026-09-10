<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Stock;

use MyInvoice\Service\Stock\ExactUnitConversion;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\TestCase;

final class ExactUnitConversionTest extends TestCase
{
    public function testReducesAndConvertsWithoutFloatRounding(): void
    {
        self::assertSame([3, 2], ExactUnitConversion::reduce(6, 4));
        self::assertSame(1875, ExactUnitConversion::toBaseT('1.250', 3, 2));
    }

    public function testRejectsQuantityThatCannotFitBaseThousandths(): void
    {
        $this->expectException(StockException::class);
        $this->expectExceptionMessage('přesně');
        ExactUnitConversion::toBaseT('0.001', 1, 3);
    }
}
