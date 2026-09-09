<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\CatalogReadAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Service\Eshop\CatalogReadRequest;
use MyInvoice\Service\Eshop\CatalogReadService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CatalogReadTest extends StockTestCase
{
    public function testSelectiveReadPreservesOrderAndHidesForeignAndInternalData(): void
    {
        $sid = $this->createSupplier();
        $own = $this->item($sid, '0000123');
        $foreign = $this->item($this->createSupplier(), 'FOREIGN');
        $service = $this->container->get(CatalogReadService::class);
        $result = $service->products($sid, ['ids' => [$foreign, $own, 2147483647], 'fields' => ['sku', 'i18n', 'categories', 'tag_ids', 'attributes', 'fees', 'media', 'availability', 'prices']]);
        self::assertSame(['requested' => 3, 'found' => 1], $result['meta']);
        self::assertSame(['unavailable', 'ok', 'unavailable'], array_column($result['items'], 'status'));
        self::assertNull($result['items'][0]['data']);
        self::assertNull($result['items'][2]['data']);
        $product = $result['items'][1]['data'];
        self::assertSame('0000123', $product['sku']);
        self::assertSame(1, $product['row_version']);
        self::assertSame([], $product['media']);
        self::assertSame(['qty' => '0.000', 'warehouses' => []], $product['availability']);
        self::assertArrayNotHasKey('name', $product);
        self::assertArrayNotHasKey('costs', $product);
        self::assertArrayNotHasKey('markup_pct', $product['prices'][0]);
        self::assertFalse($this->db->pdo()->inTransaction());
    }

    public function testAvailabilityIncludesEveryRequestedWarehouseWithoutCosts(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'WAREHOUSES');
        $warehouses = [];
        for ($i = 1; $i <= 5; $i++) {
            $warehouse = $this->warehouse($sid, 'WH-' . $i);
            $warehouses[] = $warehouse;
            $this->receiveStock($sid, $warehouse, $item, (string) $i, 10.00);
        }
        $service = $this->container->get(CatalogReadService::class);
        $data = $service->products($sid, ['ids' => [$item], 'fields' => ['availability']])['items'][0]['data'];
        self::assertSame('15.000', $data['availability']['qty']);
        self::assertCount(5, $data['availability']['warehouses']);
        self::assertArrayNotHasKey('value_total', $data['availability']['warehouses'][0]);
        $data = $service->products($sid, ['ids' => [$item], 'fields' => ['availability'], 'warehouse_ids' => [$warehouses[0]]])['items'][0]['data'];
        self::assertSame('1.000', $data['availability']['qty']);
        self::assertCount(1, $data['availability']['warehouses']);
    }

    public function testQuantityIsAppliedPerItemAndMissingPricesStayNull(): void
    {
        $sid = $this->createSupplier();
        $first = $this->item($sid, 'PRICE-1');
        $second = $this->item($sid, 'PRICE-2');
        foreach ([$first, $second] as $id) {
            $this->itemsRepo->setSalePrice($sid, $id, '100.00');
            $this->container->get(StockItemPromoPriceRepository::class)->insert($sid, $id, [
                'currency_code' => 'CZK', 'promo_price' => '80.00', 'label' => 'Synthetic promotion',
                'valid_from' => null, 'valid_to' => null, 'qty_mode' => 'limited', 'qty_limit' => '5', 'is_active' => true, 'note' => null,
            ]);
        }
        $service = $this->container->get(CatalogReadService::class);
        $result = $service->prices($sid, ['items' => [['id' => $first, 'qty' => '4'], ['id' => $second, 'qty' => '6']], 'on_date' => '2099-06-15']);
        self::assertSame('80.00', $result['items'][0]['data']['unit_price']);
        self::assertSame('100.00', $result['items'][1]['data']['unit_price']);
        self::assertSame('qty_exceeds_remaining', $result['items'][1]['data']['promo_reason']);
        self::assertFalse($result['items'][0]['data']['prices_include_vat']);
        $eur = $service->prices($sid, ['items' => [['id' => $first]], 'currency' => 'EUR']);
        self::assertNull($eur['items'][0]['data']['unit_price']);
    }

    public function testReadOnlyActionRejectsCostProjectionButAllowsBasicBatch(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'ACL');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/catalog/products/batch')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']);
        $action = $this->container->get(CatalogReadAction::class);
        self::assertSame(200, $action->products($request->withParsedBody(['ids' => [$item]]), new Response())->getStatusCode());
        self::assertSame(403, $action->products($request->withParsedBody(['ids' => [$item], 'fields' => ['costs']]), new Response())->getStatusCode());
        self::assertSame(400, $action->products($request->withParsedBody(['ids' => range(1, 501)]), new Response())->getStatusCode());
        $adminToken = $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read'])
            ->withParsedBody(['ids' => [$item], 'fields' => ['costs']]);
        self::assertSame(403, $action->products($adminToken, new Response())->getStatusCode());
        self::assertSame(200, $action->products($adminToken->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read_write']), new Response())->getStatusCode());
    }

    public function testBatchQueryCountDoesNotGrowPerProduct(): void
    {
        $sid = $this->createSupplier();
        $ids = [];
        for ($i = 1; $i <= 50; $i++) {
            $id = $this->item($sid, 'BATCH-' . $i);
            $ids[] = $id;
            $this->itemsRepo->setSalePrice($sid, $id, '100.00');
            $this->container->get(StockItemPromoPriceRepository::class)->insert($sid, $id, [
                'currency_code' => 'CZK', 'promo_price' => '80.00', 'label' => 'Synthetic batch promotion',
                'valid_from' => null, 'valid_to' => null, 'qty_mode' => 'limited', 'qty_limit' => '5', 'is_active' => true, 'note' => null,
            ]);
        }
        $service = $this->container->get(CatalogReadService::class);
        $fields = ['sku', 'i18n', 'categories', 'tag_ids', 'attributes', 'fees', 'media', 'availability', 'prices'];
        $questions = fn (): int => (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
        $before = $questions();
        $service->products($sid, ['ids' => [$ids[0]], 'fields' => $fields]);
        $one = $questions() - $before;
        $before = $questions();
        $result = $service->products($sid, ['ids' => $ids, 'fields' => $fields]);
        $many = $questions() - $before;
        self::assertSame(50, $result['meta']['found']);
        self::assertLessThanOrEqual($one + 1, $many);
        self::assertLessThan(25, $many);
    }
}
