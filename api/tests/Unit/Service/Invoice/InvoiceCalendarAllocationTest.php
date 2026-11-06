<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoiceCalendar;
use PHPUnit\Framework\TestCase;

final class InvoiceCalendarAllocationTest extends TestCase
{
    public function testSingleRateMatchesScheduleExactly(): void
    {
        $out = InvoiceCalendar::allocate(
            [['total_without_vat' => 1000.00, 'total_vat' => 210.00]],
            [
                ['base_amount' => 333.33, 'vat_amount' => 70.00],
                ['base_amount' => 333.33, 'vat_amount' => 70.00],
                ['base_amount' => 333.34, 'vat_amount' => 70.00],
            ],
        );

        self::assertSame([333.33, 333.33, 333.34], array_map(static fn (array $r): float => $r[0]['base'], $out));
        self::assertSame([70.0, 70.0, 70.0], array_map(static fn (array $r): float => $r[0]['vat'], $out));
    }

    public function testMixedRatesSumToBothRowsAndItems(): void
    {
        $items = [
            ['total_without_vat' => 100.01, 'total_vat' => 21.00],
            ['total_without_vat' => 33.33, 'total_vat' => 4.00],
        ];
        $rows = [
            ['base_amount' => 44.45, 'vat_amount' => 8.33],
            ['base_amount' => 44.45, 'vat_amount' => 8.33],
            ['base_amount' => 44.44, 'vat_amount' => 8.34],
        ];
        $out = InvoiceCalendar::allocate($items, $rows);

        foreach ($rows as $r => $row) {
            self::assertEqualsWithDelta($row['base_amount'], $out[$r][0]['base'] + $out[$r][1]['base'], 0.001);
            self::assertEqualsWithDelta($row['vat_amount'], $out[$r][0]['vat'] + $out[$r][1]['vat'], 0.001);
        }
        foreach ($items as $i => $item) {
            self::assertEqualsWithDelta($item['total_without_vat'], array_sum(array_map(static fn (array $r): float => $r[$i]['base'], $out)), 0.001);
            self::assertEqualsWithDelta($item['total_vat'], array_sum(array_map(static fn (array $r): float => $r[$i]['vat'], $out)), 0.001);
        }
    }

    public function testTypesAreSharedWithSqlList(): void
    {
        self::assertSame(
            "'" . implode("','", InvoiceCalendar::TYPES) . "'",
            InvoiceCalendar::SQL_TYPES,
        );
        self::assertSame('invoice', InvoiceCalendar::instalmentType(InvoiceCalendar::RENT));
        self::assertSame('proforma', InvoiceCalendar::instalmentType(InvoiceCalendar::ADVANCE));
    }
}
