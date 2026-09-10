<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Stock;

use MyInvoice\Service\Stock\OpeningStockImportProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpeningStockImportProfileTest extends TestCase
{
    private const PROFILE = [
        'source_key' => 'warehouse-2026',
        'warehouse_id' => 7,
        'doc_date' => '2026-01-01',
        'mapping' => [
            'external_id' => 'ID',
            'sku' => 'SKU',
            'quantity' => 'Množství',
            'unit_cost' => 'Cena',
        ],
    ];

    public function testMapsCanonicalDecimalsAndPreservesLeadingZeros(): void
    {
        $profile = OpeningStockImportProfile::normalize(self::PROFILE);
        self::assertSame([
            'external_id' => '000042',
            'sku' => '000007',
            'quantity' => '100.000',
            'unit_cost' => '12.340000',
        ], OpeningStockImportProfile::map($profile, ['ID', 'SKU', 'Množství', 'Cena'], ['000042', '000007', '100', '12,34']));
    }

    #[DataProvider('invalidDecimals')]
    public function testRejectsValuesOutsideDatabaseRange(string $quantity, string $cost): void
    {
        $profile = OpeningStockImportProfile::normalize(self::PROFILE);
        $this->expectException(\InvalidArgumentException::class);
        OpeningStockImportProfile::map($profile, ['ID', 'SKU', 'Množství', 'Cena'], ['1', 'SKU', $quantity, $cost]);
    }

    public static function invalidDecimals(): array
    {
        return [
            'zero quantity' => ['0', '1'],
            'quantity scale' => ['1.0001', '1'],
            'quantity range' => ['100000000000', '1'],
            'negative cost' => ['1', '-1'],
            'cost scale' => ['1', '1.0000001'],
            'cost range' => ['1', '1000000000'],
        ];
    }
}
