<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockItemNeighborsAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class StockItemNeighborsTest extends StockTestCase
{
    public function testNeighborsFollowGlobalSortAndExplicitSelectionWithoutCrossTenantRows(): void
    {
        $sid = $this->createSupplier();
        $warehouse = $this->warehouse($sid, 'NEIGHBORS');
        $ids = [];
        for ($i = 0; $i < 55; $i++) {
            $id = $this->item($sid, 'NEIGHBOR-' . $i);
            $this->levels->setLevel($sid, $warehouse, $id, $i * 1000, $i * 2000);
            $ids[] = $id;
        }
        $foreign = $this->item($this->createSupplier(), 'NEIGHBOR-FOREIGN');
        $filters = ['sort' => 'qty', 'direction' => 'desc', 'warehouse_id' => $warehouse];
        self::assertSame(['previous_id' => $ids[6], 'next_id' => $ids[4], 'position' => 50, 'total' => 55],
            $this->itemsRepo->neighbors($sid, $ids[5], $filters));
        self::assertSame(['previous_id' => $ids[54], 'next_id' => null, 'position' => 2, 'total' => 2],
            $this->itemsRepo->neighbors($sid, $ids[5], $filters, [$ids[54], $ids[5], $ids[4], $foreign], [$ids[4]]));
        self::assertSame(['previous_id' => null, 'next_id' => null, 'position' => null, 'total' => 55],
            $this->itemsRepo->neighbors($sid, $foreign, $filters));
        self::assertSame(['previous_id' => null, 'next_id' => null, 'position' => null, 'total' => 0],
            $this->itemsRepo->neighbors($sid, $ids[0], $filters, []));
        self::assertSame(0, $this->itemsRepo->neighbors($sid, $ids[0], ['q' => 'NOT-PRESENT'])['total']);
        $this->db->pdo()->prepare('UPDATE stock_items SET name = ? WHERE supplier_id = ?')->execute(['Stejný název', $sid]);
        self::assertSame($ids[1], $this->itemsRepo->neighbors($sid, $ids[0], ['sort' => 'name', 'direction' => 'desc'])['next_id']);
    }

    public function testActionRejectsMalformedFiltersAndDoesNotBroadenEmptySelection(): void
    {
        $sid = $this->createSupplier();
        $id = $this->item($sid, 'NEIGHBOR-ACTION');
        $action = $this->container->get(StockItemNeighborsAction::class);
        foreach ([['ids' => [0]], ['ids' => [1.5]], ['filters' => 'bad'], ['filters' => ['unknown' => true]], ['unexpected' => 1]] as $body) {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/stock/items/' . $id . '/neighbors')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)->withParsedBody($body);
            self::assertSame(422, $action($request, new Response(), ['id' => $id])->getStatusCode());
        }
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/stock/items/' . $id . '/neighbors')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)->withParsedBody(['ids' => []]);
        $response = $action($request, new Response(), ['id' => $id]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, json_decode((string) $response->getBody(), true)['total']);
    }
}
