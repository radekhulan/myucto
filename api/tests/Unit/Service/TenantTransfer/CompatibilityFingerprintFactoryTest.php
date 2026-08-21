<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprintFactory;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprintUnavailable;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetState;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class CompatibilityFingerprintFactoryTest extends TestCase
{
    public function testBuildsIdentityFingerprintAndSurfacesPendingMigration(): void
    {
        $migrations = new MigrationSetState(
            'sha256:' . str_repeat('1', 64),
            ['0001_init.sql'],
            ['0002_pending.sql'],
            [],
        );
        $registry = $this->registry(complete: true);

        $fingerprint = (new CompatibilityFingerprintFactory())->create(
            '5.2.0',
            'git:0123456789abcdef',
            $migrations,
            $registry,
            'sha256:' . str_repeat('3', 64),
        );

        self::assertSame('myucto', $fingerprint->product);
        self::assertSame('identity', $fingerprint->compatibilityProfile);
        self::assertTrue($fingerprint->pendingMigrations);
        self::assertSame($registry->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE), $fingerprint->tenantRegistryHash);
    }

    public function testMissingAppliedMigrationFileMakesFingerprintUnavailable(): void
    {
        $migrations = new MigrationSetState(null, ['0001_missing.sql'], [], ['0001_missing.sql']);

        try {
            (new CompatibilityFingerprintFactory())->create(
                '5.2.0',
                'git:0123456789abcdef',
                $migrations,
                $this->registry(complete: true),
                'sha256:' . str_repeat('3', 64),
            );
            self::fail('Chybějící migrační soubor nesmí vytvořit fingerprint.');
        } catch (CompatibilityFingerprintUnavailable $exception) {
            self::assertSame('migration_set_unavailable', $exception->reason);
        }
    }

    public function testIncompleteTenantRegistryMakesFingerprintUnavailable(): void
    {
        $migrations = new MigrationSetState('sha256:' . str_repeat('1', 64), ['0001_init.sql'], [], []);

        try {
            (new CompatibilityFingerprintFactory())->create(
                '5.2.0',
                'git:0123456789abcdef',
                $migrations,
                $this->registry(complete: false),
                'sha256:' . str_repeat('3', 64),
            );
            self::fail('Neúplný tenantový registr nesmí vytvořit fingerprint.');
        } catch (CompatibilityFingerprintUnavailable $exception) {
            self::assertSame('tenant_registry_incomplete', $exception->reason);
        }
    }

    private function registry(bool $complete): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:supplier',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [TenantDataRegistry::TRANSFER_PROFILE],
                ['ownership' => ['strategy' => 'selected_supplier']],
            )],
            $complete ? [TenantDataRegistry::TRANSFER_PROFILE] : [],
        );
    }
}
