<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\PsrSecurityClock;
use MyInvoice\Service\TenantTransfer\Grant\TenantTransferGrantService;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class TenantTransferGrantServiceTest extends TestCase
{
    private PDO $pdo;
    private MutableTenantTransferGrantClock $clock;
    private TenantTransferGrantService $grants;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($this->pdo);

        $connection = new Connection(new Config([]));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $this->pdo);
        $this->clock = new MutableTenantTransferGrantClock(
            new \DateTimeImmutable('2026-08-20 10:00:00.000000', new \DateTimeZone('UTC')),
        );
        $this->grants = new TenantTransferGrantService(
            $connection,
            new PsrSecurityClock($this->clock),
        );
    }

    public function testGrantIsStoredHashOnlyAndPlaintextIsReturnedOnce(): void
    {
        $issued = $this->grants->issue(7, 3, '192.0.2.10');

        self::assertMatchesRegularExpression('/^ttg_v1_[A-Za-z0-9_-]{43}$/D', $issued['plaintext']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $issued['public_id'],
        );
        $row = $this->pdo->query(
            'SELECT grant_hash, grant_prefix FROM tenant_transfer_grants',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(32, strlen((string) $row['grant_hash']));
        self::assertSame(hash('sha256', $issued['plaintext'], true), $row['grant_hash']);
        self::assertNotSame($issued['plaintext'], $row['grant_prefix']);

        $listed = $this->grants->listForSupplier(3);
        self::assertCount(1, $listed);
        self::assertSame('active', $listed[0]['state']);
        self::assertSame($issued['prefix'], $listed[0]['grant_prefix']);
        self::assertArrayNotHasKey('grant_hash', $listed[0]);
        self::assertArrayNotHasKey('plaintext', $listed[0]);

        $persistedText = (string) $this->pdo->query(
            "SELECT group_concat(COALESCE(grant_prefix, '') || COALESCE(public_id, ''))
               FROM tenant_transfer_grants",
        )->fetchColumn();
        $auditText = (string) $this->pdo->query(
            "SELECT group_concat(COALESCE(reason, '') || COALESCE(endpoint, ''))
               FROM tenant_transfer_grant_audit",
        )->fetchColumn();
        self::assertStringNotContainsString($issued['plaintext'], $persistedText . $auditText);
    }

    public function testAuthenticationIsTenantBoundAndRevocationCannotCrossTenantBoundary(): void
    {
        $issued = $this->grants->issue(7, 3, '192.0.2.10');

        $allowed = $this->grants->authenticate(
            $issued['plaintext'],
            'GET',
            '/api/tenant-transfer/v1/capabilities',
            '198.51.100.20',
        );
        self::assertTrue($allowed->isAllowed());
        self::assertSame(3, $allowed->grant['supplier_id'] ?? null);
        self::assertSame($issued['public_id'], $allowed->grant['public_id'] ?? null);
        self::assertNotNull(
            $this->pdo->query(
                'SELECT last_used_at FROM tenant_transfer_grants WHERE id = ' . $issued['id'],
            )->fetchColumn(),
        );

        self::assertFalse($this->grants->revoke($issued['id'], 4, 8, '192.0.2.11'));
        self::assertNull(
            $this->pdo->query(
                'SELECT revoked_at FROM tenant_transfer_grants WHERE id = ' . $issued['id'],
            )->fetchColumn(),
        );
        self::assertTrue($this->grants->revoke($issued['id'], 3, 8, '192.0.2.11'));

        $revoked = $this->grants->authenticate(
            $issued['plaintext'],
            'GET',
            '/api/tenant-transfer/v1/capabilities',
            '198.51.100.20',
        );
        self::assertFalse($revoked->isAllowed());
        self::assertSame('invalid_transfer_grant', $revoked->errorCode);
        self::assertSame('revoked', $this->grants->listForSupplier(3)[0]['state']);
    }

    public function testExpiryAndBothAuthenticationRateLimitsAreAudited(): void
    {
        $expiring = $this->grants->issue(7, 3, '192.0.2.10');
        $this->clock->advance('+31 minutes');
        $expired = $this->grants->authenticate(
            $expiring['plaintext'],
            'GET',
            '/api/tenant-transfer/v1/capabilities',
            '192.0.2.10',
        );
        self::assertSame('transfer_grant_expired', $expired->errorCode);
        self::assertSame(
            'expired',
            $this->pdo->query(
                'SELECT revoked_reason FROM tenant_transfer_grants WHERE id = ' . $expiring['id'],
            )->fetchColumn(),
        );

        $unknown = 'ttg_v1_' . str_repeat('A', 43);
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            self::assertSame(401, $this->grants->authenticate(
                $unknown,
                'GET',
                '/api/tenant-transfer/v1/capabilities',
                '198.51.100.30',
            )->httpStatus);
        }
        $ipLimited = $this->grants->authenticate(
            $unknown,
            'GET',
            '/api/tenant-transfer/v1/capabilities',
            '198.51.100.30',
        );
        self::assertSame(429, $ipLimited->httpStatus);
        self::assertSame(60, $ipLimited->retryAfterSeconds);

        $this->clock->advance('+61 seconds');
        $known = $this->grants->issue(7, 3, '192.0.2.10');
        for ($attempt = 0; $attempt < 120; ++$attempt) {
            self::assertTrue($this->grants->authenticate(
                $known['plaintext'],
                'GET',
                '/api/tenant-transfer/v1/capabilities',
                '203.0.113.40',
            )->isAllowed());
        }
        $grantLimited = $this->grants->authenticate(
            $known['plaintext'],
            'GET',
            '/api/tenant-transfer/v1/capabilities',
            '203.0.113.40',
        );
        self::assertSame(429, $grantLimited->httpStatus);
        self::assertSame(60, $grantLimited->retryAfterSeconds);
        self::assertSame(
            2,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM tenant_transfer_grant_audit WHERE reason = 'rate_limited'",
            )->fetchColumn(),
        );
    }

    public function testMigrationKeepsGrantSecretHashOnlyAndPreparesTargetBinding(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 5) . '/db/migrations/1513_tenant_transfer_grants.sql',
        );
        self::assertIsString($migration);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS tenant_transfer_grants',
            $migration,
        );
        self::assertStringContainsString('grant_hash                      BINARY(32) NOT NULL', $migration);
        self::assertStringContainsString('target_instance_fingerprint     BINARY(32) NULL', $migration);
        self::assertStringContainsString('target_payload_key_fingerprint  BINARY(32) NULL', $migration);
        self::assertStringNotContainsString('grant_plaintext', $migration);
        self::assertStringNotContainsString('grant_secret', $migration);
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE tenant_transfer_grants (
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
        $pdo->exec('CREATE TABLE tenant_transfer_grant_audit (
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

final class MutableTenantTransferGrantClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $time) {}

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(string $modifier): void
    {
        $this->time = $this->time->modify($modifier);
    }
}
