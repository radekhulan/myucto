<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\{ClientStockRoutePolicy, EffectiveRole, PermissionCatalog, PermissionChecker, PermissionResolver, RequestAuthorization, RoutePermissionMap, AccessLevel};
use MyInvoice\Middleware\{AuthMiddleware, PermissionMiddleware};
use MyInvoice\Service\Tenant\{ClientRoutePolicy, SupplierAccess, SupplierAccessResolver};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\{ServerRequestFactory, ResponseFactory};

final class ClientStockRoutePolicyTest extends TestCase
{
    public function testNarrowClientBoundaryAppliesOnCanonicalAndClientHostsEvenWithCorruptBroadGrants(): void
    {
        $role = new EffectiveRole(10, 'Synthetic broad client', 'client', true, ['stock' => 2, 'stock.items.write' => 2, 'stock.fulfillment.write' => 2, 'stock.documents.write' => 2, 'stock.orders.write' => 2, 'stock.close' => 2]);
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn(new SupplierAccess(1, false, null));
        $middleware = new PermissionMiddleware(new ResponseFactory(), new RoutePermissionMap(), $roles, new PermissionChecker(new PermissionCatalog()), $suppliers);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { return (new ResponseFactory())->createResponse(204); }
        };
        $domain = new ClientRoutePolicy();
        foreach ([
            ['GET', '/api/stock/items', true], ['GET', '/api/stock/items/search', true], ['GET', '/api/stock/items/1', true],
            ['GET', '/api/stock/items/1/tracking', true], ['GET', '/api/stock/items/1/tracking-choices', true],
            ['POST', '/api/stock/items/quote', true], ['POST', '/api/stock/items', true], ['PUT', '/api/stock/items/1', true],
            ['PUT', '/api/stock/items/1/packaging', true], ['PUT', '/api/stock/items/1/customer-prices', true],
            ['GET', '/api/stock/warehouses', true], ['GET', '/api/stock/locations', true], ['GET', '/api/stock/quantities', true],
            ['POST', '/api/stock/fulfillment/tasks', true], ['POST', '/api/stock/fulfillment/tasks/1/scan', true],
            ['POST', '/api/stock/fulfillment/tasks/1/shipments', true], ['POST', '/api/stock/fulfillment/shipments/1/dispatch', true],
            ['POST', '/api/stock/fulfillment/shipments/1/returns', true],
            ['GET', '/api/stock/documents', false], ['POST', '/api/stock/documents', false],
            ['GET', '/api/stock/items/1/movements', false], ['GET', '/api/stock/items/1/movements/export', false],
            ['GET', '/api/stock/items/1/posting-defaults', false], ['PUT', '/api/stock/items/1/posting-defaults', false],
            ['PUT', '/api/stock/items/1/dimensions', false], ['PUT', '/api/stock/items/1/units', false],
            ['POST', '/api/stock/locations', false], ['POST', '/api/stock/warehouses', false],
            ['GET', '/api/stock/levels', false], ['GET', '/api/stock/reports/valuation', false],
            ['POST', '/api/stock/reports/value-trend-jobs', false],
            ['POST', '/api/stock/takes', false], ['POST', '/api/stock/takes/1/close', false],
            ['POST', '/api/stock/opening-import/preview', false], ['GET', '/api/stock/sales-orders', false],
            ['GET', '/api/stock/purchase-orders', false], ['GET', '/api/stock/vendor-offers', false],
            ['GET', '/api/invoices/1/stock-documents', false], ['GET', '/api/purchase-invoices/1/stock-receipt', false],
            ['GET', '/api/eshop/products', false],
        ] as [$method, $path, $allowed]) {
            $request = (new ServerRequestFactory())->createServerRequest($method, $path)->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10]);
            self::assertSame($allowed ? 204 : 403, $middleware->process($request, $handler)->getStatusCode(), "$method $path");
            self::assertSame($allowed, $domain->allowsApiRequest($method, $path), "$method $path on client host");
        }
    }

    public function testStockWriteFallbackAndOrphanActionsAreDeniedByBothCheckerAndDirectActionAuthorization(): void
    {
        $checker = new PermissionChecker(new PermissionCatalog());
        foreach ([['stock' => 2], ['stock.items.write' => 2], ['stock.fulfillment.write' => 2]] as $permissions) {
            $role = new EffectiveRole(11, 'Synthetic corrupt client', 'client', true, $permissions);
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withAttribute('auth.effective_role', $role);
            foreach (['stock', 'stock.items.write', 'stock.fulfillment.write', 'stock.fulfillment.override'] as $key) {
                self::assertFalse($checker->allows($role, $key, AccessLevel::WRITE));
                self::assertFalse(RequestAuthorization::allows($request, $key, AccessLevel::WRITE));
            }
        }
    }
}
