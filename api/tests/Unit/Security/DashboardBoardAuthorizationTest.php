<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DashboardBoardAuthorizationTest extends TestCase
{
    public function testPortalReaderCanQueryWithoutBusinessWriteAndHomeRequiresItsOwnPermission(): void
    {
        $reader = $this->request(new EffectiveRole(9, 'Čtenář', 'staff', true, ['profile' => 1]));
        self::assertTrue(RequestAuthorization::canAccessDashboardBoard($reader, 'portal'));
        self::assertFalse(RequestAuthorization::allows($reader, 'profile', AccessLevel::WRITE));
        self::assertFalse(RequestAuthorization::canAccessDashboardBoard($reader, 'home'));
        $home = $this->request(new EffectiveRole(10, 'Pracovní přehled', 'staff', true, ['dashboard' => 1]));
        self::assertTrue(RequestAuthorization::canAccessDashboardBoard($home, 'home'));
        self::assertFalse(RequestAuthorization::canAccessDashboardBoard($home, 'portal'));
    }

    public function testMaximumClientDoesNotAcquireStaffDashboard(): void
    {
        $permissions = array_fill_keys(array_keys((new PermissionCatalog())->all()), 2);
        $request = $this->request(new EffectiveRole(11, 'Klient', 'client', true, $permissions));
        self::assertTrue(RequestAuthorization::canAccessDashboardBoard($request, 'portal'));
        self::assertFalse(RequestAuthorization::canAccessDashboardBoard($request, 'home'));
    }

    public function testBoardRequiresResolvedActiveCompanyRoleAndExplicitSession(): void
    {
        $request = $this->request(new EffectiveRole(12, 'Správce', 'superadmin', true, [], 'superadmin'));
        foreach (['portal', 'home'] as $board) {
            self::assertTrue(RequestAuthorization::canAccessDashboardBoard($request, $board));
            self::assertFalse(RequestAuthorization::canAccessDashboardBoard($request->withoutAttribute(AuthMiddleware::ATTR_METHOD), $board));
            self::assertFalse(RequestAuthorization::canAccessDashboardBoard($request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), $board));
            self::assertFalse(RequestAuthorization::canAccessDashboardBoard($request->withoutAttribute('auth.effective_role'), $board));
            self::assertFalse(RequestAuthorization::canAccessDashboardBoard($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0), $board));
            self::assertFalse(RequestAuthorization::canAccessDashboardBoard($request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'admin']), $board));
            self::assertFalse(RequestAuthorization::canAccessDashboardBoard($this->request(new EffectiveRole(12, 'Vypnutý', 'superadmin', false, [], 'superadmin')), $board));
            self::assertFalse(RequestAuthorization::canAccessDashboardBoard($this->request(new EffectiveRole(13, 'Technický', 'instance_admin', true, [], 'instance_admin')), $board));
        }
        self::assertFalse(RequestAuthorization::canAccessDashboardBoard($request, 'unknown'));
    }

    public function testNarrowReadRoutesResolveEffectiveCompanyRole(): void
    {
        $map = new RoutePermissionMap();
        $domain = new ClientRoutePolicy();
        foreach ([
            ['GET', '/api/dashboard-widgets/catalog'],
            ['POST', '/api/dashboard-widgets/query'],
        ] as [$method, $path]) {
            $route = $map->match($method, $path);
            self::assertNotNull($route, $path);
            self::assertSame(RoutePermissionMap::PERMISSION, $route->kind, $path);
            self::assertSame('profile', $route->key, $path);
            self::assertSame(AccessLevel::READ, $route->minimum, $path);
            self::assertSame(['dashboard'], $route->alternativeKeys, $path);
            self::assertTrue($domain->allowsApiRequest($method, $path), $path);
        }
        self::assertNull($map->match('PUT', '/api/dashboard-widgets/query'));
        self::assertNull($map->match('POST', '/api/user/dashboard-layouts/portal'));
        self::assertNull($map->match('PUT', '/api/user/dashboard-layouts/unknown'));
        self::assertSame(AccessLevel::WRITE, $map->match('PUT', '/api/user/preferences/table.invoices')->minimum);
    }

    private function request(EffectiveRole $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/dashboard-widgets/catalog')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 81, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $role)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 71);
    }
}
