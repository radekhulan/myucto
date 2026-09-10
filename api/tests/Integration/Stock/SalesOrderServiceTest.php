<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\FulfillmentService;
use MyInvoice\Service\Stock\SalesOrderException;
use MyInvoice\Service\Stock\SalesOrderExpiryJobService;
use MyInvoice\Service\Stock\SalesOrderFulfillmentSourceProvider;
use MyInvoice\Service\Stock\SalesOrderInvoiceService;
use MyInvoice\Service\Stock\SalesOrderService;
use MyInvoice\Service\Stock\StockCommitmentService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SalesOrderServiceTest extends StockTestCase
{
    private SalesOrderService $orders;
    private FulfillmentService $fulfillment;
    private SalesOrderInvoiceService $orderInvoices;
    private SalesOrderFulfillmentSourceProvider $fulfillmentSource;
    private StockCommitmentService $commitments;
    private SalesOrderExpiryJobService $expiryJobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = $this->container->get(SalesOrderService::class);
        $this->fulfillment = $this->container->get(FulfillmentService::class);
        $this->orderInvoices = $this->container->get(SalesOrderInvoiceService::class);
        $this->fulfillmentSource = $this->container->get(SalesOrderFulfillmentSourceProvider::class);
        $this->commitments = $this->container->get(StockCommitmentService::class);
        $this->expiryJobs = $this->container->get(SalesOrderExpiryJobService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            foreach ($this->supplierIds as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM fulfillment_tasks WHERE supplier_id = ?')->execute([$supplierId]);
                $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    public function testConfirmationIsIdempotentAndLastAvailableUnitCannotBeOverbooked(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SO-LAST');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '1.000', 20.0, date('Y-m-d', strtotime('-1 day')));

        $first = $this->orders->create($supplierId, $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-1'), $this->userId);
        $second = $this->orders->create($supplierId, $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-2'), $this->userId);

        $confirmed = $this->orders->confirm($supplierId, (int) $first['id'], 'confirm-first');
        $replayed = $this->orders->confirm($supplierId, (int) $first['id'], 'confirm-first');
        self::assertSame('reserved', $confirmed['fulfillment_status']);
        self::assertSame($confirmed['reservations'][0]['id'], $replayed['reservations'][0]['id']);

        try {
            $this->orders->confirm($supplierId, (int) $second['id'], 'confirm-second');
            self::fail('Poslední kus nesmí rezervovat dvě objednávky.');
        } catch (SalesOrderException $e) {
            self::assertSame('insufficient_stock', $e->errorCode);
        }
        self::assertSame(1, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM sales_order_reservations WHERE stock_item_id = ' . $itemId
        )->fetchColumn());
    }

    public function testReservedStockOnlyShipsThroughItsTaskAndPartialShipmentKeepsRemainder(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SO-EXPEDICE');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '3', 20.0, date('Y-m-d', strtotime('-1 day')));
        $order = $this->orders->create($supplierId, $this->draft($supplierId, $this->client($supplierId), $warehouseId, $itemId, 'TEST-SO-EXPEDICE', '3'), $this->userId);
        $order = $this->orders->confirm($supplierId, (int) $order['id'], 'confirm-shipment');
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'issue', 'warehouse_id' => $warehouseId, 'doc_date' => date('Y-m-d'), 'description' => 'Samostatný výdej',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '1']],
        ], $this->userId);
        try {
            $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
            self::fail('Samostatný výdej nesmí spotřebovat rezervovanou zásobu.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame('insufficient_stock', $e->errorCode);
        }
        $fulfillment = $this->container->get(\MyInvoice\Service\Stock\FulfillmentService::class);
        $task = $fulfillment->createTask($supplierId, 'sales_order', $order['order_uuid'], $this->userId);
        $fulfillment->scan($supplierId, $task['id'], [
            'client_operation_id' => 'scan-order', 'code' => 'SO-EXPEDICE', 'quantity' => '2',
        ], $this->userId, false);
        $packed = $fulfillment->createShipment($supplierId, $task['id'], [
            'carrier' => 'Test carrier', 'tracking_number' => 'TEST-TRACKING',
            'items' => [['task_line_id' => $task['lines'][0]['id'], 'quantity' => '2']],
        ], $this->userId);
        $shipmentId = (int) $packed['id'];
        $shipped = $fulfillment->dispatch($supplierId, $shipmentId, $this->userId);
        self::assertSame('partially_shipped', $shipped['status']);
        $remaining = $this->orders->detail($supplierId, $order['id']);
        self::assertSame('active', $remaining['reservations'][0]['status']);
        self::assertSame('1.000', $remaining['reservations'][0]['remaining_qty']);
        self::assertSame(1000, $this->commitments->committedForPairs($supplierId, [['warehouse_id' => $warehouseId, 'stock_item_id' => $itemId]])[$warehouseId . ':' . $itemId]);
    }

    public function testPartialShipmentConsumesOnlyShippedQuantityAndCancellationReleasesRemainder(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SO-PART');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '3.000', 20.0, date('Y-m-d', strtotime('-1 day')));
        $order = $this->orders->create(
            $supplierId,
            $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-PART', '3.000'),
            $this->userId,
        );
        $order = $this->orders->confirm($supplierId, (int) $order['id'], 'confirm-partial');
        $sourceLineId = (string) $order['reservations'][0]['source_line_id'];

        $this->db->pdo()->beginTransaction();
        $this->fulfillmentSource->consumeForShipment($supplierId, (string) $order['order_uuid'], 71001, [[
            'source_line_id' => $sourceLineId,
            'quantity' => '1.000',
        ]]);
        $this->db->pdo()->commit();

        $partiallyShipped = $this->orders->detail($supplierId, (int) $order['id']);
        self::assertSame('partially_fulfilled', $partiallyShipped['fulfillment_status']);
        self::assertSame('1.000', $partiallyShipped['reservations'][0]['qty_consumed']);
        self::assertSame('2.000', $partiallyShipped['reservations'][0]['remaining_qty']);

        $cancelled = $this->orders->cancel($supplierId, (int) $order['id'], 'cancel-remainder');
        self::assertSame('cancelled', $cancelled['commercial_status']);
        self::assertSame('1.000', $cancelled['reservations'][0]['qty_consumed']);
        self::assertSame('2.000', $cancelled['reservations'][0]['qty_released']);
        self::assertSame('0.000', $cancelled['reservations'][0]['remaining_qty']);
    }

    public function testShippingAllPartiallyReservedStockDoesNotCompleteShortOrder(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SO-SHORT');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '1.000', 20.0, date('Y-m-d', strtotime('-1 day')));
        $payload = $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-SHORT', '2.000');
        $payload['allocation_policy'] = 'partial';
        $order = $this->orders->create($supplierId, $payload, $this->userId);
        $order = $this->orders->confirm($supplierId, (int) $order['id'], 'confirm-short');
        self::assertSame('partially_reserved', $order['fulfillment_status']);

        $this->db->pdo()->beginTransaction();
        $this->fulfillmentSource->consumeForShipment($supplierId, (string) $order['order_uuid'], 71002, [[
            'source_line_id' => (string) $order['reservations'][0]['source_line_id'],
            'quantity' => '1.000',
        ]]);
        $this->db->pdo()->commit();

        $afterShipment = $this->orders->detail($supplierId, (int) $order['id']);
        self::assertSame('confirmed', $afterShipment['commercial_status']);
        self::assertSame('partially_fulfilled', $afterShipment['fulfillment_status']);
    }

    public function testPartiallyShippedOrderCanReserveReplenishedRemainderAndReopenItsTask(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SO-REMAINDER');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '5.000', 20.0, date('Y-m-d', strtotime('-1 day')));
        $payload = $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-REMAINDER', '10.000');
        $payload['allocation_policy'] = 'partial';
        $order = $this->orders->create($supplierId, $payload, $this->userId);
        $order = $this->orders->confirm($supplierId, (int) $order['id'], 'confirm-remainder-first');
        self::assertSame('5.000', $order['reservations'][0]['qty_reserved']);

        $task = $this->fulfillment->createTask(
            $supplierId,
            'sales_order',
            (string) $order['order_uuid'],
            $this->userId,
        );
        $taskId = (int) $task['id'];
        $taskLineId = (int) $task['lines'][0]['id'];
        $this->fulfillment->scan($supplierId, $taskId, [
            'client_operation_id' => 'remainder-scan-first',
            'code' => 'SO-REMAINDER',
            'quantity' => '5.000',
        ], $this->userId, false);
        $firstShipment = $this->fulfillment->createShipment($supplierId, $taskId, [
            'carrier' => 'Test Carrier',
            'tracking_number' => 'REMAINDER-1',
            'items' => [['task_line_id' => $taskLineId, 'quantity' => '5.000']],
        ], $this->userId);
        $afterFirstShipment = $this->fulfillment->dispatch($supplierId, (int) $firstShipment['id'], $this->userId);
        self::assertSame('shipped', $afterFirstShipment['status']);
        self::assertSame(0, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);

        $this->receiveStock($supplierId, $warehouseId, $itemId, '5.000', 21.0, date('Y-m-d'));
        $reallocated = $this->orders->confirm($supplierId, (int) $order['id'], 'reserve-remainder-second');
        self::assertSame('partially_fulfilled', $reallocated['fulfillment_status']);
        self::assertSame('10.000', $reallocated['reservations'][0]['qty_reserved']);
        self::assertSame('5.000', $reallocated['reservations'][0]['qty_consumed']);
        self::assertSame('5.000', $reallocated['reservations'][0]['remaining_qty']);
        self::assertSame($taskId, $reallocated['fulfillment_task_id']);
        $reopened = $this->fulfillment->find($supplierId, $taskId);
        self::assertSame('picking', $reopened['status']);
        self::assertSame('10.000', $reopened['lines'][0]['expected_qty']);
        self::assertSame('5.000', $reopened['lines'][0]['picked_qty']);
        self::assertSame('5.000', $reopened['lines'][0]['shipped_qty']);

        $replayed = $this->orders->confirm($supplierId, (int) $order['id'], 'reserve-remainder-second');
        self::assertSame('10.000', $replayed['reservations'][0]['qty_reserved']);
        self::assertSame('10.000', $this->fulfillment->find($supplierId, $taskId)['lines'][0]['expected_qty']);

        $this->fulfillment->scan($supplierId, $taskId, [
            'client_operation_id' => 'remainder-scan-second',
            'code' => 'SO-REMAINDER',
            'quantity' => '5.000',
        ], $this->userId, false);
        $secondShipment = $this->fulfillment->createShipment($supplierId, $taskId, [
            'carrier' => 'Test Carrier',
            'tracking_number' => 'REMAINDER-2',
            'items' => [['task_line_id' => $taskLineId, 'quantity' => '5.000']],
        ], $this->userId);
        $completedTask = $this->fulfillment->dispatch($supplierId, (int) $secondShipment['id'], $this->userId);
        self::assertSame('shipped', $completedTask['status']);
        self::assertCount(2, $completedTask['shipments']);
        self::assertSame('5.000', $completedTask['shipments'][0]['items'][0]['qty']);
        self::assertSame('5.000', $completedTask['shipments'][1]['items'][0]['qty']);

        $completed = $this->orders->detail($supplierId, (int) $order['id']);
        self::assertSame('completed', $completed['commercial_status']);
        self::assertSame('fulfilled', $completed['fulfillment_status']);
        self::assertSame('10.000', $completed['reservations'][0]['qty_consumed']);
        self::assertSame(0, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);
    }

    public function testReplenishedMissingComponentCanBeAddedToTaskAndShipped(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $availableItemId = $this->item($supplierId, 'SO-COMPONENT-A');
        $missingItemId = $this->item($supplierId, 'SO-COMPONENT-B');
        $this->receiveStock($supplierId, $warehouseId, $availableItemId, '1.000', 20.0, date('Y-m-d', strtotime('-1 day')));

        $payload = $this->draft(
            $supplierId,
            $clientId,
            $warehouseId,
            $availableItemId,
            'TEST-SO-REPLENISHED-COMPONENT',
        );
        $payload['allocation_policy'] = 'partial';
        $order = $this->orders->create($supplierId, $payload, $this->userId);
        $line = $order['lines'][0];
        $components = [[
            'stock_item_id' => $availableItemId,
            'warehouse_id' => $warehouseId,
            'quantity' => '1.000',
            'sku' => 'SO-COMPONENT-A',
            'ean' => null,
            'name' => 'Synthetic component A',
            'unit' => 'ks',
        ], [
            'stock_item_id' => $missingItemId,
            'warehouse_id' => $warehouseId,
            'quantity' => '1.000',
            'sku' => 'SO-COMPONENT-B',
            'ean' => null,
            'name' => 'Synthetic component B',
            'unit' => 'ks',
        ]];
        $this->db->pdo()->prepare(
            'UPDATE sales_order_lines SET component_snapshot = ? WHERE supplier_id = ? AND id = ?'
        )->execute([json_encode($components, JSON_THROW_ON_ERROR), $supplierId, $line['id']]);

        $order = $this->orders->confirm($supplierId, (int) $order['id'], 'confirm-first-component');
        self::assertSame('partially_reserved', $order['fulfillment_status']);
        self::assertCount(1, $order['reservations']);
        self::assertSame(0, (int) $order['reservations'][0]['component_no']);

        $task = $this->fulfillment->createTask(
            $supplierId,
            'sales_order',
            (string) $order['order_uuid'],
            $this->userId,
        );
        $taskId = (int) $task['id'];
        $firstTaskLineId = (int) $task['lines'][0]['id'];
        $this->fulfillment->scan($supplierId, $taskId, [
            'client_operation_id' => 'scan-first-component',
            'code' => 'SO-COMPONENT-A',
            'quantity' => '1.000',
        ], $this->userId, false);
        $firstShipment = $this->fulfillment->createShipment($supplierId, $taskId, [
            'carrier' => 'Test Carrier',
            'tracking_number' => 'COMPONENT-1',
            'items' => [['task_line_id' => $firstTaskLineId, 'quantity' => '1.000']],
        ], $this->userId);
        $this->fulfillment->dispatch($supplierId, (int) $firstShipment['id'], $this->userId);

        $this->receiveStock($supplierId, $warehouseId, $missingItemId, '1.000', 21.0, date('Y-m-d'));
        $reallocated = $this->orders->confirm($supplierId, (int) $order['id'], 'reserve-second-component');
        self::assertSame('partially_fulfilled', $reallocated['fulfillment_status']);
        self::assertCount(2, $reallocated['reservations']);

        $reopened = $this->fulfillment->find($supplierId, $taskId);
        self::assertSame('picking', $reopened['status']);
        self::assertCount(2, $reopened['lines']);
        $secondTaskLine = array_values(array_filter(
            $reopened['lines'],
            static fn (array $taskLine): bool => (int) $taskLine['stock_item_id'] === $missingItemId,
        ))[0];
        self::assertSame(
            (string) $line['line_uuid'] . '#1',
            $secondTaskLine['component_snapshot']['source_line_id'] ?? null,
        );

        $this->fulfillment->scan($supplierId, $taskId, [
            'client_operation_id' => 'scan-second-component',
            'code' => 'SO-COMPONENT-B',
            'quantity' => '1.000',
        ], $this->userId, false);
        $secondShipment = $this->fulfillment->createShipment($supplierId, $taskId, [
            'carrier' => 'Test Carrier',
            'tracking_number' => 'COMPONENT-2',
            'items' => [['task_line_id' => (int) $secondTaskLine['id'], 'quantity' => '1.000']],
        ], $this->userId);
        $completedTask = $this->fulfillment->dispatch($supplierId, (int) $secondShipment['id'], $this->userId);

        self::assertSame('shipped', $completedTask['status']);
        self::assertCount(2, $completedTask['shipments']);
        $completedOrder = $this->orders->detail($supplierId, (int) $order['id']);
        self::assertSame('completed', $completedOrder['commercial_status']);
        self::assertSame('fulfilled', $completedOrder['fulfillment_status']);
    }

    public function testInvoiceSnapshotPreservesVatModeAndRetryCreatesOneDraft(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SO-INVOICE');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '1.000', 20.0, date('Y-m-d', strtotime('-1 day')));
        $payload = $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-INVOICE');
        $payload['prices_include_vat'] = true;
        $payload['lines'][0]['unit_price'] = '121.000000';
        $order = $this->orders->create($supplierId, $payload, $this->userId);
        $this->orders->confirm($supplierId, (int) $order['id'], 'confirm-invoice');

        $first = $this->orderInvoices->createDraft($supplierId, (int) $order['id'], $this->userId, 'invoice-first');
        $second = $this->orderInvoices->createDraft($supplierId, (int) $order['id'], $this->userId, 'invoice-replay');
        self::assertSame($first['id'], $second['id']);
        self::assertSame('draft', $first['status']);
        self::assertSame(1, (int) $first['prices_include_vat']);
        $this->issue->assertAvailableForInvoice($supplierId, $first);
        $this->db->pdo()->beginTransaction();
        try {
            $this->issue->issueForInvoice($supplierId, $first, $this->userId);
            self::assertSame([], $this->docsRepo->listByInvoice($supplierId, (int) $first['id']));
        } finally {
            $this->db->pdo()->rollBack();
        }
        self::assertSame(1, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM sales_order_invoice_links WHERE order_id = ' . (int) $order['id']
        )->fetchColumn());
    }

    public function testCommitmentsCountStandaloneFulfillmentButDoNotDoubleCountOrderTask(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SO-COMMITMENT');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '3.000', 20.0, date('Y-m-d', strtotime('-1 day')));
        $order = $this->orders->create(
            $supplierId,
            $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-COMMITMENT', '2.000'),
            $this->userId,
        );
        $order = $this->orders->confirm($supplierId, (int) $order['id'], 'confirm-commitment');
        $pair = [['warehouse_id' => $warehouseId, 'stock_item_id' => $itemId]];

        $this->insertFulfillmentAllocation($supplierId, 'sales_order', (string) $order['order_uuid'], $warehouseId, $itemId, '2.000');
        self::assertSame(2000, $this->commitments->committedForPairs($supplierId, $pair)[$warehouseId . ':' . $itemId]);
        self::assertSame([], $this->commitments->committedForPairs($supplierId, $pair, (string) $order['order_uuid']));

        $this->insertFulfillmentAllocation($supplierId, 'stock_issue_draft', 'synthetic-draft', $warehouseId, $itemId, '1.000');
        self::assertSame(3000, $this->commitments->committedForPairs($supplierId, $pair)[$warehouseId . ':' . $itemId]);
        self::assertSame(1000, $this->commitments->committedForPairs($supplierId, $pair, (string) $order['order_uuid'])[$warehouseId . ':' . $itemId]);

        $second = $this->orders->create($supplierId, $this->draft(
            $supplierId,
            $clientId,
            $warehouseId,
            $itemId,
            'TEST-SO-COMMITMENT-2',
        ), $this->userId);
        try {
            $this->orders->confirm($supplierId, (int) $second['id'], 'confirm-blocked-by-fulfillment');
            self::fail('Rozpracovaná samostatná fulfillment úloha musí blokovat novou hard rezervaci.');
        } catch (SalesOrderException $e) {
            self::assertSame('insufficient_stock', $e->errorCode);
        }
    }

    public function testExpiryJobReportsRealProgressAndReleasesReservation(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SO-EXPIRY');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '2.000', 20.0, date('Y-m-d', strtotime('-1 day')));
        $payload = $this->draft($supplierId, $clientId, $warehouseId, $itemId, 'TEST-SO-EXPIRY');
        $payload['reservation_expires_at'] = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $order = $this->orders->create($supplierId, $payload, $this->userId);
        $this->orders->confirm($supplierId, (int) $order['id'], 'confirm-expiry');
        $payload['order_number'] = 'TEST-SO-EXPIRY-SECOND';
        $second = $this->orders->create($supplierId, $payload, $this->userId);
        $this->orders->confirm($supplierId, (int) $second['id'], 'confirm-expiry-second');

        $queued = $this->expiryJobs->enqueue($supplierId, $this->userId);
        self::assertSame(2, (int) $queued['total']);
        $partial = $this->expiryJobs->runNextBatch($supplierId, 1);
        self::assertSame('queued', $partial['status']);
        $completed = $this->expiryJobs->runNextBatch($supplierId, 1);
        self::assertSame('completed', $completed['status']);
        self::assertSame(2, (int) $completed['checkpoint']);
        self::assertSame(2, (int) $completed['report']['expired']);

        $expired = $this->orders->detail($supplierId, (int) $order['id']);
        self::assertSame('cancelled', $expired['commercial_status']);
        self::assertSame('1.000', $expired['reservations'][0]['qty_released']);
    }

    public function testOrderConversionRequiresInvoiceCreationPermission(): void
    {
        $supplierId = $this->createSupplier();
        $action = $this->container->get(\MyInvoice\Action\Stock\SalesOrderAction::class);
        foreach ([0 => 403, 2 => 404] as $invoicePermission => $status) {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/stock/sales-orders/0/invoice')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(1, 'Syntetická role', 'staff', true, ['stock' => 1, 'stock.orders.write' => 2, 'invoices.create' => $invoicePermission]))
                ->withParsedBody(['idempotency_key' => 'permission-test']);
            self::assertSame($status, $action->invoice($request, new \Slim\Psr7\Response(), ['id' => '0'])->getStatusCode());
        }
    }

    private function insertFulfillmentAllocation(
        int $supplierId,
        string $sourceType,
        string $sourceId,
        int $warehouseId,
        int $itemId,
        string $quantity,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO fulfillment_tasks (supplier_id, source_type, source_id, source_snapshot, created_by)
             VALUES (?, ?, ?, "{}", ?)'
        )->execute([$supplierId, $sourceType, $sourceId, $this->userId]);
        $taskId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO fulfillment_task_lines
                (task_id, supplier_id, source_line_id, stock_item_id, warehouse_id, component_snapshot, expected_qty)
             VALUES (?, ?, ?, ?, ?, "{}", ?)'
        )->execute([$taskId, $supplierId, 'line-' . $taskId, $itemId, $warehouseId, $quantity]);
    }

    private function draft(
        int $supplierId,
        int $clientId,
        int $warehouseId,
        int $itemId,
        string $number,
        string $quantity = '1.000',
    ): array {
        return [
            'client_id' => $clientId,
            'currency_id' => $this->currencyIdFor($supplierId),
            'order_number' => $number,
            'allocation_policy' => 'all_or_nothing',
            'prices_include_vat' => false,
            'lines' => [[
                'stock_item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'unit_price' => '100.000000',
                'vat_rate_id' => $this->vatRateId,
            ]],
        ];
    }
}
