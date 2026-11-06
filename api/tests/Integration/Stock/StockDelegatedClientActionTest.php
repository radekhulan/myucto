<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\{StockItemAction, StockItemCustomerPriceAction, WarehouseAction, StockTrackingAction, StockQuantityAction, FulfillmentAction};
use MyInvoice\Middleware\{AuthMiddleware, SupplierScopeMiddleware};
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Stock\StockItemCustomerPriceService;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class StockDelegatedClientActionTest extends StockTestCase
{
    private array $taskIds = [];

    protected function tearDown(): void
    {
        if (isset($this->db)) foreach ($this->taskIds as $id) $this->db->pdo()->prepare('DELETE FROM fulfillment_tasks WHERE id = ?')->execute([$id]);
        parent::tearDown();
    }

    public function testClientCreatesEditsAndDeletesBasicCardWithoutAccountingAndPreservesProtectedFields(): void
    {
        $sid = $this->createSupplier();
        $action = $this->container->get(StockItemAction::class);
        $request = $this->request($sid);
        $response = $action->create($request->withParsedBody(['name' => 'Synthetic delegated card', 'sku' => 'SYNTHETIC-DELEGATED', 'unit' => 'ks']), new Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $item = $this->data($response);
        $id = $item['id'];
        self::assertArrayNotHasKey('revenue_account_code', $item);
        $this->db->pdo()->prepare('UPDATE stock_items SET revenue_account_code = ?, expense_account_code = ?, export_eshop = 1 WHERE supplier_id = ? AND id = ?')->execute(['604', '504', $sid, $id]);
        $version = $this->itemsRepo->find($sid, $id)['row_version'];
        $staff = $request->withAttribute('auth.effective_role', new EffectiveRole(101, 'Synthetic narrow staff', 'staff', true, ['stock' => 1, 'stock.items.write' => 2]));
        self::assertSame(403, $action->create($staff->withParsedBody(['name' => 'Forbidden staff accounting mutation', 'revenue_account_code' => '602']), new Response())->getStatusCode());
        self::assertSame(403, $action->update($staff->withParsedBody(['name' => 'Forbidden staff accounting mutation', 'revenue_account_code' => '602']), new Response(), ['id' => $id])->getStatusCode());

        foreach (['revenue_account_code' => '602', 'dimension_allocations' => [], 'manufacturer_id' => 1, 'export_eshop' => false, 'pricing_base' => 'purchase'] as $field => $value) {
            $response = $action->update($request->withParsedBody(['name' => 'Forbidden', 'row_version' => $version, $field => $value]), new Response(), ['id' => $id]);
            self::assertSame(403, $response->getStatusCode(), $field);
        }
        $response = $action->update($request->withParsedBody(['name' => 'Synthetic changed card', 'row_version' => $version]), new Response(), ['id' => $id]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $stored = $this->itemsRepo->find($sid, $id);
        self::assertSame('Synthetic changed card', $stored['name']);
        self::assertSame('604', $stored['revenue_account_code']);
        self::assertSame('504', $stored['expense_account_code']);
        self::assertTrue($stored['export_eshop']);
        self::assertSame(409, $action->update($request->withParsedBody(['name' => 'Stale', 'row_version' => $version]), new Response(), ['id' => $id])->getStatusCode());
        $reader = $this->request($sid, ['stock' => 1]);
        self::assertSame(403, $action->create($reader->withParsedBody(['name' => 'No write']), new Response())->getStatusCode());
        self::assertSame(403, $action->delete($reader, new Response(), ['id' => $id])->getStatusCode());
        $other = $this->createSupplier();
        self::assertSame(404, $action->update($this->request($other)->withParsedBody(['name' => 'Foreign', 'row_version' => $stored['row_version']]), new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(404, $action->delete($this->request($other), new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(200, $action->delete($request, new Response(), ['id' => $id])->getStatusCode());
        self::assertNull($this->itemsRepo->find($sid, $id));
    }

    public function testClientStockResponsesHideValuationPostingAndSourceDetails(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $id = $this->item($sid, 'SYNTHETIC-CLIENT-READ');
        $this->receiveStock($sid, $wh, $id, '5.000', 17.0);
        $request = $this->request($sid, ['stock' => 1]);
        $action = $this->container->get(StockItemAction::class);
        $list = $this->data($action->list($request, new Response()))['data'];
        self::assertCount(1, $list);
        self::assertSame('5.000', $list[0]['qty']);
        foreach (['value_total', 'avg_unit_cost', 'revenue_account_code', 'expense_account_code', 'pricing_base'] as $key) self::assertArrayNotHasKey($key, $list[0]);
        self::assertSame(403, $action->list($request->withQueryParams(['sort' => 'value']), new Response())->getStatusCode());
        $warehouses = $this->data($this->container->get(WarehouseAction::class)->list($request, new Response()));
        self::assertCount(1, $warehouses);
        self::assertArrayNotHasKey('value', $warehouses[0]);
        $tracking = $this->data($this->container->get(StockTrackingAction::class)->item($request, new Response(), ['id' => $id]));
        self::assertSame([], $tracking['history']);
        $quantities = $this->data($this->container->get(StockQuantityAction::class)->quantities($request, new Response()))['items'];
        self::assertCount(1, $quantities);
        self::assertArrayNotHasKey('vendor_offers', $quantities[0]);
        self::assertArrayNotHasKey('in_transit_orders', $quantities[0]);
    }

    public function testClientSearchRetainsAvailabilityWithoutExposingProtectedCardFields(): void
    {
        $sid = $this->createSupplier();
        $firstWarehouse = $this->warehouse($sid, 'SEARCH-A');
        $secondWarehouse = $this->warehouse($sid, 'SEARCH-B', false);
        $id = $this->item($sid, 'SYNTHETIC-SEARCH-STOCKED');
        $nonStocked = $this->item($sid, 'SYNTHETIC-SEARCH-SERVICE');
        $this->db->pdo()->prepare('UPDATE stock_items SET is_stocked = 0 WHERE supplier_id = ? AND id = ?')->execute([$sid, $nonStocked]);
        $this->levels->setLevel($sid, $firstWarehouse, $id, 2_000, 3_000);
        $this->levels->setLevel($sid, $secondWarehouse, $id, 3_000, 4_500);
        $action = $this->container->get(StockItemAction::class);
        $request = $this->request($sid, ['stock' => 1])->withQueryParams(['q' => 'SYNTHETIC-SEARCH']);

        $response = $action->search($request, new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rows = array_column($this->data($response), null, 'id');
        self::assertSame('5.000', $rows[$id]['qty']);
        self::assertNull($rows[$nonStocked]['qty']);
        self::assertFalse($rows[$nonStocked]['is_stocked']);
        foreach (['value_total', 'avg_unit_cost', 'revenue_account_code', 'expense_account_code', 'pricing_base', 'export_eshop'] as $key) {
            self::assertArrayNotHasKey($key, $rows[$id]);
        }

        $response = $action->search($request->withQueryParams(['q' => 'SYNTHETIC-SEARCH', 'warehouse_id' => $firstWarehouse]), new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rows = array_column($this->data($response), null, 'id');
        self::assertSame('2.000', $rows[$id]['qty']);
    }

    public function testClientCanFulfillPreparedIssueWithoutOverrideOrUnderlyingDocumentAccess(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $id = $this->item($sid, 'SYNTHETIC-FULFILLMENT');
        $this->receiveStock($sid, $wh, $id, '3.000', 19.0, date('Y-m-d', strtotime('-1 day')));
        $source = $this->documents->create($sid, ['doc_type' => 'issue', 'warehouse_id' => $wh, 'doc_date' => date('Y-m-d'), 'description' => 'Synthetic prepared issue', 'lines' => [['stock_item_id' => $id, 'qty' => '2.000']]], $this->userId);
        $action = $this->container->get(FulfillmentAction::class);
        $request = $this->request($sid, ['stock' => 1, 'stock.fulfillment.write' => 2]);
        $response = $action->create($request->withParsedBody(['source_type' => 'stock_issue_draft', 'source_id' => (string) $source['id']]), new Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $task = $this->data($response);
        $this->taskIds[] = $task['id'];
        $scan = ['client_operation_id' => 'synthetic-client-scan', 'code' => 'SYNTHETIC-FULFILLMENT', 'quantity' => '2.000'];
        $denied = $action->scan($request->withParsedBody($scan + ['override' => true, 'override_reason' => 'Synthetic forbidden override']), new Response(), ['id' => $task['id']]);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame(200, $action->scan($request->withParsedBody($scan), new Response(), ['id' => $task['id']])->getStatusCode());
        $shipment = $this->data($action->createShipment($request->withParsedBody(['carrier' => 'Synthetic carrier', 'tracking_number' => 'SYNTHETIC-TRACK', 'items' => [['task_line_id' => $task['lines'][0]['id'], 'quantity' => '2.000']]]), new Response(), ['id' => $task['id']]));
        $response = $action->dispatch($request, new Response(), ['shipmentId' => $shipment['id']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $result = $this->data($response);
        self::assertSame('shipped', $result['status']);
        self::assertArrayNotHasKey('unit_cost_snapshot', $result['shipments'][0]['items'][0]);
        self::assertSame(403, $action->create($this->request($sid, ['stock' => 1]), new Response())->getStatusCode());
        $other = $this->createSupplier();
        self::assertSame(404, $action->get($this->request($other, ['stock' => 1]), new Response(), ['id' => $task['id']])->getStatusCode());
    }

    public function testClientCustomerPricesOnStockCardRequirePriceListPermission(): void
    {
        $sid = $this->createSupplier();
        $id = $this->item($sid, 'SYNTHETIC-CUSTOMER-PRICE');
        $customer = $this->client($sid, 'Synthetic customer price client');
        $row = ['client_id' => $customer, 'currency_code' => 'CZK', 'price_type' => 'fixed', 'fixed_price' => '90.00'];
        $this->container->get(StockItemCustomerPriceService::class)->save($sid, $id, [$row]);
        $action = $this->container->get(StockItemCustomerPriceAction::class);
        $stored = fn (): array => $this->db->pdo()->query('SELECT fixed_price FROM stock_item_customer_prices WHERE supplier_id = ' . $sid . ' AND stock_item_id = ' . $id)
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(403, $action->get($this->request($sid, ['stock' => 1]), new Response(), ['id' => $id])->getStatusCode());
        $response = $action->get($this->request($sid, ['stock' => 1, 'price_list' => 1]), new Response(), ['id' => $id]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($customer, $this->data($response)[0]['client_id']);

        $changed = [['price_type' => 'fixed', 'fixed_price' => '80.00'] + $row];
        foreach ([['stock' => 1, 'stock.items.write' => 2], ['stock' => 1, 'stock.items.write' => 2, 'price_list' => 1], ['stock' => 1, 'price_list' => 2]] as $permissions) {
            $denied = $action->put($this->request($sid, $permissions)->withParsedBody($changed), new Response(), ['id' => $id]);
            self::assertSame(403, $denied->getStatusCode(), json_encode($permissions) . ': ' . (string) $denied->getBody());
        }
        self::assertSame(['90.000000'], $stored());
        $response = $action->put($this->request($sid, ['stock' => 1, 'stock.items.write' => 2, 'price_list' => 2])->withParsedBody($changed), new Response(), ['id' => $id]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['80.000000'], $stored());

        // Interní role se řídí skladem jako dosud; podmínka platí jen pro klienta.
        $staff = $this->request($sid, ['stock' => 1])->withAttribute('auth.effective_role', new EffectiveRole(101, 'Synthetic stock staff', 'staff', true, ['stock' => 1]));
        self::assertSame(200, $action->get($staff, new Response(), ['id' => $id])->getStatusCode());
    }

    private function request(int $supplierId, array $permissions = ['stock' => 1, 'stock.items.write' => 2]): Request
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/stock/items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(100, 'Synthetic client stock', 'client', true, $permissions));
    }

    private function data(Response $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
