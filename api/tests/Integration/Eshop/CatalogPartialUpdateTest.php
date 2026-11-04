<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\AttributeAction;
use MyInvoice\Action\Eshop\CatalogPricingProfileAction;
use MyInvoice\Action\Eshop\CatalogPricingRuleAction;
use MyInvoice\Action\Eshop\CategoryAction;
use MyInvoice\Action\Eshop\ProductCardAction;
use MyInvoice\Action\Eshop\ProductMasterAction;
use MyInvoice\Action\Eshop\ProductMediaAction;
use MyInvoice\Action\Eshop\ProductPriceAction;
use MyInvoice\Action\Eshop\ProductPromoPriceAction;
use MyInvoice\Action\Eshop\ProductVendorAction;
use MyInvoice\Action\Stock\StockItemPackagingAction;
use MyInvoice\Action\Stock\StockTrackingAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ManufacturerRepository;
use MyInvoice\Repository\StockAttributeRepository;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Repository\StockMediaRepository;
use MyInvoice\Service\Eshop\Pricing\PriceMatrixService;
use MyInvoice\Service\Eshop\Pricing\PriceMatrixWorker;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Issue #113: aktualizační endpointy katalogu nesmí vynulovat vynechaná pole.
 * Vynechaný klíč = ponechat uloženou hodnotu, klíč s null/"" = vymazat, klíč s hodnotou = nastavit.
 * U kolekcí (prices, promo_prices, vendors, i18n) nahrazuje seznam celek, ale u řádku,
 * který se spáruje s uloženým, si vynechané klíče drží uloženou hodnotu.
 */
#[Group('integration')]
final class CatalogPartialUpdateTest extends StockTestCase
{
    // ── eshop/attribute-options/{oid} ───────────────────────────────────────

    public function testAttributeOptionPartialUpdateKeepsOmittedFields(): void
    {
        $sid = $this->createSupplier();
        $attributeId = $this->enumAttribute($sid, 'color');
        $created = $this->call($sid, AttributeAction::class, 'createOption', 'POST', [
            'code' => 'red', 'label' => 'Červená', 'display_order' => 7,
        ], ['id' => (string) $attributeId]);
        self::assertSame(201, $created['status']);
        $oid = (int) $created['body']['id'];

        $res = $this->call($sid, AttributeAction::class, 'updateOption', 'PUT', ['label' => 'Rudá'], ['oid' => (string) $oid]);
        self::assertSame(200, $res['status']);
        self::assertSame('red', $res['body']['code']);
        self::assertSame('Rudá', $res['body']['label']);
        self::assertSame(7, (int) $res['body']['display_order'], 'Vynechaný display_order se nesmí vynulovat.');

        $res = $this->call($sid, AttributeAction::class, 'updateOption', 'PUT', ['display_order' => 3], ['oid' => (string) $oid]);
        self::assertSame(200, $res['status']);
        self::assertSame('Rudá', $res['body']['label']);
        self::assertSame(3, (int) $res['body']['display_order']);
    }

    // ── eshop/categories/{id}/i18n ──────────────────────────────────────────

    public function testCategoryI18nRowKeepsOmittedFieldsAndNullClears(): void
    {
        $sid = $this->createSupplier();
        $this->db->pdo()->prepare("INSERT IGNORE INTO stock_locales (supplier_id, code, name, is_default) VALUES (?, 'en', 'English', 1)")->execute([$sid]);
        $categoryId = $this->category($sid);

        $res = $this->call($sid, CategoryAction::class, 'putI18n', 'PUT', ['translations' => [[
            'locale' => 'en', 'name' => 'Shoes', 'description' => 'Desc', 'seo_slug' => 'shoes',
        ]]], ['id' => (string) $categoryId]);
        self::assertSame(200, $res['status']);

        $res = $this->call($sid, CategoryAction::class, 'putI18n', 'PUT', ['translations' => [['locale' => 'en', 'name' => 'Boots']]], ['id' => (string) $categoryId]);
        self::assertSame(200, $res['status']);
        $row = $this->i18nRow($sid, $categoryId, 'en');
        self::assertSame('Boots', $row['name']);
        self::assertSame('Desc', $row['description'], 'Vynechaný description se nesmí smazat.');
        self::assertSame('shoes', $row['seo_slug'], 'Vynechaný seo_slug se nesmí smazat (mění veřejnou URL).');

        $this->call($sid, CategoryAction::class, 'putI18n', 'PUT', ['translations' => [['locale' => 'en', 'description' => null]]], ['id' => (string) $categoryId]);
        $row = $this->i18nRow($sid, $categoryId, 'en');
        self::assertSame('Boots', $row['name'], 'Vynechaný name zůstává.');
        self::assertNull($row['description'], 'Explicitní null maže.');
        self::assertSame('shoes', $row['seo_slug']);

        $this->call($sid, CategoryAction::class, 'putI18n', 'PUT', ['i18n' => [['locale' => 'en', 'name' => 'Alias']]], ['id' => (string) $categoryId]);
        self::assertSame('Alias', $this->i18nRow($sid, $categoryId, 'en')['name'], 'Klíč i18n je alias translations.');
    }

