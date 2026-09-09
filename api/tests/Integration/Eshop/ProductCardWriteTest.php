<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Service\Eshop\ProductCardService;
use MyInvoice\Service\Eshop\ProductEditorService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\ProductVendorWriteService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ProductCardWriteTest extends StockTestCase
{
    private ProductCardService $cards;
    private ProductEditorService $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cards = $this->container->get(ProductCardService::class);
        $this->editor = $this->container->get(ProductEditorService::class);
    }

    public function testCategoryOnlyPatchKeepsManufacturer(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CARD-PATCH-1');
        $manufacturerId = $this->manufacturer($supplierId, 'PATCH-MANUFACTURER');
        $categoryId = $this->category($supplierId, 'patch-category');

        $this->db->pdo()->prepare(
            'UPDATE stock_items SET manufacturer_id = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$manufacturerId, $supplierId, $itemId]);

        $this->cards->update($supplierId, $itemId, [
            'row_version' => 1,
            'categories' => [['category_id' => $categoryId, 'is_primary' => true]],
        ]);

        $card = $this->cards->get($supplierId, $itemId);
        self::assertSame($manufacturerId, $card['manufacturer_id']);
        self::assertSame(2, $card['row_version']);
    }

    public function testExplicitNullRemovesManufacturer(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CARD-PATCH-NULL');
        $manufacturerId = $this->manufacturer($supplierId, 'NULL-MANUFACTURER');
        $this->db->pdo()->prepare(
            'UPDATE stock_items SET manufacturer_id = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$manufacturerId, $supplierId, $itemId]);

        $this->cards->update($supplierId, $itemId, [
            'row_version' => 1,
            'manufacturer_id' => null,
        ]);

        self::assertNull($this->cards->get($supplierId, $itemId)['manufacturer_id']);
    }

    public function testStaleVersionCannotOverwriteNewerChange(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CARD-CONFLICT');

        $this->cards->update($supplierId, $itemId, [
            'row_version' => 1,
            'delivery_days' => 3,
        ]);

        try {
            $this->cards->update($supplierId, $itemId, [
                'row_version' => 1,
                'delivery_days' => 10,
            ]);
            self::fail('Zastaralá verze musí skončit konfliktem.');
        } catch (EshopException $e) {
            self::assertSame('version_conflict', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        $card = $this->cards->get($supplierId, $itemId);
        self::assertSame(3, $card['delivery_days']);
        self::assertSame(2, $card['row_version']);
    }

    public function testEditorRollsBackBaseAndProductWhenPriceValidationFails(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CARD-ATOMIC');

        try {
            $this->editor->save($supplierId, $itemId, $this->editorPayload(1, [
                'name' => 'Rozpracovaná změna',
            ], [
                'delivery_days' => 14,
            ], [
                ['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => null],
            ]));
            self::fail('Neplatná cena musí celý agregát odmítnout.');
        } catch (\InvalidArgumentException) {
        }

        $card = $this->cards->get($supplierId, $itemId);
        self::assertSame('Karta CARD-ATOMIC', $card['name']);
        self::assertNull($card['delivery_days']);
        self::assertSame(1, $card['row_version']);
    }

    public function testEditorRollsBackBaseWhenVendorValidationFails(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CARD-VENDOR-ATOMIC');
        $payload = $this->editorPayload(1, ['name' => 'Neuložená karta'], [], []);
        $payload['vendors'] = [[
            'client_id' => 999999999,
            'currency_code' => 'CZK',
            'is_preferred' => true,
        ]];

        try {
            $this->editor->save($supplierId, $itemId, $payload);
            self::fail('Cizí dodavatel musí celý agregát odmítnout.');
        } catch (EshopException $e) {
            self::assertSame('vendor_invalid', $e->errorCode);
        }

        $card = $this->cards->get($supplierId, $itemId);
        self::assertSame('Karta CARD-VENDOR-ATOMIC', $card['name']);
        self::assertSame(1, $card['row_version']);
    }

    public function testEditorSavesWholeAggregateAndReturnsNextVersion(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CARD-SAVE');

        $saved = $this->editor->save($supplierId, $itemId, $this->editorPayload(1, [
            'name' => 'Uložená karta',
        ], [
            'delivery_days' => 4,
        ], [
            ['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '199.90', 'rounding' => 'none'],
        ]));

        self::assertSame('Uložená karta', $saved['name']);
        self::assertSame(4, $saved['delivery_days']);
        self::assertGreaterThan(1, $saved['row_version']);
        self::assertSame('199.90', $saved['prices'][0]['computed_price']);
    }

    public function testStandaloneVendorWriteMakesAlreadyOpenEditorStale(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CARD-STALE-VENDOR');
        $vendorId = $this->client($supplierId, 'Dodavatel souběžné změny');
        $openEditorPayload = $this->editorPayload(1, ['name' => 'Zastaralá změna'], [], []);

        $this->container->get(ProductVendorWriteService::class)->save($supplierId, $itemId, [[
            'client_id' => $vendorId,
            'currency_code' => 'CZK',
            'purchase_price' => '50.00',
        ]]);

        try {
            $this->editor->save($supplierId, $itemId, $openEditorPayload);
            self::fail('Samostatná změna dodavatele musí zneplatnit dříve otevřený editor.');
        } catch (EshopException $e) {
            self::assertSame('version_conflict', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        self::assertSame('Karta CARD-STALE-VENDOR', $this->cards->get($supplierId, $itemId)['name']);
    }

    /**
     * @param array<string,mixed> $itemChanges
     * @param array<string,mixed> $productChanges
     * @param list<array<string,mixed>> $prices
     * @return array<string,mixed>
     */
    private function editorPayload(int $version, array $itemChanges, array $productChanges, array $prices): array
    {
        return [
            'row_version' => $version,
            'item' => array_replace([
                'sku' => str_replace(' ', '-', strtoupper((string) ($itemChanges['name'] ?? 'KARTA'))),
                'name' => 'Karta',
                'item_type' => 'goods',
                'unit' => 'ks',
                'ean' => null,
                'vat_rate_id' => null,
                'sale_price_without_vat' => null,
                'min_qty' => null,
                'is_active' => true,
                'note' => null,
            ], $itemChanges),
            'product' => array_replace([
                'manufacturer_id' => null,
                'warranty_months' => null,
                'delivery_days' => null,
                'export_eshop' => false,
                'is_stocked' => true,
                'weight_g' => null,
                'pricing_base' => 'weighted_avg',
                'i18n' => [],
                'categories' => [],
                'tag_ids' => [],
                'attributes' => [],
                'fees' => [],
            ], $productChanges),
            'prices' => $prices,
            'promo_prices' => [],
            'vendors' => [],
        ];
    }

    private function manufacturer(int $supplierId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO manufacturers (supplier_id, code, name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$supplierId, $code, 'Testovací výrobce']);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function category(int $supplierId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO stock_categories (supplier_id, code, name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$supplierId, $code, 'Testovací kategorie']);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
