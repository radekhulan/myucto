<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\TenantTransfer;

use MyInvoice\Action\TenantTransfer\TenantTransferGrantAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\PsrSecurityClock;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\TenantTransfer\Grant\TenantTransferGrantService;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class TenantTransferGrantActionTest extends TestCase
{
    private PDO $pdo;
    private Config $config;
    private Connection $connection;
    private TenantTransferGrantAction $action;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->pdo->exec(
            "INSERT INTO users VALUES (7, 0, 'admin@example.test', 'stored-hash', 1)",
        );
        $this->pdo->exec(
            "INSERT INTO sessions VALUES ('session-token', 7, '2026-08-20 11:00:00.000000', NULL, NULL, 'family-1')",
        );

        $this->config = new Config([
            'tenant_transfer' => ['enabled' => true],
            'app' => ['url' => 'https://source.example.test/'],
        ]);
        $this->connection = new Connection($this->config);
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue(
            $this->connection,
            $this->pdo,
        );
        $securityClock = new PsrSecurityClock(new FixedTenantTransferActionClock(
            new \DateTimeImmutable('2026-08-20 10:00:00.000000', new \DateTimeZone('UTC')),
        ));
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::never())->method('consumeInTransaction');
        $mfaPolicy = $this->createMock(MfaPolicyService::class);
        $mfaPolicy->method('isRequired')->willReturn(false);
        $protected = new MfaProtectedOperationService(
            $this->connection,
            $securityClock,
            $stepUp,
            $mfaPolicy,
            $this->createMock(PasskeyCredentialRepository::class),
            $this->createMock(ApiTokenService::class),
        );
        $grants = new TenantTransferGrantService($this->connection, $securityClock);
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->method('verify')->willReturnCallback(
            static fn (string $password, string $hash): bool => $password === 'current-password'
                && $hash === 'stored-hash',
        );
        $this->action = new TenantTransferGrantAction(
            $this->config,
            $grants,
            $protected,
            $bruteForce,
            $passwords,
            $mfaPolicy,
            new IpMatcher($this->config),
        );
    }

    public function testCreateAlwaysUsesCurrentSupplierAndListNeverReturnsCodeOrHash(): void
    {
        $request = $this->request('POST')->withParsedBody([
            'password' => 'current-password',
            'supplier_id' => 999,
        ]);
        $response = $this->action->create(
            $request,
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(201, $response->getStatusCode());
        $created = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, $created['supplier_id']);
        self::assertSame('https://source.example.test', $created['source_url']);
        self::assertMatchesRegularExpression('/^ttg_v1_[A-Za-z0-9_-]{43}$/D', $created['code']);
        self::assertSame(
            3,
            (int) $this->pdo->query('SELECT supplier_id FROM tenant_transfer_grants')->fetchColumn(),
        );
        self::assertSame(
            hash('sha256', $created['code'], true),
            $this->pdo->query('SELECT grant_hash FROM tenant_transfer_grants')->fetchColumn(),
        );

        $listedResponse = $this->action->list(
            $this->request('GET'),
            (new ResponseFactory())->createResponse(),
        );
        $listed = json_decode(
            (string) $listedResponse->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertCount(1, $listed['grants']);
        self::assertArrayNotHasKey('code', $listed['grants'][0]);
        self::assertArrayNotHasKey('plaintext', $listed['grants'][0]);
        self::assertArrayNotHasKey('grant_hash', $listed['grants'][0]);
        self::assertSame('active', $listed['grants'][0]['state']);
    }

    public function testBearerCannotManageGrantsAndRejectionIsAudited(): void
    {
        $request = $this->request('GET')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');
        $response = $this->action->list(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('session_required', $payload['error']['code']);
        self::assertSame(
            'session_required',
            $this->pdo->query('SELECT reason FROM tenant_transfer_grant_audit')->fetchColumn(),
        );
    }

    public function testRevocationDoesNotEnumerateOrMutateGrantFromAnotherSupplier(): void
    {
        $createdResponse = $this->action->create(
            $this->request('POST')->withParsedBody(['password' => 'current-password']),
            (new ResponseFactory())->createResponse(),
        );
        $created = json_decode(
            (string) $createdResponse->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $foreignRequest = $this->request('DELETE')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 4);
        $foreignResponse = $this->action->revoke(
            $foreignRequest,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(200, $foreignResponse->getStatusCode());
        self::assertNull(
            $this->pdo->query('SELECT revoked_at FROM tenant_transfer_grants')->fetchColumn(),
        );

        $ownResponse = $this->action->revoke(
            $this->request('DELETE'),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(200, $ownResponse->getStatusCode());
        self::assertNotNull(
            $this->pdo->query('SELECT revoked_at FROM tenant_transfer_grants')->fetchColumn(),
        );
    }

    private function request(string $method): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            '/api/admin/tenant-transfer-grants',
            ['REMOTE_ADDR' => '192.0.2.10'],
        )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 7, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 3)
            ->withAttribute('auth.effective_role', new EffectiveRole(
                9,
                'Správce přenosů',
                'staff',
                true,
                ['tenant.transfer.export' => AccessLevel::WRITE->value],
            ));
    }

    private function createSchema(): void
    {
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
    }
}

final readonly class FixedTenantTransferActionClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $time) {}

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }
}
