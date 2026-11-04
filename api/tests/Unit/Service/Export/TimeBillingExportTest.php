<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Service\Export\TimeBillingExport;
use PHPUnit\Framework\TestCase;

final class TimeBillingExportTest extends TestCase
{
    public function testWholeHourHasNoDanglingDecimalSeparator(): void
    {
        self::assertSame('1', TimeBillingExport::formatQuantity(['duration_minutes' => 60], 'legacy'));
    }

    public function testLegacyQuantityIsReturnedByteForByte(): void
    {
        self::assertSame('1.20', TimeBillingExport::formatQuantity(['duration_minutes' => null], '1.20'));
    }

    public function testOnlySubCentUnitPriceOptsIntoPrecision(): void
    {
        self::assertTrue(TimeBillingExport::usesPreciseUnitPrice([
            'unit' => 'h',
            'unit_price_without_vat' => 333.33333,
        ]));
        self::assertFalse(TimeBillingExport::usesPreciseUnitPrice([
            'unit' => 'h',
            'unit_price_without_vat' => 333.33,
        ]));
        self::assertTrue(TimeBillingExport::usesPreciseUnitPrice([
            'stock_item_id' => 5,
            'unit' => 'ks',
            'unit_price_without_vat' => 0.0108,
        ]));
        self::assertFalse(TimeBillingExport::usesPreciseUnitPrice([
            'stock_item_id' => 5,
            'unit' => 'ks',
            'unit_price_without_vat' => 12.5,
        ]));
    }

    public function testPreciseUnitPriceIsTrimmedToItsOwnDecimals(): void
    {
        self::assertSame('0.004', TimeBillingExport::formatRate(0.004));
        self::assertSame('0.0108', TimeBillingExport::formatRate(0.0108));
        self::assertSame('12.50', TimeBillingExport::formatRate(12.5));
    }
}
