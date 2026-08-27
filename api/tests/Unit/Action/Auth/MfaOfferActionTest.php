<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\MfaOfferAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaOfferService;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class MfaOfferActionTest extends TestCase
{
    public function testOdmitnutiNabidkySeZapiseAZaloguje(): void
    {
        $offers = $this->createMock(MfaOfferService::class);
        $offers->expects(self::once())->method('dismiss')->with(17)->willReturn(true);
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())
            ->method('log')
            ->with('auth.mfa_offer_dismissed', 17, 'user', 17);

        $response = $this->dismiss($this->sessionRequest(), $offers, $logger);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['dismissed']);
    }

    public function testVynuceneMfaOdmitnoutNelze(): void
    {
        // Kdyby endpoint při `require_mfa = true` tiše uspěl, stačil by jeden POST
        // a povinná MFA by přestala platit. Odmítnutí musí být hlasité.
        $offers = $this->createMock(MfaOfferService::class);
        $offers->expects(self::once())->method('dismiss')->with(17)->willReturn(false);
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::never())->method('log');

        $response = $this->dismiss($this->sessionRequest(), $offers, $logger);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('mfa_required', $body['error']['code']);
    }

    public function testApiTokenNabidkuOdmitnoutNemuze(): void
    {
        $offers = $this->createMock(MfaOfferService::class);
        $offers->expects(self::never())->method('dismiss');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/mfa/offer/dismiss')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]);

        $response = $this->dismiss($request, $offers, $this->createMock(ActivityLogger::class));
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $body['error']['code']);
    }

    private function sessionRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/mfa/offer/dismiss')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]);
    }

    private function dismiss(
        ServerRequestInterface $request,
        MfaOfferService $offers,
        ActivityLogger $logger,
    ): \Psr\Http\Message\ResponseInterface {
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $action = new MfaOfferAction($offers, $logger, $ipMatcher);

        return $action->dismiss($request, (new ResponseFactory())->createResponse());
    }
}
