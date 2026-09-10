<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Integration\IntegrationEventPublisher;
use PDO;

final class SalesOrderFulfillmentSourceProvider implements FulfillmentSourceProvider, FulfillmentSourceShipmentConsumer
{
    public function __construct(
        private readonly Connection $db,
        private readonly SalesOrderService $orders,
        private readonly IntegrationEventPublisher $events,
    ) {}

    public function type(): string
    {
        return 'sales_order';
    }

    public function loadForClaim(int $supplierId, string $sourceId): array
    {
        $lock = $this->db->pdo()->prepare('SELECT id FROM sales_orders WHERE supplier_id = ? AND order_uuid = ? FOR UPDATE');
        $lock->execute([$supplierId, $sourceId]);
        if ($lock->fetchColumn() === false) throw new StockException('fulfillment_source_not_found', 'Objednávka nenalezena.', 404);
        $order = $this->orders->detail($supplierId, $sourceId);
        if ($order === null) throw new StockException('fulfillment_source_not_found', 'Objednávka nenalezena.', 404);
        if (!in_array($order['commercial_status'], ['confirmed', 'completed'], true)
            || in_array($order['fulfillment_status'], ['cancelled', 'fulfilled'], true)) {
            throw new StockException('fulfillment_source_state', 'Objednávku v tomto stavu nelze vychystat.', 409);
        }
        $lineById = array_column($order['lines'], null, 'id');
        $lines = [];
        foreach ($order['reservations'] as $reservation) {
            if (bccomp((string) $reservation['remaining_qty'], '0', 3) <= 0) continue;
            $line = $lineById[$reservation['order_line_id']] ?? null;
            if ($line === null) continue;
            $component = $line['component_snapshot'][$reservation['component_no']] ?? [];
            $lines[] = [
                'source_line_id' => (string) $reservation['source_line_id'],
                'stock_item_id' => (int) $reservation['stock_item_id'],
                'warehouse_id' => (int) $reservation['warehouse_id'],
                'expected_qty' => (string) $reservation['remaining_qty'],
                'component_snapshot' => $component + [
                    'source_line_id' => (string) $reservation['source_line_id'],
                    'sales_order_line_uuid' => (string) $line['line_uuid'],
                    'description' => (string) $line['description'],
                    'sku' => (string) ($line['sku_snapshot'] ?? ''),
                ],
            ];
        }
        if ($lines === []) throw new StockException('fulfillment_source_empty', 'Objednávka nemá aktivní rezervaci k vychystání.', 409);
        $document = $this->db->pdo()->prepare(
            'SELECT stock_document_id FROM sales_order_fulfillment_links
              WHERE supplier_id = ? AND order_id = ? AND stock_document_id IS NOT NULL ORDER BY shipment_id DESC LIMIT 1'
        );
        $document->execute([$supplierId, $order['id']]);
        $documentId = $document->fetchColumn();

        return [
            'source_snapshot' => [
                'order_uuid' => (string) $order['order_uuid'],
                'order_number' => (string) $order['order_number'],
                'client_id' => (int) $order['client_id'],
                'client_name' => (string) $order['client_name'],
                'commercial_status' => (string) $order['commercial_status'],
                'payment_status' => (string) $order['payment_status'],
                'fulfillment_status' => (string) $order['fulfillment_status'],
                'currency_code' => (string) $order['currency_code'],
                'prices_include_vat' => (bool) $order['prices_include_vat'],
                'customer_snapshot' => $order['customer_snapshot'],
                'shipping_snapshot' => $order['shipping_snapshot'],
            ],
            'lines' => $lines,
            'claimed_stock_document_id' => $documentId !== false ? (int) $documentId : null,
        ];
    }

