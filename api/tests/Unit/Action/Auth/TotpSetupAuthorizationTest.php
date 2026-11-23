<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\TotpAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\TotpEnrollmentException;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Zřízení TOTP je „přidání silného faktoru", takže před vygenerováním secretu
 * musí uživatel znovu prokázat identitu — stejně jako u registrace passkey
 * ({@see \MyInvoice\Action\Auth\PasskeyAction::registerOptions()}):
 * má-li aktivní passkey, jednorázovým step-up proofem pro operaci `totp.enable`,
 * jinak aktuálním heslem. Bez toho stačila unesená session na to, aby si
 * útočník založil vlastní druhý faktor a jím pak prošel každou step-up bránou.
 */
#[AllowMockObjectsWithoutExpectations]
final class TotpSetupAuthorizationTest extends TestCase
{
    private const USER_ID = 17;
    private const SESSION_TOKEN = 'synthetic-session-token';
    private const PASSWORD_HASH = 'synthetic-password-hash';

    private \PDO $pdo;
    private TotpService&MockObject $totp;
    private SecretEncryption&MockObject $crypto;
    private PasswordHasher&MockObject $passwords;
    private BruteForceGuard&MockObject $bruteForce;
    private MfaProtectedOperationService&MockObject $protectedOperations;
    private PasskeyCredentialRepository&MockObject $credentials;
    private ActivityLogger&MockObject $logger;
    private MfaRecoveryCodeService&MockObject $recoveryCodes;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec("INSERT INTO users (id, email, password_hash, totp_secret, totp_enabled) VALUES (17, 'synthetic@example.test', '" . self::PASSWORD_HASH . "', NULL, 0)");

