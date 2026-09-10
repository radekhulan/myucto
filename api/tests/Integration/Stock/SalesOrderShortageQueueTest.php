<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\SalesOrderService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SalesOrderShortageQueueTest extends StockTestCase
{
    private SalesOrderService $orders;
    private ?int $supplierId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = $this->container->get(SalesOrderService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->supplierId !== null) {
            $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$this->supplierId]);
        }
        parent::tearDown();
    }

    public function testQueueComparesEveryStockComponentAndSkipsServiceLines(): void
    {
        $this->supplierId = $this->createSupplier();
        $clientId = $this->client($this->supplierId);
        $warehouseId = $this->warehouse($this->supplierId);
        $firstItemId = $this->item($this->supplierId, 'QUEUE-COMPONENT-A');
        $shortItemId = $this->item($this->supplierId, 'QUEUE-COMPONENT-B');
        $order = $this->orders->create($this->supplierId, [
            'client_id' => $clientId,
            'currency_id' => $this->currencyIdFor($this->supplierId),
            'order_number' => 'TEST-SO-QUEUE-COMPONENTS',
            'allocation_policy' => 'partial',
            'prices_include_vat' => false,
            'lines' => [[
                'stock_item_id' => $firstItemId,
                'warehouse_id' => $warehouseId,
                'quantity' => '2.000',
                'unit_price' => '100.000000',
                'vat_rate_id' => $this->vatRateId,
            ], [
                'description' => 'Synthetic service',
                'quantity' => '5.000',
                'unit_price' => '10.000000',
                'vat_rate_id' => $this->vatRateId,
            ]],
        ], $this->userId);

        $stockLine = $order['lines'][0];
        $components = [[
            'stock_item_id' => $firstItemId,
            'quantity' => '2.000',
        ], [
            'stock_item_id' => $shortItemId,
            'quantity' => '6.000',
        ]];
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE sales_order_lines SET component_snapshot = ? WHERE supplier_id = ? AND id = ?')
            ->execute([json_encode($components, JSON_THROW_ON_ERROR), $this->supplierId, $stockLine['id']]);
        $pdo->prepare(
            'UPDATE sales_orders SET commercial_status = "confirmed", fulfillment_status = "partially_fulfilled"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $order['id']]);

        $reservation = $pdo->prepare(
            'INSERT INTO sales_order_reservations
                (supplier_id, order_id, order_line_id, component_no, warehouse_id, stock_item_id, qty_reserved)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $reservation->execute([$this->supplierId, $order['id'], $stockLine['id'], 0, $warehouseId, $firstItemId, '2.000']);
        $reservation->execute([$this->supplierId, $order['id'], $stockLine['id'], 1, $warehouseId, $shortItemId, '1.000']);

        $queue = $this->orders->shortageQueue($this->supplierId);

        self::assertCount(1, $queue);
        self::assertSame($stockLine['line_uuid'], $queue[0]['line_uuid']);
        self::assertSame(1, (int) $queue[0]['component_no']);
        self::assertSame($shortItemId, (int) $queue[0]['stock_item_id']);
        self::assertSame('6.000', $queue[0]['quantity']);
        self::assertSame('1.000', $queue[0]['reserved_qty']);

        $listed = $this->orders->list($this->supplierId, ['shortage' => true]);
        self::assertCount(1, $listed['items']);
        self::assertSame((int) $order['id'], (int) $listed['items'][0]['id']);

        $pdo->prepare(
            'UPDATE sales_order_reservations SET qty_reserved = "6.000"
              WHERE supplier_id = ? AND order_id = ? AND component_no = 1'
        )->execute([$this->supplierId, $order['id']]);

        self::assertSame([], $this->orders->shortageQueue($this->supplierId));
        self::assertSame([], $this->orders->list($this->supplierId, ['shortage' => true])['items']);
    }
}
