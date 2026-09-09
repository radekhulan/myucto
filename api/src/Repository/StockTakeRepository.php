<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_takes + stock_take_lines — inventury (Epic SKLAD, §29–30 ZoÚ).
 * Per tenant (supplier_id); UNIQUE (supplier_id, warehouse_id, take_date).
 * status=counting blokuje post skladových pohybů na daném skladu (rozhodnutí A13) —
 * hasOpenCounting() to hlídá pro caller (PostingService/StockDocumentService).
 */
final class StockTakeRepository
{
    private const COLUMNS =
        'id, supplier_id, warehouse_id, take_date, status, note, counting_method,
         responsible_count_name, responsible_inventory_name, started_at, receipt_document_id,
         issue_document_id, created_by, closed_by, closed_at, created_at, updated_at, preparation_job_id';

    /** Sloupce, které smí updateStatus() přes $extra nastavit (whitelist). */
    private const STATUS_EXTRA_COLUMNS = ['started_at', 'closed_by', 'closed_at', 'receipt_document_id', 'issue_document_id', 'preparation_job_id'];

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_takes WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @param array{warehouse_id?:int, status?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, array $filters = []): array
    {
        $where = ['supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['warehouse_id'])) {
            $where[] = 'warehouse_id = ?';
            $params[] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = (string) $filters['status'];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_takes
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY take_date DESC'
        );
        $stmt->execute($params);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Vrátí rozpracovanou (status=counting) inventuru daného skladu, nebo null.
     * U transferů volá caller dvakrát — pro warehouse_id i warehouse_to_id.
     */
    public function hasOpenCounting(int $supplierId, int $warehouseId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT " . self::COLUMNS . " FROM stock_takes
              WHERE supplier_id = ? AND warehouse_id = ? AND status IN ('preparing','counting')
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $warehouseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @param array{warehouse_id:int, take_date:string, status?:string, note?:?string,
     *     counting_method?:?string, responsible_count_name?:?string,
     *     responsible_inventory_name?:?string, created_by?:?int} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_takes
                (supplier_id, warehouse_id, take_date, status, note, counting_method,
                 responsible_count_name, responsible_inventory_name, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (int) $data['warehouse_id'],
            (string) $data['take_date'],
            (string) ($data['status'] ?? 'draft'),
            $data['note'] ?? null,
            $data['counting_method'] ?? null,
            $data['responsible_count_name'] ?? null,
            $data['responsible_inventory_name'] ?? null,
            isset($data['created_by']) ? (int) $data['created_by'] : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{closed_by?:int, closed_at?:string, receipt_document_id?:?int, issue_document_id?:?int} $extra
     */
    public function updateStatus(int $supplierId, int $id, string $status, array $extra = []): bool
    {
        $sets = ['status = ?'];
        $params = [$status];
        foreach (self::STATUS_EXTRA_COLUMNS as $col) {
            if (array_key_exists($col, $extra)) {
                $sets[] = "$col = ?";
                $params[] = $extra[$col];
            }
        }
        $params[] = $id;
        $params[] = $supplierId;

        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_takes SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Řádky inventury + sku/name/unit karty. ORDER BY jméno karty.
     * @return list<array<string,mixed>>
     */
    public function lines(int $supplierId, int $stockTakeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT stl.id, stl.stock_take_id, stl.supplier_id, stl.stock_item_id,
                    stl.expected_qty, stl.expected_value, stl.counted_qty, stl.surplus_unit_cost,
                    si.sku AS item_sku, si.name AS item_name, si.unit AS item_unit
               FROM stock_take_lines stl
               JOIN stock_items si ON si.id = stl.stock_item_id AND si.supplier_id = stl.supplier_id
              WHERE stl.supplier_id = ? AND stl.stock_take_id = ?
              ORDER BY si.name ASC'
        );
        $stmt->execute([$supplierId, $stockTakeId]);
        return array_map([self::class, 'castLine'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{stock_item_id:int, expected_qty?:string, expected_value?:string,
     *     counted_qty?:?string, surplus_unit_cost?:?string} $data
     */
    public function insertLine(int $supplierId, int $stockTakeId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_take_lines
                (stock_take_id, supplier_id, stock_item_id, expected_qty, expected_value, counted_qty, surplus_unit_cost)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $stockTakeId,
            $supplierId,
            (int) $data['stock_item_id'],
            (string) ($data['expected_qty'] ?? '0'),
            (string) ($data['expected_value'] ?? '0'),
            isset($data['counted_qty']) ? (string) $data['counted_qty'] : null,
            isset($data['surplus_unit_cost']) ? (string) $data['surplus_unit_cost'] : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Nahradí snapshot řádků inventury (DELETE + INSERT) — volat v transakci
     * při přechodu draft → counting.
     *
     * @param list<array{stock_item_id:int, expected_qty?:string, expected_value?:string,
     *     counted_qty?:?string, surplus_unit_cost?:?string}> $lines
     */
    public function replaceLines(int $supplierId, int $stockTakeId, array $lines): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM stock_take_lines WHERE supplier_id = ? AND stock_take_id = ?')
            ->execute([$supplierId, $stockTakeId]);
        if ($lines === []) {
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO stock_take_lines
                (stock_take_id, supplier_id, stock_item_id, expected_qty, expected_value, counted_qty, surplus_unit_cost)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $l) {
            $stmt->execute([
                $stockTakeId,
                $supplierId,
                (int) $l['stock_item_id'],
                (string) ($l['expected_qty'] ?? '0'),
                (string) ($l['expected_value'] ?? '0'),
                isset($l['counted_qty']) ? (string) $l['counted_qty'] : null,
                isset($l['surplus_unit_cost']) ? (string) $l['surplus_unit_cost'] : null,
            ]);
        }
    }

    /** Nastaví counted_qty pro jeden řádek inventury (fáze counting). */
    public function updateCounted(int $supplierId, int $stockTakeId, int $lineId, ?string $countedQty): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_take_lines SET counted_qty = ?
              WHERE id = ? AND stock_take_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$countedQty, $lineId, $stockTakeId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function updateCountedAndSurplusCost(
        int $supplierId,
        int $stockTakeId,
        int $lineId,
        ?string $countedQty,
        ?string $surplusUnitCost,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_take_lines SET counted_qty = ?, surplus_unit_cost = ?
              WHERE id = ? AND stock_take_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$countedQty, $surplusUnitCost, $lineId, $stockTakeId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['warehouse_id'] = (int) $r['warehouse_id'];
        $r['preparation_job_id'] = $r['preparation_job_id'] !== null ? (int) $r['preparation_job_id'] : null;
        $r['receipt_document_id'] = $r['receipt_document_id'] !== null ? (int) $r['receipt_document_id'] : null;
        $r['issue_document_id'] = $r['issue_document_id'] !== null ? (int) $r['issue_document_id'] : null;
        $r['created_by'] = $r['created_by'] !== null ? (int) $r['created_by'] : null;
        $r['closed_by'] = $r['closed_by'] !== null ? (int) $r['closed_by'] : null;
        return $r;
    }

    /** @return array<string,mixed> */
    private static function castLine(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['stock_take_id'] = (int) $r['stock_take_id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        return $r;
    }
}
