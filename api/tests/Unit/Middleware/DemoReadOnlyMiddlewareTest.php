<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DemoReadOnlyMiddlewareTest extends TestCase
{
    public function testDisabledModeDoesNotChangeRequests(): void
    {
        $response = $this->middleware(false)->process(
            $this->request('POST', '/api/invoices'),
            $this->handler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[DataProvider('allowedRequestProvider')]
    public function testAllowsReadAndTechnicalRequests(string $method, string $path): void
    {
        $response = $this->middleware(true)->process($this->request($method, $path), $this->handler());

        self::assertSame(204, $response->getStatusCode(), $method . ' ' . $path);
    }

    public static function allowedRequestProvider(): array
    {
        return [
            ['GET', '/api/invoices'],
            ['HEAD', '/api/reports/dph'],
            ['OPTIONS', '/api/invoices'],
            ['POST', '/api/auth/login'],
            ['POST', '/api/auth/logout'],
            ['POST', '/api/accounting/payroll/preview'],
            ['POST', '/api/tax-return/dpfo/reconcile'],
            ['POST', '/api/catalog/products/batch'],
            ['POST', '/api/catalog/prices/batch'],
            ['POST', '/api/stock/items/42/neighbors'],
        ];
    }

    #[DataProvider('blockedRequestProvider')]
    public function testBlocksBusinessAndAccountMutations(string $method, string $path): void
    {
        $response = $this->middleware(true)->process($this->request($method, $path), $this->handler());

        self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
        self::assertStringContainsString('demo_read_only', (string) $response->getBody());
    }

    public static function blockedRequestProvider(): array
    {
        return [
            ['POST', '/api/invoices'],
            ['POST', '/api/catalog/exports'],
            ['PUT', '/api/purchase-invoices/1'],
            ['PATCH', '/api/accounting/journal/1/description'],
            ['DELETE', '/api/clients/1'],
            ['POST', '/api/auth/change-password'],
            ['POST', '/api/auth/totp/setup'],
            ['POST', '/api/auth/forgot'],
            ['POST', '/api/auth/setup'],
            ['POST', '/api/public/approval/abc/decide'],
            ['POST', '/api/admin/cron-jobs/cron-cleanup/run'],
        ];
    }

    public function testMarksAllowedRequestAsDemo(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $status = DemoReadOnlyMiddleware::enabled($request) ? 204 : 500;
                return (new ResponseFactory())->createResponse($status);
            }
        };

        $response = $this->middleware(true)->process($this->request('GET', '/api/clients/1/vat-status'), $handler);
        self::assertSame(204, $response->getStatusCode());
    }

    private function middleware(bool $enabled): DemoReadOnlyMiddleware
    {
        return new DemoReadOnlyMiddleware(
            new Config(['demo' => ['enabled' => $enabled]]),
            new RoutePermissionMap(),
            new ResponseFactory(),
        );
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
