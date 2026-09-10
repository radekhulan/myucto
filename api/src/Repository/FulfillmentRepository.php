<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class FulfillmentRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function history(int $supplierId, int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.supplier_id, t.source_type, t.source_id, t.claimed_stock_document_id,
                    t.source_snapshot, t.status, t.created_by, t.created_at, t.updated_at,
                    COUNT(DISTINCT l.id) AS line_count,
                    COALESCE(SUM(l.expected_qty), 0) AS expected_qty,
                    COALESCE(SUM(l.picked_qty), 0) AS picked_qty,
                    COALESCE(SUM(l.shipped_qty), 0) AS shipped_qty
               FROM fulfillment_tasks t
          LEFT JOIN fulfillment_task_lines l ON l.task_id = t.id AND l.supplier_id = t.supplier_id
              WHERE t.supplier_id = ?
              GROUP BY t.id
              ORDER BY t.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'castTask'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findTask(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, source_type, source_id, claimed_stock_document_id,
                    source_snapshot, status, created_by, created_at, updated_at
               FROM fulfillment_tasks WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $task = self::castTask($row);
        $task['lines'] = $this->taskLines($supplierId, $id);
        $task['shipments'] = $this->shipments($supplierId, $id);
        foreach ($task['shipments'] as &$shipment) {
            $shipment['items'] = $this->shipmentItems($supplierId, (int) $shipment['id']);
            $shipment['returns'] = $this->returns($supplierId, (int) $shipment['id']);
        }
        unset($shipment);
        return $task;
    }

    public function lockTask(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, source_type, source_id, claimed_stock_document_id,
                    source_snapshot, status, created_by, created_at, updated_at
               FROM fulfillment_tasks WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castTask($row);
    }

    public function createTask(int $supplierId, string $sourceType, string $sourceId, ?int $claimedDocumentId, array $snapshot, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO fulfillment_tasks
                (supplier_id, source_type, source_id, claimed_stock_document_id, source_snapshot, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $sourceType, $sourceId, $claimedDocumentId, self::json($snapshot), $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function addTaskLine(int $supplierId, int $taskId, array $line): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO fulfillment_task_lines
                (task_id, supplier_id, source_line_id, stock_item_id, warehouse_id, component_snapshot, expected_qty)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $taskId, $supplierId, (string) $line['source_line_id'], (int) $line['stock_item_id'],
            (int) $line['warehouse_id'], self::json((array) $line['component_snapshot']), (string) $line['expected_qty'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function taskLines(int $supplierId, int $taskId, bool $lock = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, task_id, supplier_id, source_line_id, stock_item_id, warehouse_id,
                    component_snapshot, expected_qty, picked_qty, shipped_qty, returned_qty
               FROM fulfillment_task_lines WHERE supplier_id = ? AND task_id = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $taskId]);
        return array_map([self::class, 'castTaskLine'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<array{warehouse_id:int,stock_item_id:int}> $pairs @return array<string,string> */
    public function activeAllocations(int $supplierId, array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $parts = [];
        $params = [$supplierId];
        foreach ($pairs as $pair) {
            $parts[] = '(l.warehouse_id = ? AND l.stock_item_id = ?)';
            $params[] = $pair['warehouse_id'];
            $params[] = $pair['stock_item_id'];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.warehouse_id, l.stock_item_id, SUM(GREATEST(l.expected_qty - l.shipped_qty, 0)) AS qty
               FROM fulfillment_task_lines l
               JOIN fulfillment_tasks t ON t.id = l.task_id AND t.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND t.status NOT IN ('shipped','cancelled')
                AND (" . implode(' OR ', $parts) . ")
              GROUP BY l.warehouse_id, l.stock_item_id"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['warehouse_id'] . ':' . (int) $row['stock_item_id']] = (string) $row['qty'];
        }
        return $out;
    }

    public function scanOperation(int $supplierId, int $taskId, string $operationId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload_hash, result_json FROM fulfillment_scan_operations
              WHERE supplier_id = ? AND task_id = ? AND client_operation_id = ?'
        );
        $stmt->execute([$supplierId, $taskId, $operationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : [
            'payload_hash' => (string) $row['payload_hash'],
            'result' => self::decode((string) $row['result_json']),
        ];
    }

    public function addScanOperation(
        int $supplierId,
        int $taskId,
        string $operationId,
        string $hash,
        array $payload,
        array $result,
        ?int $userId,
    ): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO fulfillment_scan_operations
                (supplier_id, task_id, client_operation_id, payload_hash, payload_json, result_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $taskId, $operationId, $hash, self::json($payload), self::json($result), $userId]);
    }

    public function setPickedQty(int $supplierId, int $lineId, string $qty): void
    {
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_task_lines SET picked_qty = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$qty, $supplierId, $lineId]);
    }

    public function setTaskStatus(int $supplierId, int $taskId, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_tasks SET status = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$status, $supplierId, $taskId]);
    }

    /** @return array<int,string> */
    public function packingQtyByTaskLine(int $supplierId, int $taskId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT si.task_line_id, SUM(si.qty) AS qty
               FROM fulfillment_shipment_items si
               JOIN fulfillment_shipments s ON s.id = si.shipment_id AND s.supplier_id = si.supplier_id
              WHERE si.supplier_id = ? AND s.task_id = ? AND s.status = 'packing'
              GROUP BY si.task_line_id"
        );
        $stmt->execute([$supplierId, $taskId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['task_line_id']] = (string) $row['qty'];
        }
        return $out;
    }

    public function createShipment(int $supplierId, int $taskId, ?string $carrier, ?string $tracking, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO fulfillment_shipments (supplier_id, task_id, carrier, tracking_number, created_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, $taskId, $carrier, $tracking, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function addShipmentItem(int $supplierId, int $shipmentId, int $taskLineId, string $qty, array $tracking): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO fulfillment_shipment_items
                (shipment_id, supplier_id, task_line_id, qty, tracking_snapshot)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$shipmentId, $supplierId, $taskLineId, $qty, self::json($tracking)]);
    }

    public function lockShipment(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, task_id, carrier, tracking_number, status, issue_document_id,
                    shipped_at, created_by, created_at, updated_at
               FROM fulfillment_shipments WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castShipment($row);
    }

    public function findShipment(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, task_id, carrier, tracking_number, status, issue_document_id,
                    shipped_at, created_by, created_at, updated_at
               FROM fulfillment_shipments WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castShipment($row);
    }

    /** @return list<array<string,mixed>> */
    public function shipments(int $supplierId, int $taskId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, task_id, carrier, tracking_number, status, issue_document_id,
                    shipped_at, created_by, created_at, updated_at
               FROM fulfillment_shipments WHERE supplier_id = ? AND task_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId, $taskId]);
        return array_map([self::class, 'castShipment'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function shipmentItems(int $supplierId, int $shipmentId, bool $lock = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT si.id, si.shipment_id, si.supplier_id, si.task_line_id, si.qty,
                    si.tracking_snapshot, si.issue_document_line_id, si.unit_cost_snapshot, si.returned_qty,
                    l.stock_item_id, l.warehouse_id, l.component_snapshot
               FROM fulfillment_shipment_items si
               JOIN fulfillment_task_lines l ON l.id = si.task_line_id AND l.supplier_id = si.supplier_id
              WHERE si.supplier_id = ? AND si.shipment_id = ? ORDER BY si.id' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $shipmentId]);
        return array_map([self::class, 'castShipmentItem'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function completeShipmentItem(int $supplierId, int $id, int $documentLineId, string $unitCost): void
    {
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_shipment_items SET issue_document_line_id = ?, unit_cost_snapshot = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$documentLineId, $unitCost, $supplierId, $id]);
    }

    public function updateShipmentTrackingSnapshot(int $supplierId, int $shipmentItemId, array $tracking): void
    {
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_shipment_items SET tracking_snapshot = ? WHERE supplier_id = ? AND id = ?'
        )->execute([self::json($tracking), $supplierId, $shipmentItemId]);
    }

    /** @return list<array{quantity:string,tracking_allocations:list<array<string,mixed>>}> */
    public function returnedTrackingSnapshots(int $supplierId, int $shipmentItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ri.qty, ri.component_snapshot
               FROM fulfillment_return_items ri
               JOIN fulfillment_returns r
                 ON r.id = ri.return_id AND r.supplier_id = ri.supplier_id
               JOIN fulfillment_shipment_items si
                 ON si.id = ri.shipment_item_id AND si.supplier_id = ri.supplier_id
              WHERE ri.supplier_id = ? AND ri.shipment_item_id = ?
              ORDER BY ri.id'
        );
        $stmt->execute([$supplierId, $shipmentItemId]);
        $snapshots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $snapshot = self::decode((string) $row['component_snapshot']);
            $allocations = [];
            foreach ((array) ($snapshot['tracking_allocations'] ?? []) as $allocation) {
                if (is_array($allocation)) {
                    $allocations[] = $allocation;
                }
            }
            $snapshots[] = [
                'quantity' => (string) $row['qty'],
                'tracking_allocations' => $allocations,
            ];
        }
        return $snapshots;
    }

    public function markShipmentShipped(int $supplierId, int $id, int $documentId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE fulfillment_shipments SET status = 'shipped', issue_document_id = ?, shipped_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND id = ? AND status = 'packing'"
        )->execute([$documentId, $supplierId, $id]);
    }

    public function addTaskLineShipped(int $supplierId, int $lineId, string $qty): void
    {
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_task_lines SET shipped_qty = shipped_qty + ? WHERE supplier_id = ? AND id = ?'
        )->execute([$qty, $supplierId, $lineId]);
    }

    public function createReturn(int $supplierId, int $shipmentId, string $disposition, ?int $warehouseId, ?string $note, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO fulfillment_returns
                (supplier_id, shipment_id, disposition, warehouse_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $shipmentId, $disposition, $warehouseId, $note, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function addReturnItem(int $supplierId, int $returnId, int $shipmentItemId, string $qty, array $snapshot): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO fulfillment_return_items
                (return_id, supplier_id, shipment_item_id, qty, component_snapshot)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$returnId, $supplierId, $shipmentItemId, $qty, self::json($snapshot)]);
    }

    public function attachReturnDocument(int $supplierId, int $returnId, int $documentId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_returns SET receipt_document_id = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$documentId, $supplierId, $returnId]);
    }

    public function addReturnedQty(int $supplierId, int $shipmentItemId, int $taskLineId, string $qty): void
    {
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_shipment_items SET returned_qty = returned_qty + ? WHERE supplier_id = ? AND id = ?'
        )->execute([$qty, $supplierId, $shipmentItemId]);
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_task_lines SET returned_qty = returned_qty + ? WHERE supplier_id = ? AND id = ?'
        )->execute([$qty, $supplierId, $taskLineId]);
    }

    /** @return list<array<string,mixed>> */
    public function returns(int $supplierId, int $shipmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, shipment_id, disposition, warehouse_id, receipt_document_id,
                    note, received_at, created_by
               FROM fulfillment_returns WHERE supplier_id = ? AND shipment_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId, $shipmentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = (int) $row['supplier_id'];
            $row['shipment_id'] = (int) $row['shipment_id'];
            $row['warehouse_id'] = $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null;
            $row['receipt_document_id'] = $row['receipt_document_id'] !== null ? (int) $row['receipt_document_id'] : null;
            $row['created_by'] = $row['created_by'] !== null ? (int) $row['created_by'] : null;
            $row['items'] = $this->returnItems($supplierId, (int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    private function returnItems(int $supplierId, int $returnId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, return_id, supplier_id, shipment_item_id, qty, component_snapshot
               FROM fulfillment_return_items WHERE supplier_id = ? AND return_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId, $returnId]);
        return array_map(static function (array $row): array {
            foreach (['id', 'return_id', 'supplier_id', 'shipment_item_id'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['qty'] = (string) $row['qty'];
            $row['component_snapshot'] = self::decode((string) $row['component_snapshot']);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function castTask(array $row): array
    {
        foreach (['id', 'supplier_id', 'claimed_stock_document_id', 'created_by', 'line_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
            }
        }
        $row['source_id'] = (string) $row['source_id'];
        foreach (['expected_qty', 'picked_qty', 'shipped_qty'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (string) $row[$key];
            }
        }
        $row['source_snapshot'] = self::decode((string) $row['source_snapshot']);
        return $row;
    }

    private static function castTaskLine(array $row): array
    {
        foreach (['id', 'task_id', 'supplier_id', 'stock_item_id', 'warehouse_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['source_line_id'] = (string) $row['source_line_id'];
        foreach (['expected_qty', 'picked_qty', 'shipped_qty', 'returned_qty'] as $key) {
            $row[$key] = (string) $row[$key];
        }
        $row['component_snapshot'] = self::decode((string) $row['component_snapshot']);
        return $row;
    }

    private static function castShipment(array $row): array
    {
        foreach (['id', 'supplier_id', 'task_id', 'issue_document_id', 'created_by'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        return $row;
    }

    private static function castShipmentItem(array $row): array
    {
        foreach (['id', 'shipment_id', 'supplier_id', 'task_line_id', 'issue_document_line_id', 'stock_item_id', 'warehouse_id'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        foreach (['qty', 'unit_cost_snapshot', 'returned_qty'] as $key) {
            $row[$key] = $row[$key] !== null ? (string) $row[$key] : null;
        }
        $row['tracking_snapshot'] = self::decode((string) $row['tracking_snapshot']);
        $row['component_snapshot'] = self::decode((string) $row['component_snapshot']);
        return $row;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
}
