<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\VatLedgerDimensionAllocation;
use PHPUnit\Framework\TestCase;

/**
 * Rozdělení řádku evidence DPH mezi hodnoty dimenze: díly sedí na řádek na haléř,
 * nepokrytá část rozpadu zůstává „bez dimenze" a částky dokladu se nedělí.
 */
final class VatLedgerDimensionAllocationTest extends TestCase
{
    private const ROW = ['source' => 'sale', 'invoice_id' => 1, 'base_czk' => 100.01, 'vat_czk' => 21.0,
        'deduction_base_czk' => 100.01, 'deduction_vat_czk' => 21.0, 'total_with_vat_czk' => 5000.0];

    public function testRowWithoutAssignmentIsOnePartWithoutDimension(): void
    {
        $parts = VatLedgerDimensionAllocation::split(self::ROW, []);
        self::assertCount(1, $parts);
        self::assertNull($parts[0]['dimension_value_id']);
        self::assertSame(100.01, $parts[0]['base_czk']);
    }

    public function testSplitPartsSumToTheRowToTheHaler(): void
    {
        $parts = VatLedgerDimensionAllocation::split(self::ROW, [7 => 1 / 3, 5 => 1 / 3, 9 => 1 / 3]);
        self::assertSame([5, 7, 9], array_column($parts, 'dimension_value_id'), 'Pořadí dílů podle id hodnoty.');
        foreach (VatLedgerDimensionAllocation::AMOUNT_KEYS as $key) {
            self::assertSame((int) round(self::ROW[$key] * 100), (int) round(array_sum(array_column($parts, $key)) * 100), $key);
        }
        self::assertSame([33.33, 33.34, 33.34], array_column($parts, 'base_czk'), 'Záporný zbytek po zaokrouhlení se odečte od první z největších vah (DimensionStamper::distributeCents).');
        foreach ($parts as $part) {
            self::assertSame(5000.0, $part['total_with_vat_czk'], 'Celková částka dokladu (limit KH) se nedělí.');
        }
    }

    public function testUncoveredShareStaysWithoutDimension(): void
    {
        $parts = VatLedgerDimensionAllocation::split(self::ROW, [3 => 0.6]);
        self::assertSame([3, null], array_column($parts, 'dimension_value_id'));
        self::assertSame([12.6, 8.4], array_column($parts, 'vat_czk'));
    }

    public function testSharesAboveWholeAreNormalised(): void
    {
        $parts = VatLedgerDimensionAllocation::split(self::ROW, [1 => 1.0, 2 => 1.0]);
        self::assertSame([1, 2], array_column($parts, 'dimension_value_id'));
        self::assertSame(21.0, round(array_sum(array_column($parts, 'vat_czk')), 2), 'Řádek ve sestavě nenaroste.');
    }
}
