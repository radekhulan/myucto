<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\LoginAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\EmailOtpService;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use MyInvoice\Service\Auth\Oidc\OidcFlowStore;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Auth\TrustedDeviceService;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Lístek z přihlášení přes Google, Apple nebo Seznam nahrazuje v LoginAction
 * jen heslo. Druhý faktor účtu se vyžaduje dál a lístek bez vazby na prohlížeč
 * nebo pro jiného uživatele neprojde.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class LoginActionSsoTicketTest extends TestCase
{
    private const TICKET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BINDING = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testInvalidTicketIsRejectedWithoutTouchingPassword(): void
    {
        [$response] = $this->login(ticket: null, totpEnabled: false);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('sso_flow_invalid', (string) $response->getBody());
    }

    public function testTicketStillRequiresTheAccountsSecondFactor(): void
    {
        [$response] = $this->login(ticket: ['provider' => 'google', 'user_id' => 17, 'external_identity_id' => 5, 'return_to' => null], totpEnabled: true);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('totp_required', (string) $response->getBody());
    }

    public function testTicketWithoutFactorsIssuesExternalSessionAndConsumesTicket(): void
    {
        [$response, $captured] = $this->login(ticket: ['provider' => 'google', 'user_id' => 17, 'external_identity_id' => 5, 'return_to' => null], totpEnabled: false);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('oidc:google', $captured['context']->authMethod);
        self::assertSame('basic', $captured['context']->assuranceLevel);
        self::assertSame(5, $captured['context']->externalIdentityId);
        // Lístek je rovnocenný ověřenému heslu, takže smí vzniknout oprávnění zřídit TOTP.
        self::assertSame('synthetic-hash', $captured['password_hash']);
        self::assertTrue($captured['consumed']);
    }

    public function testTicketForOtherUserIsRejected(): void
    {
        [$response] = $this->login(ticket: ['provider' => 'google', 'user_id' => 99, 'external_identity_id' => 5, 'return_to' => null], totpEnabled: false);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('sso_flow_invalid', (string) $response->getBody());
    }

    public function testRequiredEntraTicketStillRequiresTotp(): void
    {
        [$response] = $this->login(
            ['provider' => 'entra', 'user_id' => 17, 'external_identity_id' => 5, 'return_to' => null],
            true,
            requiredProvider: 'entra',
        );
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('totp_required', (string) $response->getBody());
    }

    public function testEntraTotpSessionKeepsRequiredProviderAndStrongAssurance(): void
    {
        [$response, $captured] = $this->login(
            ['provider' => 'entra', 'user_id' => 17, 'external_identity_id' => 5, 'return_to' => null],
            true,
            requiredProvider: 'entra',
            body: ['totp' => '123456'],
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('oidc:entra', $captured['context']->authMethod);
        self::assertSame('strong', $captured['context']->assuranceLevel);
        self::assertSame(5, $captured['context']->externalIdentityId);
        self::assertNotNull($captured['context']->mfaVerifiedAt);
    }

    public function testOtherProvidersTicketDoesNotSatisfyRequiredEntra(): void
    {
        [$response] = $this->login(
            ['provider' => 'google', 'user_id' => 17, 'external_identity_id' => 5, 'return_to' => null],
            false,
            requiredProvider: 'entra',
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('sso_required', (string) $response->getBody());
    }

    public function testEntraWithoutLocalFactorEntersRequiredMfaSetup(): void
    {
        [$response, $captured] = $this->login(
            ['provider' => 'entra', 'user_id' => 17, 'external_identity_id' => 5, 'return_to' => null],
            false,
            requiredProvider: 'entra',
            requireMfa: true,
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('setup', $captured['context']->assuranceLevel);
        self::assertSame('oidc:entra', $captured['context']->authMethod);
        self::assertSame(5, $captured['context']->externalIdentityId);
    }

    /**
     * @param array{provider:string,user_id:int,external_identity_id:int,return_to:?string}|null $ticket
     * @return array{0:ResponseInterface,1:array<string,mixed>}
     */
    private function login(?array $ticket, bool $totpEnabled, ?string $requiredProvider = null, array $body = [], bool $requireMfa = false): array
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn('user@example.invalid');
        $statement->method('fetch')->willReturn([
            'id' => 17, 'email' => 'user@example.invalid', 'name' => 'Synthetic User', 'role' => 'admin',
            'locale' => 'cs', 'password_hash' => 'synthetic-hash', 'is_active' => 1,
            'totp_secret' => $totpEnabled ? 'encrypted' : null, 'totp_enabled' => $totpEnabled ? 1 : 0,
            'session_lock_after_minutes' => null, 'system_key' => 'accountant',
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::never())->method('verify');
        $hasher->method('needsRehash')->willReturn(false);
        $turnstile = $this->createMock(TurnstileVerifier::class);
        $turnstile->expects(self::never())->method('verify');
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->method('findAllForUser')->willReturn([]);
        $credentials->method('countActiveForUser')->willReturn(0);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('isAvailable')->willReturn(true);

        $captured = ['consumed' => false];
        $flows = $this->createMock(OidcFlowStore::class);
        $flows->method('peekTicket')->willReturnCallback(
            static fn (string $t, ?string $b): ?array => $t === self::TICKET && $b === self::BINDING ? $ticket : null,
        );
        $flows->method('consumeTicket')->willReturnCallback(static function () use (&$captured): bool {
            $wasConsumed = $captured['consumed'];
            $captured['consumed'] = true;
            return !$wasConsumed;
        });
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->method('issue')->willReturnCallback(
            static function ($response, $user, $ip, $ua, SessionAuthContext $context, $td = false, $hash = null) use (&$captured) {
                $captured['context'] = $context;
                $captured['password_hash'] = $hash;
                return $response->withStatus(200);
            },
        );

        $totp = $this->createMock(TotpService::class);
        $totp->method('verifyAndConsume')->willReturn(true);
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-11-06 12:00:00 UTC'));
        $policy = $this->createMock(LoginPolicyService::class);
        $policy->method('requiredProviderForUser')->willReturn($requiredProvider);
        $action = new LoginAction(
            $db, $hasher, $bruteForce, $turnstile, $this->createMock(ActivityLogger::class), $ipMatcher,
            new Config(['auth' => ['email_otp' => ['enabled' => false]]]),
            $totp, $this->createMock(SecretEncryption::class),
            $this->createMock(EmailOtpService::class), $this->createMock(TrustedDeviceService::class),
            $credentials, $passkeys, $this->createMock(WebAuthnCeremonyStore::class),
            new MfaPolicyService(new Config(['auth' => ['require_mfa' => $requireMfa, 'allowed_mfa_methods' => ['passkey', 'totp']]])),
            $issuer, $clock, $this->createMock(MfaRecoveryCodeService::class),
            $policy, $flows,
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/login')
            ->withCookieParams(['__Host-myinvoice_oidc' => self::BINDING])
            ->withParsedBody(['sso_ticket' => self::TICKET] + $body);

        return [$action($request, (new ResponseFactory())->createResponse()), $captured];
    }
}
