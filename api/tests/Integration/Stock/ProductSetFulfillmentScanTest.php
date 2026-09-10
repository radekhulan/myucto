<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Eshop\Sets\ProductSetService;
use MyInvoice\Service\Stock\FulfillmentService;
use MyInvoice\Service\Stock\SalesOrderService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ProductSetFulfillmentScanTest extends StockTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach ($this->supplierIds as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM fulfillment_tasks WHERE supplier_id = ?')->execute([$supplierId]);
                $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    public function testActualSetOrderCanBeScannedByComponentSkuAndEan(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $componentId = $this->item($supplierId, 'SET-SCAN-SKU');
        $this->db->pdo()->prepare('UPDATE stock_items SET ean = ?, sale_price_without_vat = 10,
            vat_rate_id = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['8591234567890', $this->vatRateId, $supplierId, $componentId]);
        $setId = $this->item($supplierId, 'SET-SCAN');
        $this->db->pdo()->prepare('UPDATE stock_items SET is_stocked = 0 WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $setId]);
        $this->container->get(ProductSetService::class)->save($supplierId, $setId, 0, [
            'components' => [['item_id' => $componentId, 'quantity' => '2']],
        ]);
        $this->receiveStock($supplierId, $warehouseId, $componentId, '2.000', 5.0, date('Y-m-d', strtotime('-1 day')));

        $orders = $this->container->get(SalesOrderService::class);
        $order = $orders->create($supplierId, [
            'client_id' => $this->client($supplierId),
            'currency_id' => $this->currencyIdFor($supplierId),
            'order_number' => 'TEST-SET-SCAN',
            'allocation_policy' => 'all_or_nothing',
            'prices_include_vat' => false,
            'lines' => [[
                'stock_item_id' => $setId,
                'warehouse_id' => $warehouseId,
                'description' => 'Syntetický set pro skenování',
                'quantity' => '1.000',
                'unit_price' => '20.000000',
                'vat_rate_id' => $this->vatRateId,
            ]],
        ], $this->userId);
        $order = $orders->confirm($supplierId, (int) $order['id'], 'confirm-set-scan');
        $fulfillment = $this->container->get(FulfillmentService::class);
        $task = $fulfillment->createTask($supplierId, 'sales_order', (string) $order['order_uuid'], $this->userId);
        $bySku = $fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'set-scan-sku',
            'code' => 'SET-SCAN-SKU',
            'quantity' => '1.000',
        ], $this->userId, false);
        self::assertSame('1.000', $bySku['picked_qty']);
        $byEan = $fulfillment->scan($supplierId, (int) $task['id'], [
            'client_operation_id' => 'set-scan-ean',
            'code' => '8591234567890',
            'quantity' => '1.000',
        ], $this->userId, false);
        self::assertSame('2.000', $byEan['picked_qty']);
        self::assertSame('packing', $byEan['status']);

        $snapshot = $order['lines'][0]['component_snapshot'][0];
        self::assertSame($componentId, $snapshot['stock_item_id']);
        self::assertSame('2.000', $snapshot['quantity']);
        self::assertSame('20.00', $snapshot['allocated_amount']);
        self::assertSame('10.00', $snapshot['unit_price']);
        self::assertSame('SET-SCAN-SKU', $snapshot['sku']);
        self::assertSame('8591234567890', $snapshot['ean']);
        self::assertSame('Karta SET-SCAN-SKU', $snapshot['name']);
        self::assertSame('ks', $snapshot['unit']);
    }
}
