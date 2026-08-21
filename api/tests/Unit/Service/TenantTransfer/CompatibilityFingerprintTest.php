<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprint;
use MyInvoice\Service\TenantTransfer\Compatibility\InvalidCompatibilityFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompatibilityFingerprintTest extends TestCase
{
    public function testStrictPayloadRoundTripsWithoutNormalization(): void
    {
        $payload = self::payload();

        $fingerprint = CompatibilityFingerprint::fromArray($payload);

        self::assertSame($payload, $fingerprint->toArray());
        self::assertSame('5.2.0', $fingerprint->appVersion);
        self::assertSame('git:0123456789abcdef', $fingerprint->buildRevision);
        self::assertFalse($fingerprint->pendingMigrations);
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutate */
    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedOrAmbiguousPayload(callable $mutate): void
    {
        $this->expectException(InvalidCompatibilityFingerprint::class);

        CompatibilityFingerprint::fromArray($mutate(self::payload()));
    }

    /** @return iterable<string,array{callable(array<string,mixed>):array<string,mixed>}> */
    public static function invalidPayloads(): iterable
    {
        yield 'unknown field' => [static function (array $payload): array {
            $payload['hostname'] = 'source.example.test';
            return $payload;
        }];
        yield 'missing field' => [static function (array $payload): array {
            unset($payload['build_revision']);
            return $payload;
        }];
        yield 'string schema version' => [static function (array $payload): array {
            $payload['schema_version'] = '1';
            return $payload;
        }];
        yield 'unsafe product' => [static function (array $payload): array {
            $payload['product'] = 'MyÚčto';
            return $payload;
        }];
        yield 'trimmed version would be ambiguous' => [static function (array $payload): array {
            $payload['app_version'] = ' 5.2.0';
            return $payload;
        }];
        yield 'control character' => [static function (array $payload): array {
            $payload['build_revision'] = "git:abc\nforged";
            return $payload;
        }];
        yield 'integer boolean' => [static function (array $payload): array {
            $payload['pending_migrations'] = 0;
            return $payload;
        }];
        yield 'uppercase hash' => [static function (array $payload): array {
            $payload['migration_set_hash'] = 'sha256:' . str_repeat('A', 64);
            return $payload;
        }];
        yield 'zero registry version' => [static function (array $payload): array {
            $payload['tenant_registry_version'] = 0;
            return $payload;
        }];
    }

    /** @return array<string,mixed> */
    public static function payload(): array
    {
        return [
            'schema' => CompatibilityFingerprint::SCHEMA,
            'schema_version' => CompatibilityFingerprint::SCHEMA_VERSION,
            'product' => 'myucto',
            'app_version' => '5.2.0',
            'build_revision' => 'git:0123456789abcdef',
            'format' => 'myucto-tenant-transfer',
            'format_version' => 1,
            'compatibility_profile' => 'identity',
            'pending_migrations' => false,
            'migration_set_hash' => 'sha256:' . str_repeat('1', 64),
            'tenant_registry_version' => 1,
            'tenant_registry_hash' => 'sha256:' . str_repeat('2', 64),
            'tenant_schema_hash' => 'sha256:' . str_repeat('3', 64),
        ];
    }
}
