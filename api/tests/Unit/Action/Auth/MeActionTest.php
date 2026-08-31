<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\Auth\MfaOfferService;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class MeActionTest extends TestCase
{
    public function testResponseContainsMfaAndAuthoritativeSessionState(): void
    {
        $supplierStatement = $this->createMock(\PDOStatement::class);
        $supplierStatement->expects(self::once())
            ->method('fetchAll')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn([]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturn($supplierStatement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('hasColumn')->with('supplier', 'oss_enabled')->willReturn(true);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        // MyÚčto filtruje firmy podle membershipu; superadmin projde bez omezení.
        $userSuppliers = $this->createMock(UserSupplierRepository::class);
        $permissions = $this->createMock(PermissionResolver::class);
        $permissions->method('resolve')->willReturn(EffectiveRole::denied());
        $license = $this->createMock(LicenseService::class);

        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(1);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $config = new Config([
            'session' => ['lock_after_minutes' => 15],
            'auth' => [
                'require_mfa' => true,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]);
        $offers = $this->createMock(MfaOfferService::class);
        $offers->expects(self::once())->method('shouldOffer')->with(17, true)->willReturn(false);
        $action = new MeAction(
            $db,
            $config,
            $userSuppliers,
            $permissions,
            $license,
            $credentials,
            new MfaPolicyService($config),
            $offers,
            new SessionLockPolicy($config),
            $clock,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/me')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'email' => 'synthetic@example.invalid',
                'name' => 'Synthetic User',
                'role' => 'admin',
                'is_superadmin' => true,
                'locale' => 'cs',
                'totp_enabled' => true,
                'session_lock_after_minutes' => 5,
            ])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'strong',
                'last_user_activity_at' => '2026-07-24 11:58:00.000000',
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $request = $request->withAttribute(LicenseMiddleware::ATTR_STATE, new LicenseState(
            state: LicenseState::ACTIVE,
            instanceId: 'synthetic-instance',
            tier: 'single',
            maxCompanies: 1,
            usersLicensed: 3,
            usersActive: 1,
            companiesActive: 1,
            validUntil: 1_800_000_000,
            trialEndsAt: null,
            overageDeadline: null,
            licenseKey: 'MYU-TEST-0001-AAAA',
            lastCheckAt: '2026-07-24 05:00:00',
            lastCheckOk: true,
            subscription: ['state' => 'past_due'],
            commercial: true,
            managed: true,
        ));

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['passkey', 'totp'], $body['user']['mfa_methods']);
        self::assertTrue($body['user']['mfa_enabled']);
        self::assertSame(1, $body['user']['passkey_count']);
        self::assertFalse($body['user']['must_setup_mfa']);
        // Nabídka dobrovolného MFA při `require_mfa = true` neexistuje — kdyby ano,
        // nesla by s sebou tlačítko „pokračovat bez ověření".
        self::assertFalse($body['user']['should_offer_mfa']);
        self::assertTrue($body['require_mfa']);
        self::assertSame(['passkey', 'totp'], $body['allowed_mfa_methods']);
        self::assertSame('active', $body['session_state']);
        self::assertSame(5, $body['lock_after_minutes']);
        self::assertSame('2026-07-24T12:00:00.000000Z', $body['server_time']);
        self::assertSame('2026-07-24T12:03:00.000000Z', $body['idle_expires_at']);
        self::assertSame(str_repeat('b', 64), $body['csrf_token']);
        self::assertSame('past_due', $body['license']['subscription_state']);
    }

    /**
     * Bez tohohle pole frontend o dobrovolné nabídce neví a stránka /setup-mfa se
     * při `require_mfa = false` nikdy nezobrazí — přesně stav před opravou.
     */
    public function testUcetBezFaktoruDostaneNabidkuKdyzSeMfaNevynucuje(): void
    {
        $supplierStatement = $this->createMock(\PDOStatement::class);
        $supplierStatement->method('fetchAll')->willReturn([]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($supplierStatement);
        $db = $this->createMock(Connection::class);
        $db->method('hasColumn')->willReturn(true);
        $db->method('pdo')->willReturn($pdo);

        $permissions = $this->createMock(PermissionResolver::class);
        $permissions->method('resolve')->willReturn(EffectiveRole::denied());
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->method('countActiveForUser')->willReturn(0);
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $config = new Config([
            'session' => ['lock_after_minutes' => 15],
            'auth' => [
                'require_mfa' => false,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]);
        $offers = $this->createMock(MfaOfferService::class);
        $offers->expects(self::once())->method('shouldOffer')->with(17, false)->willReturn(true);

        $action = new MeAction(
            $db,
            $config,
            $this->createMock(UserSupplierRepository::class),
            $permissions,
            $this->createMock(LicenseService::class),
            $credentials,
            new MfaPolicyService($config),
            $offers,
            new SessionLockPolicy($config),
            $clock,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/me')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'email' => 'synthetic@example.invalid',
                'name' => 'Synthetic User',
                'role' => 'admin',
                'is_superadmin' => true,
                'locale' => 'cs',
                'totp_enabled' => false,
            ])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'setup',
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($body['user']['should_offer_mfa']);
        // Nabídka NIC neblokuje — `must_setup_mfa` musí zůstat false i pro setup session.
        self::assertFalse($body['user']['must_setup_mfa']);
        self::assertFalse($body['require_mfa']);
    }

    public function testClientAccountDoesNotReceiveSubscriptionState(): void
    {
        $db = $this->createMock(Connection::class);
        $userSuppliers = $this->createMock(UserSupplierRepository::class);
        $userSuppliers->method('allowedSupplierIds')->willReturn([]);
        $permissions = $this->createMock(PermissionResolver::class);
        $permissions->method('resolve')->willReturn(new EffectiveRole(9, 'Klient', 'client', true));
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->method('countActiveForUser')->willReturn(0);
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $config = new Config([
            'auth' => ['allowed_mfa_methods' => ['passkey', 'totp']],
        ]);
        $action = new MeAction(
            $db,
            $config,
            $userSuppliers,
            $permissions,
            $this->createMock(LicenseService::class),
            $credentials,
            new MfaPolicyService($config),
            $this->createMock(MfaOfferService::class),
            new SessionLockPolicy($config),
            $clock,
        );
        $state = new LicenseState(
            state: LicenseState::ACTIVE,
            instanceId: 'synthetic-instance',
            tier: 'single',
            maxCompanies: 1,
            usersLicensed: 3,
            usersActive: 1,
            companiesActive: 1,
            validUntil: 1_800_000_000,
            trialEndsAt: null,
            overageDeadline: null,
            licenseKey: 'MYU-TEST-0001-AAAA',
            lastCheckAt: '2026-07-24 05:00:00',
            lastCheckOk: true,
            subscription: ['state' => 'past_due'],
            commercial: true,
            managed: true,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/me')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 42,
                'role_summary' => ['id' => 9, 'name' => 'Klient', 'type' => 'client'],
                'is_superadmin' => false,
                'totp_enabled' => false,
            ])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => str_repeat('c', 64)])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0)
            ->withAttribute(LicenseMiddleware::ATTR_STATE, $state);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNull($body['license']['subscription_state']);
    }
}