    public function consumeForShipment(
        int $supplierId,
        string $sourceId,
        int $shipmentId,
        array $lineQuantities,
    ): void {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new StockException('no_transaction', 'Spotřeba rezervace vyžaduje transakci.', 500);
        $order = $pdo->prepare('SELECT id, order_uuid, commercial_status, row_version FROM sales_orders WHERE supplier_id = ? AND order_uuid = ? FOR UPDATE');
        $order->execute([$supplierId, $sourceId]);
        $header = $order->fetch(PDO::FETCH_ASSOC);
        if ($header === false) throw new StockException('fulfillment_source_not_found', 'Objednávka nenalezena.', 404);
        if ($header['commercial_status'] === 'cancelled') throw new StockException('fulfillment_source_state', 'Zrušenou objednávku nelze expedovat.', 409);
        $normalized = [];
        foreach ($lineQuantities as $line) {
            $sourceLineId = (string) ($line['source_line_id'] ?? '');
            $quantity = self::quantity((string) ($line['quantity'] ?? '0'));
            if (isset($normalized[$sourceLineId])) throw new StockException('fulfillment_line_duplicate', 'Řádek zásilky je uveden vícekrát.', 422);
            $normalized[$sourceLineId] = $quantity;
        }
        ksort($normalized);
        $payload = json_encode($normalized, JSON_THROW_ON_ERROR);
        $existing = $pdo->prepare('SELECT consumed_json FROM sales_order_fulfillment_links WHERE supplier_id = ? AND order_id = ? AND shipment_id = ? FOR UPDATE');
        $existing->execute([$supplierId, $header['id'], $shipmentId]);
        $existingPayload = $existing->fetchColumn();
        if ($existingPayload !== false) {
            if ((string) $existingPayload !== $payload) throw new StockException('fulfillment_idempotency_conflict', 'Zásilka už spotřebovala jiná množství.', 409);
            return;
        }

        $find = $pdo->prepare(
            "SELECT r.* FROM sales_order_reservations r
               JOIN sales_order_lines l ON l.id = r.order_line_id AND l.supplier_id = r.supplier_id
              WHERE r.supplier_id = ? AND r.order_id = ? AND CONCAT(l.line_uuid, '#', r.component_no) = ? FOR UPDATE"
        );
        $update = $pdo->prepare(
            "UPDATE sales_order_reservations SET status = IF(qty_reserved - (qty_consumed + ?) - qty_released <= 0, 'consumed', 'active'),
                    qty_consumed = qty_consumed + ?
              WHERE supplier_id = ? AND id = ?"
        );
        $changedItemIds = [];
        $consumedLines = [];
        foreach ($normalized as $sourceLineId => $quantity) {
            $find->execute([$supplierId, $header['id'], $sourceLineId]);
            $reservation = $find->fetch(PDO::FETCH_ASSOC);
            if ($reservation === false) throw new StockException('fulfillment_line_not_found', 'Řádek nepatří aktivní objednávce.', 422);
            $remaining = bcsub(bcsub((string) $reservation['qty_reserved'], (string) $reservation['qty_consumed'], 3), (string) $reservation['qty_released'], 3);
            if (bccomp($quantity, $remaining, 3) > 0) throw new StockException('fulfillment_quantity_exceeded', 'Zásilka překračuje zbývající rezervaci.', 409);
            $update->execute([$quantity, $quantity, $supplierId, $reservation['id']]);
            $changedItemIds[(int) $reservation['stock_item_id']] = true;
            $consumedLines[] = [
                'source_line_id' => $sourceLineId,
                'stock_item_id' => (int) $reservation['stock_item_id'],
                'warehouse_id' => (int) $reservation['warehouse_id'],
                'quantity' => $quantity,
            ];
        }
        $pdo->prepare(
            'INSERT INTO sales_order_fulfillment_links (supplier_id, order_id, shipment_id, consumed_json, stock_document_id)
             VALUES (?, ?, ?, ?, (SELECT issue_document_id FROM fulfillment_shipments WHERE supplier_id = ? AND id = ?))'
        )->execute([$supplierId, $header['id'], $shipmentId, $payload, $supplierId, $shipmentId]);

        $lines = $pdo->prepare(
            'SELECT line_uuid, component_snapshot FROM sales_order_lines
              WHERE supplier_id = ? AND order_id = ? ORDER BY line_no, id'
        );
        $lines->execute([$supplierId, $header['id']]);
        $consumed = $pdo->prepare(
            'SELECT CONCAT(l.line_uuid, "#", r.component_no) source_line_id, r.qty_consumed
               FROM sales_order_reservations r
               JOIN sales_order_lines l ON l.id = r.order_line_id AND l.supplier_id = r.supplier_id
              WHERE r.supplier_id = ? AND r.order_id = ?'
        );
        $consumed->execute([$supplierId, $header['id']]);
        $consumedByLine = [];
        $anyConsumed = false;
        foreach ($consumed->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $consumedByLine[(string) $row['source_line_id']] = (string) $row['qty_consumed'];
            $anyConsumed = $anyConsumed || bccomp((string) $row['qty_consumed'], '0', 3) > 0;
        }
        $fulfilled = $anyConsumed;
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) ?: [] as $line) {
            $components = json_decode((string) $line['component_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            foreach (is_array($components) ? $components : [] as $componentNo => $component) {
                $required = (string) ($component['quantity'] ?? '0');
                $sourceLineId = (string) $line['line_uuid'] . '#' . $componentNo;
                if (bccomp($consumedByLine[$sourceLineId] ?? '0', $required, 3) < 0) {
                    $fulfilled = false;
                }
            }
        }
        $pdo->prepare(
            'UPDATE sales_orders SET fulfillment_status = ?, commercial_status = IF(?, "completed", commercial_status),
                    completed_at = IF(?, NOW(), completed_at), row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$fulfilled ? 'fulfilled' : 'partially_fulfilled', (int) $fulfilled, (int) $fulfilled, $supplierId, $header['id']]);
        foreach (array_keys($changedItemIds) as $itemId) {
            $this->events->catalogChanged($supplierId, $itemId, 'reservation');
        }
        $version = (int) $header['row_version'] + 1;
        $this->events->publish(
            $supplierId,
            'sales_order',
            (string) $header['order_uuid'],
            'sales_order.shipped',
            $version,
            [
                'order_uuid' => (string) $header['order_uuid'],
                'order_id' => (int) $header['id'],
                'row_version' => $version,
                'shipment_id' => $shipmentId,
                'commercial_status' => $fulfilled ? 'completed' : (string) $header['commercial_status'],
                'fulfillment_status' => $fulfilled ? 'fulfilled' : 'partially_fulfilled',
                'consumed_reservations' => $consumedLines,
            ],
            'sales_order:' . $header['order_uuid'] . ':shipment:' . $shipmentId,
        );
    }

    private static function quantity(string $value): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,3})?$/D', $value) || bccomp($value, '0', 3) <= 0) {
            throw new StockException('fulfillment_quantity_invalid', 'Množství zásilky není platné.', 422);
        }
        return number_format((float) $value, 3, '.', '');
    }
}
