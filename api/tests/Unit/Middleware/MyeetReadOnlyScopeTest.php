<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Auth\MyeetCustomerProvisioner;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MyeetReadOnlyScopeTest extends TestCase
{
    public function testGraceAllowsTenantExportAndRejectsWrites(): void
    {
        $now = time();
        $middleware = $this->middleware(['valid_until' => $now - 60, 'readonly_until' => $now + 60]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(static function ($request) {
            self::assertSame(11, $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID));
            self::assertSame(11, $request->getAttribute(SupplierScopeMiddleware::ATTR_MYEET_READONLY_SUPPLIER_ID));
            return (new ResponseFactory())->createResponse(200);
        });

        self::assertSame(200, $middleware->process($this->request('GET', 'eet.myucto.online', '/api/eet/export'), $handler)->getStatusCode());
        $blocked = $middleware->process($this->request('POST', 'eet.myucto.online', '/api/eet/payments'), $handler);
        self::assertSame(402, $blocked->getStatusCode());
        self::assertStringContainsString('myeet_license_readonly', (string) $blocked->getBody());
    }

    public function testExpiredGraceBlocksCompanyButAllowsAccountAndLeavesOtherHostsAlone(): void
    {
        $now = time();
        $middleware = $this->middleware(['valid_until' => $now - 120, 'readonly_until' => $now - 60]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->exactly(2))->method('handle')->willReturnCallback(static function ($request) {
            self::assertNull($request->getAttribute(SupplierScopeMiddleware::ATTR_MYEET_READONLY_SUPPLIER_ID));
            return (new ResponseFactory())->createResponse(200);
        });

        self::assertSame(402, $middleware->process($this->request('GET', 'eet.myucto.online', '/api/eet/export'), $handler)->getStatusCode());
        self::assertSame(200, $middleware->process($this->request('GET', 'eet.myucto.online', '/api/auth/me'), $handler)->getStatusCode());
        self::assertSame(200, $middleware->process($this->request('POST', 'other.example.test', '/api/eet/payments'), $handler)->getStatusCode());
    }

    private function middleware(array $license): SupplierScopeMiddleware
    {
        $access = $this->createStub(SupplierAccessResolver::class);
        $access->method('resolve')->willReturn(new SupplierAccess(11, false, null));
        $myeet = $this->createStub(MyeetCustomerProvisioner::class);
        $myeet->method('licenseAccess')->willReturn($license);

        return new SupplierScopeMiddleware(
            $access,
            new ResponseFactory(),
            new Config(['app' => ['url' => 'https://eet.myucto.online'], 'myeet' => ['sale_managed' => true]]),
            $myeet,
        );
    }

    private function request(string $method, string $host, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, 'https://' . $host . $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 7]);
    }
}
