<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockValuation;
use PDO;

final class StockValuationSnapshotRepository
{
    public function __construct(private readonly Connection $db) {}

    public function versions(int $supplierId, ?array $warehouseIds = null): array
    {
        $params = [$supplierId];
        $sql = 'SELECT id, valuation_version FROM warehouses WHERE supplier_id = ?';
        if ($warehouseIds !== null) {
            if ($warehouseIds === []) {
                return [];
            }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ')';
            array_push($params, ...$warehouseIds);
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);
        $versions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $versions[(int) $row['id']] = (int) $row['valuation_version'];
        }
        if ($warehouseIds !== null && count($versions) !== count(array_unique($warehouseIds))) {
            throw new StockException('not_found', 'Sklad nenalezen.', 404);
        }
        return $versions;
    }

    public function latest(int $supplierId, int $warehouseId, int $itemId, string $date, bool $strict = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT cutoff_date, qty, value_total FROM stock_valuation_snapshots
            WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ? AND algorithm_version = 1 AND cutoff_date '
            . ($strict ? '<' : '<=') . ' ? ORDER BY cutoff_date DESC LIMIT 1');
        $stmt->execute([$supplierId, $warehouseId, $itemId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function save(int $supplierId, int $warehouseId, int $itemId, string $date, int $qtyT, int $valueC, int $version): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_valuation_snapshots
            (supplier_id, warehouse_id, stock_item_id, cutoff_date, qty, value_total, source_version)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = VALUES(qty), value_total = VALUES(value_total), source_version = VALUES(source_version), algorithm_version = 1, created_at = NOW(6)');
        $stmt->execute([$supplierId, $warehouseId, $itemId, $date, StockValuation::tToDecimal($qtyT), StockValuation::cToDecimal($valueC), $version]);
    }

    public function invalidate(int $supplierId, array $pairs, string $fromDate): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Zneplatnění ocenění vyžaduje transakci skladového pohybu.');
        }
        $warehouses = [];
        foreach ($pairs as $pair) {
            $warehouseId = (int) $pair['warehouse_id'];
            $warehouses[$warehouseId] = true;
            $this->db->pdo()->prepare('DELETE FROM stock_valuation_snapshots
                WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ? AND cutoff_date >= ?')
                ->execute([$supplierId, $warehouseId, (int) $pair['stock_item_id'], $fromDate]);
        }
        foreach (array_keys($warehouses) as $warehouseId) {
            $this->db->pdo()->prepare('UPDATE warehouses SET valuation_version = valuation_version + 1 WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $warehouseId]);
        }
    }
}
