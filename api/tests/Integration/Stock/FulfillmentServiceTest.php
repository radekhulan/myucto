<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\FulfillmentService;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class FulfillmentServiceTest extends StockTestCase
{
    private FulfillmentService $fulfillment;
    /** @var list<int> */
    private array $taskIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fulfillment = $this->container->get(FulfillmentService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach ($this->taskIds as $taskId) {
                $this->db->pdo()->prepare('DELETE FROM fulfillment_tasks WHERE id = ?')->execute([$taskId]);
            }
        }
        parent::tearDown();
    }

    public function testClaimIsAtomicBlocksSourcePostingAndReservesAvailableStock(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'FUL-CLAIM');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '2.000', 10.0, date('Y-m-d', strtotime('-1 day')));

        $source = $this->issueDraft($supplierId, $warehouseId, $itemId, '2.000');
        $task = $this->createTask($supplierId, (int) $source['id']);
        self::assertSame('picking', $task['status']);
        self::assertSame('2.000', $task['lines'][0]['expected_qty']);

        try {
            $this->documents->post($supplierId, (int) $source['id'], $this->userId);
            self::fail('Převzatý zdrojový draft nesmí jít zaúčtovat vedle zásilek.');
        } catch (StockException $e) {
            self::assertSame('fulfillment_source_claimed', $e->errorCode);
        }

        $secondSource = $this->issueDraft($supplierId, $warehouseId, $itemId, '1.000');
        try {
            $this->fulfillment->createTask($supplierId, 'stock_issue_draft', (string) $secondSource['id'], $this->userId);
            self::fail('Aktivní alokace musí bránit nadprodeji.');
        } catch (StockException $e) {
            self::assertSame('fulfillment_insufficient_available', $e->errorCode);
        }
    }

    public function testInvoiceReferencesSurviveShipmentAndPhysicalReturn(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'FUL-INVOICE');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '3.000', 10.0, date('Y-m-d', strtotime('-1 day')));
        $invoiceId = $this->invoiceDraft($supplierId, $this->client($supplierId));
        $invoiceItemId = $this->invoiceItem($invoiceId, $itemId, $warehouseId, '3.000');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);
        $source = $this->documents->create($supplierId, [
            'doc_type' => 'issue', 'warehouse_id' => $warehouseId, 'doc_date' => date('Y-m-d'),
            'description' => 'Syntetická expedice k faktuře',
            'invoice_id' => $invoiceId,
            'lines' => [['stock_item_id' => $itemId, 'qty' => '3.000', 'invoice_item_id' => $invoiceItemId]],
        ], $this->userId);
        $task = $this->createTask($supplierId, (int) $source['id']);
        $this->fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'invoice-scan', 'code' => 'FUL-INVOICE', 'quantity' => '2.000',
        ], $this->userId, false);
        $shipment = $this->fulfillment->createShipment($supplierId, (int) $task['id'], [
            'carrier' => 'Test Carrier', 'tracking_number' => 'INVOICE-TRACK',
            'items' => [['task_line_id' => (int) $task['lines'][0]['id'], 'quantity' => '2.000']],
        ], $this->userId);
        $task = $this->fulfillment->dispatch($supplierId, (int) $shipment['id'], $this->userId);
        $shipment = $task['shipments'][0];
        $issue = $this->docsRepo->findWithLines($supplierId, (int) $shipment['issue_document_id']);
        self::assertSame($invoiceId, (int) $issue['invoice_id']);
        self::assertSame($invoiceItemId, (int) $issue['lines'][0]['invoice_item_id']);
        $transit = $this->container->get(\MyInvoice\Repository\InTransitRepository::class);
        self::assertSame('1.000', $transit->reservedForItems($supplierId, [$itemId])[0]['qty_reserved']);
        $return = $this->fulfillment->receiveReturn($supplierId, (int) $shipment['id'], [
            'disposition' => 'sellable', 'warehouse_id' => $warehouseId,
            'items' => [['shipment_item_id' => (int) $shipment['items'][0]['id'], 'quantity' => '1.000']],
        ], $this->userId);
        $receipt = $this->docsRepo->findWithLines($supplierId, (int) $return['receipt_document_id']);
        self::assertSame($invoiceId, (int) $receipt['invoice_id']);
        self::assertSame($invoiceItemId, (int) $receipt['lines'][0]['invoice_item_id']);
        self::assertSame('2.000', $transit->reservedForItems($supplierId, [$itemId])[0]['qty_reserved']);
        $creditId = $this->invoiceDraft($supplierId, (int) $this->db->pdo()->query('SELECT client_id FROM invoices WHERE id = ' . $invoiceId)->fetchColumn(), 'credit_note', ['parent_invoice_id' => $invoiceId]);
        $this->invoiceItem($creditId, $itemId, $warehouseId, '1.000');
        $this->db->pdo()->beginTransaction();
        try {
            $this->issue->returnForCreditNote($supplierId, ['id' => $creditId, 'parent_invoice_id' => $invoiceId, 'issue_date' => date('Y-m-d')], $this->userId);
            self::assertSame([], $this->docsRepo->listByInvoice($supplierId, $creditId));
            self::assertSame(2000, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);
        } finally {
            $this->db->pdo()->rollBack();
        }
    }

    public function testScanOperationIsIdempotentAndTenantScoped(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'FUL-SCAN');
        $this->db->pdo()->prepare('UPDATE stock_items SET ean = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['8590000000001', $supplierId, $itemId]);
        $this->receiveStock($supplierId, $warehouseId, $itemId, '2.000', 10.0, date('Y-m-d', strtotime('-1 day')));
        $task = $this->createTask($supplierId, (int) $this->issueDraft($supplierId, $warehouseId, $itemId, '2.000')['id']);

        $first = $this->fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'scanner-1',
            'code' => '8590000000001',
            'quantity' => '1.000',
        ], $this->userId, false);
        $replay = $this->fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'scanner-1',
            'code' => '8590000000001',
            'quantity' => '1.000',
        ], $this->userId, false);
        self::assertFalse($first['replayed']);
        self::assertTrue($replay['replayed']);
        self::assertSame('1.000', $this->fulfillment->find($supplierId, (int) $task['id'])['lines'][0]['picked_qty']);
        $storedScan = $this->db->pdo()->query(
            'SELECT payload_json, created_by FROM fulfillment_scan_operations ORDER BY id DESC LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($this->userId, (int) $storedScan['created_by']);
        self::assertSame('8590000000001', json_decode((string) $storedScan['payload_json'], true, 512, JSON_THROW_ON_ERROR)['code']);

        $foreignSupplier = $this->createSupplier();
        try {
            $this->fulfillment->scan($foreignSupplier, (int) $task['id'], [
                'client_operation_id' => 'foreign-1', 'code' => 'FUL-SCAN', 'quantity' => '1.000',
            ], $this->userId, false);
            self::fail('Cizí tenant nesmí úlohu skenovat.');
        } catch (StockException $e) {
            self::assertSame('fulfillment_task_not_found', $e->errorCode);
        }
    }

    public function testPartialShipmentsIssueOnlyTheirQuantityAndKeepRemainderOpen(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'FUL-PART');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '3.000', 10.0, date('Y-m-d', strtotime('-1 day')));
        $task = $this->createTask($supplierId, (int) $this->issueDraft($supplierId, $warehouseId, $itemId, '3.000')['id']);
        $this->fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'partial-scan-1', 'code' => 'FUL-PART', 'quantity' => '2.000',
        ], $this->userId, false);

        $shipment = $this->fulfillment->createShipment($supplierId, (int) $task['id'], [
            'carrier' => 'Test Carrier', 'tracking_number' => 'TRACK-1',
            'items' => [['task_line_id' => (int) $task['lines'][0]['id'], 'quantity' => '1.000']],
        ], $this->userId);
        $afterFirst = $this->fulfillment->dispatch($supplierId, (int) $shipment['id'], $this->userId);
        self::assertSame('partially_shipped', $afterFirst['status']);
        self::assertSame(2000, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);
        try {
            $this->documents->reverse($supplierId, (int) $afterFirst['shipments'][0]['issue_document_id'], [], $this->userId);
            self::fail('Expediční doklad nesmí jít stornovat bez vypořádání zásilky.');
        } catch (StockException $e) {
            self::assertSame('fulfillment_document_linked', $e->errorCode);
        }

        $shipment2 = $this->fulfillment->createShipment($supplierId, (int) $task['id'], [
            'carrier' => 'Test Carrier', 'tracking_number' => 'TRACK-2',
            'items' => [['task_line_id' => (int) $task['lines'][0]['id'], 'quantity' => '1.000']],
        ], $this->userId);
        $afterSecond = $this->fulfillment->dispatch($supplierId, (int) $shipment2['id'], $this->userId);
        self::assertSame('partially_shipped', $afterSecond['status']);
        self::assertSame(1000, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);

        $this->fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'partial-scan-2', 'code' => 'FUL-PART', 'quantity' => '1.000',
        ], $this->userId, false);
        $shipment3 = $this->fulfillment->createShipment($supplierId, (int) $task['id'], [
            'carrier' => 'Test Carrier', 'tracking_number' => 'TRACK-3',
            'items' => [['task_line_id' => (int) $task['lines'][0]['id'], 'quantity' => '1.000']],
        ], $this->userId);
        $complete = $this->fulfillment->dispatch($supplierId, (int) $shipment3['id'], $this->userId);
        self::assertSame('shipped', $complete['status']);
        self::assertSame(0, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);
    }

    public function testReturnsUseHistoricalIssueCostAndDispositionControlsMovement(): void
    {
        $supplierId = $this->createSupplier();
        $sellableWarehouse = $this->warehouse($supplierId);
        $quarantineWarehouse = $this->warehousesRepo->insert($supplierId, [
            'code' => 'KARANTENA', 'name' => 'Karanténa', 'is_default' => false, 'is_active' => true, 'is_sellable' => false,
        ]);
        $itemId = $this->item($supplierId, 'FUL-RETURN');
        $this->receiveStock($supplierId, $sellableWarehouse, $itemId, '3.000', 12.5, date('Y-m-d', strtotime('-1 day')));
        $task = $this->createTask($supplierId, (int) $this->issueDraft($supplierId, $sellableWarehouse, $itemId, '3.000')['id']);
        $this->fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'return-scan', 'code' => 'FUL-RETURN', 'quantity' => '3.000',
        ], $this->userId, false);
        $shipment = $this->fulfillment->createShipment($supplierId, (int) $task['id'], [
            'carrier' => 'Test Carrier', 'tracking_number' => 'RETURN-TRACK',
            'items' => [['task_line_id' => (int) $task['lines'][0]['id'], 'quantity' => '3.000']],
        ], $this->userId);
        $task = $this->fulfillment->dispatch($supplierId, (int) $shipment['id'], $this->userId);
        $shipment = $task['shipments'][0];
        $shipmentItemId = (int) $shipment['items'][0]['id'];

        $sellable = $this->fulfillment->receiveReturn($supplierId, (int) $shipment['id'], [
            'disposition' => 'sellable', 'warehouse_id' => $sellableWarehouse,
            'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => '1.000']],
        ], $this->userId);
        self::assertNotNull($sellable['receipt_document_id']);
        self::assertSame(1000, $this->level($supplierId, $sellableWarehouse, $itemId)['qtyT']);
        $receipt = $this->docsRepo->findWithLines($supplierId, (int) $sellable['receipt_document_id']);
        self::assertSame('12.500000', $receipt['lines'][0]['unit_cost']);

        $quarantine = $this->fulfillment->receiveReturn($supplierId, (int) $shipment['id'], [
            'disposition' => 'quarantine', 'warehouse_id' => $quarantineWarehouse,
            'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => '1.000']],
        ], $this->userId);
        self::assertNotNull($quarantine['receipt_document_id']);
        self::assertSame(1000, $this->level($supplierId, $quarantineWarehouse, $itemId)['qtyT']);
        self::assertSame('1.000', $this->levelsRepo->availability($supplierId, [$itemId], null)[$itemId]);
        self::assertSame([], $this->levelsRepo->availability($supplierId, [$itemId], $quarantineWarehouse));

        $scrap = $this->fulfillment->receiveReturn($supplierId, (int) $shipment['id'], [
            'disposition' => 'scrap',
            'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => '1.000']],
        ], $this->userId);
        self::assertNull($scrap['receipt_document_id']);
        self::assertSame(1000, $this->level($supplierId, $sellableWarehouse, $itemId)['qtyT']);
        self::assertSame(1000, $this->level($supplierId, $quarantineWarehouse, $itemId)['qtyT']);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('historicky expedované');
        $this->fulfillment->receiveReturn($supplierId, (int) $shipment['id'], [
            'disposition' => 'scrap',
            'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => '1.000']],
        ], $this->userId);
    }

    public function testTrackedReturnCannotReturnSameSerialAgainThroughAnotherAlias(): void
    {
        [$supplierId, $shipmentId, $shipmentItemId] = $this->shipmentWithTracking('2.000', [
            self::serialAllocation(101, 'SERIAL-A'),
            self::serialAllocation(102, 'SERIAL-B'),
        ]);
        $this->fulfillment->receiveReturn($supplierId, $shipmentId, [
            'disposition' => 'scrap',
            'items' => [[
                'shipment_item_id' => $shipmentItemId,
                'quantity' => '1.000',
                'tracking_allocations' => [['serial_number' => 'SERIAL-A', 'quantity' => '1.000']],
            ]],
        ], $this->userId);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('překračuje množství z původní zásilky');
        $this->fulfillment->receiveReturn($supplierId, $shipmentId, [
            'disposition' => 'scrap',
            'items' => [[
                'shipment_item_id' => $shipmentItemId,
                'quantity' => '1.000',
                'tracking_allocations' => [['stock_tracking_unit_id' => 101, 'quantity' => '1.000']],
            ]],
        ], $this->userId);
    }

    public function testTrackedReturnRejectsSerialOutsideShipment(): void
    {
        [$supplierId, $shipmentId, $shipmentItemId] = $this->shipmentWithTracking('1.000', [
            self::serialAllocation(201, 'SERIAL-OWN'),
        ]);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('nepatří do původní zásilky');
        $this->fulfillment->receiveReturn($supplierId, $shipmentId, [
            'disposition' => 'scrap',
            'items' => [[
                'shipment_item_id' => $shipmentItemId,
                'quantity' => '1.000',
                'tracking_allocations' => [[
                    'stock_tracking_unit_id' => 201,
                    'serial_number' => 'SERIAL-FOREIGN',
                    'quantity' => '1.000',
                ]],
            ]],
        ], $this->userId);
    }

    public function testTrackedReturnRejectsExplicitEmptyAllocationList(): void
    {
        [$supplierId, $shipmentId, $shipmentItemId] = $this->shipmentWithTracking('1.000', [
            self::serialAllocation(301, 'SERIAL-EMPTY'),
        ]);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('vyberte konkrétní');
        $this->fulfillment->receiveReturn($supplierId, $shipmentId, [
            'disposition' => 'scrap',
            'items' => [[
                'shipment_item_id' => $shipmentItemId,
                'quantity' => '1.000',
                'tracking_allocations' => [],
            ]],
        ], $this->userId);
    }

    public function testTrackedReturnRejectsMoreOfOneLotThanShipmentContained(): void
    {
        [$supplierId, $shipmentId, $shipmentItemId] = $this->shipmentWithTracking('3.000', [
            self::lotAllocation(401, 'LOT-X', '2028-06-30', '1.000'),
            self::lotAllocation(402, 'LOT-Y', '2028-06-30', '2.000'),
        ]);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('překračuje množství z původní zásilky');
        $this->fulfillment->receiveReturn($supplierId, $shipmentId, [
            'disposition' => 'scrap',
            'items' => [[
                'shipment_item_id' => $shipmentItemId,
                'quantity' => '2.000',
                'tracking_allocations' => [[
                    'lot_code' => 'LOT-X', 'expires_on' => '2028-06-30', 'quantity' => '2.000',
                ]],
            ]],
        ], $this->userId);
    }

    public function testTrackedLotCanBeReturnedInTwoExactPartialReturns(): void
    {
        [$supplierId, $shipmentId, $shipmentItemId] = $this->shipmentWithTracking('3.000', [
            self::lotAllocation(501, 'LOT-PARTIAL', '2028-12-31', '3.000'),
        ]);
        foreach (['1.000', '2.000'] as $quantity) {
            $this->fulfillment->receiveReturn($supplierId, $shipmentId, [
                'disposition' => 'scrap',
                'items' => [[
                    'shipment_item_id' => $shipmentItemId,
                    'quantity' => $quantity,
                    'tracking_allocations' => [[
                        'lot_code' => 'LOT-PARTIAL', 'expires_on' => '2028-12-31', 'quantity' => $quantity,
                    ]],
                ]],
            ], $this->userId);
        }

        $task = $this->fulfillment->find(
            $supplierId,
            (int) $this->db->pdo()->query('SELECT task_id FROM fulfillment_shipments WHERE id = ' . $shipmentId)->fetchColumn(),
        );
        self::assertCount(2, $task['shipments'][0]['returns']);
        self::assertSame('3.000', $task['shipments'][0]['items'][0]['returned_qty']);
    }

    /** @param list<array<string,mixed>> $tracking @return array{int,int,int} */
    private function shipmentWithTracking(string $qty, array $tracking): array
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'FUL-TRACK-' . uniqid());
        $this->receiveStock($supplierId, $warehouseId, $itemId, $qty, 10.0, date('Y-m-d', strtotime('-1 day')));
        $task = $this->createTask($supplierId, (int) $this->issueDraft($supplierId, $warehouseId, $itemId, $qty)['id']);
        $this->fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'tracking-scan-' . uniqid(), 'code' => $task['lines'][0]['component_snapshot']['sku'], 'quantity' => $qty,
        ], $this->userId, false);
        $shipment = $this->fulfillment->createShipment($supplierId, (int) $task['id'], [
            'carrier' => 'Test Carrier', 'tracking_number' => 'TRACK-' . uniqid(),
            'items' => [['task_line_id' => (int) $task['lines'][0]['id'], 'quantity' => $qty]],
        ], $this->userId);
        $task = $this->fulfillment->dispatch($supplierId, (int) $shipment['id'], $this->userId);
        $shipment = $task['shipments'][0];
        $shipmentItemId = (int) $shipment['items'][0]['id'];
        $this->db->pdo()->prepare(
            'UPDATE fulfillment_shipment_items SET tracking_snapshot = ? WHERE supplier_id = ? AND id = ?'
        )->execute([json_encode($tracking, JSON_THROW_ON_ERROR), $supplierId, $shipmentItemId]);
        return [$supplierId, (int) $shipment['id'], $shipmentItemId];
    }

    /** @return array<string,mixed> */
    private static function serialAllocation(int $id, string $serial): array
    {
        return [
            'stock_tracking_unit_id' => $id, 'serial_number' => $serial,
            'lot_code' => null, 'expires_on' => null, 'location_id' => null, 'quantity' => '1.000',
        ];
    }

    /** @return array<string,mixed> */
    private static function lotAllocation(int $id, string $lot, string $expiresOn, string $quantity): array
    {
        return [
            'stock_tracking_unit_id' => $id, 'serial_number' => null,
            'lot_code' => $lot, 'expires_on' => $expiresOn, 'location_id' => null, 'quantity' => $quantity,
        ];
    }

    private function createTask(int $supplierId, int $sourceId): array
    {
        $task = $this->fulfillment->createTask($supplierId, 'stock_issue_draft', (string) $sourceId, $this->userId);
        $this->taskIds[] = (int) $task['id'];
        return $task;
    }

    private function issueDraft(int $supplierId, int $warehouseId, int $itemId, string $qty): array
    {
        return $this->documents->create($supplierId, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $warehouseId,
            'doc_date' => date('Y-m-d'), 'description' => 'Syntetický výdej pro fulfillment',
            'lines' => [['stock_item_id' => $itemId, 'qty' => $qty]],
        ], $this->userId);
    }
}
