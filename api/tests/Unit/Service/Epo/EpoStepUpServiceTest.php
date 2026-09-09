<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpProof;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Epo\EpoStepUpService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Správa podpisového certifikátu pro EPO je stejně citlivá jako vydání API tokenu:
 * soukromý klíč podepisuje daňová podání jménem uživatele. Po začlenění passkeys
 * je proto primární cestou účelový jednorázový step-up proof a heslo zůstává
 * jen pro účet, který žádný silný faktor nemá.
 */
#[AllowMockObjectsWithoutExpectations]
final class EpoStepUpServiceTest extends TestCase
{
    private const USER_ID = 17;
    private const SESSION_TOKEN = 'session-token';

    public function testUsedLoginTotpCannotAuthorizeEpoCertificateAccess(): void
    {
        [$service, $totp, $db, $secret] = $this->realTotpService();
        $code = $totp->currentCode($secret);
        self::assertTrue($totp->verifyAndConsume($db, $secret, $code));
        try {
            $service->verify($this->request(), self::USER_ID, ['password' => 'Synthetic-Password-2026', 'totp_code' => $code], 'certificate.store');
            self::fail('Použitý přihlašovací kód nesmí potvrdit přístup k podpisovému certifikátu.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('invalid_code', $e->errorCode);
            self::assertSame(401, $e->httpStatus);
        }
    }

    public function testFreshEpoTotpIsConsumedForOtherOperations(): void
    {
        [$service, $totp, $db, $secret] = $this->realTotpService();
        $code = $totp->currentCode($secret);
        $service->verify($this->request(), self::USER_ID, ['password' => 'Synthetic-Password-2026', 'totp_code' => $code], 'certificate.store');
        self::assertFalse($totp->verifyAndConsume($db, $secret, $code));
    }

    private function realTotpService(): array
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->exec('CREATE TABLE totp_used_steps (secret_fingerprint TEXT, time_step INTEGER, PRIMARY KEY (secret_fingerprint, time_step))');
        $pdo->exec('CREATE TABLE users (id INTEGER, email TEXT, password_hash TEXT, totp_secret TEXT, totp_enabled INTEGER)');
        $pdo->exec("INSERT INTO users VALUES (17, 'synthetic@example.test', 'synthetic-hash', 'synthetic-encrypted-secret', 1)");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $secret = TotpService::generateSecret();
        $totp = new TotpService();
        $crypto = $this->createStub(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn($secret);
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('verify')->willReturn(true);
        $service = new EpoStepUpService($db, $totp, $crypto, $this->createStub(ActivityLogger::class), new IpMatcher(), $hasher, $this->createStub(BruteForceGuard::class), $this->createStub(MfaStepUpService::class));
        return [$service, $totp, $db, $secret];
    }

    public function testPasskeyProofIsAcceptedWithoutPassword(): void
    {
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::once())
            ->method('consume')
            ->with(
                'proof-token',
                self::USER_ID,
                self::SESSION_TOKEN,
                MfaStepUpService::OPERATION_EPO_CERTIFICATE,
            )
            ->willReturn($this->createMock(MfaStepUpProof::class));

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::never())->method('verify');

        $service = $this->service(
            stepUp: $stepUp,
            hasher: $hasher,
            passkeyCount: 1,
            totpEnabled: false,
        );

        $service->verify(
            $this->request(),
            self::USER_ID,
            ['step_up_token' => 'proof-token'],
            'certificate.store',
        );
    }

    public function testSpentOrForeignProofIsRejected(): void
    {
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->method('consume')->willThrowException(new OneTimeTokenException('spotřebováno'));

        $service = $this->service(stepUp: $stepUp, passkeyCount: 1, totpEnabled: false);

        try {
            $service->verify(
                $this->request(),
                self::USER_ID,
                ['step_up_token' => 'proof-token'],
                'certificate.store',
            );
            self::fail('Spotřebovaný proof musí operaci odmítnout.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('step_up_proof_invalid', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }
    }

    /**
     * Passkey je zkratka, ne náhrada: kdo klíč zrovna nemá po ruce, musí se pořád
     * dostat dál původní cestou přes heslo (+ TOTP).
     */
    public function testPasswordFallbackStaysAvailableForPasskeyAccount(): void
    {
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::once())->method('verify')->willReturn(true);

        $service = $this->service(hasher: $hasher, passkeyCount: 1, totpEnabled: false);

        $service->verify(
            $this->request(),
            self::USER_ID,
            ['password' => 'Str0ng-Test-Pwd-2026'],
            'certificate.store',
        );
    }

    /** Samotná session nestačí ani u účtu bez silného faktoru. */
    public function testAccountWithoutStrongFactorStillRequiresPassword(): void
    {
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::once())->method('verify')->willReturn(false);

        $service = $this->service(hasher: $hasher, passkeyCount: 0, totpEnabled: false);

        try {
            $service->verify(
                $this->request(),
                self::USER_ID,
                ['password' => 'wrong'],
                'certificate.store',
            );
            self::fail('Chybné heslo musí operaci odmítnout.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('invalid_password', $e->errorCode);
            self::assertSame(401, $e->httpStatus);
        }
    }

    public function testBearerTokenCannotManageCertificates(): void
    {
        $service = $this->service();
        $request = $this->request()->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');

        try {
            $service->verify($request, self::USER_ID, [], 'certificate.store');
            self::fail('Bearer nesmí spravovat podpisový certifikát.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('session_required', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/reports/submissions/epo-credentials')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, self::SESSION_TOKEN);
    }

    /**
     * `$passkeyCount` je jen popisný: pro backend je rozhodující doručený proof,
     * ne to, kolik klíčů účet má — nabídku tlačítka řeší frontend z auth store.
     */
    private function service(
        ?MfaStepUpService $stepUp = null,
        ?PasswordHasher $hasher = null,
        int $passkeyCount = 0,
        bool $totpEnabled = false,
    ): EpoStepUpService {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetch')->willReturn([
            'email' => 'user@example.invalid',
            'password_hash' => '$2y$12$syntheticsynthetichashvaluesyntheticsyntheticsyntheti',
            'totp_secret' => $totpEnabled ? 'encrypted-secret' : null,
            'totp_enabled' => $totpEnabled ? 1 : 0,
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);

        unset($passkeyCount);

        return new EpoStepUpService(
            $db,
            $this->createMock(TotpService::class),
            $this->createMock(SecretEncryption::class),
            $this->createMock(ActivityLogger::class),
            new IpMatcher(),
            $hasher ?? $this->createMock(PasswordHasher::class),
            $bruteForce,
            $stepUp ?? $this->createMock(MfaStepUpService::class),
        );
    }
}
