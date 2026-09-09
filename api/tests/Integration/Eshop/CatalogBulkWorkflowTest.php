<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\CatalogBulkAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Eshop\CatalogBulkService;
use MyInvoice\Service\Eshop\CatalogBulkWorker;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class CatalogBulkWorkflowTest extends StockTestCase
{
    private CatalogBulkService $bulk;
    private CatalogBulkWorker $worker;
    private CatalogJobItemRepository $jobItems;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bulk = $this->container->get(CatalogBulkService::class);
        $this->worker = $this->container->get(CatalogBulkWorker::class);
        $this->jobItems = $this->container->get(CatalogJobItemRepository::class);
    }

    public function testClassificationApplyAndRestoreRecalculateRulePricesAtomically(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'BULK-RULE');
        $old = $this->manufacturer($sid, 'RULE-OLD');
        $new = $this->manufacturer($sid, 'RULE-NEW');
        $this->receiveStock($sid, $this->warehouse($sid), $item, '1.000', 100.0);
        $this->db->pdo()->prepare('UPDATE stock_items SET manufacturer_id = ? WHERE supplier_id = ? AND id = ?')->execute([$old, $sid, $item]);
        $profiles = $this->container->get(\MyInvoice\Repository\CatalogPricingProfileRepository::class);
        $rules = $this->container->get(\MyInvoice\Repository\CatalogPricingRuleRepository::class);
        foreach ([[$old, '10'], [$new, '50']] as [$manufacturer, $percentage]) {
            $profile = $profiles->insert($sid, ['code' => 'bulk-' . $manufacturer, 'name' => 'Fixture', 'currency_code' => 'CZK',
                'calculation_mode' => 'markup', 'percentage' => $percentage, 'rounding' => 'none', 'fx_source' => 'cnb', 'max_rate_age_days' => 7, 'is_active' => true]);
            $rules->insert($sid, ['profile_id' => $profile, 'match_type' => 'manufacturer', 'match_id' => $manufacturer, 'priority' => 0, 'is_active' => true]);
        }
        $this->container->get(\MyInvoice\Service\Eshop\Pricing\PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'CZK', 'use_pricing_rules' => true],
        ]);
        self::assertSame('110.00', $this->itemsRepo->find($sid, $item)['sale_price_without_vat']);
        $preview = $this->bulk->preview($sid, ['all_matching' => false, 'ids' => [$item]], ['manufacturer_id' => $new]);
        $this->worker->tick($sid);
        $apply = $this->bulk->apply($sid, $preview['id']);
        $apply = $this->worker->tick($sid);
        self::assertSame(1, $apply['report']['counts']['applied']);
        self::assertSame('150.00', $this->itemsRepo->find($sid, $item)['sale_price_without_vat']);
        $this->bulk->restore($sid, $apply['id']);
        $restore = $this->worker->tick($sid);
        self::assertSame(1, $restore['report']['counts']['applied']);
        self::assertSame('110.00', $this->itemsRepo->find($sid, $item)['sale_price_without_vat']);
        $missing = $this->manufacturer($sid, 'RULE-MISSING');
        $preview = $this->bulk->preview($sid, ['all_matching' => false, 'ids' => [$item]], ['manufacturer_id' => $missing]);
        $this->worker->tick($sid);
        $this->bulk->apply($sid, $preview['id']);
        $failed = $this->worker->tick($sid);
        self::assertSame(1, $failed['report']['counts']['failed']);
        self::assertSame($old, $this->itemsRepo->find($sid, $item)['manufacturer_id']);
        self::assertSame('110.00', $this->itemsRepo->find($sid, $item)['sale_price_without_vat']);
        self::assertSame('missing_pricing_rule', $this->jobItems->page($sid, $failed['id'])['items'][0]['error_code']);
    }
    public function testPreviewApplyAndRestoreOnlyChangedRows(): void
    {
        $supplierId = $this->createSupplier();
        $changedId = $this->item($supplierId, 'BULK-CHANGED');
        $unchangedId = $this->item($supplierId, 'BULK-UNCHANGED', active: false);

        $preview = $this->bulk->preview($supplierId, [
            'all_matching' => false,
            'ids' => [$changedId, $unchangedId],
        ], ['is_active' => false], $this->userId);
        self::assertSame('queued', $preview['status']);

        $preview = $this->worker->tick($supplierId);
        self::assertSame('completed', $preview['status']);
        self::assertSame(1, $preview['report']['counts']['ready']);
        self::assertSame(1, $preview['report']['counts']['unchanged']);
        $ready = $this->jobItems->page($supplierId, $preview['id'], 1, 10, 'ready')['items'][0];
        self::assertTrue($ready['before']['is_active']);
        self::assertFalse($ready['after']['is_active']);
        self::assertSame($ready['before']['row_version'] + 1, $ready['after']['row_version']);

        $apply = $this->bulk->apply($supplierId, $preview['id'], $this->userId);
        self::assertSame(1, $apply['total']);
        $apply = $this->worker->tick($supplierId);
        self::assertSame('completed', $apply['status']);
        self::assertFalse($this->itemsRepo->find($supplierId, $changedId)['is_active']);
        self::assertFalse($this->itemsRepo->find($supplierId, $unchangedId)['is_active']);

        $restore = $this->bulk->restore($supplierId, $apply['id'], $this->userId);
        self::assertSame(1, $restore['total']);
        $restore = $this->worker->tick($supplierId);
        self::assertSame('completed', $restore['status']);
        self::assertTrue($this->itemsRepo->find($supplierId, $changedId)['is_active']);
        self::assertFalse($this->itemsRepo->find($supplierId, $unchangedId)['is_active']);
        self::assertSame(0, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM stock_document_lines WHERE stock_item_id IN (' . $changedId . ',' . $unchangedId . ')'
        )->fetchColumn());
    }

    public function testApplyUsesFrozenVersionAndReportsPerItemConflict(): void
    {
        $supplierId = $this->createSupplier();
        $firstId = $this->item($supplierId, 'BULK-CAS-1');
        $secondId = $this->item($supplierId, 'BULK-CAS-2');
        $preview = $this->bulk->preview($supplierId, ['all_matching' => false, 'ids' => [$firstId, $secondId]], [
            'min_qty' => '2.5',
        ]);
        $this->worker->tick($supplierId);
        $apply = $this->bulk->apply($supplierId, $preview['id']);

        $second = $this->itemsRepo->find($supplierId, $secondId);
        $second['name'] = 'Souběžně změněná karta';
        self::assertTrue($this->itemsRepo->updateVersioned($supplierId, $secondId, $second['row_version'], $second));

        $apply = $this->worker->tick($supplierId);
        self::assertSame(1, $apply['report']['counts']['applied']);
        self::assertSame(1, $apply['report']['counts']['conflict']);
        self::assertSame('2.500', $this->itemsRepo->find($supplierId, $firstId)['min_qty']);
        self::assertNull($this->itemsRepo->find($supplierId, $secondId)['min_qty']);
        $conflict = $this->jobItems->page($supplierId, $apply['id'], 1, 10, 'conflict')['items'][0];
        self::assertSame('version_conflict', $conflict['error_code']);
        self::assertSame('Souběžně změněná karta', $conflict['before']['name']);
    }

    public function testAllMatchingSelectionUsesFilterSnapshotAndExclusions(): void
    {
        $supplierId = $this->createSupplier();
        $includedId = $this->item($supplierId, 'BULK-FILTER-IN');
        $excludedId = $this->item($supplierId, 'BULK-FILTER-EXCLUDED');
        $this->item($supplierId, 'BULK-FILTER-INACTIVE', active: false);

        $preview = $this->bulk->preview($supplierId, [
            'all_matching' => true,
            'filters' => ['active' => true],
            'excluded_ids' => [$excludedId],
        ], ['export_eshop' => true]);
        self::assertSame(1, $preview['total']);
        $preview = $this->worker->tick($supplierId);
        $item = $this->jobItems->page($supplierId, $preview['id'])['items'][0];
        self::assertSame($includedId, $item['stock_item_id']);
        self::assertSame('ready', $item['status']);
    }

    public function testApplySnapshotCopiesLargeAuditSetWithJsonObjects(): void
    {
        $supplierId = $this->createSupplier();
        $ids = [];
        for ($index = 1; $index <= 205; $index++) {
            $ids[] = $this->item($supplierId, 'BULK-SET-' . $index);
        }
        $preview = $this->bulk->preview($supplierId, ['all_matching' => false, 'ids' => $ids], [
            'is_active' => false,
        ]);
        $this->worker->tick($supplierId);

        $apply = $this->bulk->apply($supplierId, $preview['id']);
        self::assertSame(205, $apply['total']);
        $copied = $this->jobItems->page($supplierId, $apply['id'], 1, 500);
        self::assertCount(205, $copied['items']);
        self::assertIsArray($copied['items'][0]['input']['before']);
        self::assertIsArray($copied['items'][0]['input']['after']);
        self::assertIsBool($copied['items'][0]['input']['before']['is_active']);
        self::assertSame(205, $copied['items'][204]['ordinal']);
    }

    public function testProductRelationsAreReplacedAndRestoredFromAudit(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'BULK-PRODUCT');
        $oldManufacturer = $this->manufacturer($supplierId, 'OLD');
        $newManufacturer = $this->manufacturer($supplierId, 'NEW');
        $firstCategory = $this->category($supplierId, 'first-category');
        $secondCategory = $this->category($supplierId, 'second-category');
        $oldTag = $this->tag($supplierId, 'old-tag');
        $newTag = $this->tag($supplierId, 'new-tag');
        $this->db->pdo()->prepare('UPDATE stock_items SET manufacturer_id = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$oldManufacturer, $supplierId, $itemId]);
        $this->db->pdo()->prepare('INSERT INTO stock_item_categories
            (supplier_id, stock_item_id, category_id, is_primary, display_order) VALUES (?, ?, ?, 0, 7), (?, ?, ?, 0, 20)')
            ->execute([$supplierId, $itemId, $firstCategory, $supplierId, $itemId, $secondCategory]);
        $this->db->pdo()->prepare('INSERT INTO stock_item_tags (supplier_id, stock_item_id, tag_id) VALUES (?, ?, ?)')
            ->execute([$supplierId, $itemId, $oldTag]);

        $preview = $this->bulk->preview($supplierId, ['all_matching' => false, 'ids' => [$itemId]], [
            'manufacturer_id' => $newManufacturer,
            'category_ids' => [$firstCategory, $secondCategory],
            'tag_ids' => [$newTag],
            'export_eshop' => true,
        ]);
        $preview = $this->worker->tick($supplierId);
        $previewItem = $this->jobItems->page($supplierId, $preview['id'])['items'][0];
        self::assertSame([$firstCategory, $secondCategory], $previewItem['before']['category_ids']);
        self::assertSame([
            ['category_id' => $firstCategory, 'is_primary' => false, 'display_order' => 7],
            ['category_id' => $secondCategory, 'is_primary' => false, 'display_order' => 20],
        ], $previewItem['before']['categories']);
        self::assertSame([
            ['category_id' => $firstCategory, 'is_primary' => true, 'display_order' => 0],
            ['category_id' => $secondCategory, 'is_primary' => false, 'display_order' => 1],
        ], $previewItem['after']['categories']);
        self::assertSame($previewItem['before']['row_version'] + 2, $previewItem['after']['row_version']);

        $apply = $this->bulk->apply($supplierId, $preview['id']);
        $apply = $this->worker->tick($supplierId);
        $state = $this->bulk->states($supplierId, [$itemId])[$itemId];
        self::assertSame($newManufacturer, $state['manufacturer_id']);
        self::assertSame([$firstCategory, $secondCategory], $state['category_ids']);
        self::assertTrue($state['categories'][0]['is_primary']);
        self::assertSame(1, $state['categories'][1]['display_order']);
        self::assertSame([$newTag], $state['tag_ids']);

        $restore = $this->bulk->restore($supplierId, $apply['id']);
        $this->worker->tick($supplierId);
        $state = $this->bulk->states($supplierId, [$itemId])[$itemId];
        self::assertSame($oldManufacturer, $state['manufacturer_id']);
        self::assertSame([
            ['category_id' => $firstCategory, 'is_primary' => false, 'display_order' => 7],
            ['category_id' => $secondCategory, 'is_primary' => false, 'display_order' => 20],
        ], $state['categories']);
        self::assertSame([$oldTag], $state['tag_ids']);
        self::assertFalse($state['export_eshop']);
    }

    public function testTickKindProcessesApplyLaneWhilePreviewIsQueued(): void
    {
        $supplierId = $this->createSupplier();
        $applyItem = $this->item($supplierId, 'BULK-LANE-APPLY');
        $queuedPreviewItem = $this->item($supplierId, 'BULK-LANE-PREVIEW');
        $preview = $this->bulk->preview($supplierId, ['all_matching' => false, 'ids' => [$applyItem]], [
            'is_active' => false,
        ]);
        $this->worker->tickKind($supplierId, CatalogBulkService::PREVIEW_KIND);
        $apply = $this->bulk->apply($supplierId, $preview['id']);
        $queuedPreview = $this->bulk->preview($supplierId, [
            'all_matching' => false,
            'ids' => [$queuedPreviewItem],
        ], ['is_active' => false]);

        $result = $this->worker->tickKind($supplierId, CatalogBulkService::APPLY_KIND);
        self::assertSame($apply['id'], $result['id']);
        self::assertSame('completed', $result['status']);
        self::assertSame('queued', $this->container->get(CatalogJobService::class)->find(
            $supplierId, $queuedPreview['id'],
        )['status']);
    }

    public function testRestoreDoesNotOverwriteChangeMadeAfterApply(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'BULK-RESTORE-CAS');
        $preview = $this->bulk->preview($supplierId, ['all_matching' => false, 'ids' => [$itemId]], [
            'is_active' => false,
        ]);
        $this->worker->tick($supplierId);
        $apply = $this->bulk->apply($supplierId, $preview['id']);
        $apply = $this->worker->tick($supplierId);
        $restore = $this->bulk->restore($supplierId, $apply['id']);

        $concurrent = $this->itemsRepo->find($supplierId, $itemId);
        $concurrent['name'] = 'Ruční změna po hromadné úpravě';
        self::assertTrue($this->itemsRepo->updateVersioned(
            $supplierId, $itemId, $concurrent['row_version'], $concurrent,
        ));

        $restore = $this->worker->tick($supplierId);
        self::assertSame(1, $restore['report']['counts']['conflict']);
        $current = $this->itemsRepo->find($supplierId, $itemId);
        self::assertFalse($current['is_active']);
        self::assertSame('Ruční změna po hromadné úpravě', $current['name']);
    }

    public function testActionRequiresBothPermissionsAndHidesForeignJob(): void
    {
        $supplierId = $this->createSupplier();
        $other = $this->createSupplier();
        $itemId = $this->item($supplierId, 'BULK-ACTION');
        $action = $this->container->get(CatalogBulkAction::class);
        $body = ['selection' => ['all_matching' => false, 'ids' => [$itemId]], 'changes' => ['is_active' => false]];
        $request = $this->request($supplierId, ['eshop.write' => 2])->withParsedBody($body);
        self::assertSame(403, $action->preview($request, new Response())->getStatusCode());

        $request = $this->request($supplierId, ['eshop.write' => 2, 'stock.items.write' => 2])->withParsedBody($body);
        $response = $action->preview($request, new Response());
        self::assertSame(202, $response->getStatusCode());
        $job = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('input', $job);
        $this->worker->tick($supplierId);
        self::assertSame(404, $action->apply($this->request($other, ['eshop.write' => 2, 'stock.items.write' => 2]), new Response(), [
            'id' => $job['id'],
        ])->getStatusCode());
    }

    private function request(int $supplierId, array $permissions): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/eshop/bulk/preview')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(123, 'Katalog', 'staff', true, $permissions));
    }

    private function manufacturer(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare('INSERT INTO manufacturers (supplier_id, code, name) VALUES (?, ?, ?)')
            ->execute([$supplierId, $code, 'Výrobce ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function category(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare('INSERT INTO stock_categories (supplier_id, code, name) VALUES (?, ?, ?)')
            ->execute([$supplierId, $code, 'Kategorie ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function tag(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare('INSERT INTO stock_tags (supplier_id, code, name) VALUES (?, ?, ?)')
            ->execute([$supplierId, $code, 'Štítek ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
