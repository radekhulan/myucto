<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use InvalidArgumentException;
use MyInvoice\Service\Invoice\TimeBilling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimeBillingTest extends TestCase
{
    #[DataProvider('hourUnitProvider')]
    public function testKnownHourUnitsAreRecognized(string $unit): void
    {
        self::assertTrue(TimeBilling::isHourUnit($unit));
    }

    public static function hourUnitProvider(): array
    {
        return array_map(static fn (string $unit): array => [$unit], [
            'h', 'H', ' hod ', 'hod.', 'hodina', 'hodiny', 'hour', 'hours', 'hr', 'hrs',
        ]);
    }

    public function testMinuteUnitIsNotTreatedAsHourlyRate(): void
    {
        self::assertFalse(TimeBilling::isHourUnit('min'));
    }

    public function testInvoiceNormalizationKeepsLegacyDecimalQuantityAndSixDecimalHourlyRate(): void
    {
        $item = TimeBilling::normalizeInvoiceItem([
            'quantity' => 0.33,
            'unit' => 'hod',
            'unit_price_without_vat' => 333.3333337,
        ]);

        self::assertSame(0.33, $item['quantity']);
        self::assertNull($item['duration_minutes']);
        self::assertSame(333.333334, $item['unit_price_without_vat']);
    }

    public function testEveryItemKeepsSixDecimalUnitPrice(): void
    {
        $ordinary = TimeBilling::normalizeInvoiceItem([
            'quantity' => 1,
            'unit' => 'ks',
            'unit_price_without_vat' => 12.3456789,
        ]);
        $stock = TimeBilling::normalizeInvoiceItem([
            'quantity' => 1,
            'unit' => 'h',
            'stock_item_id' => 10,
            'unit_price_without_vat' => 12.345678,
        ]);
        $catalog = TimeBilling::normalizeInvoiceItem([
            'quantity' => 1,
            'unit' => 'h',
            'price_list_item_id' => 11,
            'unit_price_without_vat' => 12.345678,
        ]);

        self::assertSame(12.345679, $ordinary['unit_price_without_vat']);
        self::assertSame(12.345678, $stock['unit_price_without_vat']);
        self::assertSame(12.345678, $catalog['unit_price_without_vat']);
    }

    public function testSubCentUnitPriceOfBulkItemKeepsLineTotal(): void
    {
        $caps = TimeBilling::normalizeInvoiceItem([
            'quantity' => 250000,
            'unit' => 'ks',
            'unit_price_without_vat' => 0.004,
        ]);
        $preciseCaps = TimeBilling::normalizeInvoiceItem([
            'quantity' => 250000,
            'unit' => 'ks',
            'stock_item_id' => 10,
            'unit_price_without_vat' => 0.0108,
        ]);

        self::assertSame(0.004, $caps['unit_price_without_vat']);
        self::assertSame(1000.0, TimeBilling::invoiceAmount($caps));
        self::assertSame(0.0108, $preciseCaps['unit_price_without_vat']);
        self::assertSame(2700.0, TimeBilling::invoiceAmount($preciseCaps));
    }

    public function testExactDurationCreatesOnlyACompatibilityProjection(): void
    {
        $item = TimeBilling::normalizeInvoiceItem([
            'quantity' => 99,
            'duration_minutes' => 1,
            'unit' => 'h',
            'unit_price_without_vat' => 1000,
        ]);

        self::assertEqualsWithDelta(1 / 60, $item['quantity'], 0.000000000001);
        self::assertSame(1, $item['duration_minutes']);
        self::assertSame(16.666666666666668, TimeBilling::invoiceAmountInput($item));
    }

    public function testExactDurationRoundsDecimalAmountBeforeFloatConversion(): void
    {
        self::assertSame(0.02, TimeBilling::invoiceAmount([
            'quantity' => 1,
            'duration_minutes' => 60,
            'unit_price_without_vat' => 0.015,
        ]));
        self::assertSame(0.02, TimeBilling::invoiceAmount([
            'quantity' => 0.017,
            'duration_minutes' => 1,
            'unit_price_without_vat' => 0.9,
        ]));
        self::assertSame(0.02, TimeBilling::invoiceAmount([
            'quantity' => 0.333333333333,
            'duration_minutes' => 20,
            'unit_price_without_vat' => 0.045,
        ]));
        self::assertSame(-0.02, TimeBilling::invoiceAmount([
            'quantity' => -1,
            'duration_minutes' => -60,
            'unit_price_without_vat' => 0.015,
        ]));
        self::assertSame(0.02, TimeBilling::workAmount([
            'hours' => 1,
            'duration_minutes' => 60,
            'rate' => 0.015,
        ]));
    }

    public function testHighPrecisionImportQuantityCanInferWholeMinutes(): void
    {
        self::assertSame(1, TimeBilling::inferDurationMinutes('0.016666666667', 'h'));
        self::assertSame(2, TimeBilling::inferDurationMinutes('0.033333333333', 'hours'));
        self::assertNull(TimeBilling::inferDurationMinutes('1.5', 'hours'));
        self::assertNull(TimeBilling::inferDurationMinutes('0.33', 'h'));
        self::assertNull(TimeBilling::inferDurationMinutes('0.016666666667', 'min'));
    }

    public function testDurationRejectsIntegerOverflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimeBilling::durationMinutes(['duration_minutes' => '2147483648']);
    }

    public function testStockItemCannotOptIntoExactDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimeBilling::normalizeInvoiceItem([
            'quantity' => 1,
            'duration_minutes' => 60,
            'unit' => 'h',
            'stock_item_id' => 10,
            'unit_price_without_vat' => 1000,
        ]);
    }
}
