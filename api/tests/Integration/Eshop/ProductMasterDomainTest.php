<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\CatalogPricingRuleRepository;
use MyInvoice\Service\Eshop\ProductContentTransferService;
use MyInvoice\Service\Eshop\ProductContentTransferWorker;
use MyInvoice\Service\Eshop\ProductMasterService;
use MyInvoice\Service\Eshop\ProductRelationService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use MyInvoice\Action\Eshop\ProductMasterAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Eshop\Import\CatalogImportWriter;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class ProductMasterDomainTest extends StockTestCase
{
    private ProductMasterService $masters;
    private ProductRelationService $relations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masters = $this->container->get(ProductMasterService::class);
        $this->relations = $this->container->get(ProductRelationService::class);
    }

    public function testAttachPreservesSaleStockHistoryPriceAndExternalIdentity(): void
    {
        $sid = $this->createSupplier();
        [$attribute, $red] = $this->axis($sid, 'COLOR', 'RED');
        $manufacturer = $this->manufacturer($sid, 'MASTER-M');
        $master = $this->masters->create($sid, [
            'name' => 'Tričko', 'manufacturer_id' => $manufacturer, 'axis_attribute_ids' => [$attribute],
            'i18n' => [['locale' => 'cs', 'name' => 'Tričko master', 'description' => 'Společný obsah']],
        ]);
        $itemId = $this->item($sid, 'VARIANT-RED');
        $warehouse = $this->warehouse($sid, 'MASTER-A');
        $this->receiveStock($sid, $warehouse, $itemId, '3.000', 40.0);
        $this->db->pdo()->prepare('UPDATE stock_items SET sale_price_without_vat = 123.45, row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
            ->execute([$sid, $itemId]);
        $this->db->pdo()->prepare("INSERT INTO external_entity_map (supplier_id, source_key, entity_type, external_id, internal_id)
            VALUES (?, 'synthetic', 'stock_item', 'variant-red', ?)")->execute([$sid, $itemId]);
        $before = $this->itemsRepo->find($sid, $itemId);
        $history = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM stock_document_lines WHERE stock_item_id = ' . $itemId)->fetchColumn();

        $detail = $this->masters->attach($sid, $master['id'], [
            'master_row_version' => $master['row_version'],
            'variants' => [['stock_item_id' => $itemId, 'row_version' => $before['row_version'],
                'options' => [['attribute_id' => $attribute, 'option_id' => $red]]]],
        ]);

        $after = $this->itemsRepo->find($sid, $itemId);
        self::assertSame('VARIANT-RED', $after['sku']);
        self::assertSame('123.45', $after['sale_price_without_vat']);
        self::assertSame('3.000', $this->level($sid, $warehouse, $itemId)['qtyT'] / 1000 === 3 ? '3.000' : 'invalid');
        self::assertSame($history, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM stock_document_lines WHERE stock_item_id = ' . $itemId)->fetchColumn());
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM external_entity_map WHERE entity_type='stock_item' AND internal_id=" . $itemId)->fetchColumn());
        self::assertSame($itemId, $detail['variants'][0]['stock_item_id']);
        self::assertSame($manufacturer, $this->container->get(CatalogPricingRuleRepository::class)->itemContext($sid, $itemId)['manufacturer_id']);
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM stock_items WHERE supplier_id={$sid} AND sku='Tričko'")->fetchColumn());
    }

    public function testInheritanceInvalidatesOnlyAffectedVariantAndDetachMaterializes(): void
    {
        $sid = $this->createSupplier();
        $firstManufacturer = $this->manufacturer($sid, 'MASTER-ONE');
        $secondManufacturer = $this->manufacturer($sid, 'MASTER-TWO');
        [$attribute, $small, $large] = $this->axisWithTwoOptions($sid);
        $master = $this->masters->create($sid, [
            'name' => 'Mikina', 'manufacturer_id' => $firstManufacturer, 'axis_attribute_ids' => [$attribute],
            'i18n' => [['locale' => 'cs', 'name' => 'Mikina', 'description' => 'První popis']],
        ]);
        $inherited = $this->item($sid, 'HOODIE-S');
        $own = $this->item($sid, 'HOODIE-L');
        $this->db->pdo()->prepare("INSERT INTO stock_item_i18n (supplier_id, stock_item_id, locale, name, description) VALUES (?, ?, 'cs', 'Vlastní S', 'Vlastní popis S'), (?, ?, 'cs', 'Vlastní L', 'Vlastní popis L')")
            ->execute([$sid, $inherited, $sid, $own]);
        $this->masters->attach($sid, $master['id'], [
            'master_row_version' => $master['row_version'], 'variants' => [
                ['stock_item_id' => $inherited, 'row_version' => $this->itemsRepo->find($sid, $inherited)['row_version'], 'options' => [['attribute_id' => $attribute, 'option_id' => $small]]],
                ['stock_item_id' => $own, 'row_version' => $this->itemsRepo->find($sid, $own)['row_version'], 'options' => [['attribute_id' => $attribute, 'option_id' => $large]],
                    'inheritance' => ['manufacturer' => false, 'i18n' => ['cs' => ['description' => false]]]],
            ],
        ]);
        $beforeInherited = $this->itemsRepo->find($sid, $inherited)['row_version'];
        $beforeOwn = $this->itemsRepo->find($sid, $own)['row_version'];
        $currentMaster = $this->masters->detail($sid, $master['id']);
        $this->masters->update($sid, $master['id'], [
            'row_version' => $currentMaster['row_version'], 'name' => 'Mikina', 'manufacturer_id' => $secondManufacturer,
            'axis_attribute_ids' => [$attribute], 'i18n' => [['locale' => 'cs', 'name' => 'Mikina', 'description' => 'Nový popis']],
        ]);
        self::assertGreaterThan($beforeInherited, $this->itemsRepo->find($sid, $inherited)['row_version']);
        self::assertSame($beforeOwn, $this->itemsRepo->find($sid, $own)['row_version']);

        $preview = $this->masters->detachPreview($sid, $master['id'], $inherited);
        self::assertSame($secondManufacturer, $preview['materialized']['manufacturer_id']);
        self::assertSame('Nový popis', $preview['materialized']['i18n'][0]['description']);
        $this->masters->detach($sid, $master['id'], $inherited, $preview);
        self::assertNull($this->container->get(\MyInvoice\Repository\ProductMasterRepository::class)->variantContext($sid, $inherited));
        self::assertSame($secondManufacturer, $this->itemsRepo->find($sid, $inherited)['manufacturer_id']);
        $description = $this->db->pdo()->query("SELECT description FROM stock_item_i18n WHERE supplier_id={$sid} AND stock_item_id={$inherited} AND locale='cs'")->fetchColumn();
        self::assertSame('Nový popis', $description);
    }

    public function testTenantCombinationAndCasGuardsRollbackWholeAttach(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        [$attribute, $red] = $this->axis($sid, 'SHADE', 'RED');
        $master = $this->masters->create($sid, ['name' => 'Varianty', 'axis_attribute_ids' => [$attribute]]);
        $first = $this->item($sid, 'COMBO-1');
        $second = $this->item($sid, 'COMBO-2');
        $foreign = $this->item($other, 'FOREIGN');
        $this->masters->attach($sid, $master['id'], ['master_row_version' => $master['row_version'], 'variants' => [[
            'stock_item_id' => $first, 'row_version' => $this->itemsRepo->find($sid, $first)['row_version'],
            'options' => [['attribute_id' => $attribute, 'option_id' => $red]],
        ]]]);
        $current = $this->masters->detail($sid, $master['id']);
        $this->assertEshop('option_combination_conflict', fn () => $this->masters->attach($sid, $master['id'], [
            'master_row_version' => $current['row_version'], 'variants' => [[
                'stock_item_id' => $second, 'row_version' => $this->itemsRepo->find($sid, $second)['row_version'],
                'options' => [['attribute_id' => $attribute, 'option_id' => $red]],
            ]],
        ]));
        self::assertNull($this->container->get(\MyInvoice\Repository\ProductMasterRepository::class)->variantContext($sid, $second));
        $this->assertEshop('not_found', fn () => $this->masters->attach($sid, $master['id'], [
            'master_row_version' => $current['row_version'], 'variants' => [[
                'stock_item_id' => $foreign, 'row_version' => $this->itemsRepo->find($other, $foreign)['row_version'],
                'options' => [['attribute_id' => $attribute, 'option_id' => $red]],
            ]],
        ]));
        $this->assertEshop('version_conflict', fn () => $this->masters->changeStatus($sid, $master['id'], 999999, 'archived'));
    }

    public function testTypedRelationsAreTenantScopedCasProtectedAndBatchReadable(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $source = $this->item($sid, 'REL-SOURCE');
        $target = $this->item($sid, 'REL-TARGET');
        $foreign = $this->item($other, 'REL-FOREIGN');
        $version = $this->itemsRepo->find($sid, $source)['row_version'];
        $stored = $this->relations->replace($sid, $source, ['row_version' => $version, 'items' => [[
            'type' => 'accessory', 'target_stock_item_id' => $target, 'display_order' => 3,
        ]]]);
        self::assertSame('REL-TARGET', $stored['items'][0]['target_sku']);
        $this->assertEshop('version_conflict', fn () => $this->relations->replace($sid, $source, ['row_version' => $version, 'items' => []]));
        $this->assertEshop('relation_target_invalid', fn () => $this->relations->replace($sid, $source, [
            'row_version' => $stored['row_version'], 'items' => [['type' => 'related', 'target_stock_item_id' => $foreign]],
        ]));
        $batch = $this->container->get(\MyInvoice\Service\Eshop\CatalogReadService::class)->products($sid, [
            'ids' => [$source], 'fields' => ['relations'], 'locales' => ['cs'],
        ], false);
        self::assertSame('accessory', $batch['items'][0]['data']['relations'][0]['type']);
    }

    public function testContentTransferPreviewApplyUsesDurableJobAndFrozenCas(): void
    {
        $sid = $this->createSupplier();
        [$attribute, $red] = $this->axis($sid, 'TRANSFER', 'RED');
        $master = $this->masters->create($sid, [
            'name' => 'Transfer master', 'axis_attribute_ids' => [$attribute],
            'i18n' => [['locale' => 'cs', 'name' => 'Master název', 'description' => 'Master popis']],
        ]);
        $itemId = $this->item($sid, 'TRANSFER-1');
        $this->db->pdo()->prepare("INSERT INTO stock_item_i18n (supplier_id, stock_item_id, locale, name, description) VALUES (?, ?, 'cs', 'Vlastní', 'Původní')")
            ->execute([$sid, $itemId]);
        $this->db->pdo()->prepare('UPDATE stock_items SET sale_price_without_vat = 88.00 WHERE supplier_id = ? AND id = ?')->execute([$sid, $itemId]);
        $attached = $this->masters->attach($sid, $master['id'], ['master_row_version' => $master['row_version'], 'variants' => [[
            'stock_item_id' => $itemId, 'row_version' => $this->itemsRepo->find($sid, $itemId)['row_version'],
            'options' => [['attribute_id' => $attribute, 'option_id' => $red]],
        ]]]);
        $transfer = $this->container->get(ProductContentTransferService::class);
        $worker = $this->container->get(ProductContentTransferWorker::class);
        $preview = $transfer->preview($sid, $master['id'], [
            'master_row_version' => $attached['row_version'], 'stock_item_ids' => [$itemId],
            'fields' => ['i18n.cs.description'], 'overwrite' => true,
        ], $this->userId);
        $preview = $worker->tickKind($sid, ProductContentTransferService::PREVIEW_KIND);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $apply = $transfer->apply($sid, $master['id'], ['preview_job_id' => $preview['id']], $this->userId);
        $apply = $worker->tickKind($sid, ProductContentTransferService::APPLY_KIND);
        self::assertSame(1, $apply['report']['counts']['applied']);
        self::assertSame('Master popis', $this->db->pdo()->query("SELECT description FROM stock_item_i18n WHERE supplier_id={$sid} AND stock_item_id={$itemId} AND locale='cs'")->fetchColumn());
        self::assertSame('88.00', $this->itemsRepo->find($sid, $itemId)['sale_price_without_vat']);
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM activity_log WHERE supplier_id={$sid} AND action='eshop.product_content_transferred'")->fetchColumn());
        self::assertSame('applied', $this->container->get(CatalogJobItemRepository::class)->page($sid, $apply['id'])['items'][0]['status']);
    }

    public function testImportWritesVariantAndTypedRelationsWithoutChangingPrice(): void
    {
        $sid = $this->createSupplier();
        [$attribute, $red] = $this->axis($sid, 'IMPORT-AXIS', 'RED');
        $master = $this->masters->create($sid, ['name' => 'Import master', 'axis_attribute_ids' => [$attribute]]);
        $itemId = $this->item($sid, 'IMPORT-VARIANT');
        $targetId = $this->item($sid, 'IMPORT-RELATED');
        $this->db->pdo()->prepare('UPDATE stock_items SET sale_price_without_vat = 77.00 WHERE supplier_id = ? AND id = ?')->execute([$sid, $itemId]);
        $current = $this->itemsRepo->find($sid, $itemId);
        $profile = ['identity' => 'sku', 'source_key' => null, 'mode' => 'upsert'];
        $this->db->pdo()->beginTransaction();
        try {
            $state = $this->container->get(CatalogImportWriter::class)->write($sid, $profile, [
                'sku' => 'IMPORT-VARIANT', 'master_id' => $master['id'],
                'variant_options' => [['attribute_id' => $attribute, 'option_id' => $red]],
                'inheritance' => ['manufacturer' => true],
                'relations' => [['type' => 'related', 'target_stock_item_id' => $targetId]],
            ], $itemId, $current['row_version']);
            $this->db->pdo()->commit();
        } catch (\Throwable $e) {
            $this->db->pdo()->rollBack();
            throw $e;
        }
        self::assertSame($master['id'], $state['master_id']);
        self::assertSame('related', $state['relations'][0]['type']);
        self::assertSame('77.00', $this->itemsRepo->find($sid, $itemId)['sale_price_without_vat']);
    }

    public function testMasterMutationsRequireBothStockAndEshopWritePermissions(): void
    {
        $sid = $this->createSupplier();
        $action = $this->container->get(ProductMasterAction::class);
        foreach ([
            new EffectiveRole(1, 'Pouze sklad', 'staff', true, ['stock.items.write' => AccessLevel::WRITE->value]),
            new EffectiveRole(2, 'Pouze e-shop', 'staff', true, ['eshop.write' => AccessLevel::WRITE->value]),
        ] as $role) {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/eshop/product-masters')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute('auth.effective_role', $role)
                ->withParsedBody(['name' => 'Zakázaný master']);
            self::assertSame(403, $action->create($request, new Response())->getStatusCode());
        }
    }

    public function testCardProjectsMasterAndRejectsIndependentVariantAxisChanges(): void
    {
        $sid = $this->createSupplier();
        [$attribute, $small, $large] = $this->axisWithTwoOptions($sid);
        $master = $this->masters->create($sid, ['name' => 'Rozměry', 'axis_attribute_ids' => [$attribute]]);
        $itemId = $this->item($sid, 'AXIS-GUARD');
        $this->masters->attach($sid, $master['id'], ['master_row_version' => $master['row_version'], 'variants' => [[
            'stock_item_id' => $itemId, 'row_version' => $this->itemsRepo->find($sid, $itemId)['row_version'],
            'options' => [['attribute_id' => $attribute, 'option_id' => $small]],
        ]]]);
        $card = $this->container->get(\MyInvoice\Service\Eshop\ProductCardService::class)->get($sid, $itemId);
        self::assertSame($master['id'], $card['variant']['master_id']);
        $attributes = $this->container->get(\MyInvoice\Service\Eshop\AttributeValueService::class);
        foreach ([[], [['attribute_id' => $attribute, 'option_id' => $large]]] as $entries) {
            $this->assertEshop('variant_axis_change_required', fn () => $attributes->replaceForItem($sid, $itemId, $entries));
        }
        $attributes->replaceForItem($sid, $itemId, [['attribute_id' => $attribute, 'option_id' => $small]]);
        self::assertSame($small, (int) $this->db->pdo()->query('SELECT option_id FROM stock_item_attribute_values WHERE stock_item_id = ' . $itemId)->fetchColumn());
    }

    private function axis(int $supplierId, string $code, string $option): array
    {
        $stmt = $this->db->pdo()->prepare("INSERT INTO stock_attributes (supplier_id, code, name, data_type, is_multivalue) VALUES (?, ?, ?, 'enum', 0)");
        $stmt->execute([$supplierId, $code, $code]);
        $attribute = (int) $this->db->pdo()->lastInsertId();
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_attribute_options (supplier_id, attribute_id, code, label) VALUES (?, ?, ?, ?)');
        $stmt->execute([$supplierId, $attribute, $option, $option]);
        return [$attribute, (int) $this->db->pdo()->lastInsertId()];
    }

    private function axisWithTwoOptions(int $supplierId): array
    {
        [$attribute, $first] = $this->axis($supplierId, 'SIZE', 'S');
        $stmt = $this->db->pdo()->prepare("INSERT INTO stock_attribute_options (supplier_id, attribute_id, code, label) VALUES (?, ?, 'L', 'L')");
        $stmt->execute([$supplierId, $attribute]);
        return [$attribute, $first, (int) $this->db->pdo()->lastInsertId()];
    }

    private function manufacturer(int $supplierId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO manufacturers (supplier_id, code, name) VALUES (?, ?, ?)');
        $stmt->execute([$supplierId, $code, $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function assertEshop(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail('Očekávána EshopException.');
        } catch (EshopException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
