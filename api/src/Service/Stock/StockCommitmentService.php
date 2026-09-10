<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class StockCommitmentService
{
    public function __construct(private readonly Connection $db) {}

    public function isInvoiceManagedByOrder(int $supplierId, int $invoiceId): bool
    {
        $query = $this->db->pdo()->prepare('SELECT 1 FROM sales_order_invoice_links WHERE supplier_id = ? AND invoice_id = ?');
        $query->execute([$supplierId, $invoiceId]);
        return $query->fetchColumn() !== false;
    }

    public function isInvoiceStockManaged(int $supplierId, int $invoiceId): bool
    {
        if ($this->isInvoiceManagedByOrder($supplierId, $invoiceId)) return true;
        $query = $this->db->pdo()->prepare("SELECT 1 FROM fulfillment_tasks t
            JOIN stock_documents d ON d.id = t.claimed_stock_document_id AND d.supplier_id = t.supplier_id
            WHERE t.supplier_id = ? AND d.invoice_id = ? AND t.status <> 'cancelled' LIMIT 1");
        $query->execute([$supplierId, $invoiceId]);
        return $query->fetchColumn() !== false;
    }

    /**
     * @param list<array{warehouse_id:int,stock_item_id:int}> $pairs
     * @return array<string,int> quantity in StockValuation thousandths
     */
    public function committedForPairs(int $supplierId, array $pairs, ?string $excludeOrderUuid = null): array
    {
        $pairs = $this->uniquePairs($pairs);
        if ($pairs === []) {
            return [];
        }

        $committed = $this->hardReservations($supplierId, $pairs, $excludeOrderUuid);
        foreach ($this->standaloneFulfillmentAllocations($supplierId, $pairs) as $key => $qtyT) {
            $committed[$key] = ($committed[$key] ?? 0) + $qtyT;
        }

        return $committed;
    }

    /**
     * @param list<array{warehouse_id:int,stock_item_id:int}> $pairs
     * @return array<string,int>
     */
    private function hardReservations(int $supplierId, array $pairs, ?string $excludeOrderUuid): array
    {
        if (!$this->db->hasTable('sales_order_reservations')) {
            return [];
        }
        [$predicate, $params] = $this->pairPredicate($pairs);
        $params = [$supplierId, ...$params];
        $exclude = '';
        if ($excludeOrderUuid !== null) {
            $exclude = ' AND o.order_uuid <> ?';
            $params[] = $excludeOrderUuid;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.warehouse_id, r.stock_item_id,
                    SUM(GREATEST(r.qty_reserved - r.qty_consumed - r.qty_released, 0)) AS qty
               FROM sales_order_reservations r
               JOIN sales_orders o ON o.id = r.order_id AND o.supplier_id = r.supplier_id
              WHERE r.supplier_id = ? AND r.status = 'active'
                AND ({$predicate}){$exclude}
              GROUP BY r.warehouse_id, r.stock_item_id"
        );
        $stmt->execute($params);

        return $this->quantities($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Order sourced fulfillment only represents the remaining hard reservation.
     * Counting it again would reject the order's own picking task and overstate ATP.
     *
     * @param list<array{warehouse_id:int,stock_item_id:int}> $pairs
     * @return array<string,int>
     */
    private function standaloneFulfillmentAllocations(int $supplierId, array $pairs): array
    {
        if (!$this->db->hasTable('fulfillment_tasks') || !$this->db->hasTable('fulfillment_task_lines')) {
            return [];
        }
        [$predicate, $params] = $this->pairPredicate($pairs, 'l');
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.warehouse_id, l.stock_item_id,
                    SUM(GREATEST(l.expected_qty - l.shipped_qty, 0)) AS qty
               FROM fulfillment_task_lines l
               JOIN fulfillment_tasks t ON t.id = l.task_id AND t.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND t.source_type <> 'sales_order'
                AND t.status NOT IN ('shipped', 'cancelled')
                AND ({$predicate})
              GROUP BY l.warehouse_id, l.stock_item_id"
        );
        $stmt->execute([$supplierId, ...$params]);

        return $this->quantities($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<array{warehouse_id:int,stock_item_id:int}> $pairs @return list<array{warehouse_id:int,stock_item_id:int}> */
    private function uniquePairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            $warehouseId = (int) ($pair['warehouse_id'] ?? 0);
            $stockItemId = (int) ($pair['stock_item_id'] ?? 0);
            if ($warehouseId < 1 || $stockItemId < 1) {
                continue;
            }
            $out[$warehouseId . ':' . $stockItemId] = [
                'warehouse_id' => $warehouseId,
                'stock_item_id' => $stockItemId,
            ];
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    /**
     * @param list<array{warehouse_id:int,stock_item_id:int}> $pairs
     * @return array{0:string,1:list<int>}
     */
    private function pairPredicate(array $pairs, string $alias = 'r'): array
    {
        $parts = [];
        $params = [];
        foreach ($pairs as $pair) {
            $parts[] = "({$alias}.warehouse_id = ? AND {$alias}.stock_item_id = ?)";
            $params[] = $pair['warehouse_id'];
            $params[] = $pair['stock_item_id'];
        }
        return [implode(' OR ', $parts), $params];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function quantities(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = (int) $row['warehouse_id'] . ':' . (int) $row['stock_item_id'];
            $out[$key] = StockValuation::qtyToT((string) $row['qty']);
        }
        return $out;
    }
}
