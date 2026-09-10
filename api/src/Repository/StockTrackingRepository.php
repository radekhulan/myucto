<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class StockTrackingRepository
{
    public function __construct(private readonly Connection $db) {}

    public function item(int $supplierId, int $itemId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, sku, name, unit, tracking_mode FROM stock_items WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function location(int $supplierId, int $locationId, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, warehouse_id, code, name, is_active FROM warehouse_locations WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $locationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castLocation($row);
    }

    public function locations(int $supplierId, ?int $warehouseId = null): array
    {
        $sql = 'SELECT id, supplier_id, warehouse_id, code, name, is_active FROM warehouse_locations WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($warehouseId !== null) {
            $sql .= ' AND warehouse_id = ?';
            $params[] = $warehouseId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY warehouse_id, code, id');
        $stmt->execute($params);
        return array_map($this->castLocation(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveLocation(int $supplierId, int $warehouseId, ?int $id, string $code, string $name, bool $active): int
    {
        if ($id === null) {
            $stmt = $this->db->pdo()->prepare('INSERT INTO warehouse_locations (supplier_id, warehouse_id, code, name, is_active) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$supplierId, $warehouseId, $code, $name, (int) $active]);
            return (int) $this->db->pdo()->lastInsertId();
        }
        $stmt = $this->db->pdo()->prepare('UPDATE warehouse_locations SET code = ?, name = ?, is_active = ? WHERE supplier_id = ? AND warehouse_id = ? AND id = ?');
        $stmt->execute([$code, $name, (int) $active, $supplierId, $warehouseId, $id]);
        return $stmt->rowCount() === 1 ? $id : 0;
    }

    public function units(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, stock_item_id, unit_code, numerator, denominator FROM stock_item_units WHERE supplier_id = ? AND stock_item_id = ? ORDER BY unit_code');
        $stmt->execute([$supplierId, $itemId]);
        return array_map(static function (array $row): array {
            foreach (['id', 'supplier_id', 'stock_item_id', 'numerator', 'denominator'] as $key) $row[$key] = (int) $row[$key];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function replaceUnits(int $supplierId, int $itemId, array $units): void
    {
        $this->db->pdo()->prepare('DELETE FROM stock_item_units WHERE supplier_id = ? AND stock_item_id = ?')->execute([$supplierId, $itemId]);
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator) VALUES (?, ?, ?, ?, ?)');
        foreach ($units as $unit) $stmt->execute([$supplierId, $itemId, $unit['unit_code'], $unit['numerator'], $unit['denominator']]);
    }

    public function unitRatio(int $supplierId, int $itemId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT numerator, denominator FROM stock_item_units WHERE supplier_id = ? AND stock_item_id = ? AND unit_code = ?');
        $stmt->execute([$supplierId, $itemId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['numerator' => (int) $row['numerator'], 'denominator' => (int) $row['denominator']];
    }

    public function findUnit(int $supplierId, int $itemId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $itemId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castUnit($row);
    }

    public function findUnitByKey(int $supplierId, int $itemId, string $key, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ? AND tracking_key = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $itemId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castUnit($row);
    }

    public function insertUnit(int $supplierId, int $itemId, string $type, string $key, ?string $lot, ?string $serial, ?string $expires): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_tracking_units (supplier_id, stock_item_id, tracking_type, tracking_key, lot_code, serial_number, expires_on) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$supplierId, $itemId, $type, $key, $lot, $serial, $expires]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertAllocation(int $supplierId, int $lineId, int $unitId, int $warehouseId, ?int $locationId, string $direction, string $qty, ?int $originalId = null): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_tracking_allocations (supplier_id, stock_document_line_id, stock_tracking_unit_id, warehouse_id, location_id, direction, quantity, original_allocation_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$supplierId, $lineId, $unitId, $warehouseId, $locationId, $direction, $qty, $originalId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function allocationsForLine(int $supplierId, int $lineId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT a.*, u.stock_item_id, u.tracking_type, u.lot_code, u.serial_number, u.expires_on FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id WHERE a.supplier_id = ? AND a.stock_document_line_id = ? ORDER BY a.id');
        $stmt->execute([$supplierId, $lineId]);
        return array_map($this->castAllocation(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function balance(int $supplierId, int $unitId, ?int $warehouseId = null, ?int $locationId = null, bool $allLocations = false): int
    {
        $sql = "SELECT COALESCE(SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END), 0) FROM stock_tracking_allocations a JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id WHERE a.supplier_id = ? AND a.stock_tracking_unit_id = ? AND d.status IN ('draft','posted','reversed')";
        $params = [$supplierId, $unitId];
        if ($warehouseId !== null) { $sql .= ' AND a.warehouse_id = ?'; $params[] = $warehouseId; }
        if (!$allLocations) { $sql .= ' AND a.location_id <=> ?'; $params[] = $locationId; }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return \MyInvoice\Service\Stock\StockValuation::qtyToT((string) $stmt->fetchColumn());
    }

    public function history(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT a.id, a.stock_document_line_id, a.stock_tracking_unit_id, a.warehouse_id, a.location_id, a.direction, a.quantity, a.original_allocation_id, u.tracking_type, u.lot_code, u.serial_number, u.expires_on, d.id AS document_id, d.doc_number, d.doc_date, d.doc_type, d.status, w.code AS warehouse_code, wl.code AS location_code FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = a.supplier_id JOIN warehouses w ON w.id = a.warehouse_id AND w.supplier_id = a.supplier_id LEFT JOIN warehouse_locations wl ON wl.id = a.location_id AND wl.supplier_id = a.supplier_id WHERE a.supplier_id = ? AND u.stock_item_id = ? AND d.status IN ('posted','reversed') ORDER BY d.doc_date DESC, a.id DESC");
        $stmt->execute([$supplierId, $itemId]);
        return array_map($this->castAllocation(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function inventory(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT u.id AS stock_tracking_unit_id, u.tracking_type, u.lot_code, u.serial_number, u.expires_on, a.warehouse_id, a.location_id, w.code AS warehouse_code, wl.code AS location_code, SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END) AS quantity FROM stock_tracking_units u JOIN stock_tracking_allocations a ON a.supplier_id = u.supplier_id AND a.stock_tracking_unit_id = u.id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id JOIN warehouses w ON w.id = a.warehouse_id AND w.supplier_id = a.supplier_id LEFT JOIN warehouse_locations wl ON wl.id = a.location_id AND wl.supplier_id = a.supplier_id WHERE u.supplier_id = ? AND u.stock_item_id = ? AND d.status IN ('posted','reversed') GROUP BY u.id, u.tracking_type, u.lot_code, u.serial_number, u.expires_on, a.warehouse_id, a.location_id, w.code, wl.code HAVING quantity <> 0 ORDER BY u.expires_on IS NULL, u.expires_on, u.id");
        $stmt->execute([$supplierId, $itemId]);
        return array_map(static function (array $row): array {
            foreach (['stock_tracking_unit_id', 'warehouse_id'] as $key) $row[$key] = (int) $row[$key];
            $row['location_id'] = $row['location_id'] === null ? null : (int) $row['location_id'];
            $row['quantity'] = (string) $row['quantity'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function castLocation(array $row): array
    {
        foreach (['id', 'supplier_id', 'warehouse_id'] as $key) $row[$key] = (int) $row[$key];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }

    private function castUnit(array $row): array
    {
        foreach (['id', 'supplier_id', 'stock_item_id'] as $key) $row[$key] = (int) $row[$key];
        return $row;
    }

    private function castAllocation(array $row): array
    {
        foreach (['id', 'stock_document_line_id', 'stock_tracking_unit_id', 'warehouse_id'] as $key) $row[$key] = (int) $row[$key];
        foreach (['location_id', 'original_allocation_id', 'stock_item_id', 'document_id'] as $key) if (array_key_exists($key, $row)) $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        $row['quantity'] = (string) $row['quantity'];
        return $row;
    }
}
