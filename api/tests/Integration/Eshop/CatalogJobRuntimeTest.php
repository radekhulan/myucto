<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\CatalogJobAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Service\Eshop\CatalogJobDispatcher;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CatalogJobRuntimeTest extends StockTestCase
{
    public function testItemResultRollsBackWithCheckpointAndSurvivesRetry(): void
    {
        $sid = $this->createSupplier();
        $jobs = $this->container->get(CatalogJobService::class);
        $items = $this->container->get(CatalogJobItemRepository::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $id = $jobs->enqueue($sid, 'catalog_bulk_preview', [], 2, createdBy: $this->userId);
        $items->append($sid, $id, [['ordinal' => 1, 'input' => ['name' => 'Synthetic']], ['ordinal' => 2]]);
        $pdo->commit();
        self::assertSame($this->userId, $jobs->find($sid, $id)['created_by']);
        $claim = $jobs->claim($sid, 'catalog_bulk_preview');
        try {
            $jobs->batch($sid, $id, $claim['lease_token'], function () use ($sid, $id, $items): array {
                $items->finish($sid, $id, 1, 'ready', ['name' => 'Before'], ['name' => 'After']);
                throw new \RuntimeException('interrupted');
            });
            self::fail('Expected interrupted transaction');
        } catch (\RuntimeException $e) {
            self::assertSame('interrupted', $e->getMessage());
        }
        self::assertSame('pending', $items->batch($sid, $id)[0]['status']);
        self::assertSame(0, $jobs->find($sid, $id)['checkpoint']);
        $jobs->batch($sid, $id, $claim['lease_token'], function () use ($sid, $id, $items): array {
            $items->finish($sid, $id, 1, 'ready', ['name' => 'Before'], ['name' => 'After']);
            return ['checkpoint' => 1];
        });
        self::assertSame(['name' => 'After'], $items->page($sid, $id, 1, 1, 'ready')['items'][0]['after']);
        self::assertSame(1, $items->counts($sid, $id)['pending']);
        self::assertSame(2, $items->batch($sid, $id, 1)[0]['ordinal']);
    }

    public function testAuditRequiresWriteAndCannotCrossTenant(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $jobs = $this->container->get(CatalogJobService::class);
        $id = $jobs->enqueue($sid, 'catalog_bulk_preview', [], 1);
        $action = $this->container->get(CatalogJobAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/eshop/jobs/' . $id . '/items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']);
        self::assertSame(404, $action->items($request, new Response(), ['id' => $id])->getStatusCode());
        self::assertSame([], json_decode((string) $action->list($request, new Response())->getBody(), true));
        $writer = $request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        self::assertSame(200, $action->items($writer, new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(404, $action->items($writer->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $other), new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(400, $action->items($writer->withQueryParams(['limit' => 501]), new Response(), ['id' => $id])->getStatusCode());
        $this->db->pdo()->beginTransaction();
        try {
            $this->container->get(CatalogJobItemRepository::class)->append($other, $id, [['ordinal' => 1]]);
            self::fail('Foreign job accepted');
        } catch (\OutOfBoundsException) {
            self::assertTrue(true);
        } finally {
            $this->db->pdo()->rollBack();
        }
    }

    public function testDispatcherUsesRegisteredHandlerAndCompletesQueuedValuation(): void
    {
        $sid = $this->createSupplier();
        $service = $this->container->get(\MyInvoice\Service\Stock\StockValuationJobService::class);
        $job = $service->enqueue($sid, '2026-09-01');
        $this->container->get(CatalogJobDispatcher::class)->tick();
        self::assertSame('completed', $this->container->get(CatalogJobService::class)->find($sid, $job['id'])['status']);
    }
}
