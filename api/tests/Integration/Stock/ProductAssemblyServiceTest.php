<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Eshop\Sets\ProductAssemblyService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ProductAssemblyServiceTest extends StockTestCase
{
    private array $assemblySuppliers = [];

    protected function tearDown(): void
    {
        foreach ($this->assemblySuppliers as $sid) $this->db->pdo()->prepare('DELETE FROM product_assemblies WHERE supplier_id = ?')->execute([$sid]);
        parent::tearDown();
    }

    private function fixture(string $componentQuantity = '10'): array
    {
        $sid = $this->createSupplier();
        $this->assemblySuppliers[] = $sid;
        $warehouse = $this->warehouse($sid);
        $product = $this->item($sid, 'ASSEMBLED', 'product');
        $component = $this->item($sid, 'COMPONENT');
        $this->receiveStock($sid, $warehouse, $component, $componentQuantity, 10.0);
        return [$sid, $warehouse, $product, $component, [
            'operation_key' => 'assembly-fixture-001', 'stock_item_id' => $product, 'warehouse_id' => $warehouse,
            'doc_date' => '2099-01-11', 'quantity' => '2',
            'definition' => ['components' => [['item_id' => $component, 'quantity' => '1.5']]],
        ]];
    }

    public function testMutationUsesDocumentPermissionInsteadOfGeneralStockWrite(): void
    {
        $sid = $this->createSupplier();
        $action = $this->container->get(\MyInvoice\Action\Stock\ProductAssemblyAction::class);
        foreach ([
            [['stock' => 2, 'stock.documents.write' => 0], 403],
            [['stock' => 1, 'stock.documents.write' => 2], 422],
        ] as [$permissions, $status]) {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/stock/assemblies')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(1, 'Syntetická role', 'staff', true, $permissions))
                ->withParsedBody([]);
            self::assertSame($status, $action->create($request, new \Slim\Psr7\Response())->getStatusCode());
        }
    }

    public function testAssemblyAndReversalPreserveValueAndReplayDoesNotDuplicateMovements(): void
    {
        [$sid, $warehouse, $product, $component, $body] = $this->fixture();
        $service = $this->container->get(ProductAssemblyService::class);
        $created = $service->create($sid, $body, $this->userId);
        self::assertSame('30.00', $created['value_total']);
        self::assertSame(7000, $this->levels->current($sid, $warehouse, $component)['qtyT']);
        self::assertSame(2000, $this->levels->current($sid, $warehouse, $product)['qtyT']);
        self::assertSame($created['id'], $service->create($sid, $body, $this->userId)['id']);
        self::assertSame(2000, $this->levels->current($sid, $warehouse, $product)['qtyT']);
        $reversed = $service->reverse($sid, $created['id'], $this->userId);
        self::assertSame('reversed', $reversed['status']);
        self::assertSame(10000, $this->levels->current($sid, $warehouse, $component)['qtyT']);
        self::assertSame(0, $this->levels->current($sid, $warehouse, $product)['qtyT']);
        self::assertSame($reversed, $service->reverse($sid, $created['id'], $this->userId));
    }

    public function testSerialAssemblyConsumesAndReceivesExactIdentitiesAndReversesThem(): void
    {
        $sid = $this->createSupplier();
        $this->assemblySuppliers[] = $sid;
        $warehouse = $this->warehouse($sid);
        $location = $this->location($sid, $warehouse, 'SERIAL');
        $product = $this->item($sid, 'SERIAL-PRODUCT', 'product');
        $component = $this->item($sid, 'SERIAL-COMPONENT');
        $this->setTrackingMode($sid, [$product, $component], 'serial');
        $this->trackedReceipt($sid, $warehouse, $component, '2', '10', [
            ['serial_number' => 'COMP-SN-1', 'quantity' => '1', 'location_id' => $location],
            ['serial_number' => 'COMP-SN-2', 'quantity' => '1', 'location_id' => $location],
        ]);

        $body = [
            'operation_key' => 'assembly-serial-001',
            'stock_item_id' => $product,
            'warehouse_id' => $warehouse,
            'doc_date' => '2099-01-11',
            'quantity' => '2',
            'definition' => ['components' => [['item_id' => $component, 'quantity' => '1']]],
            'component_tracking_allocations' => [(string) $component => [
                ['serial_number' => 'COMP-SN-1', 'quantity' => '1', 'location_id' => $location],
                ['serial_number' => 'COMP-SN-2', 'quantity' => '1', 'location_id' => $location],
            ]],
            'product_tracking_allocations' => [
                ['serial_number' => 'PRODUCT-SN-1', 'quantity' => '1', 'location_id' => $location],
                ['serial_number' => 'PRODUCT-SN-2', 'quantity' => '1', 'location_id' => $location],
            ],
        ];
        $service = $this->container->get(ProductAssemblyService::class);
        $created = $service->create($sid, $body, $this->userId);

        self::assertSame(['COMP-SN-1', 'COMP-SN-2'], $this->documentIdentities($sid, $created['issue_document_id'], 'out'));
        self::assertSame(['PRODUCT-SN-1', 'PRODUCT-SN-2'], $this->documentIdentities($sid, $created['receipt_document_id'], 'in'));
        self::assertSame($created['id'], $service->create($sid, $body, $this->userId)['id']);

        $changed = $body;
        $changed['product_tracking_allocations'][1]['serial_number'] = 'PRODUCT-SN-CHANGED';
        try {
            $service->create($sid, $changed, $this->userId);
            self::fail('Stejný operation_key nesmí přijmout jiné sledované identity.');
        } catch (EshopException $error) {
            self::assertSame('operation_conflict', $error->errorCode);
        }

        $service->reverse($sid, $created['id'], $this->userId);
        self::assertSame(['COMP-SN-1', 'COMP-SN-2'], $this->inventoryIdentities($sid, $component));
        self::assertSame([], $this->inventoryIdentities($sid, $product));
        self::assertSame(4, $this->reverseAllocationCount($sid, $created['issue_document_id'], $created['receipt_document_id']));
    }

    public function testLotAssemblyKeepsExactLocationAndQuantityThroughReverse(): void
    {
        $sid = $this->createSupplier();
        $this->assemblySuppliers[] = $sid;
        $warehouse = $this->warehouse($sid);
        $location = $this->location($sid, $warehouse, 'LOT');
        $product = $this->item($sid, 'LOT-PRODUCT', 'product');
        $component = $this->item($sid, 'LOT-COMPONENT');
        $this->setTrackingMode($sid, [$product, $component], 'lot');
        $this->trackedReceipt($sid, $warehouse, $component, '5', '10', [[
            'lot_code' => 'COMP-LOT', 'expires_on' => '2100-12-31', 'quantity' => '5', 'location_id' => $location,
        ]]);
        $body = [
            'operation_key' => 'assembly-lot-0001',
            'stock_item_id' => $product,
            'warehouse_id' => $warehouse,
            'doc_date' => '2099-01-11',
            'quantity' => '2',
            'definition' => ['components' => [['item_id' => $component, 'quantity' => '1.5']]],
            'component_tracking_allocations' => [(string) $component => [[
                'lot_code' => 'COMP-LOT', 'expires_on' => '2100-12-31', 'quantity' => '3', 'location_id' => $location,
            ]]],
            'product_tracking_allocations' => [[
                'lot_code' => 'PRODUCT-LOT', 'expires_on' => '2101-12-31', 'quantity' => '2', 'location_id' => $location,
            ]],
        ];
        $service = $this->container->get(ProductAssemblyService::class);
        $created = $service->create($sid, $body, $this->userId);

        self::assertSame([['COMP-LOT', '3.000', $location]], $this->documentLots($sid, $created['issue_document_id'], 'out'));
        self::assertSame([['PRODUCT-LOT', '2.000', $location]], $this->documentLots($sid, $created['receipt_document_id'], 'in'));
        self::assertSame('2.000', $this->inventoryQuantity($sid, $product, 'PRODUCT-LOT'));

        $service->reverse($sid, $created['id'], $this->userId);
        self::assertSame('5.000', $this->inventoryQuantity($sid, $component, 'COMP-LOT'));
        self::assertNull($this->inventoryQuantity($sid, $product, 'PRODUCT-LOT'));
    }

    public function testInsufficientComponentsRollbackBothDocumentsAndOperation(): void
    {
        [$sid, $warehouse, $product, $component, $body] = $this->fixture('2');
        $service = $this->container->get(ProductAssemblyService::class);
        try {
            $service->create($sid, $body, $this->userId);
            self::fail('Insufficient stock accepted');
        } catch (StockException $error) {
            self::assertSame('insufficient_stock', $error->errorCode);
        }
        $query = $this->db->pdo()->prepare('SELECT COUNT(*) FROM product_assemblies WHERE supplier_id = ?');
        $query->execute([$sid]);
        self::assertSame(0, (int) $query->fetchColumn());
        self::assertSame(2000, $this->levels->current($sid, $warehouse, $component)['qtyT']);
    }

    public function testIndividualAssemblyDocumentCannotBeReversed(): void
    {
        [$sid, $warehouse, $product, $component, $body] = $this->fixture();
        $created = $this->container->get(ProductAssemblyService::class)->create($sid, $body, $this->userId);
        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Doklady kompletace');
        $this->documents->reverse($sid, $created['receipt_document_id'], [], $this->userId);
    }

    public function testBackdatingCannotChangeOnlyOneSideOfAssemblyValuation(): void
    {
        [$sid, $warehouse, $product, $component, $body] = $this->fixture();
        $created = $this->container->get(ProductAssemblyService::class)->create($sid, $body, $this->userId);
        try {
            $this->receiveStock($sid, $warehouse, $component, '10', 20.0, '2099-01-09');
            self::fail('Backdating changed paired assembly valuation');
        } catch (StockException $error) {
            self::assertSame('stock_backdate_assembly_unsupported', $error->errorCode);
        }
        self::assertSame(3000, $this->levels->current($sid, $warehouse, $product)['valueC']);
        self::assertSame('30.00', $this->container->get(ProductAssemblyService::class)->get($sid, $created['id'])['value_total']);
    }

    private function setTrackingMode(int $supplierId, array $itemIds, string $mode): void
    {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = ? WHERE supplier_id = ? AND id IN ({$placeholders})")
            ->execute([$mode, $supplierId, ...$itemIds]);
    }

    private function location(int $supplierId, int $warehouseId, string $code): int
    {
        $this->db->pdo()->prepare('INSERT INTO warehouse_locations (supplier_id, warehouse_id, code, name) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $warehouseId, $code, 'Syntetická lokace']);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function trackedReceipt(int $supplierId, int $warehouseId, int $itemId, string $quantity, string $cost, array $allocations): void
    {
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt',
            'warehouse_id' => $warehouseId,
            'doc_date' => '2099-01-10',
            'description' => 'Syntetický příjem sledované komponenty',
            'lines' => [[
                'stock_item_id' => $itemId,
                'qty' => $quantity,
                'unit_cost' => $cost,
                'tracking_allocations' => $allocations,
            ]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
    }

    private function documentIdentities(int $supplierId, int $documentId, string $direction): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT u.serial_number FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id WHERE a.supplier_id = ? AND l.document_id = ? AND a.direction = ? ORDER BY u.serial_number');
        $stmt->execute([$supplierId, $documentId, $direction]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'serial_number');
    }

    private function documentLots(int $supplierId, int $documentId, string $direction): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT u.lot_code, a.quantity, a.location_id FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id WHERE a.supplier_id = ? AND l.document_id = ? AND a.direction = ? ORDER BY u.lot_code');
        $stmt->execute([$supplierId, $documentId, $direction]);
        return array_map(static fn (array $row): array => [(string) $row['lot_code'], (string) $row['quantity'], (int) $row['location_id']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function inventoryIdentities(int $supplierId, int $itemId): array
    {
        $inventory = $this->container->get(\MyInvoice\Repository\StockTrackingRepository::class)->inventory($supplierId, $itemId);
        $serials = array_column($inventory, 'serial_number');
        sort($serials);
        return $serials;
    }

    private function inventoryQuantity(int $supplierId, int $itemId, string $lotCode): ?string
    {
        foreach ($this->container->get(\MyInvoice\Repository\StockTrackingRepository::class)->inventory($supplierId, $itemId) as $row) {
            if ($row['lot_code'] === $lotCode) return (string) $row['quantity'];
        }
        return null;
    }

    private function reverseAllocationCount(int $supplierId, int $issueDocumentId, int $receiptDocumentId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_tracking_allocations a JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id JOIN stock_documents original ON original.reversal_document_id = d.id AND original.supplier_id = d.supplier_id WHERE a.supplier_id = ? AND original.id IN (?, ?) AND a.original_allocation_id IS NOT NULL');
        $stmt->execute([$supplierId, $issueDocumentId, $receiptDocumentId]);
        return (int) $stmt->fetchColumn();
    }
}