    // ── eshop/media/{mid} ───────────────────────────────────────────────────

    public function testMediaUpdateKeepsTitleAndAltText(): void
    {
        $sid = $this->createSupplier();
        $itemId = $this->item($sid, 'MEDIA-1');
        $mediaId = $this->container->get(StockMediaRepository::class)->add($sid, $itemId, [
            'media_type' => 'image', 'storage_key' => 'k/' . uniqid(), 'title' => 'Titulek', 'alt_text' => 'Alternativa',
        ]);

        $res = $this->call($sid, ProductMediaAction::class, 'update', 'PUT', ['export_eshop' => false], ['mid' => (string) $mediaId]);
        self::assertSame(200, $res['status']);
        self::assertSame('Titulek', $res['body']['title'], 'Přepnutí exportu nesmí smazat title.');
        self::assertSame('Alternativa', $res['body']['alt_text']);

        $res = $this->call($sid, ProductMediaAction::class, 'update', 'PUT', ['is_primary' => true], ['mid' => (string) $mediaId]);
        self::assertSame('Titulek', $res['body']['title'], 'Nastavení hlavního obrázku nesmí smazat title.');

        $res = $this->call($sid, ProductMediaAction::class, 'update', 'PUT', ['title' => null], ['mid' => (string) $mediaId]);
        self::assertNull($res['body']['title'], 'Explicitní null maže.');
        self::assertSame('Alternativa', $res['body']['alt_text']);
    }

    // ── stock/locations/{id} ────────────────────────────────────────────────

    public function testLocationPutKeepsIsActiveAndIdentity(): void
    {
        $sid = $this->createSupplier();
        $warehouseId = $this->warehouse($sid);
        $created = $this->call($sid, StockTrackingAction::class, 'saveLocation', 'POST', [
            'warehouse_id' => $warehouseId, 'code' => 'A1', 'name' => 'Regál A1', 'is_active' => false,
        ]);
        self::assertSame(201, $created['status']);
        $id = (int) $created['body']['id'];
        self::assertFalse($created['body']['is_active']);

        $res = $this->call($sid, StockTrackingAction::class, 'saveLocation', 'PUT', ['name' => 'Regál A1 nový'], ['id' => (string) $id]);
        self::assertSame(200, $res['status']);
        self::assertSame('A1', $res['body']['code']);
        self::assertSame('Regál A1 nový', $res['body']['name']);
        self::assertSame($warehouseId, (int) $res['body']['warehouse_id']);
        self::assertFalse($res['body']['is_active'], 'PUT bez is_active nesmí znovu aktivovat lokaci.');

        $res = $this->call($sid, StockTrackingAction::class, 'saveLocation', 'PUT', [], ['id' => (string) $id]);
        self::assertSame(200, $res['status'], 'Úprava beze změny není 404.');

        $res = $this->call($sid, StockTrackingAction::class, 'saveLocation', 'PUT', ['is_active' => true], ['id' => (string) $id]);
        self::assertTrue($res['body']['is_active']);
    }

    // ── stock/items/{id}/packaging ──────────────────────────────────────────

