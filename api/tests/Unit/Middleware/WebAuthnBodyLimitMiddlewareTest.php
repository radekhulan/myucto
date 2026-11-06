<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\WebAuthnBodyLimitMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class WebAuthnBodyLimitMiddlewareTest extends TestCase
{
    public function testOversizedVerifyPayloadIsRejectedBeforeHandler(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/login/verify')
            ->withBody((new StreamFactory())->createStream(str_repeat('x', 65_537)));

        $response = (new WebAuthnBodyLimitMiddleware(new ResponseFactory()))
            ->process($request, $handler);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(
            'request_too_large',
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code'],
        );
    }

    public function testOversizedCredentialTestPayloadIsRejectedBeforeHandler(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/credentials/7/test/verify')
            ->withBody((new StreamFactory())->createStream(str_repeat('x', 65_537)));

        $response = (new WebAuthnBodyLimitMiddleware(new ResponseFactory()))
            ->process($request, $handler);

        self::assertSame(413, $response->getStatusCode());
    }

    public function testLimitDoesNotAffectOtherEndpoints(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/documents/upload')
            ->withBody((new StreamFactory())->createStream(str_repeat('x', 65_537)));

        $response = (new WebAuthnBodyLimitMiddleware(new ResponseFactory()))
            ->process($request, $this->handler());

        self::assertSame(204, $response->getStatusCode());
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
