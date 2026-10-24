<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Action\Auth\LogoutAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ApiRequestLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\SessionManager;
use Psr\Log\NullLogger;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\DomainLoginSessionMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Service\Auth\DomainLoginSessionCookie;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DomainLoginSessionMiddlewareTest extends TestCase
{
    private const REQUEST = 'rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr';
    private const STATE = 'sssssssssssssssssssssssssssssssssssssssssss';
    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NAME = '__Host-myinvoice_session';
    private const NOW = 1_900_000_000;

    public function testOrdinaryRequestKeepsCanonicalCookieWithoutDatabaseAccess(): void
    {
        $request = $this->request()->withoutHeader(DomainLoginSessionMiddleware::REQUEST_HEADER)
            ->withoutHeader(DomainLoginSessionMiddleware::STATE_HEADER)
            ->withCookieParams([self::NAME => self::SESSION]);
        $response = $this->middleware(null)->process($request, $this->handler(function ($request) {
            self::assertSame(self::SESSION, $request->getCookieParams()[self::NAME]);
            return $this->response();
        }));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCanonicalSessionCannotBeSelectedForClientLogin(): void
    {
        $this->middleware()->process($this->request()->withCookieParams([
            self::NAME => self::SESSION, 'other' => 'keep',
        ]), $this->handler(function ($request) {
            self::assertArrayNotHasKey(self::NAME, $request->getCookieParams());
            self::assertSame('keep', $request->getCookieParams()['other']);
            self::assertNull($request->getAttribute(DomainLoginSessionMiddleware::ATTR_VERIFIED_FLOW));
            return $this->response();
        }));
    }

    #[DataProvider('loginPaths')]
    public function testFreshPasswordAndPasswordlessLoginUseIsolatedEncryptedCookie(string $path): void
    {
        $cookie = (new SessionCookieFactory($this->config(), $this->clock()))->create(self::SESSION, self::NOW + 86400);
        $response = $this->middleware()->process($this->request($path), $this->handler(
            fn () => $this->response()->withHeader('Set-Cookie', [$cookie, 'trusted_device=keep; HttpOnly']),
        ));
        $headers = $response->getHeader('Set-Cookie');
        self::assertCount(2, $headers);
        self::assertSame('trusted_device=keep; HttpOnly', $headers[1]);
        self::assertStringStartsWith($this->codec()->name(self::REQUEST) . '=', $headers[0]);
        self::assertStringNotContainsString(self::SESSION, $headers[0]);
        self::assertStringContainsString('HttpOnly', $headers[0]);
        self::assertStringContainsString('Secure', $headers[0]);
        self::assertStringContainsString('Max-Age=120', $headers[0]);
        self::assertStringNotContainsString('Max-Age=86400', $headers[0]);
        self::assertSame(self::SESSION, $this->codec()->open($this->cookieValue($headers[0]), self::REQUEST, self::STATE));
    }

    public static function loginPaths(): array
    {
        return [['/api/auth/login'], ['/api/auth/webauthn/login/verify']];
    }

    #[DataProvider('rotationPaths')]
    public function testVerifiedFlowSurvivesMfaSetupAndSessionRotation(string $path): void
    {
        $newToken = str_repeat('b', 64);
        $params = [self::NAME => str_repeat('c', 64), $this->codec()->name(self::REQUEST) => $this->sealed()];
        $response = $this->middleware()->process($this->request($path)->withCookieParams($params),
            $this->handler(function ($request) use ($newToken) {
                self::assertSame(self::SESSION, $request->getCookieParams()[self::NAME]);
                self::assertSame([
                    'request_token_hash' => hash('sha256', self::REQUEST),
                    'state_hash' => hash('sha256', self::STATE),
                ], $request->getAttribute(DomainLoginSessionMiddleware::ATTR_VERIFIED_FLOW));
                return $this->response()->withHeader('Set-Cookie', self::NAME . '=' . $newToken . '; HttpOnly; Path=/; Max-Age=86400; Secure');
            }));
        self::assertSame($newToken, $this->codec()->open($this->cookieValue($response->getHeader('Set-Cookie')[0]), self::REQUEST, self::STATE));
    }

    public static function rotationPaths(): array
    {
        return [['/api/auth/totp/confirm'], ['/api/auth/webauthn/register/verify'], ['/api/auth/session/unlock/verify']];
    }

    public function testLogoutAfterExchangeOrExpiryDeletesOnlyItsOwnFlowCookie(): void
    {
        $response = $this->middleware(null)->process(
            $this->request('/api/auth/logout')->withCookieParams([
                self::NAME => str_repeat('b', 64), $this->codec()->name(self::REQUEST) => $this->sealed(),
            ]), $this->handler(function ($request) {
                self::assertSame(self::SESSION, $request->getCookieParams()[self::NAME]);
                return $this->response()->withHeader('Set-Cookie', self::NAME . '=; HttpOnly; Max-Age=0; Path=/; Secure');
            }),
        );
        self::assertSame($this->codec()->name(self::REQUEST) . '=; HttpOnly; Max-Age=0; Path=/; Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testCleanupStatusAfterExpiryDoesNotNeedActiveFlow(): void
    {
        $response = $this->middleware(null)->process($this->request('/api/auth/session/status', 'GET')
            ->withCookieParams([$this->codec()->name(self::REQUEST) => $this->sealed()]),
            $this->handler(function ($request) {
                self::assertSame(self::SESSION, $request->getCookieParams()[self::NAME]);
                return $this->response();
            }));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testTwoConcurrentFlowsCannotUseEachOthersCookies(): void
    {
        $response = $this->middleware()->process($this->request()->withHeader(
            DomainLoginSessionMiddleware::REQUEST_HEADER, str_repeat('t', 43),
        )->withCookieParams([$this->codec()->name(self::REQUEST) => $this->sealed()]),
            $this->handler(function ($request) {
                self::assertArrayNotHasKey(self::NAME, $request->getCookieParams());
                self::assertNull($request->getAttribute(DomainLoginSessionMiddleware::ATTR_VERIFIED_FLOW));
                return $this->response();
            }));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testExpiredOrInactiveFlowFailsBeforeHandler(): void
    {
        $response = $this->middleware(false)->process($this->request(), $this->handler(function () {
            self::fail('Neplatná flow se nesmí dostat k akci.');
        }));
        self::assertSame(400, $response->getStatusCode());
    }

    #[DataProvider('invalidHeaders')]
    public function testInvalidOrIncompleteHeadersFailClosed(array $headers): void
    {
        $request = $this->request()->withoutHeader(DomainLoginSessionMiddleware::REQUEST_HEADER)
            ->withoutHeader(DomainLoginSessionMiddleware::STATE_HEADER);
        foreach ($headers as $name => $value) $request = $request->withHeader($name, $value);
        $response = $this->middleware(null)->process($request, $this->handler(fn () => self::fail('Neplatné hlavičky nesmí projít.')));
        self::assertSame(400, $response->getStatusCode());
    }

    public static function invalidHeaders(): array
    {
        return [
            [[DomainLoginSessionMiddleware::REQUEST_HEADER => self::REQUEST]],
            [[DomainLoginSessionMiddleware::STATE_HEADER => self::STATE]],
            [[DomainLoginSessionMiddleware::REQUEST_HEADER => 'bad', DomainLoginSessionMiddleware::STATE_HEADER => self::STATE]],
            [[DomainLoginSessionMiddleware::REQUEST_HEADER => [self::REQUEST, self::REQUEST], DomainLoginSessionMiddleware::STATE_HEADER => self::STATE]],
            [[DomainLoginSessionMiddleware::REQUEST_HEADER => self::REQUEST, DomainLoginSessionMiddleware::STATE_HEADER => self::STATE, 'Authorization' => 'Bearer test']],
        ];
    }

    #[DataProvider('forgedCookies')]
    public function testPlaintextAndLegacyCiphertextsNeverEstablishVerifiedFlow(string $value): void
    {
        $response = $this->middleware()->process($this->request()->withCookieParams([
            self::NAME => self::SESSION, $this->codec()->name(self::REQUEST) => $value,
        ]), $this->handler(function ($request) {
            self::assertArrayNotHasKey(self::NAME, $request->getCookieParams());
            self::assertNull($request->getAttribute(DomainLoginSessionMiddleware::ATTR_VERIFIED_FLOW));
            return $this->response();
        }));
        self::assertSame(200, $response->getStatusCode());
    }

    public static function forgedCookies(): array
    {
        return [[self::SESSION], [rtrim(strtr(base64_encode(self::SESSION), '+/', '-_'), '=')],
            [rtrim(strtr(base64_encode('enc:v1:' . self::SESSION), '+/', '-_'), '=')], ['invalid!']];
    }

    #[DataProvider('cleanupPaths')]
    public function testExpiredCookieCleanupCannotFallBackToCanonicalAccount(string $method, string $path): void
    {
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::never())->method('loadAuthenticationContext');
        $sessions->expects(self::never())->method('destroy');
        $response = $this->middleware(null)->process($this->request($path, $method)->withCookieParams([
            self::NAME => self::SESSION,
        ]), $this->throughAuth($sessions, $this->handler(fn () => self::fail('Bez dočasné cookie nesmí canonical účet projít.'))));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthenticated', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public static function cleanupPaths(): array
    {
        return [['POST', '/api/auth/logout'], ['GET', '/api/auth/session/status']];
    }

    public function testActualAuthenticationAndLogoutUseOnlyVerifiedTemporarySession(): void
    {
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::once())->method('loadAuthenticationContext')->with(self::SESSION)->willReturn([
            'session' => ['user_id' => 42, 'last_seen' => self::NOW, 'evaluated_at_epoch' => self::NOW],
            'user' => ['id' => 42, 'is_active' => true, 'locale' => 'cs'],
        ]);
        $sessions->expects(self::once())->method('touchIfStale')->with(self::SESSION, self::NOW, self::NOW);
        $sessions->expects(self::once())->method('destroy')->with(self::SESSION);
        $logout = new LogoutAction($sessions, $this->config(), $this->createStub(ActivityLogger::class), new IpMatcher());
        $response = $this->middleware(null)->process($this->request('/api/auth/logout')->withCookieParams([
            self::NAME => str_repeat('c', 64), $this->codec()->name(self::REQUEST) => $this->sealed(),
        ]), $this->throughAuth($sessions, $this->handler(function ($request) use ($logout) {
            self::assertSame(42, $request->getAttribute(AuthMiddleware::ATTR_USER)['id']);
            return $logout($request, $this->response());
        })));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith($this->codec()->name(self::REQUEST) . '=;', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
    }

    public function testCustomDomainCannotOptIntoCanonicalFlowHeaders(): void
    {
        $response = $this->middleware(null)->process($this->request()->withAttribute(
            TenantDomainMiddleware::ATTR_CONTEXT,
            new TenantDomainContext(TenantDomainContext::CUSTOM, 'client.example.test', 'https://app.example.test', 1, 2, 'portal', 'active'),
        ), $this->handler(fn () => self::fail('Hlavičky flow patří jen na canonical origin.')));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testUnverifiedNonLoginResponseCannotMintFlowCookie(): void
    {
        $response = $this->middleware()->process($this->request('/api/auth/totp/confirm'), $this->handler(
            fn () => $this->response()->withHeader('Set-Cookie', self::NAME . '=' . self::SESSION . '; HttpOnly'),
        ));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    private function throughAuth(SessionManager $sessions, RequestHandlerInterface $terminal): RequestHandlerInterface
    {
        $db = $this->createStub(Connection::class);
        $roles = $this->createStub(UserRoleProfile::class);
        $roles->method('enrich')->willReturnArgument(0);
        $auth = new AuthMiddleware($this->config(), $sessions, $db, new ResponseFactory(),
            $this->createStub(ApiTokenService::class), new IpMatcher(), $roles,
            new ApiRequestLogger($db, new NullLogger()));
        return $this->handler(fn ($request) => $auth->process($request, $terminal));
    }

    public function testEnvelopeAuthenticatesStateAndRequestAndCiphertext(): void
    {
        $codec = $this->codec();
        $sealed = $this->sealed();
        self::assertNull($codec->open($sealed, str_repeat('t', 43), self::STATE));
        self::assertNull($codec->open($sealed, self::REQUEST, str_repeat('t', 43)));
        $sealed[40] = $sealed[40] === 'A' ? 'B' : 'A';
        self::assertNull($codec->open($sealed, self::REQUEST, self::STATE));
    }

    private function config(): Config
    {
        return new Config(['app' => ['secret_encryption_key' => base64_encode(str_repeat('x', 32))]]);
    }

    private function codec(): DomainLoginSessionCookie
    {
        return new DomainLoginSessionCookie($this->config(), new SecretEncryption($this->config()));
    }

    private function sealed(): string
    {
        return $this->codec()->seal(self::SESSION, self::REQUEST, self::STATE);
    }

    private function clock(): ClockInterface
    {
        return new class (self::NOW) implements ClockInterface {
            public function __construct(private readonly int $timestamp) {}
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('@' . $this->timestamp); }
        };
    }

    private function middleware(?bool $valid = true): DomainLoginSessionMiddleware
    {
        $db = $valid === null ? $this->createMock(Connection::class) : $this->createStub(Connection::class);
        if ($valid === null) {
            $db->expects(self::never())->method('pdo');
        } else {
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('fetch')->willReturn($valid ? ['expires_at' => self::NOW + 120] : false);
            $pdo = $this->createStub(PDO::class);
            $pdo->method('prepare')->willReturn($stmt);
            $db->method('pdo')->willReturn($pdo);
        }
        return new DomainLoginSessionMiddleware($db, $this->codec(), $this->clock(), new ResponseFactory());
    }

    private function request(string $path = '/api/auth/login', string $method = 'POST'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, 'https://app.example.test' . $path)
            ->withHeader(DomainLoginSessionMiddleware::REQUEST_HEADER, self::REQUEST)
            ->withHeader(DomainLoginSessionMiddleware::STATE_HEADER, self::STATE)
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext(
                TenantDomainContext::CANONICAL, 'app.example.test', 'https://app.example.test',
            ));
    }

    private function handler(\Closure $callback): RequestHandlerInterface
    {
        return new class ($callback) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $callback) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return ($this->callback)($request); }
        };
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    private function cookieValue(string $cookie): string
    {
        return explode(';', explode('=', $cookie, 2)[1], 2)[0];
    }
}