    public function testPackagingKeepsDefaultSaleUnitAndEan(): void
    {
        $sid = $this->createSupplier();
        $this->db->pdo()->prepare("INSERT IGNORE INTO stock_packaging_units (supplier_id, code, name) VALUES (?, 'KT', 'Karton'), (?, 'PAL', 'Paleta')")->execute([$sid, $sid]);
        $itemId = $this->item($sid, 'PACK-1');
        $res = $this->call($sid, StockItemPackagingAction::class, 'put', 'PUT', [
            'default_sale_unit' => 'KT',
            'units' => [['unit_code' => 'KT', 'factor' => '8', 'ean' => '8590000000024'], ['unit_code' => 'PAL', 'factor' => '40']],
        ], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);

        $res = $this->call($sid, StockItemPackagingAction::class, 'put', 'PUT', [
            'units' => [['unit_code' => 'KT'], ['unit_code' => 'PAL', 'factor' => '40']],
        ], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);
        self::assertSame('KT', $res['body']['default_sale_unit'], 'Vynechaný default_sale_unit se nesmí vynulovat.');
        $byCode = array_column($res['body']['units'], null, 'unit_code');
        self::assertSame('8590000000024', $byCode['KT']['ean'], 'Vynechaný ean balení se nesmí smazat.');

        $res = $this->call($sid, StockItemPackagingAction::class, 'put', 'PUT', [
            'units' => [['unit_code' => 'PAL', 'factor' => '40']],
        ], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status'], 'Uložená výchozí jednotka, která zmizela z balení, nesmí shodit zápis.');
        self::assertNull($res['body']['default_sale_unit']);

        $this->call($sid, StockItemPackagingAction::class, 'put', 'PUT', [
            'default_sale_unit' => 'PAL', 'units' => [['unit_code' => 'PAL', 'factor' => '40']],
        ], ['id' => (string) $itemId]);
        $res = $this->call($sid, StockItemPackagingAction::class, 'put', 'PUT', [
            'default_sale_unit' => null, 'units' => [['unit_code' => 'PAL', 'ean' => null]],
        ], ['id' => (string) $itemId]);
        self::assertNull($res['body']['default_sale_unit'], 'Explicitní null maže výchozí jednotku.');
    }

    // ── eshop/products/{id}/promo-prices ────────────────────────────────────

    public function testPromoPriceRowKeepsOmittedFields(): void
    {
        $sid = $this->createSupplier();
        $itemId = $this->item($sid, 'PROMO-1');
        $res = $this->call($sid, ProductPromoPriceAction::class, 'put', 'PUT', ['promo_prices' => [[
            'currency_code' => 'EUR', 'promo_price' => '50', 'label' => 'Akce', 'valid_from' => '2099-01-01', 'valid_to' => '2099-12-31',
            'qty_mode' => 'limited', 'qty_limit' => '5', 'is_active' => false, 'note' => 'Poznámka',
        ]]], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);
        $promoId = (int) $this->container->get(StockItemPromoPriceRepository::class)->listForItem($sid, $itemId)[0]['id'];

        $res = $this->call($sid, ProductPromoPriceAction::class, 'put', 'PUT', ['promo_prices' => [['id' => $promoId, 'promo_price' => '45']]], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);
        $row = $this->container->get(StockItemPromoPriceRepository::class)->find($sid, $promoId);
        self::assertSame('45.00', $row['promo_price']);
        self::assertSame('EUR', $row['currency_code'], 'Vynechaná měna se nesmí vrátit na CZK.');
        self::assertSame('2099-01-01', $row['valid_from']);
        self::assertSame('2099-12-31', $row['valid_to']);
        self::assertSame('limited', $row['qty_mode']);
        self::assertSame('5.000', $row['qty_limit']);
        self::assertFalse($row['is_active'], 'Vypnutá akce se nesmí znovu zapnout.');
        self::assertSame('Akce', $row['label']);
        self::assertSame('Poznámka', $row['note']);

        $this->call($sid, ProductPromoPriceAction::class, 'put', 'PUT', ['promo_prices' => [['id' => $promoId, 'label' => null, 'valid_to' => null]]], ['id' => (string) $itemId]);
        $row = $this->container->get(StockItemPromoPriceRepository::class)->find($sid, $promoId);
        self::assertNull($row['label']);
        self::assertNull($row['valid_to']);
        self::assertSame('2099-01-01', $row['valid_from']);
        self::assertSame('45.00', $row['promo_price']);
    }

    // ── eshop/products/{id}/vendors ─────────────────────────────────────────

    public function testVendorRowKeepsOmittedFields(): void
    {
        $sid = $this->createSupplier();
        $itemId = $this->item($sid, 'VENDOR-1');
        $clientId = $this->client($sid, 'Dodavatel A');
        $res = $this->call($sid, ProductVendorAction::class, 'put', 'PUT', ['vendors' => [[
            'client_id' => $clientId, 'vendor_sku' => 'SKU-V1', 'purchase_price' => '10.50', 'currency_code' => 'EUR',
            'delivery_days' => 3, 'stock_qty' => '4', 'is_preferred' => true, 'note' => 'Pozn',
        ]]], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);

        $res = $this->call($sid, ProductVendorAction::class, 'put', 'PUT', ['vendors' => [['client_id' => $clientId, 'purchase_price' => '12']]], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);
        $row = $this->container->get(StockItemVendorRepository::class)->listForItem($sid, $itemId)[0];
        self::assertSame('12.0000', $this->money($row['purchase_price']));
        self::assertSame('EUR', $row['currency_code']);
        self::assertTrue($row['is_preferred'], 'Preferovaný dodavatel se nesmí shodit.');
        self::assertSame('SKU-V1', $row['vendor_sku']);
        self::assertSame(3, (int) $row['delivery_days']);
        self::assertSame('Pozn', $row['note']);
        self::assertNotNull($row['stock_qty']);

        $this->call($sid, ProductVendorAction::class, 'put', 'PUT', ['vendors' => [['client_id' => $clientId, 'vendor_sku' => null, 'delivery_days' => '']]], ['id' => (string) $itemId]);
        $row = $this->container->get(StockItemVendorRepository::class)->listForItem($sid, $itemId)[0];
        self::assertNull($row['vendor_sku'], 'Explicitní null maže.');
        self::assertNull($row['delivery_days']);
        self::assertSame('EUR', $row['currency_code']);
    }

    // ── eshop/products/{id}/prices ──────────────────────────────────────────

    public function testPriceRowKeepsOmittedRuleFieldsOnPutAndPatch(): void
    {
        $sid = $this->createSupplier();
        $this->currencies($sid, 'CZK', 'EUR');
        $itemId = $this->item($sid, 'PRICE-1');
        $res = $this->call($sid, ProductPriceAction::class, 'put', 'PUT', ['prices' => [[
            'currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '90', 'rounding' => '0.50',
            'is_manual_override' => true, 'use_pricing_rules' => false,
        ]]], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);

        foreach (['PUT', 'PATCH'] as $method) {
            $res = $this->call($sid, ProductPriceAction::class, 'put', $method, ['prices' => [['currency_code' => 'CZK', 'fixed_price' => '95']]], ['id' => (string) $itemId]);
            self::assertSame(200, $res['status'], $method);
            $row = $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $itemId, 'CZK');
            self::assertSame('fixed', $row['price_mode'], $method);
            self::assertSame('95.00', $row['fixed_price'], $method);
            self::assertSame('0.50', $row['rounding'], $method);
            self::assertTrue($row['is_manual_override'], $method . ': ruční cena se nesmí odznačit.');
            self::assertFalse($row['use_pricing_rules'], $method);
        }

        $this->call($sid, ProductPriceAction::class, 'put', 'PUT', ['prices' => [['currency_code' => 'CZK', 'use_pricing_rules' => true]]], ['id' => (string) $itemId]);
        $row = $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $itemId, 'CZK');
        self::assertTrue($row['use_pricing_rules']);
        self::assertSame('0.50', $row['rounding']);

