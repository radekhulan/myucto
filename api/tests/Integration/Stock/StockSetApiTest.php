<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Action\Stock\StockSetAction;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Sady ve veřejném API `/api/v1/stock/sets` (issue #138). */
#[Group('integration')]
final class StockSetApiTest extends StockTestCase
{
    public function testReadWriteTokenCreatesSetAndReadTokenCannot(): void
    {
        $sid = $this->createSupplier();
        $this->item($sid, 'COMP-A');
        $body = ['sku' => 'SADA-1', 'name' => 'Sada 1', 'definition' => ['components' => [['sku' => 'COMP-A', 'quantity' => '2']]]];

        $denied = $this->viaScope($this->bearer($sid, 'read', 'POST', '/api/stock/sets')->withParsedBody($body),
            fn (ServerRequestInterface $r) => $this->sets()->create($r, new Response()));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('insufficient_scope', $this->json($denied)['error']['code']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'SADA-1'));

        $created = $this->viaScope($this->bearer($sid, 'read_write', 'POST', '/api/stock/sets')->withParsedBody($body),
            fn (ServerRequestInterface $r) => $this->sets()->create($r, new Response()));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $set = $this->json($created);
        self::assertSame('SADA-1', $set['sku']);
        self::assertSame(1, $set['row_version']);
        self::assertSame($this->itemsRepo->findBySku($sid, 'COMP-A')['id'], $set['definition']['components'][0]['item_id']);
        self::assertSame('2.000', $set['definition']['components'][0]['quantity']);
        self::assertFalse((bool) $this->itemsRepo->find($sid, $set['stock_item_id'])['is_stocked']);

        $read = $this->viaScope($this->bearer($sid, 'read', 'GET', '/api/stock/sets/' . $set['stock_item_id']),
            fn (ServerRequestInterface $r) => $this->sets()->get($r, new Response(), ['id' => $set['stock_item_id']]));
        self::assertSame(200, $read->getStatusCode());
        self::assertSame($set['definition'], $this->json($read)['definition']);
    }

    public function testListFiltersAndStockItemCarriesSetFlag(): void
    {
        $sid = $this->createSupplier();
        $component = $this->item($sid, 'COMP-A');
        $set = $this->createSet($sid, 'SADA-1', [['item_id' => $component, 'quantity' => '1']]);
        $this->createSet($sid, 'SADA-2', [['item_id' => $component, 'quantity' => '3']]);

        $list = $this->json($this->sets()->list($this->bearer($sid, 'read', 'GET', '/api/stock/sets')->withQueryParams(['sku' => 'SADA-2']), new Response()));
        self::assertSame(1, $list['meta']['total']);
        self::assertSame('SADA-2', $list['data'][0]['sku']);
        $all = $this->json($this->sets()->list($this->bearer($sid, 'read', 'GET', '/api/stock/sets')->withQueryParams(['q' => 'SADA']), new Response()));
        self::assertSame(2, $all['meta']['total']);

        $items = $this->container->get(StockItemAction::class);
        $card = $this->json($items->get($this->bearer($sid, 'read', 'GET', '/api/stock/items/' . $set), new Response(), ['id' => $set]));
        self::assertTrue($card['is_set']);
        self::assertSame($set, $card['set_id']);
        $plain = $this->json($items->get($this->bearer($sid, 'read', 'GET', '/api/stock/items/' . $component), new Response(), ['id' => $component]));
        self::assertFalse($plain['is_set']);
        self::assertNull($plain['set_id']);
    }

    public function testComponentOfAnotherCompanyIsRejected(): void
    {
        $sid = $this->createSupplier();
        $foreign = $this->createSupplier();
        $foreignItem = $this->item($foreign, 'CIZI');

        $byId = $this->sets()->create($this->bearer($sid, 'read_write', 'POST', '/api/stock/sets')->withParsedBody(
            ['sku' => 'SADA-X', 'name' => 'X', 'definition' => ['components' => [['item_id' => $foreignItem, 'quantity' => '1']]]]), new Response());
        self::assertSame(404, $byId->getStatusCode());
        self::assertSame('set_component_not_found', $this->json($byId)['error']['code']);

        $bySku = $this->sets()->create($this->bearer($sid, 'read_write', 'POST', '/api/stock/sets')->withParsedBody(
            ['sku' => 'SADA-X', 'name' => 'X', 'definition' => ['components' => [['sku' => 'CIZI', 'quantity' => '1']]]]), new Response());
        self::assertSame(404, $bySku->getStatusCode());
        self::assertSame(['CIZI'], $this->json($bySku)['error']['skus']);

        self::assertNull($this->itemsRepo->findBySku($sid, 'SADA-X'), 'Neúspěšné založení nesmí nechat kartu.');
    }

    public function testStaleRowVersionIsConflict(): void
    {
        $sid = $this->createSupplier();
        $a = $this->item($sid, 'COMP-A');
        $b = $this->item($sid, 'COMP-B');
        $set = $this->createSet($sid, 'SADA-1', [['item_id' => $a, 'quantity' => '1']]);

        $update = fn (int $version) => $this->sets()->update($this->bearer($sid, 'read_write', 'PUT', '/api/stock/sets/' . $set)
            ->withParsedBody(['row_version' => $version, 'definition' => ['components' => [['item_id' => $b, 'quantity' => '1']]]]), new Response(), ['id' => $set]);
        $ok = $update(1);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame(2, $this->json($ok)['row_version']);

        $stale = $update(1);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('version_conflict', $this->json($stale)['error']['code']);
    }

    public function testBulkReportsEachItemAndRepeatIsIdempotent(): void
    {
        $sid = $this->createSupplier();
        $this->item($sid, 'COMP-A');
        $this->item($sid, 'COMP-B');
        $stocked = $this->item($sid, 'SKLADOVA');
        $batch = ['sets' => [
            ['sku' => 'SADA-1', 'name' => 'Sada 1', 'definition' => ['components' => [['sku' => 'COMP-A', 'quantity' => '1']]]],
            ['sku' => 'SADA-2', 'name' => 'Sada 2', 'definition' => ['components' => [['sku' => 'SADA-1', 'quantity' => '2'], ['sku' => 'COMP-B', 'quantity' => '1']]]],
            ['sku' => 'SADA-3', 'name' => 'Sada 3', 'definition' => ['components' => [['sku' => 'NEEXISTUJE', 'quantity' => '1']]]],
            ['sku' => 'SADA-1', 'name' => 'Duplicita', 'definition' => ['components' => [['sku' => 'COMP-B', 'quantity' => '1']]]],
            ['sku' => 'SKLADOVA', 'definition' => ['components' => [['sku' => 'COMP-A', 'quantity' => '1']]]],
            ['sku' => 'SADA-4', 'definition' => ['components' => [['sku' => 'COMP-A', 'quantity' => '1']]]],
        ]];

        $first = $this->bulk($sid, $batch);
        self::assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'failed' => 4], $first['summary']);
        self::assertSame(['created', 'created', 'error', 'error', 'error', 'error'], array_column($first['results'], 'status'));
        self::assertSame(
            [null, null, 'set_component_not_found', 'duplicate_in_batch', 'set_stocked_card', 'validation_failed'],
            array_map(static fn (array $r): ?string => $r['error']['code'] ?? null, $first['results']),
        );
        self::assertSame(['NEEXISTUJE'], $first['results'][2]['error']['skus']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'SADA-3'));
        self::assertTrue((bool) $this->itemsRepo->find($sid, $stocked)['is_stocked']);

        $second = $this->bulk($sid, $batch);
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'failed' => 4], $second['summary']);
        self::assertSame(array_column($first['results'], 'row_version'), array_column($second['results'], 'row_version'));
        self::assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM product_set_revisions WHERE stock_item_id = ' . (int) $first['results'][0]['stock_item_id'])->fetchColumn());

        $batch['sets'][0]['definition']['components'][0]['quantity'] = '5';
        $third = $this->bulk($sid, ['sets' => [$batch['sets'][0], $batch['sets'][1] + ['row_version' => 7]]]);
        self::assertSame('updated', $third['results'][0]['status']);
        self::assertSame(2, $third['results'][0]['row_version']);
        self::assertSame('unchanged', $third['results'][1]['status'], 'Shodná definice nevyžaduje aktuální verzi.');
    }

    public function testBulkRejectsOversizedBatch(): void
    {
        $sid = $this->createSupplier();
        $rows = array_fill(0, 201, ['sku' => 'X', 'definition' => ['components' => []]]);
        $response = $this->sets()->bulk($this->bearer($sid, 'read_write', 'POST', '/api/stock/sets/bulk')->withParsedBody(['sets' => $rows]), new Response());
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('batch_too_large', $this->json($response)['error']['code']);
    }

    private function createSet(int $sid, string $sku, array $components): int
    {
        $response = $this->sets()->create($this->bearer($sid, 'read_write', 'POST', '/api/stock/sets')
            ->withParsedBody(['sku' => $sku, 'name' => $sku, 'definition' => ['components' => $components]]), new Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response)['stock_item_id'];
    }

    private function bulk(int $sid, array $body): array
    {
        $response = $this->viaScope($this->bearer($sid, 'read_write', 'POST', '/api/stock/sets/bulk')->withParsedBody($body),
            fn (ServerRequestInterface $r) => $this->sets()->bulk($r, new Response()));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response);
    }

    private function sets(): StockSetAction
    {
        return $this->container->get(StockSetAction::class);
    }

    private function bearer(int $supplierId, string $scope, string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    private function viaScope(ServerRequestInterface $request, \Closure $action): ResponseInterface
    {
        $handler = new class ($action) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $action) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->action)($request);
            }
        };
        return (new ApiScopeMiddleware(new ResponseFactory()))->process($request, $handler);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
