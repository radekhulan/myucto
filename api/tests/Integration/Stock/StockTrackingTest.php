<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\TrackingAllocationService;

final class StockTrackingTest extends StockTestCase
{
    public function testUnassignedLocationCannotSpendLocatedLot(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LOT-LOCATION');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'lot' WHERE id = ?")->execute([$itemId]);
        $this->db->pdo()->prepare('INSERT INTO warehouse_locations (supplier_id, warehouse_id, code, name) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $warehouseId, 'REGAL', 'Regál']);
        $locationId = (int) $this->db->pdo()->lastInsertId();
        $this->trackedDocument($supplierId, $warehouseId, $itemId, 'receipt', ['lot_code' => 'LOT-LOCATION', 'location_id' => $locationId]);
        try {
            $this->trackedDocument($supplierId, $warehouseId, $itemId, 'issue', ['lot_code' => 'LOT-LOCATION']);
            self::fail('Výdej bez lokace nesmí spotřebovat zásobu v regálu.');
        } catch (StockException $e) {
            self::assertSame('tracking_insufficient_stock', $e->errorCode);
        }
        $issue = $this->trackedDocument($supplierId, $warehouseId, $itemId, 'issue', ['lot_code' => 'LOT-LOCATION', 'location_id' => $locationId]);
        self::assertSame('posted', $issue['status']);
    }

    public function testIssueReverseCannotDuplicateReturnedSerial(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SERIAL-RETURN');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$itemId]);
        $allocation = ['serial_number' => 'SN-RETURN'];
        $this->trackedDocument($supplierId, $warehouseId, $itemId, 'receipt', $allocation);
        $issue = $this->trackedDocument($supplierId, $warehouseId, $itemId, 'issue', $allocation);
        $this->trackedDocument($supplierId, $warehouseId, $itemId, 'receipt', $allocation);
        try {
            $this->documents->reverse($supplierId, (int) $issue['id'], [], $this->userId);
            self::fail('Storno nesmí podruhé přijmout již vrácenou sérii.');
        } catch (StockException $e) {
            self::assertSame('serial_already_in_stock', $e->errorCode);
        }
    }

    public function testSerialTransferReverseMovesIdentityBack(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseA = $this->warehouse($supplierId, 'A');
        $warehouseB = $this->warehouse($supplierId, 'B', false);
        $itemId = $this->item($supplierId, 'SERIAL-TRANSFER');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$itemId]);
        $allocation = ['serial_number' => 'SN-TRANSFER'];
        $this->trackedDocument($supplierId, $warehouseA, $itemId, 'receipt', $allocation);
        $transfer = $this->trackedDocument($supplierId, $warehouseA, $itemId, 'transfer', $allocation, $warehouseB);
        $this->documents->reverse($supplierId, (int) $transfer['id'], [], $this->userId);
        $inventory = $this->container->get(\MyInvoice\Repository\StockTrackingRepository::class)->inventory($supplierId, $itemId);
        self::assertCount(1, $inventory);
        self::assertSame($warehouseA, $inventory[0]['warehouse_id']);
        self::assertSame('1.000', $inventory[0]['quantity']);
    }

    private function trackedDocument(int $supplierId, int $warehouseId, int $itemId, string $type, array $allocation, ?int $warehouseToId = null): array
    {
        $document = $this->documents->create($supplierId, [
            'doc_type' => $type, 'warehouse_id' => $warehouseId, 'warehouse_to_id' => $warehouseToId,
            'doc_date' => '2099-08-01', 'description' => 'Syntetický sledovaný pohyb',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '1', 'unit_cost' => '10',
                'tracking_allocations' => [$allocation + ['quantity' => '1']]]],
        ], $this->userId);
        return $this->documents->post($supplierId, (int) $document['id'], $this->userId);
    }

    public function testTrackedLineRequiresCompleteAllocationAndSerialReverseRestoresIdentity(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SERIAL-1');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $itemId]);

        $missing = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-08-01', 'description' => 'Příjem bez sérií',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '1.000', 'unit_cost' => '10']],
        ], $this->userId);
        try {
            $this->documents->post($supplierId, (int) $missing['id'], $this->userId);
            self::fail('Post bez úplné alokace měl být odmítnut.');
        } catch (StockException $e) {
            self::assertSame('tracking_allocations_required', $e->errorCode);
        }

        $receipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-08-01', 'description' => 'Příjem sérií',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '2.000', 'unit_cost' => '10', 'tracking_allocations' => [
                ['serial_number' => 'SN-A', 'quantity' => '1'],
                ['serial_number' => 'SN-B', 'quantity' => '1'],
            ]]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $receipt['id'], $this->userId);

        $issue = $this->documents->create($supplierId, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-08-02', 'description' => 'Výdej série',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '1.000', 'tracking_allocations' => [
                ['serial_number' => 'SN-A', 'quantity' => '1'],
            ]]],
        ], $this->userId);
        $postedIssue = $this->documents->post($supplierId, (int) $issue['id'], $this->userId);
        $canonical = $this->container->get(TrackingAllocationService::class)
            ->canonicalAllocationsForLine($supplierId, (int) $postedIssue['lines'][0]['id'], 'out');
        self::assertGreaterThan(0, $canonical[0]['stock_tracking_unit_id']);
        self::assertSame('SN-A', $canonical[0]['serial_number']);
        self::assertSame('out', $canonical[0]['direction']);
        $result = $this->documents->reverse($supplierId, (int) $postedIssue['id'], [], $this->userId);

        $stmt = $this->db->pdo()->prepare('SELECT original_allocation_id, stock_tracking_unit_id, direction, quantity FROM stock_tracking_allocations WHERE supplier_id = ? AND stock_document_line_id = ?');
        $stmt->execute([$supplierId, $result['reversal']['lines'][0]['id']]);
        $reverse = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($reverse);
        self::assertNotNull($reverse['original_allocation_id']);
        self::assertSame('in', $reverse['direction']);
        self::assertSame('1.000', $reverse['quantity']);
    }

    public function testLotTransferKeepsExactLocationsAndTenantOwnership(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseA = $this->warehouse($supplierId, 'A');
        $warehouseB = $this->warehouse($supplierId, 'B', false);
        $itemId = $this->item($supplierId, 'LOT-1');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'lot' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $itemId]);
        $insert = $this->db->pdo()->prepare('INSERT INTO warehouse_locations (supplier_id, warehouse_id, code, name) VALUES (?, ?, ?, ?)');
        $insert->execute([$supplierId, $warehouseA, 'A-01', 'A 01']); $locationA = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([$supplierId, $warehouseB, 'B-01', 'B 01']); $locationB = (int) $this->db->pdo()->lastInsertId();
        $otherSupplierId = $this->createSupplier();
        $otherWarehouseId = $this->warehouse($otherSupplierId, 'OTHER');
        $insert->execute([$otherSupplierId, $otherWarehouseId, 'X-01', 'Cizí lokace']);
        $otherLocationId = (int) $this->db->pdo()->lastInsertId();

        $invalid = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseA, 'doc_date' => '2099-08-01', 'description' => 'Cizí lokace',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '1', 'unit_cost' => '3', 'tracking_allocations' => [[
                'lot_code' => 'LOT-INVALID', 'quantity' => '1', 'location_id' => $otherLocationId,
            ]]]],
        ], $this->userId);
        try {
            $this->documents->post($supplierId, (int) $invalid['id'], $this->userId);
            self::fail('Cizí tenant lokaci nelze použít v alokaci.');
        } catch (StockException $e) {
            self::assertSame('invalid_location', $e->errorCode);
        }

        $this->db->pdo()->prepare('INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator) VALUES (?, ?, ?, ?, ?)')
            ->execute([$supplierId, $itemId, 'bal', 5, 1]);

        $receipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseA, 'doc_date' => '2099-08-01', 'description' => 'Šarže',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '3', 'tracking_allocations' => [[
                'lot_code' => 'LOT-X', 'expires_on' => '2100-01-01', 'quantity' => '1', 'unit_code' => 'bal', 'location_id' => $locationA,
            ]]]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $receipt['id'], $this->userId);

        $transfer = $this->documents->create($supplierId, [
            'doc_type' => 'transfer', 'warehouse_id' => $warehouseA, 'warehouse_to_id' => $warehouseB,
            'doc_date' => '2099-08-02', 'description' => 'Přesun šarže',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '2', 'tracking_allocations' => [[
                'lot_code' => 'LOT-X', 'expires_on' => '2100-01-01', 'quantity' => '2',
                'location_id' => $locationA, 'location_to_id' => $locationB,
            ]]]],
        ], $this->userId);
        $posted = $this->documents->post($supplierId, (int) $transfer['id'], $this->userId);
        $stmt = $this->db->pdo()->prepare('SELECT warehouse_id, location_id, direction, quantity FROM stock_tracking_allocations WHERE supplier_id = ? AND stock_document_line_id = ? ORDER BY direction');
        $stmt->execute([$supplierId, $posted['lines'][0]['id']]);
        $legs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $legs);
        self::assertSame([$warehouseB, $warehouseA], array_map('intval', array_column($legs, 'warehouse_id')));
        self::assertSame([$locationB, $locationA], array_map('intval', array_column($legs, 'location_id')));
    }
}
