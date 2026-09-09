<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\CatalogSelectionService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;

final class CatalogSelectionTest extends StockTestCase
{
    public function testFilterSelectionIsFrozenAcrossLaterChangesAndExclusions(): void
    {
        $sid = $this->createSupplier();
        $first = $this->item($sid, 'SELECT-1');
        $second = $this->item($sid, 'SELECT-2');
        $this->item($this->createSupplier(), 'SELECT-FOREIGN');
        $service = $this->container->get(CatalogSelectionService::class);
        $id = $service->enqueue($sid, 'catalog_bulk_preview', [], [
            'all_matching' => true, 'filters' => ['q' => 'SELECT-', 'per_page' => 1], 'excluded_ids' => [$second],
        ]);
        $this->item($sid, 'SELECT-LATER');
        $this->db->pdo()->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE id = ?')->execute([$first]);
        $rows = $this->container->get(CatalogJobItemRepository::class)->batch($sid, $id);
        self::assertCount(1, $rows);
        self::assertSame($first, $rows[0]['stock_item_id']);
        self::assertSame(1, $rows[0]['expected_version']);
        self::assertSame(1, $this->container->get(CatalogJobService::class)->find($sid, $id)['total']);
    }

    public function testExplicitIdsPreserveOrderAndTreatMissingAndForeignIdentically(): void
    {
        $sid = $this->createSupplier();
        $own = $this->item($sid, 'OWN');
        $foreign = $this->item($this->createSupplier(), 'FOREIGN');
        $id = $this->container->get(CatalogSelectionService::class)->enqueue($sid, 'catalog_export', [], ['ids' => [$foreign, $own, 2147483647, $own]]);
        $rows = $this->container->get(CatalogJobItemRepository::class)->batch($sid, $id);
        self::assertSame([$foreign, $own, 2147483647], array_column($rows, 'stock_item_id'));
        self::assertSame(['failed', 'pending', 'failed'], array_column($rows, 'status'));
        self::assertSame(['unavailable', null, 'unavailable'], array_column($rows, 'error_code'));
        self::assertSame([null, 1, null], array_column($rows, 'expected_version'));
    }

    public function testOverLimitSnapshotRollsBackAllRows(): void
    {
        $sid = $this->createSupplier();
        $this->item($sid, 'LIMIT-1');
        $this->item($sid, 'LIMIT-2');
        $pdo = $this->db->pdo();
        $jobs = $this->container->get(CatalogJobService::class);
        $pdo->beginTransaction();
        $id = $jobs->enqueue($sid, 'catalog_export', []);
        try {
            $this->container->get(StockItemRepository::class)->snapshotCatalogSelection($sid, $id, [], [], 1);
            self::fail('Over-limit selection accepted');
        } catch (\InvalidArgumentException) {
            $pdo->rollBack();
        }
        self::assertNull($jobs->find($sid, $id));
        self::assertSame([], $this->container->get(CatalogJobItemRepository::class)->batch($sid, $id));
    }

    public function testAmbiguousSelectionDoesNotEnqueueAJob(): void
    {
        $sid = $this->createSupplier();
        foreach ([[], ['all_matching' => 'true', 'filters' => []], ['all_matching' => true],
            ['ids' => [1], 'filters' => []], ['all_matching' => true, 'filters' => ['typo' => 1]],
            ['ids' => ['1']], ['all_matching' => true, 'filters' => [], 'excluded_ids' => [0]]] as $selection) {
            try {
                $this->container->get(CatalogSelectionService::class)->enqueue($sid, 'catalog_export', [], $selection);
                self::fail('Invalid selection accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->container->get(CatalogJobService::class)->history($sid));
            }
        }
    }
}
