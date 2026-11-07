<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\InstanceAdminRoutePolicy;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class InstanceAdminAuthorizationTest extends TestCase
{
    public function testTechnicalRoleNeverInheritsLegacyAdminOrStoredBusinessRights(): void
    {
        $role = $this->role(['profile' => 2, 'invoices' => 2, 'bank' => 2]);
        foreach (['profile', 'profile.tokens', 'invoices', 'bank', 'payroll'] as $key) {
            self::assertSame(AccessLevel::NONE, $role->level($key));
            self::assertFalse((new PermissionChecker(new PermissionCatalog()))->allows($role, $key));
        }
        $request = $this->request('GET', '/api/auth/me')->withAttribute(AuthMiddleware::ATTR_USER,
            $this->user() + ['role' => 'admin']);
        self::assertTrue(RequestAuthorization::isInstanceAdmin($request));
        self::assertFalse(RequestAuthorization::isSuperadmin($request));
        self::assertFalse(RequestAuthorization::allows($request, 'invoices'));
        self::assertTrue(RequestAuthorization::canManageInstance($request));
        self::assertFalse(RequestAuthorization::canManageInstance($request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')));
    }

    public function testOwnAccountAndExactTechnicalOperationsWorkWithoutSupplierResolution(): void
    {
        $cases = [
            ['GET', '/api/auth/me'], ['HEAD', '/api/auth/me'], ['POST', '/api/auth/logout'],
            ['GET', '/api/auth/session/status'], ['PUT', '/api/auth/session/lock-preference'],
            ['PATCH', '/api/auth/webauthn/credentials/7'], ['DELETE', '/api/auth/webauthn/credentials/7'],
            ['POST', '/api/auth/webauthn/credentials/7/test/verify'],
            ['GET', '/api/instance-admin/status'], ['HEAD', '/api/instance-admin/status'],
            ['GET', '/api/instance-admin/suppliers/7/status'], ['PUT', '/api/instance-admin/smtp'],
            ['POST', '/api/instance-admin/suppliers/7/smtp/test'],
        ];
        foreach ($cases as [$method, $path]) {
            $request = $this->request($method, $path);
            $scope = new SupplierScopeMiddleware($this->supplierResolver(), new ResponseFactory());
            self::assertSame(204, $scope->process($request, $this->handler(true))->getStatusCode(), $path);
            self::assertSame(204, $this->middleware()->process($request, $this->handler())->getStatusCode(), $path);
        }
    }

    public function testPublicAndSelfServiceExceptionsDoNotOpenDataTokensOrOAuth(): void
    {
        foreach ([
            ['GET', '/api/search'], ['GET', '/api/suppliers'], ['GET', '/api/portal'],
            ['GET', '/api/user/preferences'], ['GET', '/api/auth/api-me'], ['POST', '/api/auth/tokens'],
            ['PUT', '/api/auth/default-supplier'], ['GET', '/api/oauth/callback'],
            ['GET', '/api/mcp/settings'], ['DELETE', '/api/mcp/grants/7'], ['POST', '/mcp'],
            ['GET', '/oauth/authorize'], ['GET', '/api/public/invoice/synthetic'],
            ['GET', '/api/admin/diagnostics'], ['GET', '/api/admin/backups'], ['GET', '/api/invoices'],
            ['POST', '/api/auth/me'], ['PUT', '/api/auth/webauthn/credentials/7'],
            ['POST', '/api/instance-admin/suppliers/7/smtp'], ['GET', '/api/instance-admin/status/anything'],
            ['GET', '/api/instance-admin/suppliers/0/status'], ['GET', '/api/instance-admin/suppliers/7/export'],
        ] as [$method, $path]) {
            self::assertFalse(InstanceAdminRoutePolicy::allows($method, $path), "$method $path");
            $request = $this->request($method, $path);
            self::assertSame(403, $this->middleware()->process($request, $this->handler())->getStatusCode(), $path);
            $scope = new SupplierScopeMiddleware($this->supplierResolver(), new ResponseFactory());
            self::assertSame(403, $scope->process($request, $this->handler())->getStatusCode(), $path);
        }
    }

    public function testSelfServiceMcpSettingsAreDeniedBeforeTheGeneralException(): void
    {
        self::assertSame(403, $this->middleware()->process(
            $this->request('GET', '/api/mcp/settings'), $this->handler())->getStatusCode());
    }

    public function testBearerInactiveAndOrdinaryRolesCannotUseTechnicalEndpoints(): void
    {
        self::assertSame(403, $this->middleware()->process(
            $this->request('GET', '/api/instance-admin/status')->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'),
            $this->handler())->getStatusCode());
        self::assertSame(403, $this->middleware(new EffectiveRole(9, 'Inactive', 'instance_admin', false, [], 'instance_admin'))
            ->process($this->request('GET', '/api/auth/me'), $this->handler())->getStatusCode());
        $staff = new EffectiveRole(9, 'Synthetic staff', 'staff', true, ['profile' => 2]);
        self::assertSame(403, $this->middleware($staff)->process(
            $this->request('GET', '/api/instance-admin/status')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 9]),
            $this->handler())->getStatusCode());
        $admin = new EffectiveRole(9, 'Superadmin', 'superadmin', true, [], 'superadmin');
        self::assertSame(204, $this->middleware($admin)->process(
            $this->request('GET', '/api/instance-admin/status')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 9]),
            $this->handler())->getStatusCode());
    }

    private function role(array $permissions = []): EffectiveRole
    {
        return new EffectiveRole(9, 'Správce instance', 'instance_admin', true, $permissions, 'instance_admin');
    }

    private function user(): array
    {
        return ['id' => 9, 'role_id' => 9, 'is_instance_admin' => true,
            'role_summary' => ['id' => 9, 'name' => 'Správce instance', 'type' => 'instance_admin',
                'system_key' => 'instance_admin', 'is_active' => true]];
    }

    private function supplierResolver(): SupplierAccessResolver
    {
        $resolver = $this->createMock(SupplierAccessResolver::class);
        $resolver->expects(self::never())->method('resolve');
        return $resolver;
    }

    private function middleware(?EffectiveRole $role = null): PermissionMiddleware
    {
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolveDefault')->willReturn($role ?? $this->role());
        return new PermissionMiddleware(new ResponseFactory(), new RoutePermissionMap(), $roles,
            new PermissionChecker(new PermissionCatalog()), $this->supplierResolver());
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withAttribute(AuthMiddleware::ATTR_USER, $this->user());
    }

    private function handler(bool $checkScope = false): RequestHandlerInterface
    {
        return new class($checkScope) implements RequestHandlerInterface {
            public function __construct(private bool $checkScope) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->checkScope) TestCase::assertSame(0, $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID));
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
