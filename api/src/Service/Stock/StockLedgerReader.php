<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class StockLedgerReader
{
    public function __construct(private readonly Connection $db) {}

    public function pairs(int $supplierId, string $date, ?int $warehouseId = null): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT warehouse_id, stock_item_id FROM (
            SELECT d.warehouse_id, l.stock_item_id FROM stock_document_lines l
            JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.doc_date <= ? AND d.status IN ('posted','reversed')
            UNION ALL
            SELECT d.warehouse_to_id AS warehouse_id, l.stock_item_id FROM stock_document_lines l
            JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.doc_date <= ? AND d.status IN ('posted','reversed') AND d.doc_type = 'transfer'
        ) legs" . ($warehouseId !== null ? ' WHERE warehouse_id = ?' : '') . ' ORDER BY stock_item_id, warehouse_id');
        $params = [$supplierId, $date, $supplierId, $date];
        if ($warehouseId !== null) {
            $params[] = $warehouseId;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function page(int $supplierId, int $warehouseId, int $itemId, string $date, ?string $afterDate = null, ?array $cursor = null, int $limit = 2000): array
    {
        $limit = max(1, min(10000, $limit));
        $where = '';
        $params = [$warehouseId, $supplierId, $itemId, $date, $warehouseId, $warehouseId];
        if ($afterDate !== null) {
            $where .= ' AND l.doc_date > ?';
            $params[] = $afterDate;
        }
        if ($cursor !== null) {
            $columns = ['l.doc_date', 'l.ledger_booked_at', 'l.document_id', 'l.line_no', 'l.id'];
            $branches = [];
            foreach ($columns as $index => $column) {
                $terms = [];
                for ($prefix = 0; $prefix < $index; $prefix++) {
                    $terms[] = $columns[$prefix] . ' = ?';
                    $params[] = $cursor[$prefix];
                }
                $terms[] = $column . ' > ?';
                $params[] = $cursor[$index];
                $branches[] = '(' . implode(' AND ', $terms) . ')';
            }
            $where .= ' AND (' . implode(' OR ', $branches) . ')';
        }
        $stmt = $this->db->pdo()->prepare("SELECT l.id AS line_id, l.document_id, d.doc_type, d.status, l.doc_date,
            l.ledger_booked_at AS booked_at, l.line_no, l.qty, l.unit_cost, l.value_total, l.extra_cost,
            CASE WHEN d.doc_type = 'receipt' THEN 1 WHEN d.doc_type = 'issue' THEN -1 WHEN d.warehouse_id = ? THEN -1 ELSE 1 END AS direction,
            EXISTS(SELECT 1 FROM stock_documents o WHERE o.supplier_id = d.supplier_id AND o.reversal_document_id = d.id) AS is_reversal,
            si.sku, si.name
            FROM stock_document_lines l FORCE INDEX (idx_sdl_valuation_cursor)
            STRAIGHT_JOIN stock_documents d FORCE INDEX (PRIMARY) ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            STRAIGHT_JOIN stock_items si ON si.id = l.stock_item_id AND si.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.stock_item_id = ? AND l.doc_date <= ? AND d.status IN ('posted','reversed')
            AND (d.warehouse_id = ? OR (d.doc_type = 'transfer' AND d.warehouse_to_id = ?))
            {$where} ORDER BY l.doc_date, l.ledger_booked_at, l.document_id, l.line_no, l.id LIMIT {$limit}");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stream(int $supplierId, int $warehouseId, int $itemId, string $date, ?string $afterDate = null): \Generator
    {
        $cursor = null;
        do {
            $rows = $this->page($supplierId, $warehouseId, $itemId, $date, $afterDate, $cursor);
            foreach ($rows as $row) {
                yield $row;
            }
            if ($rows !== []) {
                $cursor = self::cursor($rows[array_key_last($rows)]);
            }
        } while (count($rows) === 2000);
    }

    public function affectedDates(int $supplierId, int $warehouseId, int $itemId, string $fromDate): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT l.doc_date FROM stock_document_lines l
            JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.stock_item_id = ? AND l.doc_date >= ? AND d.status IN ('posted','reversed')
            AND (d.warehouse_id = ? OR (d.doc_type = 'transfer' AND d.warehouse_to_id = ?))");
        $stmt->execute([$supplierId, $itemId, $fromDate, $warehouseId, $warehouseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function cursor(array $line): array
    {
        return [$line['doc_date'], $line['booked_at'], (int) $line['document_id'], (int) $line['line_no'], (int) $line['line_id']];
    }
}
