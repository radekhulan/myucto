<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Eshop\CatalogJobAction;
use MyInvoice\Action\Stock\StockReportAction;
use MyInvoice\Action\Stock\StockTakeAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Stock\StockValuationJobService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CatalogJobActionTest extends StockTestCase
{
    public function testEshopWriterCannotCancelStockValuation(): void
    {
        $sid = $this->createSupplier();
        $jobs = $this->container->get(CatalogJobService::class);
        $id = $jobs->enqueue($sid, 'stock_valuation', []);
        $request = $this->request($sid)->withAttribute('auth.effective_role',
            new \MyInvoice\Security\EffectiveRole(1002, 'Eshop', 'staff', true, ['eshop.write' => 2]));
        $response = $this->container->get(CatalogJobAction::class)->change($request, new Response(), ['id' => $id, 'operation' => 'cancel']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('queued', $jobs->find($sid, $id)['status']);
    }

    public function testValuationReadRoleCanQueueAndReadCompletedResult(): void
    {
        $sid = $this->createSupplier();
        $action = $this->container->get(StockReportAction::class);
        $request = $this->request($sid, 'readonly')->withParsedBody(['date' => '2026-09-01']);
        $response = $action->createValuationJob($request, new Response());
        self::assertSame(202, $response->getStatusCode());
        $job = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('lease_token', $job);
        self::assertArrayNotHasKey('input', $job);
        $this->container->get(StockValuationJobService::class)->tick($sid);
        $result = $action->valuationJobResult($request, new Response(), ['id' => $job['id']]);
        self::assertSame(200, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01', $data['date']);
        self::assertSame(0, $data['totals']['count']);
        self::assertArrayHasKey('pages', $data['pagination']);
        self::assertSame(403, $action->cancelValuationJob($request, new Response(), ['id' => $job['id']])->getStatusCode());
    }

    public function testJobActionsDoNotExposeAnotherTenant(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $id = $this->container->get(CatalogJobService::class)->enqueue($sid, 'price_recompute', ['item_ids' => []]);
        $action = $this->container->get(CatalogJobAction::class);
        self::assertSame(404, $action->get($this->request($other), new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(404, $action->change($this->request($other), new Response(), ['id' => $id, 'operation' => 'cancel'])->getStatusCode());
        $response = $action->list($this->request($other), new Response());
        self::assertSame([], json_decode((string) $response->getBody(), true));
    }

    public function testValuationRejectsMalformedInputAndPagination(): void
    {
        $sid = $this->createSupplier();
        $action = $this->container->get(StockReportAction::class);
        $request = $this->request($sid)->withParsedBody(['date' => ['2026-01-01']]);
        self::assertSame(422, $action->createValuationJob($request, new Response())->getStatusCode());
        $request = $this->request($sid)->withQueryParams(['limit' => 501]);
        self::assertSame(422, $action->valuationJobResult($request, new Response(), ['id' => 1])->getStatusCode());
        self::assertInstanceOf(StockTakeAction::class, $this->container->get(StockTakeAction::class));
    }

    private function request(int $supplierId, string $role = 'accountant'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/stock/reports/valuation-jobs')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }
}
