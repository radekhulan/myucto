<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\RequireMfaMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ApiRequestLogger;
use Psr\Log\NullLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaOfferService;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\TrustedDeviceService;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\IpMatcher;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class AuthMiddlewareReauthenticationTest extends TestCase
{
    public function testLegacyCookieDoesNotBlockIssuingReplacementStrongSession(): void
    {
        $oldToken = str_repeat('a', 64);
        $newToken = str_repeat('c', 64);
        $context = SessionAuthContext::strong(
            'totp',
            new \DateTimeImmutable('2026-07-25 10:00:00 UTC'),
        );
        $config = $this->config();

        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::never())->method('loadAuthenticationContext');
        $sessions->expects(self::never())->method('touchIfStale');
        $sessions->expects(self::once())
            ->method('create')
            ->with(17, '127.0.0.1', 'PHPUnit', $context)
            ->willReturn([
                'token' => $newToken,
                'csrf_token' => str_repeat('d', 64),
                'expires_at' => 1_774_694_400,
            ]);

        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with([inet_pton('127.0.0.1'), 'PHPUnit', 17])
            ->willReturn(true);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->expects(self::once())
            ->method('recordSuccess')
            ->with('synthetic@example.invalid', '127.0.0.1');
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())->method('log');
        $trustedDevices = $this->createMock(TrustedDeviceService::class);
        $trustedDevices->expects(self::never())->method('issue');
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(1);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('@1774690800'));

        $mfaOffers = $this->createMock(MfaOfferService::class);
        $mfaOffers->method('shouldOffer')->willReturn(false);
        $issuer = new LoginSessionIssuer(
            $db,
            $sessions,
            $bruteForce,
            $logger,
            $trustedDevices,
            $config,
            $clock,
            $credentials,
            new MfaPolicyService($config),
            $mfaOffers,
            new SessionLockPolicy($config),
            $this->roleProfileStub(),
        );
        $terminal = new class ($issuer, $context) implements RequestHandlerInterface {
            public function __construct(
                private readonly LoginSessionIssuer $issuer,
                private readonly SessionAuthContext $context,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_USER));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_SESSION));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_TOKEN));

                return $this->issuer->issue(
                    (new ResponseFactory())->createResponse(),
                    [
                        'id' => 17,
                        'email' => 'synthetic@example.invalid',
                        'name' => 'Synthetic User',
                        'role' => 'admin',
                        'locale' => 'cs',
                        'totp_enabled' => 1,
                        'session_lock_after_minutes' => null,
                    ],
                    '127.0.0.1',
                    'PHPUnit',
                    $this->context,
                );
            }
        };
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/login')
            ->withCookieParams(['__Host-myinvoice_session' => $oldToken]);

        $response = $this->authMiddleware($config, $sessions, $db)->process(
            $request,
            $this->throughMfaGuard($config, $terminal),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            '__Host-myinvoice_session=' . $newToken,
            $response->getHeaderLine('Set-Cookie'),
        );
        self::assertStringNotContainsString($oldToken, $response->getHeaderLine('Set-Cookie'));
    }

    #[DataProvider('otherFreshAuthenticationEndpoints')]
    public function testOtherFreshAuthenticationEndpointsIgnoreExistingSession(
        string $method,
        string $path,
    ): void {
        $config = $this->config();
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::never())->method('loadAuthenticationContext');
        $sessions->expects(self::never())->method('touchIfStale');
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $terminal = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_USER));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_SESSION));
                return (new ResponseFactory())->createResponse(204);
            }
        };
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams(['__Host-myinvoice_session' => str_repeat('a', 64)]);

        $response = $this->authMiddleware($config, $sessions, $db)->process(
            $request,
            $this->throughMfaGuard($config, $terminal),
        );

        self::assertSame(204, $response->getStatusCode(), "{$method} {$path}");
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function otherFreshAuthenticationEndpoints(): iterable
    {
        yield 'setup status' => ['GET', '/api/auth/setup-status'];
        yield 'passkey options' => ['POST', '/api/auth/webauthn/login/options'];
        yield 'passkey verification' => ['POST', '/api/auth/webauthn/login/verify'];
    }

    #[DataProvider('protectedOrNearMatchEndpoints')]
    public function testLegacySessionStillRequiresMfaOutsideExactFreshAuthenticationEndpoints(
        string $method,
        string $path,
    ): void {
        $token = str_repeat('a', 64);
        $config = $this->config();
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::once())
            ->method('loadAuthenticationContext')
            ->with($token)
            ->willReturn([
                'session' => [
                    'user_id' => 17,
                    'assurance_level' => 'legacy',
                    'last_seen' => 0,
                    'evaluated_at_epoch' => 0,
                ],
                'user' => [
                    'id' => 17,
                    'email' => 'synthetic@example.invalid',
                    'name' => 'Synthetic User',
                    'role' => 'admin',
                    'locale' => 'cs',
                    'is_active' => true,
                    'totp_enabled' => true,
                    'session_lock_after_minutes' => null,
                ],
            ]);
        $sessions->expects(self::once())
            ->method('touchIfStale')
            ->with($token, 0, 0);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $terminal = $this->createMock(RequestHandlerInterface::class);
        $terminal->expects(self::never())->method('handle');
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams(['__Host-myinvoice_session' => $token]);

        $response = $this->authMiddleware($config, $sessions, $db)->process(
            $request,
            $this->throughMfaGuard($config, $terminal),
        );

        self::assertSame(401, $response->getStatusCode(), "{$method} {$path}");
        self::assertSame(
            'mfa_reauthentication_required',
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code'],
        );
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function protectedOrNearMatchEndpoints(): iterable
    {
        yield 'protected business route' => ['GET', '/api/invoices'];
        yield 'wrong login method' => ['GET', '/api/auth/login'];
        yield 'wrong passkey options method' => ['GET', '/api/auth/webauthn/login/options'];
        yield 'passkey options prefix' => ['POST', '/api/auth/webauthn/login/options/extra'];
    }

    private function config(): Config
    {
        return new Config([
            'session' => [
                'cookie_name' => '__Host-myinvoice_session',
                'cookie_secure' => false,
                'cookie_samesite' => 'Lax',
                'lock_after_minutes' => 0,
            ],
            'auth' => [
                'require_mfa' => true,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]);
    }

    private function authMiddleware(
        Config $config,
        SessionManager $sessions,
        Connection $db,
    ): AuthMiddleware {
        return new AuthMiddleware(
            $config,
            $sessions,
            $db,
            new ResponseFactory(),
            $this->createMock(ApiTokenService::class),
            $this->createMock(IpMatcher::class),
            $this->roleProfileStub(),
            new ApiRequestLogger($this->createMock(Connection::class), new NullLogger()),
        );
    }

    private function throughMfaGuard(
        Config $config,
        RequestHandlerInterface $terminal,
    ): RequestHandlerInterface {
        $guard = new RequireMfaMiddleware(
            new MfaPolicyService($config),
            new ResponseFactory(),
        );

        return new class ($guard, $terminal) implements RequestHandlerInterface {
            public function __construct(
                private readonly RequireMfaMiddleware $guard,
                private readonly RequestHandlerInterface $terminal,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->guard->process($request, $this->terminal);
            }
        };
    }

    /**
     * MyÚčto doplňuje k účtu jemnozrnnou roli z tabulky `roles`. Tyhle testy
     * ověřují přechody session, ne autorizaci — profil proto vrací prázdno
     * a uživatele propouští beze změny.
     */
    private function roleProfileStub(): UserRoleProfile
    {
        $roleProfile = $this->createMock(UserRoleProfile::class);
        $roleProfile->method('enrich')->willReturnArgument(0);
        $roleProfile->method('forUser')->willReturn(
            ['id' => 0, 'name' => '', 'type' => '', 'is_active' => false, 'system_key' => null],
        );
        return $roleProfile;
    }
}
