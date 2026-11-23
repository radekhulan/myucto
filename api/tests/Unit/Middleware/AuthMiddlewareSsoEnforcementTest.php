<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\ApiRequestLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tests\Support\FakeOidcIdp;
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
 * Vynucené přihlášení přes poskytovatele identity platí pro celou instalaci na
 * vstupu každého požadavku. Sezení z hesla, passkey nebo záložního kódu ani API
 * token vydaný z takového sezení neprojdou, a to ani technickému správci instance.
 */
#[AllowMockObjectsWithoutExpectations]
final class AuthMiddlewareSsoEnforcementTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return iterable<string,array{string,string,int}> */
    public static function sessions(): iterable
    {
        yield 'heslo' => ['password', 'admin', 401];
        yield 'passkey' => ['passkey', 'admin', 401];
        yield 'jiný poskytovatel' => ['oidc:seznam', 'admin', 401];
        yield 'heslo správce instance' => ['password', 'instance_admin', 401];
        yield 'Entra' => ['oidc:entra', 'admin', 204];
        yield 'Entra správce instance' => ['oidc:entra', 'instance_admin', 204];
    }

    #[DataProvider('sessions')]
    public function testEnforcedInstallationAcceptsOnlyProviderSessions(string $method, string $role, int $status): void
    {
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('loadAuthenticationContext')->willReturn([
            'session' => ['user_id' => 17, 'auth_method' => $method, 'csrf_token' => str_repeat('b', 64)],
            'user' => ['id' => 17, 'email' => 'synthetic@example.invalid', 'name' => 'Synthetic', 'role' => $role,
                'locale' => 'cs', 'is_active' => true, 'totp_enabled' => false],
        ]);
        $sessions->expects($status === 401 ? self::once() : self::never())->method('destroy')->with(self::TOKEN);

        $response = $this->middleware($sessions, $this->createStub(ApiTokenService::class), true)->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
                ->withCookieParams(['__Host-myinvoice_session' => self::TOKEN]),
            $this->handler(),
        );

        self::assertSame($status, $response->getStatusCode());
        if ($status === 401) {
            self::assertStringContainsString('"sso_required"', (string) $response->getBody());
        }
    }

    public function testPasswordSessionPassesWithoutEnforcement(): void
    {
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('loadAuthenticationContext')->willReturn([
            'session' => ['user_id' => 17, 'auth_method' => 'password', 'csrf_token' => str_repeat('b', 64)],
            'user' => ['id' => 17, 'email' => 'synthetic@example.invalid', 'name' => 'Synthetic', 'role' => 'admin',
                'locale' => 'cs', 'is_active' => true, 'totp_enabled' => false],
        ]);
        $sessions->expects(self::never())->method('destroy');

        $response = $this->middleware($sessions, $this->createStub(ApiTokenService::class), false)->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
                ->withCookieParams(['__Host-myinvoice_session' => self::TOKEN]),
            $this->handler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    /** @return iterable<string,array{?string,int}> */
    public static function tokens(): iterable
    {
        yield 'z hesla' => ['password', 401];
        yield 'starý bez údaje' => [null, 401];
        yield 'z Entra' => ['oidc:entra', 204];
    }

    #[DataProvider('tokens')]
    public function testBearerTokenIsJudgedByTheSessionItWasIssuedFrom(?string $issuedAuthMethod, int $status): void
    {
        $tokens = $this->createStub(ApiTokenService::class);
        $tokens->method('validate')->willReturn([
            'id' => 7, 'user_id' => 3, 'supplier_id' => 1, 'scope' => 'read', 'audience' => 'api',
            'issued_auth_method' => $issuedAuthMethod,
            'user_email' => 'token@example.test', 'user_name' => 'Token', 'user_role_id' => 2,
            'user_role_name' => 'Účetní', 'user_role_type' => 'system', 'user_role_active' => 1,
            'user_role_system_key' => 'accountant', 'user_locale' => 'cs', 'user_totp_enabled' => 0,
        ]);
        $tokens->method('ipRulesFor')->willReturn([]);

        $response = $this->middleware($this->createStub(SessionManager::class), $tokens, true)->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/invoices')
                ->withHeader('Authorization', 'Bearer synthetic-token'),
            $this->handler(),
        );

        self::assertSame($status, $response->getStatusCode());
    }

    private function middleware(SessionManager $sessions, ApiTokenService $tokens, bool $enforced): AuthMiddleware
    {
        $config = new Config([
            'app' => ['url' => 'https://ucto.example.test'],
            'session' => ['cookie_name' => '__Host-myinvoice_session'],
            'auth' => ['oidc' => [
                'enabled' => true,
                'required_provider' => $enforced ? 'entra' : '',
                'providers' => [
                    'entra' => ['enabled' => true, 'tenant_id' => FakeOidcIdp::TENANT,
                        'client_id' => FakeOidcIdp::CLIENT, 'client_secret' => 'test-secret'],
                    'seznam' => ['enabled' => true, 'client_id' => 'seznam-client', 'client_secret' => 'seznam-secret'],
                ],
            ]],
        ]);
        $roleProfile = $this->createMock(UserRoleProfile::class);
        $roleProfile->method('enrich')->willReturnArgument(0);
        return new AuthMiddleware(
            $config,
            $sessions,
            $this->createStub(Connection::class),
            new ResponseFactory(),
            $tokens,
            $this->createStub(IpMatcher::class),
            $roleProfile,
            new ApiRequestLogger($this->createStub(Connection::class), new NullLogger()),
            null,
            null,
            new LoginPolicyService($config, new OidcProviderRegistry($config)),
        );
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
