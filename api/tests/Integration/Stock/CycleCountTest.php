<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Stock\CycleCountService;

final class CycleCountTest extends StockTestCase
{
    public function testDraftCreatedBeforeCutoffAndPostedAfterItIsExcludedFromSnapshot(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-DRAFT');
        $cycles = $this->container->get(CycleCountService::class);

        $lateReceipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-01', 'description' => 'Koncept před cutoffem',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '10']],
        ], $this->userId);
        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $lateReceipt['id'], $this->userId);

        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);

        self::assertSame('counting', $cycle['status']);
        self::assertSame('0.000', $cycle['lines'][0]['expected_qty']);
    }

    public function testDurableSnapshotUsesCutoffAndCloseReconcilesAgainstCurrentStock(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-1');
        $cycles = $this->container->get(CycleCountService::class);
        $jobs = $this->container->get(CatalogJobService::class);

        $receipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-01', 'description' => 'Počáteční stav',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '10']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $receipt['id'], $this->userId);

        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        self::assertSame('queued', $cycle['status']);
        self::assertNotNull($cycle['preparation_job_id']);

        $issue = $this->documents->create($supplierId, [
            'doc_type' => 'issue', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-02', 'description' => 'Pohyb po cut-off',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '2']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $issue['id'], $this->userId);

        $job = $cycles->tick($supplierId);
        self::assertSame('completed', $job['status']);
        self::assertSame('completed', $jobs->find($supplierId, (int) $cycle['preparation_job_id'])['status']);

        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        self::assertSame('counting', $cycle['status']);
        self::assertSame('5.000', $cycle['lines'][0]['expected_qty']);

        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '4', 'surplus_unit_cost' => '10',
        ]]);
        $closed = $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        self::assertSame('closed', $closed['status']);
        self::assertArrayHasKey('receipt', $closed['documents']);
        $stmt = $this->db->pdo()->prepare('SELECT qty FROM stock_levels WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?');
        $stmt->execute([$supplierId, $warehouseId, $itemId]);
        self::assertSame('4.000', $stmt->fetchColumn());
    }

    public function testRepeatedSaveOfSameCountDoesNotResetReferenceAfterMovement(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-SAVED');
        $cycles = $this->container->get(CycleCountService::class);

        $receipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-01', 'description' => 'Počáteční stav',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '10']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $receipt['id'], $this->userId);
        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '4', 'surplus_unit_cost' => '10',
        ]]);

        $lateReceipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-02', 'description' => 'Pohyb po uložení počtu',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '2', 'unit_cost' => '10']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $lateReceipt['id'], $this->userId);
        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '4', 'surplus_unit_cost' => '10',
        ]]);
        $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        $stmt = $this->db->pdo()->prepare('SELECT qty FROM stock_levels WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?');
        $stmt->execute([$supplierId, $warehouseId, $itemId]);
        self::assertSame('6.000', $stmt->fetchColumn());
    }

    public function testWholeWarehouseTrackedDifferenceUsesRealSourceLocations(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-LOC');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'lot' WHERE supplier_id = ? AND id = ?")
            ->execute([$supplierId, $itemId]);
        $locationInsert = $this->db->pdo()->prepare('INSERT INTO warehouse_locations (supplier_id, warehouse_id, code, name) VALUES (?, ?, ?, ?)');
        $locationInsert->execute([$supplierId, $warehouseId, 'A-01', 'Lokace A']);
        $locationA = (int) $this->db->pdo()->lastInsertId();
        $locationInsert->execute([$supplierId, $warehouseId, 'B-01', 'Lokace B']);
        $locationB = (int) $this->db->pdo()->lastInsertId();
        $cycles = $this->container->get(CycleCountService::class);

        $receipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-01', 'description' => 'Šarže ve dvou lokacích',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '10', 'tracking_allocations' => [
                ['lot_code' => 'LOT-CYCLE', 'quantity' => '3', 'location_id' => $locationA],
                ['lot_code' => 'LOT-CYCLE', 'quantity' => '2', 'location_id' => $locationB],
            ]]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $receipt['id'], $this->userId);
        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '1',
        ]]);
        $closed = $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        $lineId = (int) $closed['documents']['issue']['lines'][0]['id'];
        $stmt = $this->db->pdo()->prepare('SELECT location_id, direction, quantity FROM stock_tracking_allocations WHERE supplier_id = ? AND stock_document_line_id = ? ORDER BY location_id');
        $stmt->execute([$supplierId, $lineId]);
        $allocations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $allocations);
        self::assertSame([$locationA, $locationB], array_map('intval', array_column($allocations, 'location_id')));
        self::assertSame(['out', 'out'], array_column($allocations, 'direction'));
        self::assertSame(['3.000', '1.000'], array_column($allocations, 'quantity'));
    }

    public function testCountsCannotBeChangedAfterClose(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-CLOSED');
        $cycles = $this->container->get(CycleCountService::class);
        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '0',
        ]]);
        $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        try {
            $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
                'id' => $cycle['lines'][0]['id'], 'counted_qty' => '9',
            ]]);
            self::fail('Uzavřenou inventuru nesmí být možné změnit.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame('cycle_count_conflict', $e->errorCode);
        }
        $closed = $cycles->get($supplierId, (int) $cycle['id']);
        self::assertSame('0.000', $closed['lines'][0]['counted_qty']);
    }
}
