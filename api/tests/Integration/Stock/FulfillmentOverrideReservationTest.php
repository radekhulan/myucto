<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\FulfillmentService;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class FulfillmentOverrideReservationTest extends StockTestCase
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

    public function testOverrideExcessCannotConsumeAnotherTaskReservation(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'FUL-OVERRIDE');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '10.000', 10.0, date('Y-m-d', strtotime('-1 day')));

        $firstTask = $this->createTask($supplierId, $warehouseId, $itemId, '5.000');
        $this->createTask($supplierId, $warehouseId, $itemId, '5.000');
        $this->fulfillment->scan($supplierId, (int) $firstTask['id'], [
            'client_operation_id' => 'override-six',
            'code' => 'FUL-OVERRIDE',
            'quantity' => '6.000',
            'override' => true,
            'override_reason' => 'Syntetický test autorizované odchylky',
            'task_line_id' => (int) $firstTask['lines'][0]['id'],
        ], $this->userId, true);
        $shipment = $this->fulfillment->createShipment($supplierId, (int) $firstTask['id'], [
            'carrier' => 'Test Carrier',
            'tracking_number' => 'OVERRIDE-6',
            'items' => [[
                'task_line_id' => (int) $firstTask['lines'][0]['id'],
                'quantity' => '6.000',
            ]],
        ], $this->userId);

        try {
            $this->fulfillment->dispatch($supplierId, (int) $shipment['id'], $this->userId);
            self::fail('Odchylka nesmí spotřebovat množství rezervované druhou úlohou.');
        } catch (StockException $e) {
            self::assertSame('insufficient_stock', $e->errorCode);
            self::assertSame('5.000', $e->details[0]['available']);
        }
        self::assertSame(10000, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);

        $this->receiveStock($supplierId, $warehouseId, $itemId, '1.000', 10.0, date('Y-m-d'));
        $dispatched = $this->fulfillment->dispatch($supplierId, (int) $shipment['id'], $this->userId);

        self::assertSame('shipped', $dispatched['status']);
        self::assertSame(5000, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);
    }

    /** @return array<string,mixed> */
    private function createTask(int $supplierId, int $warehouseId, int $itemId, string $qty): array
    {
        $source = $this->documents->create($supplierId, [
            'doc_type' => 'issue',
            'origin' => 'manual',
            'warehouse_id' => $warehouseId,
            'doc_date' => date('Y-m-d'),
            'description' => 'Syntetický výdej pro override test',
            'lines' => [['stock_item_id' => $itemId, 'qty' => $qty]],
        ], $this->userId);
        $task = $this->fulfillment->createTask(
            $supplierId,
            'stock_issue_draft',
            (string) $source['id'],
            $this->userId,
        );
        $this->taskIds[] = (int) $task['id'];
        return $task;
    }
}
