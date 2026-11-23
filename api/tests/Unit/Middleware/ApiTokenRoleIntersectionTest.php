<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiTokenRoleIntersectionTest extends TestCase
{
    public function testReadTokenCannotWriteEvenWhenRoleAllowsWrite(): void
    {
        $response = $this->pipeline(new EffectiveRole(2, 'Zapisovatel', 'staff', true, ['clients.create' => 2]))
            ->handle($this->request('read'));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('insufficient_scope', (string) $response->getBody());
    }

    public function testReadWriteTokenCannotWriteWhenRoleAllowsOnlyRead(): void
    {
        $response = $this->pipeline(new EffectiveRole(2, 'Čtenář', 'staff', true, ['clients.create' => 1]))
            ->handle($this->request('read_write'));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('forbidden_permission', (string) $response->getBody());
    }

    public function testWritePassesOnlyWhenTokenAndRoleBothAllowIt(): void
    {
        $response = $this->pipeline(new EffectiveRole(2, 'Zapisovatel', 'staff', true, ['clients.create' => 2]))
            ->handle($this->request('read_write'));

        self::assertSame(204, $response->getStatusCode());
    }

    public function testPriceListTokenScopeAndRoleAreBothRequired(): void
    {
        foreach (['staff', 'client'] as $type) {
            foreach (['read', 'read_write'] as $scope) {
                foreach ([0, 1, 2] as $level) {
                    $pipeline = $this->pipeline(new EffectiveRole(9, 'Synthetic catalog token role', $type, true, ['price_list' => $level]));
                    $request = $this->request($scope)->withUri(new \Slim\Psr7\Uri('http', 'localhost', null, '/api/price-list-items'));
                    self::assertSame($scope === 'read_write' && $level === 2 ? 204 : 403, $pipeline->handle($request)->getStatusCode());
                    self::assertSame($level >= 1 ? 204 : 403, $pipeline->handle($request->withMethod('GET'))->getStatusCode());
                }
            }
        }
    }

    public function testWorkReportBillingTemplateReadRequiresAnExistingBusinessPermission(): void
    {
        foreach (['staff', 'client'] as $type) {
            foreach (['read', 'read_write'] as $scope) {
                foreach (['price_list', 'invoices', 'projects', 'clients'] as $key) {
                    foreach ([0, 1, 2] as $level) {
                        $pipeline = $this->pipeline(new EffectiveRole(9, 'Syntetická role pro výkazy', $type, true, [$key => $level]));
                        $request = $this->request($scope)->withMethod('GET')
                            ->withUri(new \Slim\Psr7\Uri('http', 'localhost', null, '/api/work-report-billing-templates'));
                        $response = $pipeline->handle($request);
                        self::assertSame($key !== 'clients' && $level >= 1 ? 204 : 403, $response->getStatusCode(),
                            "$type $scope $key=$level");
                        if ($response->getStatusCode() === 403) {
                            self::assertStringContainsString('forbidden_permission', (string) $response->getBody());
                        }
                    }
                }
            }
        }
    }

    private function pipeline(EffectiveRole $role): RequestHandlerInterface
    {
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn(new SupplierAccess(1, false, null));
        $catalog = new PermissionCatalog();
        $permission = new PermissionMiddleware(
            new ResponseFactory(),
            new RoutePermissionMap(),
            $roles,
            new PermissionChecker($catalog),
            $suppliers,
        );
        $endpoint = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
        $permissionHandler = new class($permission, $endpoint) implements RequestHandlerInterface {
            public function __construct(
                private readonly PermissionMiddleware $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
        $scope = new ApiScopeMiddleware(new ResponseFactory());
        return new class($scope, $permissionHandler) implements RequestHandlerInterface {
            public function __construct(
                private readonly ApiScopeMiddleware $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }

    private function request(string $scope): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/clients')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5, 'role_id' => 2]);
    }
}