        $res = $this->call($sid, ProductPriceAction::class, 'put', 'PUT', ['prices' => [['currency_code' => 'CZK', 'use_pricing_rules' => false, 'fixed_price' => null]]], ['id' => (string) $itemId]);
        self::assertSame(400, $res['status'], 'Explicitní null u povinné pevné ceny se odmítne.');

        $res = $this->call($sid, ProductPriceAction::class, 'put', 'PUT', ['prices' => [['currency_code' => 'EUR', 'fixed_price' => '4', 'price_mode' => 'fixed']]], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);
        self::assertNull($this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $itemId, 'CZK'), 'Měna mimo seznam se u PUT dál maže.');

        $res = $this->call($sid, ProductPriceAction::class, 'put', 'PUT', ['prices' => [['currency_code' => 'EUR', 'price_mode' => 'markup']]], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);
        $row = $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $itemId, 'EUR');
        self::assertSame('markup', $row['price_mode']);
        self::assertNull($row['fixed_price'], 'Změna režimu nepřebírá pevnou cenu starého režimu.');
    }

    // ── eshop/products/{id}/editor ──────────────────────────────────────────

    public function testEditorPartialItemKeepsStoredFieldsAndOmittedSections(): void
    {
        $sid = $this->createSupplier();
        $this->currencies($sid, 'CZK');
        $itemId = $this->item($sid, 'EDIT-1');
        $clientId = $this->client($sid, 'Dodavatel E');
        $this->db->pdo()->prepare(
            "UPDATE stock_items SET ean = '8590000000031', note = 'Pozn', min_qty = 2, unit = 'bal', item_type = 'material',
                    vat_rate_id = ?, sale_price_without_vat = 100 WHERE id = ?"
        )->execute([$this->vatRateId, $itemId]);
        $this->call($sid, ProductVendorAction::class, 'put', 'PUT', ['vendors' => [['client_id' => $clientId, 'purchase_price' => '10']]], ['id' => (string) $itemId]);
        $this->call($sid, ProductPromoPriceAction::class, 'put', 'PUT', ['promo_prices' => [['promo_price' => '5']]], ['id' => (string) $itemId]);
        $this->call($sid, ProductPriceAction::class, 'put', 'PUT', ['prices' => [['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '100']]], ['id' => (string) $itemId]);

        $res = $this->call($sid, ProductCardAction::class, 'saveEditor', 'PUT', ['row_version' => $this->rowVersion($itemId), 'item' => ['name' => 'Nový název']], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        $row = $this->itemRow($itemId);
        self::assertSame('Nový název', $row['name']);
        self::assertSame('EDIT-1', $row['sku'], 'SKU se při částečném PUT nesmí přegenerovat.');
        self::assertSame('8590000000031', $row['ean']);
        self::assertSame('Pozn', $row['note']);
        self::assertSame('2.000', $row['min_qty']);
        self::assertSame('bal', $row['unit']);
        self::assertSame('material', $row['item_type']);
        self::assertSame($this->vatRateId, (int) $row['vat_rate_id']);
        self::assertSame('100.000000', $row['sale_price_without_vat']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertCount(1, $this->container->get(StockItemVendorRepository::class)->listForItem($sid, $itemId), 'Vynechané vendors zůstávají.');
        self::assertCount(1, $this->container->get(StockItemPromoPriceRepository::class)->listForItem($sid, $itemId), 'Vynechané promo_prices zůstávají.');
        self::assertCount(1, $this->container->get(StockItemPriceRepository::class)->listForItem($sid, $itemId), 'Vynechané prices zůstávají.');

        $res = $this->call($sid, ProductCardAction::class, 'saveEditor', 'PUT', ['row_version' => $this->rowVersion($itemId), 'item' => ['is_active' => false]], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);
        $res = $this->call($sid, ProductCardAction::class, 'saveEditor', 'PUT', ['row_version' => $this->rowVersion($itemId), 'item' => ['note' => 'Jiná']], ['id' => (string) $itemId]);
        self::assertSame(200, $res['status']);
        $row = $this->itemRow($itemId);
        self::assertSame(0, (int) $row['is_active'], 'Vypnutá karta se nesmí znovu zapnout.');
        self::assertSame('Jiná', $row['note']);

        $this->call($sid, ProductCardAction::class, 'saveEditor', 'PUT', ['row_version' => $this->rowVersion($itemId), 'item' => ['ean' => null, 'min_qty' => '']], ['id' => (string) $itemId]);
        $row = $this->itemRow($itemId);
        self::assertNull($row['ean'], 'Explicitní null maže.');
        self::assertNull($row['min_qty']);
        self::assertSame('Jiná', $row['note']);

        $this->call($sid, ProductCardAction::class, 'saveEditor', 'PUT', ['row_version' => $this->rowVersion($itemId), 'vendors' => []], ['id' => (string) $itemId]);
        self::assertCount(0, $this->container->get(StockItemVendorRepository::class)->listForItem($sid, $itemId), 'Přítomná kolekce nahrazuje celek.');
    }

    // ── eshop/pricing/profiles/{id} a rules/{id} ────────────────────────────

    public function testPricingProfileKeepsOmittedFields(): void
    {
        $sid = $this->createSupplier();
        $this->currencies($sid, 'CZK');
        $created = $this->call($sid, CatalogPricingProfileAction::class, 'create', 'POST', [
            'code' => 'p-partial', 'name' => 'Profil', 'currency_code' => 'CZK', 'calculation_mode' => 'markup', 'percentage' => '12',
            'rounding' => '0.50', 'fx_source' => 'manual', 'max_rate_age_days' => 30, 'is_active' => false,
        ]);
        self::assertSame(201, $created['status'], json_encode($created['body']));
        $id = (int) $created['body']['profile']['id'];

        $res = $this->call($sid, CatalogPricingProfileAction::class, 'update', 'PUT', ['name' => 'Přejmenovaný'], ['id' => (string) $id]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        $profile = $res['body']['profile'];
        self::assertSame('Přejmenovaný', $profile['name']);
        self::assertSame('p-partial', $profile['code']);
        self::assertSame('markup', $profile['calculation_mode']);
        self::assertSame('12.000', $profile['percentage']);
        self::assertSame('0.50', $profile['rounding']);
        self::assertSame('manual', $profile['fx_source']);
        self::assertSame(30, $profile['max_rate_age_days']);
        self::assertFalse($profile['is_active'], 'Vypnutý profil se nesmí znovu zapnout.');

        $res = $this->call($sid, CatalogPricingProfileAction::class, 'update', 'PUT', ['rounding' => null], ['id' => (string) $id]);
        self::assertSame(200, $res['status']);
        self::assertSame('none', $res['body']['profile']['rounding'], 'Explicitní null použije doménový default (jako při vytvoření).');
        self::assertSame('manual', $res['body']['profile']['fx_source']);

        $res = $this->call($sid, CatalogPricingProfileAction::class, 'update', 'PUT', ['percentage' => null], ['id' => (string) $id]);
        self::assertSame(400, $res['status'], 'Explicitní null u povinného procenta se odmítne.');
    }

    public function testPricingRuleKeepsOmittedFields(): void
    {
        $sid = $this->createSupplier();
        $this->currencies($sid, 'CZK');
        $profileId = (int) $this->call($sid, CatalogPricingProfileAction::class, 'create', 'POST', [
            'code' => 'p-rule', 'name' => 'Profil', 'currency_code' => 'CZK', 'calculation_mode' => 'markup', 'percentage' => '10',
        ])['body']['profile']['id'];
        $itemId = $this->item($sid, 'RULE-1');
        $created = $this->call($sid, CatalogPricingRuleAction::class, 'create', 'POST', [
            'profile_id' => $profileId, 'match_type' => 'product', 'match_id' => $itemId, 'priority' => 5, 'is_active' => false,
        ]);
        self::assertSame(201, $created['status'], json_encode($created['body']));
        $id = (int) $created['body']['rule']['id'];

        $res = $this->call($sid, CatalogPricingRuleAction::class, 'update', 'PUT', ['priority' => 9], ['id' => (string) $id]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        $rule = $res['body']['rule'];
        self::assertSame(9, $rule['priority']);
        self::assertSame($itemId, $rule['match_id']);
        self::assertSame('product', $rule['match_type']);
        self::assertFalse($rule['is_active'], 'Vypnuté pravidlo se nesmí znovu zapnout.');

        $res = $this->call($sid, CatalogPricingRuleAction::class, 'update', 'PUT', ['is_active' => true], ['id' => (string) $id]);
        self::assertSame(9, $res['body']['rule']['priority'], 'Vynechaná priorita se nesmí vynulovat.');
        self::assertTrue($res['body']['rule']['is_active']);

        $res = $this->call($sid, CatalogPricingRuleAction::class, 'update', 'PUT', ['match_type' => 'manufacturer'], ['id' => (string) $id]);
        self::assertSame(400, $res['status'], 'Změna typu bez match_id nesmí zdědit id produktu.');

        $res = $this->call($sid, CatalogPricingRuleAction::class, 'update', 'PUT', ['match_type' => 'default'], ['id' => (string) $id]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertNull($res['body']['rule']['match_id'], 'Přechod na default smaže match_id.');
        self::assertSame(9, $res['body']['rule']['priority']);
    }

    // ── eshop/product-masters/{id} a /variants/{itemId} ─────────────────────

    public function testProductMasterUpdateKeepsOmittedContent(): void
    {
        $sid = $this->createSupplier();
        $manufacturerId = $this->container->get(ManufacturerRepository::class)->insert($sid, ['code' => 'MAN1', 'name' => 'Výrobce']);
        $axisId = $this->enumAttribute($sid, 'size');
        $created = $this->call($sid, ProductMasterAction::class, 'create', 'POST', [
            'name' => 'Master', 'manufacturer_id' => $manufacturerId, 'axis_attribute_ids' => [$axisId],
            'i18n' => [['locale' => 'cs', 'name' => 'Master cs', 'short_desc' => 'Krátký', 'description' => 'Dlouhý']],
        ]);
        self::assertSame(201, $created['status'], json_encode($created['body']));
        $masterId = (int) $created['body']['id'];

        $res = $this->call($sid, ProductMasterAction::class, 'update', 'PUT', ['row_version' => $created['body']['row_version'], 'name' => 'Master 2'], ['id' => (string) $masterId]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame('Master 2', $res['body']['name']);
        self::assertSame($manufacturerId, $res['body']['manufacturer_id'], 'Vynechaný výrobce se nesmí smazat.');
        self::assertSame([$axisId], array_column($res['body']['axes'], 'attribute_id'), 'Vynechané osy se nesmí smazat.');
        self::assertCount(1, $res['body']['i18n'], 'Vynechané překlady se nesmí smazat.');
        self::assertSame('Krátký', $res['body']['i18n'][0]['short_desc']);

        $res = $this->call($sid, ProductMasterAction::class, 'update', 'PUT', [
            'row_version' => $res['body']['row_version'], 'i18n' => [['locale' => 'cs', 'name' => 'Master cs 2']],
        ], ['id' => (string) $masterId]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame('Master cs 2', $res['body']['i18n'][0]['name']);
        self::assertSame('Krátký', $res['body']['i18n'][0]['short_desc'], 'Vynechané pole řádku překladu zůstává.');
        self::assertSame('Dlouhý', $res['body']['i18n'][0]['description']);

        $res = $this->call($sid, ProductMasterAction::class, 'update', 'PUT', [
            'row_version' => $res['body']['row_version'], 'i18n' => [['locale' => 'cs', 'name' => 'Master cs 2', 'short_desc' => null]],
        ], ['id' => (string) $masterId]);
        self::assertNull($res['body']['i18n'][0]['short_desc'], 'Explicitní null maže.');
        self::assertSame('Dlouhý', $res['body']['i18n'][0]['description']);

        $res = $this->call($sid, ProductMasterAction::class, 'update', 'PUT', ['row_version' => $res['body']['row_version'], 'manufacturer_id' => null], ['id' => (string) $masterId]);
        self::assertNull($res['body']['manufacturer_id'], 'Explicitní null maže výrobce.');
        self::assertCount(1, $res['body']['i18n']);
    }

    public function testVariantInheritanceKeepsOmittedFlags(): void
    {
        $sid = $this->createSupplier();
        $master = $this->call($sid, ProductMasterAction::class, 'create', 'POST', [
            'name' => 'Master V', 'i18n' => [['locale' => 'cs', 'name' => 'Master V cs']],
        ]);
        self::assertSame(201, $master['status'], json_encode($master['body']));
        $masterId = (int) $master['body']['id'];
        $itemId = $this->item($sid, 'VAR-1');

        $res = $this->call($sid, ProductMasterAction::class, 'attach', 'POST', [
            'master_row_version' => $master['body']['row_version'],
            'variants' => [[
                'stock_item_id' => $itemId, 'row_version' => $this->rowVersion($itemId),
                'inheritance' => ['manufacturer' => false, 'i18n' => ['cs' => [
                    'name' => false, 'short_desc' => false, 'description' => true, 'seo_title' => true, 'seo_description' => true,
                ]]],
            ]],
        ], ['id' => (string) $masterId]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        $variant = $res['body']['variants'][0];

        $res = $this->call($sid, ProductMasterAction::class, 'updateVariant', 'PUT', [
            'row_version' => $variant['row_version'], 'link_row_version' => $variant['link_row_version'],
            'inheritance' => ['i18n' => ['cs' => ['name' => true]]],
        ], ['id' => (string) $masterId, 'itemId' => (string) $itemId]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        $variant = $res['body']['variants'][0];
        self::assertFalse($variant['inheritance']['manufacturer'], 'Vynechaný příznak výrobce se nesmí vrátit na true.');
        self::assertTrue($variant['inheritance']['i18n']['cs']['name']);
        self::assertFalse($variant['inheritance']['i18n']['cs']['short_desc'], 'Vynechaný příznak pole se nesmí vrátit na true.');

        $res = $this->call($sid, ProductMasterAction::class, 'updateVariant', 'PUT', [
            'row_version' => $variant['row_version'], 'link_row_version' => $variant['link_row_version'],
            'inheritance' => ['manufacturer' => true],
        ], ['id' => (string) $masterId, 'itemId' => (string) $itemId]);
        $variant = $res['body']['variants'][0];
        self::assertTrue($variant['inheritance']['manufacturer']);
        self::assertFalse($variant['inheritance']['i18n']['cs']['short_desc'], 'Vynechaná i18n dědičnost zůstává.');
    }

    // ── eshop/pricing/matrix (operace upsert) ───────────────────────────────

    public function testMatrixUpsertOverrideKeepsOmittedDefinitionFields(): void
    {
        $sid = $this->createSupplier();
        $this->currencies($sid, 'CZK');
        $itemId = $this->item($sid, 'MATRIX-UPSERT');
        $this->container->get(PriceWriteService::class)->save($sid, $itemId, [[
            'currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '90', 'rounding' => '0.50',
            'is_manual_override' => true, 'use_pricing_rules' => false,
        ]]);

        $matrix = $this->container->get(PriceMatrixService::class);
        $worker = $this->container->get(PriceMatrixWorker::class);
        $preview = $matrix->preview($sid, ['all_matching' => false, 'ids' => [$itemId]], [
            'currencies' => ['CZK'], 'on_date' => date('Y-m-d'), 'ensure_missing' => false, 'reprice' => false,
            'overrides' => [['item_id' => $itemId, 'currency_code' => 'CZK', 'operation' => 'upsert', 'definition' => ['fixed_price' => '199']]],
        ]);
        $worker->tick($sid);
        $matrix->apply($sid, $preview['id']);
        $worker->tick($sid);

        $row = $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $itemId, 'CZK');
        self::assertSame('199.00', $row['fixed_price']);
        self::assertSame('fixed', $row['price_mode'], 'Vynechaný price_mode se nesmí vrátit na markup.');
        self::assertSame('0.50', $row['rounding']);
        self::assertTrue($row['is_manual_override']);
        self::assertFalse($row['use_pricing_rules']);
    }

    // ── infrastruktura ──────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(int $supplierId, string $class, string $method, string $httpMethod, array $body, array $args = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $action = $this->container->get($class);
        $response = $args === []
            ? $action->{$method}($request, new Psr7Response())
            : $action->{$method}($request, new Psr7Response(), $args);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function currencies(int $supplierId, string ...$codes): void
    {
        foreach ($codes as $code) {
            $this->db->pdo()->prepare('INSERT IGNORE INTO stock_currencies (supplier_id, code, name, is_default) VALUES (?, ?, ?, ?)')
                ->execute([$supplierId, $code, $code, $code === 'CZK' ? 1 : 0]);
        }
    }

    private function enumAttribute(int $supplierId, string $code): int
    {
        return $this->container->get(StockAttributeRepository::class)->insert($supplierId, [
            'code' => $code, 'name' => ucfirst($code), 'data_type' => 'enum', 'unit' => null,
            'is_filterable' => false, 'is_multivalue' => false, 'display_order' => 0, 'archived' => false,
        ]);
    }

    private function category(int $supplierId): int
    {
        $this->db->pdo()->prepare("INSERT INTO stock_categories (supplier_id, parent_id, code, name, path, depth) VALUES (?, NULL, ?, 'Kategorie', '/', 0)")
            ->execute([$supplierId, 'CAT-' . uniqid()]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare('UPDATE stock_categories SET path = ? WHERE id = ?')->execute(['/' . $id . '/', $id]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function i18nRow(int $supplierId, int $categoryId, string $locale): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT name, description, seo_slug FROM stock_category_i18n WHERE supplier_id = ? AND category_id = ? AND locale = ?');
        $stmt->execute([$supplierId, $categoryId, $locale]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    private function itemRow(int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_items WHERE id = ?');
        $stmt->execute([$itemId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function rowVersion(int $itemId): int
    {
        return (int) $this->itemRow($itemId)['row_version'];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
