<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\ClientStockRoutePolicy;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StockClientAccessDeniedTest extends TestCase
{
    private RoutePermissionMap $routes;
    private PermissionChecker $checker;
    private EffectiveRole $client;

    protected function setUp(): void
    {
        $this->routes = new RoutePermissionMap();
        $this->checker = new PermissionChecker(new PermissionCatalog());
        $this->client = new EffectiveRole(4, 'Klient', 'client', true, [
            'invoices' => 2,
            'purchase_invoices' => 2,
            'stock' => 2,
        ]);
    }

    public function testClientCannotUseStockModuleRoutes(): void
    {
        foreach ([
            ['GET', '/api/stock/documents'],
            ['POST', '/api/stock/items'],
            ['GET', '/api/stock/reports/valuation'],
            ['POST', '/api/stock/reports/value-trend-jobs'],
            ['POST', '/api/stock/takes'],
            ['POST', '/api/stock/documents'],
            ['POST', '/api/stock/warehouses'],
            ['GET', '/api/stock/items/1/movements'],
            ['GET', '/api/stock/items/1/posting-defaults'],
            ['PUT', '/api/stock/items/1/posting-defaults'],
            ['POST', '/api/stock/opening-stock-import/preview'],
            ['GET', '/api/stock/sales-orders'],
            ['GET', '/api/stock/purchase-orders'],
        ] as [$method, $path]) {
            self::assertFalse($this->allows($method, $path, $this->client), "$method $path");
        }
    }

    public function testNestedStockEntryPointsMapToStaffOnlyStockPermission(): void
    {
        foreach ([
            ['GET', '/api/invoices/1/stock-documents'],
            ['GET', '/api/purchase-invoices/1/stock-receipt'],
            ['POST', '/api/purchase-invoices/1/stock-receipt'],
        ] as [$method, $path]) {
            $route = $this->routes->match($method, $path);
            self::assertSame('stock', $route?->key, "$method $path");
            self::assertFalse($this->allows($method, $path, $this->client), "$method $path");
        }
    }

    public function testClientStockReadAndDedicatedActionsAreOptInAndStayNarrow(): void
    {
        foreach ([0, 1, 2] as $stock) {
            foreach ([0, 1, 2] as $items) {
                foreach ([0, 1, 2] as $fulfillment) {
                    $role = new EffectiveRole(9, 'Synthetic stock role', 'client', true, ['stock' => $stock, 'stock.items.write' => $items, 'stock.fulfillment.write' => $fulfillment]);
                    foreach (['/api/stock/items', '/api/stock/items/1', '/api/stock/items/search', '/api/stock/items/1/packaging', '/api/stock/items/1/customer-prices', '/api/stock/items/1/tracking', '/api/stock/warehouses', '/api/stock/locations', '/api/stock/quantities', '/api/stock/fulfillment/tasks'] as $path) {
                        self::assertSame($stock > 0, $this->allows('GET', $path, $role), $path);
                    }
                    self::assertSame($stock > 0 && $items === 2, $this->allows('POST', '/api/stock/items', $role));
                    self::assertSame($stock > 0 && $fulfillment === 2, $this->allows('POST', '/api/stock/fulfillment/tasks', $role));
                    self::assertFalse($this->allows('POST', '/api/stock/documents', $role));
                    self::assertFalse($this->allows('GET', '/api/stock/items/1/movements', $role));
                }
            }
        }
        $catalog = new PermissionCatalog();
        foreach (['stock', 'stock.items.write', 'stock.fulfillment.write'] as $permission) self::assertArrayNotHasKey($permission, $catalog->legacyPreset('client'));
    }

    public function testSuperadminBypassesStockMatrix(): void
    {
        $superadmin = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');
        self::assertTrue($this->allows('GET', '/api/stock/documents', $superadmin));
    }

    private function allows(string $method, string $path, EffectiveRole $role): bool
    {
        $route = $this->routes->match($method, $path);
        self::assertNotNull($route, "$method $path");
        return $route->key !== null && $this->checker->allows($role, $route->key, $route->minimum)
            && (!$role->isClientType() || ClientStockRoutePolicy::allows($method, $path, $route->key));
    }
}
