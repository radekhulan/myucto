<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\ApiRequestLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Session z domain-login výměny drží hostname vlastní domény, tedy i strana,
 * která ovládá její DNS. Na canonical adrese ani na jiné doméně proto nesmí
 * platit a vlastní doména zase nesmí přijmout canonical session.
 */
#[AllowMockObjectsWithoutExpectations]
final class AuthMiddlewareDomainBindingTest extends TestCase
{
    private const COOKIE = '__Host-myinvoice_session';

    /** @return iterable<string,array{?int,?TenantDomainContext,bool}> */
    public static function bindingCases(): iterable
    {
        yield 'domain session replayed on canonical' => [5, self::canonical(), false];
        yield 'domain session without host context' => [5, null, false];
        yield 'domain session on its own domain' => [5, self::custom(5), true];
        yield 'domain session on another domain' => [5, self::custom(6), false];
        yield 'canonical session on canonical' => [null, self::canonical(), true];
        yield 'canonical session without host context' => [null, null, true];
        yield 'canonical session on custom domain' => [null, self::custom(5), false];
    }

    #[DataProvider('bindingCases')]
    public function testSessionIsAcceptedOnlyOnItsHost(
        ?int $sessionDomainId,
        ?TenantDomainContext $context,
        bool $accepted,
    ): void {
        $token = str_repeat('a', 64);
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::once())->method('loadAuthenticationContext')->with($token)->willReturn([
            'session' => [
                'user_id' => 17,
                'supplier_domain_id' => $sessionDomainId,
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'strong',
                'last_seen' => 1_721_822_400,
                'evaluated_at_epoch' => 1_721_822_400,
            ],
            'user' => self::user(),
        ]);
        $sessions->expects(self::never())->method('destroy');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($accepted ? self::once() : self::never())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $request): ResponseInterface {
                TestCase::assertSame(17, $request->getAttribute(AuthMiddleware::ATTR_USER)['id'] ?? null);
                return (new ResponseFactory())->createResponse(204);
            });

        $response = $this->middleware($sessions)->process($this->request('GET', '/api/auth/me', $context), $handler);

        self::assertSame($accepted ? 204 : 401, $response->getStatusCode());
    }

    public function testDomainSessionTombstoneCannotLogOutThroughCanonical(): void
    {
        $token = str_repeat('a', 64);
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('loadAuthenticationContext')->willReturn(null);
        $sessions->expects(self::once())->method('loadTombstoneForLogout')->with($token)->willReturn([
            'user_id' => 17,
            'supplier_domain_id' => 5,
            'csrf_token' => str_repeat('b', 64),
            'assurance_level' => 'strong',
        ]);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware($sessions, $db)
            ->process($this->request('POST', '/api/auth/logout', self::canonical()), $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    private function middleware(SessionManager $sessions, ?Connection $db = null): AuthMiddleware
    {
        $roleProfile = $this->createMock(UserRoleProfile::class);
        $roleProfile->method('enrich')->willReturnArgument(0);
        return new AuthMiddleware(
            new Config(['session' => ['cookie_name' => self::COOKIE]]),
            $sessions,
            $db ?? $this->createMock(Connection::class),
            new ResponseFactory(),
            $this->createMock(ApiTokenService::class),
            $this->createMock(IpMatcher::class),
            $roleProfile,
            new ApiRequestLogger($this->createMock(Connection::class), new NullLogger()),
            new NullLogger(),
        );
    }

    private function request(string $method, string $path, ?TenantDomainContext $context): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams([self::COOKIE => str_repeat('a', 64)]);
        return $context !== null
            ? $request->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $context)
            : $request;
    }

    private static function canonical(): TenantDomainContext
    {
        return new TenantDomainContext(
            TenantDomainContext::CANONICAL,
            'app.example.test',
            'https://app.example.test',
        );
    }

    private static function custom(int $domainId): TenantDomainContext
    {
        $hostname = 'portal-' . $domainId . '.example.test';
        return new TenantDomainContext(
            TenantDomainContext::CUSTOM,
            $hostname,
            'https://' . $hostname,
            $domainId,
            3,
            'portal',
            'active',
        );
    }

    /** @return array<string,mixed> */
    private static function user(): array
    {
        return [
            'id' => 17,
            'email' => 'synthetic@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'is_active' => true,
            'totp_enabled' => false,
            'session_lock_after_minutes' => null,
        ];
    }
}
