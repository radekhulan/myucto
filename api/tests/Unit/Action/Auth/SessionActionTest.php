<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\SessionAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SessionLockMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasskeySessionTransitionService;
use MyInvoice\Service\Auth\PasskeyVerificationException;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\SessionLockPreferenceService;
use MyInvoice\Service\Auth\SessionLockResult;
use MyInvoice\Service\Auth\SessionLockService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\WebAuthnCeremony;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SessionActionTest extends TestCase
{
    public static function sessionIdentities(): iterable
    {
        yield 'běžný účet' => [false];
        yield 'technický správce' => [true];
    }

    #[DataProvider('sessionIdentities')]
    public function testStatusReturnsMinimalAuthoritativeContract(bool $technical): void
    {
        $role = [
            'id' => 3,
            'name' => $technical ? 'Správce instance' : 'Administrátor',
            'type' => $technical ? 'instance_admin' : 'internal',
            'is_active' => true,
            'system_key' => $technical ? 'instance_admin' : 'admin',
        ];
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::never())->method('evaluateForRequest');
        $lockResult = SessionLockResult::active(
            new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
            new \DateTimeImmutable('2026-07-24 12:05:00 UTC'),
        );
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())->method('countActiveForUser')->with(17)->willReturn(2);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('now');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('isAvailable')->willReturn(true);
        $action = new SessionAction(
            $this->createMock(SessionManager::class),
            $locks,
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 15]])),
            $this->createMock(SessionLockPreferenceService::class),
            $credentials,
            $passkeys,
            $this->createMock(WebAuthnCeremonyStore::class),
            $this->createMock(ActivityLogger::class),
            $this->createMock(IpMatcher::class),
            $clock,
            $this->createMock(BruteForceGuard::class),
            $this->createMock(SessionCookieFactory::class),
            $this->createMock(PasskeySessionTransitionService::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/session/status')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'role' => 'admin',
                'role_summary' => $role,
                'is_superadmin' => false,
                'is_instance_admin' => $technical,
            ])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'strong',
            ])
            ->withAttribute(SessionLockMiddleware::ATTR_RESULT, $lockResult);

        $response = $action->status($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'session_state' => 'active',
            'csrf_token' => str_repeat('b', 64),
            'server_time' => '2026-07-24T12:05:00.000000Z',
            'idle_expires_at' => '2026-07-24T12:15:00.000000Z',
            'lock_after_minutes' => 15,
            'unlock_methods' => ['passkey'],
            'user' => [
                'id' => 17,
                'email' => '',
                'name' => '',
                // Souhrn role, ne string — klient si tenhle objekt při zamčené
                // session ukládá jako plnohodnotného uživatele (viz SessionAction).
                'role' => $role,
                'is_superadmin' => false,
                'is_instance_admin' => $technical,
                'locale' => 'cs',
                'totp_enabled' => false,
            ],
        ], $body);
        self::assertArrayNotHasKey('role', $body);
        self::assertArrayNotHasKey('assurance_level', $body);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testManualLockWithoutPasskeyIsRejectedBeforeTransition(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::never())->method('lockManually');
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(0);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::once())->method('isAvailable')->willReturn(true);
        $action = new SessionAction(
            $this->createMock(SessionManager::class),
            $locks,
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 15]])),
            $this->createMock(SessionLockPreferenceService::class),
            $credentials,
            $passkeys,
            $this->createMock(WebAuthnCeremonyStore::class),
            $this->createMock(ActivityLogger::class),
            $this->createMock(IpMatcher::class),
            $this->createMock(ClockInterface::class),
            $this->createMock(BruteForceGuard::class),
            $this->createMock(SessionCookieFactory::class),
            $this->createMock(PasskeySessionTransitionService::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/session/lock')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => 'strong']);

        $response = $action->lock($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('session_unlock_unavailable', $body['error']['code']);
    }

    public function testMalformedUnlockConsumesCeremonyAndRecordsFailure(): void
    {
        $transitions = $this->createMock(PasskeySessionTransitionService::class);
        $transitions->expects(self::once())
            ->method('completeUnlock')
            ->with(
                'flow-token',
                [],
                17,
                str_repeat('a', 64),
            )
            ->willThrowException(new PasskeyVerificationException('Ověření passkey selhalo.'));
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->expects(self::once())->method('isPasskeyLocked')->with(17)->willReturn(false);
        $bruteForce->expects(self::once())->method('recordPasskeyFailure')->with(17);
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                'auth.session_unlock_failed',
                17,
                'user',
                17,
                null,
                '127.0.0.1',
                'PHPUnit',
            );
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $clock = $this->createMock(ClockInterface::class);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('isAvailable')->willReturn(true);
        $action = new SessionAction(
            $this->createMock(SessionManager::class),
            $this->createMock(SessionLockService::class),
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 15]])),
            $this->createMock(SessionLockPreferenceService::class),
            $this->createMock(PasskeyCredentialRepository::class),
            $passkeys,
            $this->createMock(WebAuthnCeremonyStore::class),
            $logger,
            $ipMatcher,
            $clock,
            $bruteForce,
            $this->createMock(SessionCookieFactory::class),
            $transitions,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/session/unlock/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => 'strong'])
            ->withParsedBody(['flow_token' => 'flow-token']);

        $response = $action->unlockVerify($request, (new ResponseFactory())->createResponse());

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('session_unlock_failed', $body['error']['code']);
    }

    public function testUpdateLockPreferenceReturnsEffectivePolicyAndCurrentSessionState(): void
    {
        $preferences = $this->createMock(SessionLockPreferenceService::class);
        $preferences->expects(self::once())->method('get')->with(17)->willReturn([
            'user_lock_after_minutes' => null,
            'admin_lock_after_minutes' => 15,
            'maximum_lock_after_minutes' => 15,
            'effective_lock_after_minutes' => 15,
        ]);
        $preferences->expects(self::once())->method('update')->with(17, 5)->willReturn([
            'user_lock_after_minutes' => 5,
            'admin_lock_after_minutes' => 15,
            'maximum_lock_after_minutes' => 15,
            'effective_lock_after_minutes' => 5,
        ]);
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::once())
            ->method('evaluateForRequest')
            ->with(
                str_repeat('a', 64),
                [
                    'csrf_token' => str_repeat('b', 64),
                    'assurance_level' => 'strong',
                ],
                5,
            )
            ->willReturn(SessionLockResult::active(
                new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
                new \DateTimeImmutable('2026-07-24 12:01:00 UTC'),
            ));
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->method('countActiveForUser')->willReturn(1);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('isAvailable')->willReturn(true);
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                'auth.session_lock_preference_changed',
                17,
                'user',
                17,
                [
                    'lock_after_minutes' => 5,
                    'effective_lock_after_minutes' => 5,
                ],
                '127.0.0.1',
                'PHPUnit',
            );
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $action = new SessionAction(
            $this->createMock(SessionManager::class),
            $locks,
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 15]])),
            $preferences,
            $credentials,
            $passkeys,
            $this->createMock(WebAuthnCeremonyStore::class),
            $logger,
            $ipMatcher,
            $this->createMock(ClockInterface::class),
            $this->createMock(BruteForceGuard::class),
            $this->createMock(SessionCookieFactory::class),
            $this->createMock(PasskeySessionTransitionService::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/auth/session/lock-preference')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'role' => 'readonly',
                'session_lock_after_minutes' => null,
            ])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'strong',
            ])
            ->withParsedBody(['lock_after_minutes' => 5]);

        $response = $action->updateLockPreference(
            $request,
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(5, $body['user_lock_after_minutes']);
        self::assertSame(15, $body['admin_lock_after_minutes']);
        self::assertSame(5, $body['effective_lock_after_minutes']);
        self::assertSame(5, $body['session']['lock_after_minutes']);
        self::assertSame('2026-07-24T12:05:00.000000Z', $body['session']['idle_expires_at']);
    }
}
