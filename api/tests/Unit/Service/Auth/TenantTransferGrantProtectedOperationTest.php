<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaStepUpProof;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\ProtectedOperationAuthenticationException;
use MyInvoice\Service\Auth\PsrSecurityClock;
use MyInvoice\Service\Auth\SecurityTime;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\TenantTransfer\Grant\TenantTransferGrantService;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[AllowMockObjectsWithoutExpectations]
final class TenantTransferGrantProtectedOperationTest extends TestCase
{
    private PDO $pdo;
    private Connection $connection;
    private PsrSecurityClock $clock;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            totp_enabled INTEGER NOT NULL,
            email TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE sessions (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            expires_at TEXT NOT NULL,
            replaced_at TEXT NULL,
            revoked_at TEXT NULL,
            session_family_id TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE tenant_transfer_grants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            grant_hash BLOB NOT NULL UNIQUE,
            grant_prefix TEXT NOT NULL,
            supplier_id INTEGER NOT NULL,
            created_by_user_id INTEGER NOT NULL,
            expires_at TEXT NOT NULL,
            paired_at TEXT NULL,
            target_instance_fingerprint BLOB NULL,
            target_payload_key_fingerprint BLOB NULL,
            consumed_at TEXT NULL,
            revoked_at TEXT NULL,
            revoked_reason TEXT NULL,
            last_used_at TEXT NULL,
            created_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE tenant_transfer_grant_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            grant_id INTEGER NULL,
            supplier_id INTEGER NULL,
            actor_user_id INTEGER NULL,
            event TEXT NOT NULL,
            outcome TEXT NOT NULL,
            reason TEXT NOT NULL,
            http_method TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            ip BLOB NULL,
            created_at TEXT NOT NULL
        )');
        $this->pdo->exec(
            "INSERT INTO users VALUES (7, 0, 'admin@example.test', 'stored-hash', 1)",
        );
        $this->pdo->exec(
            "INSERT INTO sessions VALUES ('session-token', 7, '2026-08-20 11:00:00.000000', NULL, NULL, 'family-1')",
        );

        $this->connection = new Connection(new Config([]));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue(
            $this->connection,
            $this->pdo,
        );
        $clock = new FixedTenantTransferClock(
            new \DateTimeImmutable('2026-08-20 10:00:00.000000', new \DateTimeZone('UTC')),
        );
        $this->clock = new PsrSecurityClock($clock);
    }

    public function testPasswordReauthenticationIsEnoughWhenInstanceMfaIsOptional(): void
    {
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::never())->method('consumeInTransaction');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isRequired')->willReturn(false);
        $protected = $this->protectedOperations($stepUp, $policy);

        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->expects(self::once())
            ->method('check')
            ->with('admin@example.test', '192.0.2.10')
            ->willReturn(BruteForceGuard::STATE_OK);
        $bruteForce->expects(self::never())->method('recordFailure');
        $bruteForce->expects(self::once())
            ->method('recordSuccess')
            ->with('admin@example.test', '192.0.2.10');
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::once())
            ->method('verify')
            ->with('current-password', 'stored-hash')
            ->willReturn(true);

        $grants = $this->grantService();
        $issued = $protected->createTenantTransferGrant(
            $grants,
            $bruteForce,
            $passwords,
            7,
            'session-token',
            'current-password',
            '',
            3,
            '192.0.2.10',
        );

        self::assertMatchesRegularExpression('/^ttg_v1_[A-Za-z0-9_-]{43}$/D', $issued['plaintext']);
        self::assertSame(1, $this->grantCount());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testRequiredMfaWithoutProofDoesNotCountAsWrongPassword(): void
    {
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::never())->method('consumeInTransaction');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isRequired')->willReturn(true);
        $protected = $this->protectedOperations($stepUp, $policy);

        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $bruteForce->expects(self::never())->method('recordFailure');
        $bruteForce->expects(self::never())->method('recordSuccess');
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->method('verify')->willReturn(true);
        $grants = $this->grantService();

        try {
            $protected->createTenantTransferGrant(
                $grants,
                $bruteForce,
                $passwords,
                7,
                'session-token',
                'current-password',
                '',
                3,
                '192.0.2.10',
            );
            self::fail('Chybějící povinný MFA proof měl operaci zastavit.');
        } catch (ProtectedOperationAuthenticationException $exception) {
            self::assertSame('missing_step_up', $exception->reason);
        }
        self::assertSame(0, $this->grantCount());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testRequiredMfaProofIsPurposeBoundAndConsumedBeforeGrantIssuance(): void
    {
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::once())
            ->method('consumeInTransaction')
            ->with(
                $this->pdo,
                self::isInstanceOf(SecurityTime::class),
                'proof-token',
                7,
                'session-token',
                MfaStepUpService::OPERATION_TENANT_TRANSFER_GRANT_CREATE,
            )
            ->willReturn(new MfaStepUpProof(
                7,
                MfaStepUpService::OPERATION_TENANT_TRANSFER_GRANT_CREATE,
                'totp',
                null,
            ));
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->method('isRequired')->willReturn(true);
        $protected = $this->protectedOperations($stepUp, $policy);

        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $bruteForce->expects(self::once())->method('recordSuccess');
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->method('verify')->willReturn(true);

        $issued = $protected->createTenantTransferGrant(
            $this->grantService(),
            $bruteForce,
            $passwords,
            7,
            'session-token',
            'current-password',
            'proof-token',
            3,
            '192.0.2.10',
        );
        self::assertSame(1, $issued['id']);
        self::assertSame(1, $this->grantCount());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testInvalidPurposeBoundProofPreventsGrantAndCountsFailure(): void
    {
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->method('consumeInTransaction')
            ->willThrowException(new StepUpOperationException('wrong purpose'));
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->method('isRequired')->willReturn(true);
        $protected = $this->protectedOperations($stepUp, $policy);

        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $bruteForce->expects(self::once())
            ->method('recordFailure')
            ->with('admin@example.test', '192.0.2.10');
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->method('verify')->willReturn(true);
        $grants = $this->grantService();

        try {
            $protected->createTenantTransferGrant(
                $grants,
                $bruteForce,
                $passwords,
                7,
                'session-token',
                'current-password',
                'wrong-proof',
                3,
                '192.0.2.10',
            );
            self::fail('Neplatný účelový proof měl operaci zastavit.');
        } catch (ProtectedOperationAuthenticationException $exception) {
            self::assertSame('invalid_step_up', $exception->reason);
        }
        self::assertSame(0, $this->grantCount());
        self::assertFalse($this->pdo->inTransaction());
    }

    private function protectedOperations(
        MfaStepUpService $stepUp,
        MfaPolicyService $policy,
    ): MfaProtectedOperationService {
        return new MfaProtectedOperationService(
            $this->connection,
            $this->clock,
            $stepUp,
            $policy,
            $this->createMock(PasskeyCredentialRepository::class),
            $this->createMock(ApiTokenService::class),
        );
    }

    private function grantService(): TenantTransferGrantService
    {
        return new TenantTransferGrantService($this->connection, $this->clock);
    }

    private function grantCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM tenant_transfer_grants')->fetchColumn();
    }
}

final readonly class FixedTenantTransferClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $time) {}

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }
}
