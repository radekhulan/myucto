<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Supplier;

use MyInvoice\Service\Invoice\DefaultInvoiceNote;
use MyInvoice\Service\Supplier\SupplierInvoiceDefaults;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupplierInvoiceDefaultsTest extends TestCase
{
    public function testValidationPreservesZeroLocalizedRatesAndNullableNotes(): void
    {
        self::assertSame([
            'default_payment_due_days' => 0,
            'default_payment_due_unit' => 'month',
            'default_hourly_rate' => '1234.567890',
            DefaultInvoiceNote::ENABLED_COLUMN => 1,
            'default_note_below_items_cs' => "První řádek\nDruhý řádek",
            'default_note_below_items_en' => null,
        ], SupplierInvoiceDefaults::validate([
            'default_payment_due_days' => '0',
            'default_payment_due_unit' => 'month',
            'default_hourly_rate' => "1\u{00A0}234,567890",
            DefaultInvoiceNote::ENABLED_COLUMN => true,
            'default_note_below_items_cs' => " První řádek\nDruhý řádek \n",
            'default_note_below_items_en' => ' ',
        ]));
        self::assertSame(['default_hourly_rate' => '0.000000'], SupplierInvoiceDefaults::validate([
            'default_payment_due_days' => '', 'default_hourly_rate' => null,
        ]));
    }

    public function testComparisonAllowsEquivalentRoundTripButRejectsEveryChangedValue(): void
    {
        $same = [
            'default_payment_due_days' => [21, '21'],
            'default_payment_due_unit' => ['month', 'month'],
            'default_hourly_rate' => ['1234.567890', '001234.56789'],
            DefaultInvoiceNote::ENABLED_COLUMN => [1, '1'],
            'default_note_below_items_cs' => [null, ' '],
            'default_note_below_items_en' => ['Text', " Text\n"],
        ];
        foreach ($same as $field => [$value, $stored]) self::assertTrue(SupplierInvoiceDefaults::sameValue($field, $value, $stored), $field);
        $changed = [
            'default_payment_due_days' => [21, '22'],
            'default_payment_due_unit' => ['days', 'month'],
            'default_hourly_rate' => ['1234.567890', '1234.567891'],
            DefaultInvoiceNote::ENABLED_COLUMN => [0, '1'],
            'default_note_below_items_cs' => ['První', 'Druhý'],
            'default_note_below_items_en' => [null, 'Text'],
        ];
        foreach ($changed as $field => [$value, $stored]) self::assertFalse(SupplierInvoiceDefaults::sameValue($field, $value, $stored), $field);
    }

    public function testDatabaseBoundariesAndBrowserFloatRoundTrip(): void
    {
        foreach ([0, 0.000001, 1234.56789, 99999999.999999] as $rate) {
            $stored = SupplierInvoiceDefaults::validate(['default_hourly_rate' => $rate]);
            self::assertSame((float) $rate, SupplierInvoiceDefaults::normalizeResponse($stored)['default_hourly_rate']);
        }
        foreach ([400, 4294967295] as $due) {
            self::assertSame($due, SupplierInvoiceDefaults::validate(['default_payment_due_days' => $due])['default_payment_due_days']);
        }
        $body = SupplierInvoiceDefaults::validate([
            'default_payment_due_days' => 400,
            'default_note_below_items_cs' => str_repeat('ž', 5000),
        ]);
        self::assertSame(400, $body['default_payment_due_days']);
        self::assertSame(5000, mb_strlen($body['default_note_below_items_cs']));
        self::assertSame(DefaultInvoiceNote::supplierColumns(), array_slice(SupplierInvoiceDefaults::FIELDS, 3));
    }

    public static function invalidValues(): iterable
    {
        foreach ([-1, 1.5, true, [], new \stdClass(), 4294967296, '1e2'] as $value) yield ['default_payment_due_days', $value];
        foreach (['year', '', null, []] as $value) yield ['default_payment_due_unit', $value];
        foreach ([-1, true, [], new \stdClass(), NAN, INF, 1e20, '1e999', 100000000, 0.0000001, '1.2345678'] as $value) yield ['default_hourly_rate', $value];
        foreach (['false', null, 2, []] as $value) yield [DefaultInvoiceNote::ENABLED_COLUMN, $value];
        foreach ([str_repeat('ž', 5001), false, [], 42] as $value) yield ['default_note_below_items_cs', $value];
    }

    #[DataProvider('invalidValues')]
    public function testMalformedOrOutOfRangeInputFailsBeforeSql(string $field, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SupplierInvoiceDefaults::validate([$field => $value]);
    }
}
