<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprint;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityProfileRegistry;
use PHPUnit\Framework\TestCase;

final class CompatibilityProfileRegistryTest extends TestCase
{
    public function testV1ContainsOnlyIdentityAndAcceptsOnlyExactFingerprint(): void
    {
        $registry = CompatibilityProfileRegistry::v1();
        $source = $this->fingerprint();
        $target = $this->fingerprint();

        $result = $registry->evaluate($source, $target);

        self::assertSame(['identity'], $registry->profileIds());
        self::assertTrue($result->isCompatible());
        self::assertSame('identity', $result->profile);
        self::assertSame([], $result->issues);
    }

    public function testIdentityReportsEveryBlockingDifferenceInStableOrder(): void
    {
        $source = $this->fingerprint([
            'format_version' => 2,
            'app_version' => '5.1.0',
            'build_revision' => 'git:source',
            'pending_migrations' => true,
            'migration_set_hash' => 'sha256:' . str_repeat('4', 64),
            'tenant_registry_version' => 2,
            'tenant_registry_hash' => 'sha256:' . str_repeat('5', 64),
            'tenant_schema_hash' => 'sha256:' . str_repeat('6', 64),
        ]);
        $target = $this->fingerprint();

        $result = CompatibilityProfileRegistry::v1()->evaluate($source, $target);

        self::assertFalse($result->isCompatible());
        self::assertSame('identity', $result->profile);
        self::assertSame([
            'unsupported_format_version',
            'application_version_mismatch',
            'build_revision_mismatch',
            'pending_migrations',
            'migration_set_mismatch',
            'tenant_registry_mismatch',
            'tenant_schema_mismatch',
        ], $this->issueCodes($result->issues));
    }

    public function testMyInvoiceSourceRequiresInPlaceUpgrade(): void
    {
        $result = CompatibilityProfileRegistry::v1()->evaluate(
            $this->fingerprint(['product' => 'myinvoice']),
            $this->fingerprint(),
        );

        self::assertFalse($result->isCompatible());
        self::assertSame(['source_upgrade_required'], $this->issueCodes($result->issues));
    }

    public function testUnknownDirectionalProfileCannotFallBackToIdentity(): void
    {
        $result = CompatibilityProfileRegistry::v1()->evaluate(
            $this->fingerprint(['compatibility_profile' => 'future-source']),
            $this->fingerprint(['compatibility_profile' => 'future-target']),
        );

        self::assertFalse($result->isCompatible());
        self::assertNull($result->profile);
        self::assertSame(['compatibility_adapter_unavailable'], $this->issueCodes($result->issues));
    }

    /** @param array<string,mixed> $overrides */
    private function fingerprint(array $overrides = []): CompatibilityFingerprint
    {
        return CompatibilityFingerprint::fromArray(array_replace(
            CompatibilityFingerprintTest::payload(),
            $overrides,
        ));
    }

    /** @param list<object{code:string}> $issues @return list<string> */
    private function issueCodes(array $issues): array
    {
        return array_map(static fn (object $issue): string => $issue->code, $issues);
    }
}
