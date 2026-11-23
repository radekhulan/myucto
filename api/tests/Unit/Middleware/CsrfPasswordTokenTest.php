<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\CsrfMiddleware;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Výměna hesla za API token volá nativní aplikace bez Origin. Výjimka z kontroly
 * originu nesmí pustit požadavek, který Origin má (prohlížeč, cizí web).
 */
final class CsrfPasswordTokenTest extends TestCase
{
    public function testNativeRequestWithoutOriginPasses(): void
    {
        $response = $this->middleware()->process($this->request('/api/auth/password-token'), $this->okHandler());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testBrowserRequestFromForeignOriginIsRejected(): void
    {
        $request = $this->request('/api/auth/password-token')->withHeader('Origin', 'https://attacker.invalid');

        $response = $this->middleware()->process($request, $this->okHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('origin_mismatch', json_decode((string) $response->getBody(), true)['error']['code'] ?? null);
    }

    public function testOtherPublicLoginRoutesStillRequireOrigin(): void
    {
        $response = $this->middleware()->process($this->request('/api/auth/login'), $this->okHandler());

        self::assertSame(403, $response->getStatusCode(), 'Výjimka platí jen pro výměnu hesla za token');
    }

    private function middleware(): CsrfMiddleware
    {
        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(false);

        return new CsrfMiddleware(
            new Config(['app' => ['url' => 'https://app.example.test', 'env' => 'production']]),
            new ResponseFactory(),
            $firstRun,
        );
    }

    private function request(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'https://app.example.test' . $path);
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
