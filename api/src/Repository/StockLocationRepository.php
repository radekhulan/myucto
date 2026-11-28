<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\StockLedgerReader;
use MyInvoice\Service\Stock\StockValuation;
use PDO;

final class StockLocationRepository
{
    public function __construct(private readonly Connection $db) {}

    public function locations(int $supplierId, array $ids, bool $lock): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, warehouse_id, is_active FROM warehouse_locations
            WHERE supplier_id = ? AND id IN (' . self::placeholders($ids) . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$ids]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    }

    public function lockBalances(int $supplierId, array $warehouses, array $items): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT warehouse_id, stock_item_id, location_id, quantity
            FROM stock_location_levels FORCE INDEX (uq_sll_item_location)
            WHERE supplier_id = ? AND stock_item_id IN (' . self::placeholders($items) . ')
              AND warehouse_id IN (' . self::placeholders($warehouses) . ')
            ORDER BY warehouse_id, stock_item_id, location_id FOR UPDATE');
        $stmt->execute([$supplierId, ...$items, ...$warehouses]);
        $balances = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['warehouse_id'] . ':' . $row['stock_item_id'];
            $balances[$key][(int) $row['location_id']] = StockValuation::qtyToT($row['quantity']);
        }
        return $balances;
    }

    public function originalMovements(int $supplierId, array $lineIds): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_document_line_id, warehouse_id, location_id, quantity
            FROM stock_location_movements WHERE supplier_id = ? AND stock_document_line_id IN (' . self::placeholders($lineIds) . ')
            ORDER BY id FOR UPDATE');
        $stmt->execute([$supplierId, ...$lineIds]);
        return $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
    }

    public function futureNamedMovements(int $supplierId, int $warehouseId, array $items, string $date): iterable
    {
        $stmt = $this->db->pdo()->prepare('SELECT warehouse_id, stock_item_id, location_id, quantity
            FROM stock_location_movements FORCE INDEX (ix_slm_item_date)
            WHERE supplier_id = ? AND stock_item_id IN (' . self::placeholders($items) . ')
              AND warehouse_id = ? AND doc_date > ?
            ORDER BY doc_date DESC, id DESC FOR UPDATE');
        $stmt->execute([$supplierId, ...$items, $warehouseId, $date]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    public function futureUnassignedMovements(int $supplierId, int $warehouseId, array $items, string $date): iterable
    {
        $warehouseMovement = StockLedgerReader::WAREHOUSE_MOVEMENT_SQL;
        $itemIds = self::placeholders($items);
        $stmt = $this->db->pdo()->prepare("SELECT x.stock_item_id, SUM(x.quantity) AS quantity FROM (
                SELECT l.stock_item_id, l.doc_date, d.booked_at, d.id AS document_id,
                       CASE WHEN d.doc_type = 'receipt' OR d.warehouse_to_id = ? THEN l.qty ELSE -l.qty END AS quantity
                  FROM stock_document_lines l FORCE INDEX (idx_sdl_valuation_cursor)
                  STRAIGHT_JOIN stock_documents d ON d.supplier_id = l.supplier_id AND d.id = l.document_id
                 WHERE l.supplier_id = ? AND l.stock_item_id IN ($itemIds) AND l.doc_date > ?
                   AND d.status IN ('posted', 'reversed')
                   AND (d.warehouse_id = ? OR d.warehouse_to_id = ?)
                   AND $warehouseMovement
                UNION ALL
                SELECT m.stock_item_id, m.doc_date, d.booked_at, d.id, -m.quantity
                  FROM stock_location_movements m FORCE INDEX (ix_slm_item_date)
                  STRAIGHT_JOIN stock_document_lines l ON l.supplier_id = m.supplier_id AND l.id = m.stock_document_line_id
                  STRAIGHT_JOIN stock_documents d ON d.supplier_id = l.supplier_id AND d.id = l.document_id
                 WHERE m.supplier_id = ? AND m.stock_item_id IN ($itemIds) AND m.warehouse_id = ? AND m.doc_date > ?
            ) x
            GROUP BY x.stock_item_id, x.doc_date, x.booked_at, x.document_id
            HAVING SUM(x.quantity) <> 0
            ORDER BY x.doc_date DESC, x.booked_at DESC, x.document_id DESC");
        $stmt->execute([$warehouseId, $supplierId, ...$items, $date, $warehouseId, $warehouseId, $supplierId, ...$items, $warehouseId, $date]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    public function saveMovements(int $supplierId, string $date, array $movements, array $levels): void
    {
        $pdo = $this->db->pdo();
        foreach (array_chunk($movements, 500) as $batch) {
            $stmt = $pdo->prepare('INSERT INTO stock_location_movements
                (supplier_id, warehouse_id, stock_item_id, location_id, stock_document_line_id, doc_date, quantity) VALUES '
                . implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?, ?, ?, ?)')));
            $args = [];
            foreach ($batch as [$warehouseId, $itemId, $locationId, $lineId, $qtyT]) {
                array_push($args, $supplierId, $warehouseId, $itemId, $locationId, $lineId, $date, StockValuation::tToDecimal($qtyT));
            }
            $stmt->execute($args);
        }
        ksort($levels);
        foreach (array_chunk($levels, 500) as $batch) {
            $stmt = $pdo->prepare('INSERT INTO stock_location_levels (supplier_id, warehouse_id, stock_item_id, location_id, quantity) VALUES '
                . implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?, ?)')) . ' ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)');
            $args = [];
            foreach ($batch as [$warehouseId, $itemId, $locationId, $qtyT]) {
                array_push($args, $supplierId, $warehouseId, $itemId, $locationId, StockValuation::tToDecimal($qtyT));
            }
            $stmt->execute($args);
        }
    }

    /** @return array<int, string> */
    public function quantitiesAt(int $supplierId, int $warehouseId, int $locationId, string $date): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT m.stock_item_id, SUM(m.quantity)
            FROM stock_location_movements m FORCE INDEX (ix_slm_location_date)
            JOIN stock_items si ON si.supplier_id = m.supplier_id AND si.id = m.stock_item_id AND si.tracking_mode = \'none\'
            WHERE m.supplier_id = ? AND m.location_id = ? AND m.warehouse_id = ? AND m.doc_date <= ?
            GROUP BY m.stock_item_id');
        $stmt->execute([$supplierId, $locationId, $warehouseId, $date]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array{rows: list<array<string,mixed>>, total: int} */
    public function balances(int $supplierId, ?int $locationId, ?int $itemId, ?int $warehouseId, int $limit, int $offset): array
    {
        $plainWhere = ['b.supplier_id = ?', 'b.quantity > 0'];
        $plainIndex = $locationId !== null ? 'ix_sll_location_item' : 'uq_sll_item_location';
        $trackedWhere = [$locationId !== null ? 'a.supplier_id = ?' : 'u.supplier_id = ?', 'a.location_id IS NOT NULL'];
        $trackedFrom = $locationId !== null
            ? 'stock_tracking_allocations a FORCE INDEX (ix_tracking_location_balance) STRAIGHT_JOIN stock_tracking_units u'
            : 'stock_tracking_units u FORCE INDEX (uq_tracking_identity) STRAIGHT_JOIN stock_tracking_allocations a FORCE INDEX (ix_tracking_history)';
        $args = [$supplierId];
        foreach (['location_id' => $locationId, 'warehouse_id' => $warehouseId, 'stock_item_id' => $itemId] as $column => $value) {
            if ($value !== null) {
                $plainWhere[] = "b.$column = ?";
                $trackedWhere[] = ($column === 'stock_item_id' ? 'u' : 'a') . ".$column = ?";
                $args[] = $value;
            }
        }
        $balances = "SELECT b.warehouse_id, b.location_id, b.stock_item_id, b.quantity
              FROM stock_location_levels b FORCE INDEX ($plainIndex)
             WHERE " . implode(' AND ', $plainWhere) . "
            UNION ALL
            SELECT a.warehouse_id, a.location_id, u.stock_item_id,
                   SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END) AS available_qty
              FROM $trackedFrom
                ON a.supplier_id = u.supplier_id AND a.stock_tracking_unit_id = u.id
             WHERE " . implode(' AND ', $trackedWhere) . '
             GROUP BY a.warehouse_id, a.location_id, u.stock_item_id HAVING available_qty > 0';
        $sql = 'WITH movements AS (' . $balances . '), balances AS (
            SELECT warehouse_id, location_id, stock_item_id, SUM(quantity) AS quantity
              FROM movements GROUP BY warehouse_id, location_id, stock_item_id
        ) ';
        $pdo = $this->db->pdo();
        $count = $pdo->prepare($sql . 'SELECT COUNT(*) FROM balances');
        $count->execute([...$args, ...$args]);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare($sql . 'SELECT b.*, wl.code AS location_code, wl.name AS location_name, si.sku, si.name, si.unit
            FROM balances b
            JOIN warehouse_locations wl ON wl.supplier_id = ? AND wl.warehouse_id = b.warehouse_id AND wl.id = b.location_id
            JOIN stock_items si ON si.supplier_id = wl.supplier_id AND si.id = b.stock_item_id
            ORDER BY b.warehouse_id, b.location_id, b.stock_item_id LIMIT ' . $limit . ' OFFSET ' . $offset);
        $stmt->execute([...$args, ...$args, $supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['warehouse_id', 'location_id', 'stock_item_id'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['quantity'] = (string) $row['quantity'];
        }
        return ['rows' => $rows, 'total' => $total];
    }

    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
