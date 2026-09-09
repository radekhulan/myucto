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
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Auth\TrustedDeviceService;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Výzva k druhému faktoru smí nabídnout jen to, co uživatel reálně má. Seznam
 * sestavuje server z uloženého stavu — klient si o metodu říct nemůže a před
 * ověřením hesla se o něm nedozví nic.
 */
#[AllowMockObjectsWithoutExpectations]
final class LoginActionSecondFactorMethodsTest extends TestCase
{
    public function testTotpOnlyUserIsNeverOfferedPasskey(): void
    {
        $response = $this->login(activePasskeys: 0, hasRecoveryCodes: false);
        $error = $this->error($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('totp_required', $error['code']);
        self::assertSame(['totp'], $error['methods']);
        self::assertNotContains('passkey', $error['methods']);
        // Nic z toho, co by šlo použít k enumeraci účtů, tu být nesmí.
        self::assertArrayNotHasKey('flow_token', $error);
        self::assertArrayNotHasKey('public_key', $error);
    }

    public function testUsableRecoveryCodesAreOfferedAlongsideTotp(): void
    {
        $error = $this->error($this->login(activePasskeys: 0, hasRecoveryCodes: true));

        self::assertSame(['totp', 'recovery'], $error['methods']);
    }

    public function testClientCannotAskForAMethodItDoesNotHave(): void
    {
        // Tělo requestu si říká o passkey; server ho ignoruje a drží se svého stavu.
        $error = $this->error($this->login(
            activePasskeys: 0,
            hasRecoveryCodes: false,
            body: ['methods' => ['passkey'], 'mfa_method' => 'passkey'],
        ));

        self::assertSame('totp_required', $error['code']);
        self::assertSame(['totp'], $error['methods']);
    }

    public function testInvalidCodeRepeatsTheSameServerSideMethodList(): void
    {
        $totp = $this->createMock(TotpService::class);
        $totp->method('verifyAndConsume')->willReturn(false);

        $error = $this->error($this->login(
            activePasskeys: 0,
            hasRecoveryCodes: true,
            body: ['totp' => '000000'],
            totpService: $totp,
        ));

        self::assertSame('invalid_totp', $error['code']);
        self::assertSame(['totp', 'recovery'], $error['methods']);
    }

    public function testUnknownUserResponseStaysIndistinguishable(): void
    {
        $error = $this->error($this->login(
            activePasskeys: 0,
            hasRecoveryCodes: false,
            userExists: false,
        ));

        self::assertSame('invalid_credentials', $error['code']);
        self::assertArrayNotHasKey('methods', $error);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function login(
        int $activePasskeys,
        bool $hasRecoveryCodes,
        array $body = [],
        ?TotpService $totpService = null,
        bool $userExists = true,
    ): ResponseInterface {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn($userExists ? [
            'id' => 17,
            'email' => 'user@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'password_hash' => 'synthetic-hash',
            'is_active' => 1,
            'totp_secret' => 'encrypted-secret',
            'totp_enabled' => 1,
            'session_lock_after_minutes' => null,
        ] : false);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->method('verify')->willReturn(true);
        $hasher->method('needsRehash')->willReturn(false);
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $bruteForce->method('isTotpLocked')->willReturn(false);
        $turnstile = $this->createMock(TurnstileVerifier::class);
        $turnstile->method('verify')->willReturn(true);
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');

        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->method('findAllForUser')->willReturn([]);
        $credentials->method('countActiveForUser')->willReturn($activePasskeys);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('isAvailable')->willReturn(true);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('SECRET');
        $recoveryCodes = $this->createMock(MfaRecoveryCodeService::class);
        $recoveryCodes->method('hasUsable')->willReturn($hasRecoveryCodes);
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $policyConfig = new Config([
            'auth' => [
                'require_mfa' => false,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]);

        $action = new LoginAction(
            $db,
            $hasher,
            $bruteForce,
            $turnstile,
            $this->createMock(ActivityLogger::class),
            $ipMatcher,
            new Config(['auth' => ['email_otp' => ['enabled' => false]]]),
            $totpService ?? $this->createMock(TotpService::class),
            $crypto,
            $this->createMock(EmailOtpService::class),
            $this->createMock(TrustedDeviceService::class),
            $credentials,
            $passkeys,
            $this->createMock(WebAuthnCeremonyStore::class),
            new MfaPolicyService($policyConfig),
            $issuer,
            $this->createMock(ClockInterface::class),
            $recoveryCodes,
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/login')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody([
                'email' => 'user@example.invalid',
                'password' => 'Synthetic-password-42',
            ] + $body);

        return $action($request, (new ResponseFactory())->createResponse());
    }

    /**
     * @return array<string,mixed>
     */
    private function error(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['error'] ?? null);

        return $body['error'];
    }
}
