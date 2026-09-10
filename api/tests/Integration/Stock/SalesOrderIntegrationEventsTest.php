<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\SalesOrderExpiryJobService;
use MyInvoice\Service\Stock\SalesOrderFulfillmentSourceProvider;
use MyInvoice\Service\Stock\SalesOrderInvoiceService;
use MyInvoice\Service\Stock\SalesOrderService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SalesOrderIntegrationEventsTest extends StockTestCase
{
    private SalesOrderService $orders;
    private SalesOrderInvoiceService $invoices;
    private SalesOrderFulfillmentSourceProvider $fulfillment;
    private SalesOrderExpiryJobService $expiryJobs;
    /** @var list<int> */
    private array $orderSupplierIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = $this->container->get(SalesOrderService::class);
        $this->invoices = $this->container->get(SalesOrderInvoiceService::class);
        $this->fulfillment = $this->container->get(SalesOrderFulfillmentSourceProvider::class);
        $this->expiryJobs = $this->container->get(SalesOrderExpiryJobService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            foreach ($this->orderSupplierIds as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    public function testLifecyclePublishesOrderedIdempotentEventsAndReservationChanges(): void
    {
        [$supplierId, $clientId, $warehouseId, $itemId] = $this->fixtures('EVENT-LIFECYCLE');
        $order = $this->orders->create($supplierId, $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-EVENT-LIFECYCLE'), $this->userId);
        $update = $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-EVENT-LIFECYCLE');
        $update['lines'][0]['line_uuid'] = $order['lines'][0]['line_uuid'];
        $order = $this->orders->update($supplierId, (int) $order['id'], 1, $update);
        $order = $this->orders->confirm($supplierId, (int) $order['id'], 'event-confirm');
        $this->orders->confirm($supplierId, (int) $order['id'], 'event-confirm');
        $order = $this->orders->setPaymentStatus($supplierId, (int) $order['id'], 'paid', 3);
        $this->orders->cancel($supplierId, (int) $order['id'], 'event-cancel');
        $this->orders->cancel($supplierId, (int) $order['id'], 'event-cancel');

        $events = $this->events($supplierId, (string) $order['order_uuid']);
        self::assertSame(
            ['sales_order.created', 'sales_order.updated', 'sales_order.confirmed', 'sales_order.payment_status_changed', 'sales_order.cancelled'],
            array_column($events, 'event_type'),
        );
        self::assertSame([1, 2, 3, 4, 5], array_column($events, 'aggregate_version'));
        self::assertSame('1.000', $events[2]['payload']['reservations'][0]['quantity']);
        self::assertSame('1.000', $events[4]['payload']['released_reservations'][0]['quantity']);

        $expiring = $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-EVENT-EXPIRY');
        $expiring['reservation_expires_at'] = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $expiredOrder = $this->orders->create($supplierId, $expiring, $this->userId);
        $this->orders->confirm($supplierId, (int) $expiredOrder['id'], 'event-expiry-confirm');
        $this->expiryJobs->enqueue($supplierId, $this->userId);
        $this->expiryJobs->runNextBatch($supplierId);
        $this->orders->expire($supplierId, (int) $expiredOrder['id']);

        self::assertSame(
            ['sales_order.created', 'sales_order.confirmed', 'sales_order.expired'],
            array_column($this->events($supplierId, (string) $expiredOrder['order_uuid']), 'event_type'),
        );
        self::assertSame([1, 2, 3], array_column($this->events($supplierId, (string) $expiredOrder['order_uuid']), 'aggregate_version'));
        self::assertSame(4, $this->reservationChanges($supplierId));
    }

    public function testInvoiceReturnAndShipmentUseNewVersionsAndShipmentRollbackRemovesHooks(): void
    {
        [$supplierId, $clientId, $warehouseId, $itemId] = $this->fixtures('EVENT-SHIPMENT');
        $order = $this->orders->create($supplierId, $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-EVENT-SHIPMENT'), $this->userId);
        $order = $this->orders->confirm($supplierId, (int) $order['id'], 'event-shipment-confirm');
        $invoice = $this->invoices->createDraft($supplierId, (int) $order['id'], $this->userId, 'event-invoice');
        self::assertSame($invoice['id'], $this->invoices->createDraft($supplierId, (int) $order['id'], $this->userId, 'event-invoice')['id']);
        $return = $this->orders->createReturn($supplierId, (int) $order['id'], [
            'commercial_resolution' => 'refund',
            'physical_resolution' => 'none',
            'lines' => [['line_uuid' => $order['lines'][0]['line_uuid'], 'quantity' => '1.000']],
        ], $this->userId);

        $sourceLineId = (string) $order['reservations'][0]['source_line_id'];
        $beforeEvents = count($this->events($supplierId, (string) $order['order_uuid']));
        $beforeChanges = $this->reservationChanges($supplierId);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->fulfillment->consumeForShipment($supplierId, (string) $order['order_uuid'], 81001, [[
            'source_line_id' => $sourceLineId,
            'quantity' => '1.000',
        ]]);
        self::assertSame($beforeEvents + 1, count($this->events($supplierId, (string) $order['order_uuid'])));
        self::assertSame($beforeChanges + 1, $this->reservationChanges($supplierId));
        $pdo->rollBack();

        self::assertSame($beforeEvents, count($this->events($supplierId, (string) $order['order_uuid'])));
        self::assertSame($beforeChanges, $this->reservationChanges($supplierId));
        $afterRollback = $this->orders->detail($supplierId, (int) $order['id']);
        self::assertSame(4, $afterRollback['row_version']);
        self::assertSame('0.000', $afterRollback['reservations'][0]['qty_consumed']);

        $pdo->beginTransaction();
        $this->fulfillment->consumeForShipment($supplierId, (string) $order['order_uuid'], 81001, [[
            'source_line_id' => $sourceLineId,
            'quantity' => '1.000',
        ]]);
        $pdo->commit();
        $pdo->beginTransaction();
        $this->fulfillment->consumeForShipment($supplierId, (string) $order['order_uuid'], 81001, [[
            'source_line_id' => $sourceLineId,
            'quantity' => '1.000',
        ]]);
        $pdo->commit();

        $events = $this->events($supplierId, (string) $order['order_uuid']);
        self::assertSame(
            ['sales_order.created', 'sales_order.confirmed', 'sales_order.invoice_requested', 'sales_order.return_requested', 'sales_order.shipped'],
            array_column($events, 'event_type'),
        );
        self::assertSame([1, 2, 3, 4, 5], array_column($events, 'aggregate_version'));
        self::assertSame($invoice['id'], $events[2]['payload']['invoice_id']);
        self::assertSame($return['return_uuid'], $events[3]['payload']['return_uuid']);
        self::assertSame(81001, $events[4]['payload']['shipment_id']);
        self::assertSame('1.000', $events[4]['payload']['consumed_reservations'][0]['quantity']);
        self::assertSame($beforeChanges + 1, $this->reservationChanges($supplierId));
        self::assertSame('1.000', $this->orders->detail($supplierId, (int) $order['id'])['reservations'][0]['qty_consumed']);
    }

    /** @return array{int,int,int,int} */
    private function fixtures(string $sku): array
    {
        $supplierId = $this->createSupplier();
        $this->orderSupplierIds[] = $supplierId;
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, $sku);
        $this->receiveStock($supplierId, $warehouseId, $itemId, '4.000', 20.0, date('Y-m-d', strtotime('-1 day')));
        $uuid = '00000000-0000-4000-8000-' . str_pad((string) $supplierId, 12, '0', STR_PAD_LEFT);
        $this->db->pdo()->prepare(
            'INSERT INTO integration_connections (connection_uuid, supplier_id, connector_key, name, status)
             VALUES (?, ?, "synthetic", "Synthetic order events", "active")'
        )->execute([$uuid, $supplierId]);
        return [$supplierId, $clientId, $warehouseId, $itemId];
    }

    /** @return list<array<string,mixed>> */
    private function events(int $supplierId, string $orderUuid): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT event_type, aggregate_version, payload_json FROM integration_outbox
              WHERE supplier_id = ? AND entity_type = "sales_order" AND entity_id = ?
              ORDER BY aggregate_version, id'
        );
        $stmt->execute([$supplierId, $orderUuid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['aggregate_version'] = (int) $row['aggregate_version'];
            $row['payload'] = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return $rows;
    }

    private function reservationChanges(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM integration_change_log WHERE supplier_id = ? AND source_area = "reservation"'
        );
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function draft(int $supplierId, int $clientId, int $warehouseId, int $itemId, string $number): array
    {
        return [
            'client_id' => $clientId,
            'currency_id' => $this->currencyIdFor($supplierId),
            'order_number' => $number,
            'allocation_policy' => 'all_or_nothing',
            'prices_include_vat' => false,
            'lines' => [[
                'stock_item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'quantity' => '1.000',
                'unit_price' => '100.000000',
                'vat_rate_id' => $this->vatRateId,
            ]],
        ];
    }
}
