<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Service\Eshop\ProductVendorWriteService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockItemDuplicationService;
use MyInvoice\Service\Stock\StockItemLifecycleService;
use MyInvoice\Service\Stock\StockItemTemplateService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class StockItemLifecycleTest extends StockTestCase
{
    private StockItemLifecycleService $lifecycle;
    private StockItemDuplicationService $duplicates;
    private StockItemTemplateService $templates;
    private PriceWriteService $priceWriter;
    private ProductVendorWriteService $vendorWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycle = $this->container->get(StockItemLifecycleService::class);
        $this->duplicates = $this->container->get(StockItemDuplicationService::class);
        $this->templates = $this->container->get(StockItemTemplateService::class);
        $this->priceWriter = $this->container->get(PriceWriteService::class);
        $this->vendorWriter = $this->container->get(ProductVendorWriteService::class);
    }

    public function testDuplicateCopiesOnlySelectedSectionsAndLeavesHistoryAndMediaUntouched(): void
    {
        $sid = $this->createSupplier();
        $warehouseId = $this->warehouse($sid, 'LIFE-DUP');
        $sourceId = $this->item($sid, 'LIFE-SOURCE', 'material');
        $this->db->pdo()->prepare(
            'UPDATE stock_items SET unit = "kg", ean = "1234567890123", min_qty = 7, note = "původní", weight_g = 123, export_eshop = 1 WHERE supplier_id = ? AND id = ?'
        )->execute([$sid, $sourceId]);
        $this->db->pdo()->prepare(
            'INSERT INTO stock_media (supplier_id, stock_item_id, storage_key, original_name) VALUES (?, ?, "synthetic-key", "synthetic.png")'
        )->execute([$sid, $sourceId]);
        $this->receiveStock($sid, $warehouseId, $sourceId, '2.000', 10.0);
        $source = $this->itemsRepo->find($sid, $sourceId);

        $copy = $this->duplicates->duplicate($sid, $sourceId, [
            'sku' => 'LIFE-COPY',
            'name' => 'Kopie karty',
            'row_version' => $source['row_version'],
            'sections' => ['core'],
        ]);

        self::assertSame('draft', $copy['lifecycle_status']);
        self::assertFalse($copy['is_active']);
        self::assertSame('material', $copy['item_type']);
        self::assertSame('kg', $copy['unit']);
        self::assertSame('7.000', $copy['min_qty']);
        self::assertSame('původní', $copy['note']);
        self::assertNull($copy['ean']);
        self::assertNull($copy['manufacturer_id']);
        self::assertNull($copy['weight_g']);
        self::assertFalse($copy['export_eshop']);
        self::assertSame(0, $this->countRows('stock_media', $sid, (int) $copy['id']));
        self::assertSame(0, $this->countRows('stock_document_lines', $sid, (int) $copy['id']));
        self::assertSame(1, $this->countRows('stock_document_lines', $sid, $sourceId));
    }

    public function testTemplateRoundTripUsesFrozenSnapshotAndCreatesDraft(): void
    {
        $sid = $this->createSupplier();
        $sourceId = $this->item($sid, 'LIFE-TEMPLATE', 'product');
        $this->db->pdo()->prepare(
            'INSERT INTO stock_locales (supplier_id, code, name, display_order, is_default) VALUES (?, "en", "English", 0, 1)'
        )->execute([$sid]);
        $this->db->pdo()->prepare('UPDATE stock_items SET unit = "bal", note = "snapshot" WHERE supplier_id = ? AND id = ?')
            ->execute([$sid, $sourceId]);
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_i18n (supplier_id, stock_item_id, locale, name, description, seo_slug) VALUES (?, ?, "en", "Snapshot name", "Snapshot description", "source-slug")'
        )->execute([$sid, $sourceId]);
        $this->priceWriter->save($sid, $sourceId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'fixed',
            'fixed_price' => '25.00',
            'rounding' => 'none',
            'is_manual_override' => true,
            'use_pricing_rules' => false,
        ]], true);
        $source = $this->itemsRepo->find($sid, $sourceId);
        $template = $this->templates->save($sid, $sourceId, [
            'name' => 'Produktová šablona',
            'row_version' => $source['row_version'],
            'sections' => StockItemDuplicationService::SECTIONS,
        ]);
        self::assertArrayNotHasKey('content', $template);
        self::assertArrayNotHasKey('content_json', $template);

        $this->db->pdo()->prepare('UPDATE stock_items SET unit = "ks", note = "pozdější změna", sale_price_without_vat = 99, row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
            ->execute([$sid, $sourceId]);
        $this->db->pdo()->prepare('UPDATE stock_item_i18n SET name = "Changed" WHERE supplier_id = ? AND stock_item_id = ?')
            ->execute([$sid, $sourceId]);

        $created = $this->templates->apply($sid, (int) $template['id'], [
            'sku' => 'LIFE-FROM-TEMPLATE',
            'name' => 'Nová karta ze šablony',
            'row_version' => $template['row_version'],
        ]);
        $translation = $this->db->pdo()->prepare('SELECT name, description, seo_slug FROM stock_item_i18n WHERE supplier_id = ? AND stock_item_id = ? AND locale = "en"');
        $translation->execute([$sid, $created['id']]);
        $i18n = $translation->fetch(\PDO::FETCH_ASSOC);

        self::assertSame('draft', $created['lifecycle_status']);
        self::assertFalse($created['is_active']);
        self::assertFalse($created['export_eshop']);
        self::assertSame('bal', $created['unit']);
        self::assertSame('snapshot', $created['note']);
        self::assertSame('25.00', $created['sale_price_without_vat']);
        self::assertSame('Snapshot name', $i18n['name']);
        self::assertSame('Snapshot description', $i18n['description']);
        self::assertNull($i18n['seo_slug']);
        self::assertArrayNotHasKey('content', $this->templates->list($sid)[0]);
        self::assertArrayNotHasKey('content_json', $this->templates->list($sid)[0]);
    }

    public function testIdentityAndSectionsRejectValuesThatWouldBeCastAndEnforceSkuLength(): void
    {
        foreach ([
            ['sku' => ['ARRAY'], 'name' => 'Karta'],
            ['sku' => 123, 'name' => 'Karta'],
            ['sku' => 'OK', 'name' => ['ARRAY']],
            ['sku' => str_repeat('X', 51), 'name' => 'Karta'],
        ] as $input) {
            $this->assertStockException('validation_failed', fn () => $this->duplicates->identity($input));
        }
        self::assertSame(str_repeat('X', 50), $this->duplicates->identity([
            'sku' => str_repeat('X', 50),
            'name' => 'Karta',
        ])[0]);
        $this->assertStockException(
            'validation_failed',
            fn () => $this->duplicates->normalizeSections(['core', ['prices']]),
        );
    }

    public function testPriceDefinitionsAreRecomputedForNewCardWithoutOldAuditSnapshot(): void
    {
        $sid = $this->createSupplier();
        $sourceId = $this->item($sid, 'LIFE-PRICE-SOURCE');
        $vendorId = $this->client($sid, 'Dodavatel ceny');
        $this->db->pdo()->prepare('UPDATE stock_items SET pricing_base = "manual" WHERE supplier_id = ? AND id = ?')
            ->execute([$sid, $sourceId]);
        $this->vendorWriter->save($sid, $sourceId, [[
            'client_id' => $vendorId,
            'vendor_sku' => 'FOREIGN-SKU',
            'purchase_price' => '40.00',
            'currency_code' => 'CZK',
            'is_preferred' => true,
        ]]);
        $this->priceWriter->save($sid, $sourceId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'markup',
            'markup_pct' => '25',
            'rounding' => 'none',
            'is_manual_override' => false,
            'use_pricing_rules' => false,
        ]], true);
        $this->db->pdo()->prepare(
            'UPDATE stock_item_prices SET computed_price = 999, computed_base = 888, computed_rate = 7,
                 computed_context = ?, computed_at = "2000-01-01 00:00:00"
             WHERE supplier_id = ? AND stock_item_id = ?'
        )->execute([json_encode(['old' => 'audit'], JSON_THROW_ON_ERROR), $sid, $sourceId]);
        $source = $this->itemsRepo->find($sid, $sourceId);

        $copy = $this->duplicates->duplicate($sid, $sourceId, [
            'sku' => 'LIFE-PRICE-COPY',
            'name' => 'Přepočtená karta',
            'row_version' => $source['row_version'],
            'sections' => ['product', 'vendors', 'prices'],
        ]);
        $price = $this->db->pdo()->prepare(
            'SELECT markup_pct, computed_price, computed_base, computed_rate, computed_context, computed_at, use_pricing_rules
             FROM stock_item_prices WHERE supplier_id = ? AND stock_item_id = ? AND currency_code = "CZK"'
        );
        $price->execute([$sid, $copy['id']]);
        $row = $price->fetch(\PDO::FETCH_ASSOC);
        $vendor = $this->db->pdo()->prepare(
            'SELECT vendor_sku, purchase_price FROM stock_item_vendors WHERE supplier_id = ? AND stock_item_id = ?'
        );
        $vendor->execute([$sid, $copy['id']]);
        $vendorRow = $vendor->fetch(\PDO::FETCH_ASSOC);

        self::assertSame('25.000', $row['markup_pct']);
        self::assertSame('50.00', $row['computed_price']);
        self::assertSame('40.000000', $row['computed_base']);
        self::assertNull($row['computed_rate']);
        self::assertNotSame('2000-01-01 00:00:00', $row['computed_at']);
        self::assertStringNotContainsString('"old":"audit"', (string) $row['computed_context']);
        self::assertSame(0, (int) $row['use_pricing_rules']);
        self::assertNull($vendorRow['vendor_sku']);
        self::assertSame('40.00', $vendorRow['purchase_price']);
    }

    public function testDuplicatePreservesCapturedVendorOfferTerms(): void
    {
        $sid = $this->createSupplier();
        $sourceId = $this->item($sid, 'LIFE-OFFER');
        $vendorId = $this->client($sid, 'Fixture vendor');
        $vendors = $this->container->get(\MyInvoice\Repository\StockItemVendorRepository::class);
        $vendors->add($sid, $sourceId, [
            'client_id' => $vendorId, 'vendor_sku' => 'V-SKU', 'purchase_price' => '10.00',
            'currency_code' => 'CZK', 'min_order_qty' => '5.000', 'package_qty' => '10.000',
            'price_valid_to' => '2099-01-01', 'stock_qty' => '20.000',
            'stock_qty_updated_at' => '2020-01-02 03:04:05', 'availability_state' => 'on_order',
            'data_source' => 'feed', 'is_active' => false,
        ]);
        $source = $this->itemsRepo->find($sid, $sourceId);
        $copy = $this->duplicates->duplicate($sid, $sourceId, [
            'sku' => 'LIFE-OFFER-COPY', 'name' => 'Fixture copy',
            'row_version' => $source['row_version'], 'sections' => ['vendors'],
        ]);
        $original = $vendors->listForItem($sid, $sourceId)[0];
        $copied = $vendors->listForItem($sid, $copy['id'])[0];
        foreach (['purchase_price', 'min_order_qty', 'package_qty', 'price_valid_to', 'stock_qty_updated_at', 'availability_state', 'data_source', 'is_active'] as $field) {
            self::assertSame($original[$field], $copied[$field], $field);
        }
    }

    public function testMissingCostRejectsPriceDuplicationAndRollsBackNewCard(): void
    {
        $sid = $this->createSupplier();
        $sourceId = $this->item($sid, 'LIFE-NO-COST');
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_prices
                (supplier_id, stock_item_id, currency_code, price_mode, markup_pct, rounding, computed_price, computed_base)
             VALUES (?, ?, "CZK", "markup", 20, "none", 777, 555)'
        )->execute([$sid, $sourceId]);
        $source = $this->itemsRepo->find($sid, $sourceId);

        $this->assertStockException('missing_purchase_cost', fn () => $this->duplicates->duplicate($sid, $sourceId, [
            'sku' => 'LIFE-NO-COST-COPY',
            'name' => 'Bez nákladu',
            'row_version' => $source['row_version'],
            'sections' => ['prices'],
        ]), 422);
        self::assertNull($this->itemsRepo->findBySku($sid, 'LIFE-NO-COST-COPY'));
    }

    public function testMissingPricingRuleRejectsTemplateApplyAndRollsBackNewCard(): void
    {
        $sid = $this->createSupplier();
        $sourceId = $this->item($sid, 'LIFE-NO-RULE');
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_prices
                (supplier_id, stock_item_id, currency_code, price_mode, markup_pct, rounding, use_pricing_rules)
             VALUES (?, ?, "CZK", "markup", 20, "none", 1)'
        )->execute([$sid, $sourceId]);
        $source = $this->itemsRepo->find($sid, $sourceId);
        $template = $this->templates->save($sid, $sourceId, [
            'name' => 'Bez pravidla',
            'row_version' => $source['row_version'],
            'sections' => ['prices'],
        ]);

        $this->assertStockException('missing_pricing_rule', fn () => $this->templates->apply($sid, (int) $template['id'], [
            'sku' => 'LIFE-NO-RULE-COPY',
            'name' => 'Bez pravidla',
            'row_version' => $template['row_version'],
        ]), 422);
        self::assertNull($this->itemsRepo->findBySku($sid, 'LIFE-NO-RULE-COPY'));
    }

    public function testHistoricalTemplateRevalidatesDeletedCodebookReferenceAndRollsBack(): void
    {
        $sid = $this->createSupplier();
        $sourceId = $this->item($sid, 'LIFE-STALE-CATEGORY');
        $category = $this->db->pdo()->prepare('INSERT INTO stock_categories (supplier_id, code, name) VALUES (?, "LIFE-CAT", "Kategorie")');
        $category->execute([$sid]);
        $categoryId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id, is_primary, display_order)
             VALUES (?, ?, ?, 1, 0)'
        )->execute([$sid, $sourceId, $categoryId]);
        $source = $this->itemsRepo->find($sid, $sourceId);
        $template = $this->templates->save($sid, $sourceId, [
            'name' => 'Historická kategorie',
            'row_version' => $source['row_version'],
            'sections' => ['categories'],
        ]);
        $this->db->pdo()->prepare('DELETE FROM stock_categories WHERE supplier_id = ? AND id = ?')
            ->execute([$sid, $categoryId]);

        $this->assertStockException('category_invalid', fn () => $this->templates->apply($sid, (int) $template['id'], [
            'sku' => 'LIFE-STALE-CATEGORY-COPY',
            'name' => 'Neplatná kategorie',
            'row_version' => $template['row_version'],
        ]), 422);
        self::assertNull($this->itemsRepo->findBySku($sid, 'LIFE-STALE-CATEGORY-COPY'));
    }

    public function testLifecycleMutationsRequireStockAndEshopWritePermissions(): void
    {
        $sid = $this->createSupplier();
        $itemId = $this->item($sid, 'LIFE-AUTH');
        $action = $this->container->get(StockItemAction::class);
        $roles = [
            new EffectiveRole(1, 'Pouze sklad', 'staff', true, ['stock.items.write' => AccessLevel::WRITE->value]),
            new EffectiveRole(2, 'Pouze e-shop', 'staff', true, ['eshop.write' => AccessLevel::WRITE->value]),
        ];
        foreach ($roles as $role) {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/stock/items')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
                ->withAttribute('auth.effective_role', $role)
                ->withParsedBody([]);
            foreach ([
                ['lifecycle', ['id' => (string) $itemId]],
                ['duplicate', ['id' => (string) $itemId]],
                ['saveTemplate', ['id' => (string) $itemId]],
                ['deleteTemplate', ['templateId' => '1']],
                ['applyTemplate', ['templateId' => '1']],
            ] as [$method, $args]) {
                $response = $action->{$method}($request, new Response(), $args);
                self::assertSame(403, $response->getStatusCode(), $role->name . ': ' . $method);
            }
        }
    }

    public function testTenantIsolationAndOptimisticConflictsAreEnforced(): void
    {
        $first = $this->createSupplier();
        $second = $this->createSupplier();
        $sourceId = $this->item($first, 'LIFE-TENANT');
        $source = $this->itemsRepo->find($first, $sourceId);
        $template = $this->templates->save($first, $sourceId, [
            'name' => 'Tenant šablona',
            'row_version' => $source['row_version'],
            'sections' => ['core'],
        ]);

        self::assertSame([], $this->templates->list($second));
        $this->assertStockException(
            'not_found',
            fn () => $this->templates->apply($second, (int) $template['id'], [
                'sku' => 'LIFE-CROSS', 'name' => 'Cizí', 'row_version' => $template['row_version'],
            ]),
        );
        $this->assertStockException(
            'version_conflict',
            fn () => $this->templates->apply($first, (int) $template['id'], [
                'sku' => 'LIFE-STALE-TEMPLATE', 'name' => 'Stará šablona', 'row_version' => $template['row_version'] + 1,
            ]),
        );
        $this->assertStockException(
            'version_conflict',
            fn () => $this->lifecycle->transition($first, $sourceId, 'retired', $source['row_version'] + 1),
        );
        self::assertNotNull($this->itemsRepo->find($first, $sourceId));
    }

    public function testOnlyReadyItemCanBeReactivatedOrRepublishedBySharedWritePaths(): void
    {
        $sid = $this->createSupplier();
        $itemId = $this->item($sid, 'LIFE-RETIRED');
        $item = $this->itemsRepo->find($sid, $itemId);
        $retired = $this->lifecycle->transition($sid, $itemId, 'retired', $item['row_version']);

        self::assertTrue($this->itemsRepo->updateEshopFieldsVersioned(
            $sid,
            $itemId,
            $retired['row_version'],
            ['export_eshop' => true],
        ));
        $this->itemsRepo->updateEshopFields($sid, $itemId, ['export_eshop' => true]);
        $this->itemsRepo->update($sid, $itemId, [
            'sku' => $retired['sku'],
            'name' => $retired['name'],
            'item_type' => $retired['item_type'],
            'unit' => $retired['unit'],
            'ean' => $retired['ean'],
            'vat_rate_id' => $retired['vat_rate_id'],
            'sale_price_without_vat' => $retired['sale_price_without_vat'],
            'min_qty' => $retired['min_qty'],
            'is_active' => true,
            'note' => $retired['note'],
        ]);
        $afterWrites = $this->itemsRepo->find($sid, $itemId);

        self::assertSame('retired', $afterWrites['lifecycle_status']);
        self::assertFalse($afterWrites['is_active']);
        self::assertFalse($afterWrites['export_eshop']);
        $ready = $this->lifecycle->transition($sid, $itemId, 'ready', $afterWrites['row_version']);
        self::assertTrue($ready['is_active']);
        self::assertSame('ready', $ready['lifecycle_status']);

        $draft = $this->lifecycle->transition($sid, $itemId, 'draft', $ready['row_version']);
        self::assertTrue($this->itemsRepo->updateEshopFieldsVersioned(
            $sid,
            $itemId,
            $draft['row_version'],
            ['export_eshop' => true],
        ));
        $afterDraftWrite = $this->itemsRepo->find($sid, $itemId);
        self::assertSame('draft', $afterDraftWrite['lifecycle_status']);
        self::assertFalse($afterDraftWrite['is_active']);
        self::assertFalse($afterDraftWrite['export_eshop']);
    }

    private function countRows(string $table, int $supplierId, int $itemId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND stock_item_id = ?");
        $stmt->execute([$supplierId, $itemId]);
        return (int) $stmt->fetchColumn();
    }

    private function assertStockException(string $code, callable $operation, ?int $httpStatus = null): void
    {
        try {
            $operation();
            self::fail('Očekávána StockException ' . $code);
        } catch (StockException $e) {
            self::assertSame($code, $e->errorCode);
            if ($httpStatus !== null) {
                self::assertSame($httpStatus, $e->httpStatus);
            }
        }
    }
}
