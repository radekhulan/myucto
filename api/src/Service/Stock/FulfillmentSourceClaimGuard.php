<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;

final class FulfillmentSourceClaimGuard
{
    public function __construct(private readonly Connection $db) {}

    public function bindShipmentDocument(int $supplierId, int $shipmentId, int $documentId): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Expedice vyžaduje transakci.');
        $query = $pdo->prepare('SELECT s.task_id, s.status, s.issue_document_id, t.status AS task_status
            FROM fulfillment_shipments s JOIN fulfillment_tasks t ON t.id = s.task_id AND t.supplier_id = s.supplier_id
            WHERE s.supplier_id = ? AND s.id = ? FOR UPDATE');
        $query->execute([$supplierId, $shipmentId]);
        $shipment = $query->fetch(\PDO::FETCH_ASSOC);
        if ($shipment === false || $shipment['status'] !== 'packing' || $shipment['issue_document_id'] !== null
            || !in_array($shipment['task_status'], ['picking', 'packing', 'partially_shipped'], true)) {
            throw new StockException('fulfillment_shipment_state', 'Zásilka není připravena k výdeji.', 409);
        }
        $query = $pdo->prepare('SELECT status, doc_type, warehouse_id FROM stock_documents WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $query->execute([$supplierId, $documentId]);
        $document = $query->fetch(\PDO::FETCH_ASSOC);
        if ($document === false || $document['status'] !== 'draft' || $document['doc_type'] !== 'issue') {
            throw new StockException('fulfillment_shipment_document', 'Expedice vyžaduje nový koncept výdejky.', 409);
        }
        $query = $pdo->prepare('SELECT l.stock_item_id, i.qty, l.warehouse_id, l.expected_qty, l.shipped_qty FROM fulfillment_shipment_items i
            JOIN fulfillment_task_lines l ON l.id = i.task_line_id AND l.supplier_id = i.supplier_id
            WHERE i.supplier_id = ? AND i.shipment_id = ? ORDER BY i.id FOR UPDATE');
        $query->execute([$supplierId, $shipmentId]);
        $items = $query->fetchAll(\PDO::FETCH_ASSOC);
        $query = $pdo->prepare('SELECT stock_item_id, qty FROM stock_document_lines WHERE supplier_id = ? AND document_id = ? ORDER BY line_no, id FOR UPDATE');
        $query->execute([$supplierId, $documentId]);
        $lines = $query->fetchAll(\PDO::FETCH_ASSOC);
        if ($items === [] || count($items) !== count($lines)) throw new StockException('fulfillment_shipment_document', 'Výdejka neodpovídá zásilce.', 409);
        $allowance = [];
        foreach ($items as $index => $item) {
            if ((int) $item['warehouse_id'] !== (int) $document['warehouse_id']
                || (int) $item['stock_item_id'] !== (int) $lines[$index]['stock_item_id']
                || StockValuation::qtyToT($item['qty']) !== StockValuation::qtyToT($lines[$index]['qty'])) {
                throw new StockException('fulfillment_shipment_document', 'Řádky výdejky neodpovídají zásilce.', 409);
            }
            $key = $item['warehouse_id'] . ':' . $item['stock_item_id'];
            $outstandingT = max(
                0,
                StockValuation::qtyToT($item['expected_qty']) - StockValuation::qtyToT($item['shipped_qty']),
            );
            $allowance[$key] = ($allowance[$key] ?? 0) + min(StockValuation::qtyToT($item['qty']), $outstandingT);
        }
        $pdo->prepare('UPDATE fulfillment_shipments SET issue_document_id = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$documentId, $supplierId, $shipmentId]);
        return $allowance;
    }

    public function assertMutable(int $supplierId, int $documentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM fulfillment_tasks
              WHERE supplier_id = ? AND claimed_stock_document_id = ? AND status <> ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $documentId, 'cancelled']);
        $taskId = $stmt->fetchColumn();
        if ($taskId !== false) {
            throw new StockException(
                'fulfillment_source_claimed',
                'Výdejní koncept převzalo vychystání a nelze jej upravit, smazat ani zaúčtovat samostatně.',
                409,
                ['fulfillment_task_id' => (int) $taskId],
            );
        }
    }

    public function assertReversible(int $supplierId, int $documentId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM fulfillment_shipments WHERE supplier_id = ? AND issue_document_id = ?
            UNION ALL SELECT id FROM fulfillment_returns WHERE supplier_id = ? AND receipt_document_id = ? LIMIT 1');
        $stmt->execute([$supplierId, $documentId, $supplierId, $documentId]);
        if ($stmt->fetchColumn() !== false) {
            throw new StockException('fulfillment_document_linked', 'Doklad je součástí expedice nebo vratky a nelze jej stornovat samostatně.', 409);
        }
    }
}