        $this->totp = $this->createMock(TotpService::class);
        $this->totp->method('provisioningUri')->willReturn('otpauth://totp/synthetic');
        $this->crypto = $this->createMock(SecretEncryption::class);
        $this->crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:' . $plain);
        $this->crypto->method('decrypt')->willReturnCallback(static fn (string $stored): string => substr($stored, 4));
        $this->passwords = $this->createMock(PasswordHasher::class);
        $this->bruteForce = $this->createMock(BruteForceGuard::class);
        $this->bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $this->protectedOperations = $this->createMock(MfaProtectedOperationService::class);
        $this->credentials = $this->createMock(PasskeyCredentialRepository::class);
        $this->credentials->method('countActiveForUser')->willReturn(0);
        $this->logger = $this->createMock(ActivityLogger::class);
        $this->recoveryCodes = $this->createMock(MfaRecoveryCodeService::class);
        $this->recoveryCodes->method('issueFirstBatch')->willReturn([]);
    }

    public function testSetupIsRefusedForBearerAuthentication(): void
    {
        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup', 'bearer')
                ->withParsedBody(['current_password' => 'Synthetic-test-password-42']),
            new Response(),
        );

        // Json::sessionRequired — jednotný kód i status pro „jen z webové session".
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->errorCode($response));
        self::assertNull($this->storedSecret());
    }

    public function testSetupWithoutPasswordStoresNoSecret(): void
    {
        $this->passwords->method('verify')->willReturn(false);

        $response = $this->action()->setup($this->request('/api/auth/totp/setup'), new Response());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('current_password_invalid', $this->errorCode($response));
        self::assertNull($this->storedSecret());
    }

    public function testWrongPasswordIsCountedAgainstBruteForceAndAudited(): void
    {
        $this->passwords->expects(self::once())
            ->method('verify')
            ->with('wrong-password', self::PASSWORD_HASH)
            ->willReturn(false);
        $this->passwords->expects(self::once())->method('dummyVerify');
        $this->bruteForce->expects(self::once())->method('recordFailure')->with('synthetic@example.test', self::anything());
        $this->logger->expects(self::once())
            ->method('log')
            ->with('auth.totp_setup_reauth_failed', self::USER_ID, 'user', self::USER_ID, ['reason' => 'password']);

        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['current_password' => 'wrong-password']),
            new Response(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('current_password_invalid', $this->errorCode($response));
        self::assertNull($this->storedSecret());
    }

    public function testLockedAccountIsRefusedBeforeThePasswordIsChecked(): void
    {
        $this->bruteForce = $this->createMock(BruteForceGuard::class);
        $this->bruteForce->method('check')->willReturn(BruteForceGuard::STATE_LOCKED_15M);
        $this->passwords->expects(self::never())->method('verify');

        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['current_password' => 'Synthetic-test-password-42']),
            new Response(),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('too_many_attempts', $this->errorCode($response));
        self::assertNull($this->storedSecret());
    }

    public function testCorrectPasswordGeneratesSecretWithoutCaching(): void
    {
        $this->passwords->method('verify')
            ->with('Synthetic-test-password-42', self::PASSWORD_HASH)
            ->willReturn(true);
        $this->protectedOperations->expects(self::once())
            ->method('storePendingTotpSecret')
            ->with(self::USER_ID, self::SESSION_TOKEN, self::PASSWORD_HASH, self::callback('is_string'), null)
            ->willReturnCallback(function (
                int $userId,
                string $sessionToken,
                string $authorizedPasswordHash,
                string $encryptedSecret,
            ): string {
                $this->pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')
                    ->execute([$encryptedSecret, $userId]);
                return 'password';
            });
        $this->logger->expects(self::once())
            ->method('log')
            ->with('auth.totp_setup', self::USER_ID, 'user', self::USER_ID, ['reauth' => 'password']);

        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['current_password' => 'Synthetic-test-password-42']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsString($body['secret'] ?? null);
        self::assertSame('enc:' . $body['secret'], $this->storedSecret());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testActivePasskeyRequiresStepUpProofInsteadOfPassword(): void
    {
        $this->credentials = $this->createMock(PasskeyCredentialRepository::class);
        $this->credentials->method('countActiveForUser')->with(self::USER_ID)->willReturn(1);
        $this->passwords->expects(self::never())->method('verify');
        $this->protectedOperations->expects(self::once())
            ->method('storePendingTotpSecret')
            ->with(self::USER_ID, self::SESSION_TOKEN, self::PASSWORD_HASH, self::callback('is_string'), '')
            ->willThrowException(new OneTimeTokenException('missing'));

        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['current_password' => 'Synthetic-test-password-42']),
            new Response(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('step_up_proof_invalid', $this->errorCode($response));
        self::assertNull($this->storedSecret());
    }

    public function testStepUpProofAuthorizesSetupWhenUserHasPasskey(): void
    {
        $this->credentials = $this->createMock(PasskeyCredentialRepository::class);
        $this->credentials->method('countActiveForUser')->willReturn(1);
        $this->protectedOperations->expects(self::once())
            ->method('storePendingTotpSecret')
            ->with(self::USER_ID, self::SESSION_TOKEN, self::PASSWORD_HASH, self::callback('is_string'), 'synthetic-proof')
            ->willReturnCallback(function (
                int $userId,
                string $sessionToken,
                string $authorizedPasswordHash,
                string $encryptedSecret,
            ): string {
                $this->pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')
                    ->execute([$encryptedSecret, $userId]);
                return 'passkey';
            });
        $this->logger->expects(self::once())
            ->method('log')
            ->with('auth.totp_setup', self::USER_ID, 'user', self::USER_ID, ['reauth' => 'passkey']);

        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['step_up_token' => 'synthetic-proof']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->storedSecret());
    }

    public function testSetupDoesNotOverwriteFactorEnabledMeanwhile(): void
    {
        // Mezi načtením uživatele a zápisem secretu doběhne souběžné enable() —
        // setup nesmí aktivní secret přepsat nezaktivovaným.
        $pdo = $this->pdo;
        $this->passwords->method('verify')->willReturnCallback(static function () use ($pdo): bool {
            $pdo->exec("UPDATE users SET totp_secret = 'enc:ACTIVE', totp_enabled = 1 WHERE id = 17");
            return true;
        });
        $this->protectedOperations->expects(self::once())
            ->method('storePendingTotpSecret')
            ->willThrowException(new TotpEnrollmentException(TotpEnrollmentException::ALREADY_ENABLED));

        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['current_password' => 'Synthetic-test-password-42']),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('already_enabled', $this->errorCode($response));
        self::assertSame('enc:ACTIVE', $this->storedSecret());
    }

    public function testPasswordResetDuringSetupCannotRestorePendingSecret(): void
    {
        $this->passwords->method('verify')->willReturn(true);
        $this->crypto = $this->createMock(SecretEncryption::class);
        $pdo = $this->pdo;
        $this->crypto->method('encrypt')->willReturnCallback(static function (string $plain) use ($pdo): string {
            $pdo->exec("UPDATE users SET password_hash = 'new-password-hash', totp_secret = NULL WHERE id = 17 AND totp_enabled = 0");
            return 'enc:' . $plain;
        });
        $this->protectedOperations->expects(self::once())
            ->method('storePendingTotpSecret')
            ->willThrowException(new TotpEnrollmentException(TotpEnrollmentException::STALE_AUTHORIZATION));

        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['current_password' => 'Old-synthetic-password']),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('enrollment_stale', $this->errorCode($response));
        self::assertNull($this->storedSecret());
    }

    public function testEnableRefusesRepeatedActivation(): void
    {
        $this->pdo->exec("UPDATE users SET totp_secret = 'enc:SECRET', totp_enabled = 1 WHERE id = 17");
        $this->totp->expects(self::never())->method('verifyAndConsume');
        $this->recoveryCodes->expects(self::never())->method('issueFirstBatch');

        $response = $this->action()->enable(
            $this->request('/api/auth/totp/enable')->withParsedBody(['code' => '123456']),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('already_enabled', $this->errorCode($response));
    }

    public function testEnableFailsWhenPendingSecretWasReplacedConcurrently(): void
    {
        $this->pdo->exec("UPDATE users SET totp_secret = 'enc:FIRST', totp_enabled = 0 WHERE id = 17");
        $pdo = $this->pdo;
        // Kód projde proti secretu FIRST, ale než se zapíše enabled=1, souběžný
        // setup() secret vymění — aktivace neověřeného secretu SECOND musí selhat.
        $this->totp->method('verifyAndConsume')->willReturnCallback(static function () use ($pdo): bool {
            $pdo->exec("UPDATE users SET totp_secret = 'enc:SECOND' WHERE id = 17");
            return true;
        });
        $this->recoveryCodes->expects(self::never())->method('issueFirstBatch');

        $response = $this->action()->enable(
            $this->request('/api/auth/totp/enable')->withParsedBody(['code' => '123456']),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('enrollment_stale', $this->errorCode($response));
        self::assertSame(0, (int) $this->pdo->query('SELECT totp_enabled FROM users WHERE id = 17')->fetchColumn());
    }

    public function testEnrollmentGrantIsVerifiedByAtomicOperationWithoutPassword(): void
    {
        $this->passwords->expects(self::never())->method('verify');
        $this->bruteForce->expects(self::never())->method('check');
        $this->protectedOperations->expects(self::once())->method('storePendingTotpSecret')
            ->with(self::USER_ID, self::SESSION_TOKEN, self::PASSWORD_HASH, self::isString(), null, 'grant')
            ->willReturn('password_enrollment');
        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['enrollment_token' => 'grant']), new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvalidEnrollmentGrantDoesNotRevealSecret(): void
    {
        $this->protectedOperations->method('storePendingTotpSecret')
            ->willThrowException(new OneTimeTokenException('invalid'));
        $response = $this->action()->setup(
            $this->request('/api/auth/totp/setup')->withParsedBody(['enrollment_token' => 'invalid']), new Response(),
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('enrollment_authorization_invalid', $this->errorCode($response));
        self::assertNull($this->storedSecret());
    }

    private function action(): TotpAction
    {
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->method('isMethodAllowed')->willReturn(true);

        return new TotpAction(
            $db,
            $this->totp,
            new Config([]),
            $this->logger,
            new IpMatcher(),
            $this->crypto,
            $policy,
            $this->recoveryCodes,
            $this->createMock(SessionManager::class),
            $this->createMock(SessionCookieFactory::class),
            $this->createMock(ClockInterface::class),
            $this->passwords,
            $this->bruteForce,
            $this->protectedOperations,
            $this->credentials,
            $this->createStub(\MyInvoice\Service\Auth\LoginPolicyService::class),
        );
    }

    private function request(string $path, string $authMethod = 'session'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => self::USER_ID, 'email' => 'synthetic@example.test'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, self::SESSION_TOKEN)
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => 'strong']);
    }

    private function storedSecret(): ?string
    {
        $value = $this->pdo->query('SELECT totp_secret FROM users WHERE id = 17')->fetchColumn();
        return $value === null || $value === false ? null : (string) $value;
    }

    private function errorCode(ResponseInterface $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        return is_array($body) && is_array($body['error'] ?? null) ? (string) ($body['error']['code'] ?? '') : null;
    }
}
