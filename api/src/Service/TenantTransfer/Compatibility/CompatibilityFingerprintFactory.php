<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetState;
use MyInvoice\Service\TenantTransfer\Registry\IncompleteTenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;

/** Skládá jediný fingerprint používaný capabilities, manifestem i preflightem. */
final class CompatibilityFingerprintFactory
{
    public function create(
        string $appVersion,
        string $buildRevision,
        MigrationSetState $migrations,
        TenantDataRegistry $tenantRegistry,
        string $tenantSchemaHash,
    ): CompatibilityFingerprint {
        if ($migrations->hash === null || $migrations->missingMigrationFiles !== []) {
            throw new CompatibilityFingerprintUnavailable(
                'migration_set_unavailable',
                'Úplný fingerprint aplikovaných migrací není dostupný.',
            );
        }
        try {
            $tenantRegistryHash = $tenantRegistry->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE);
        } catch (IncompleteTenantDataRegistry $exception) {
            throw new CompatibilityFingerprintUnavailable(
                'tenant_registry_incomplete',
                'Tenantový registr není úplný.',
                $exception,
            );
        }

        return CompatibilityFingerprint::fromArray([
            'schema' => CompatibilityFingerprint::SCHEMA,
            'schema_version' => CompatibilityFingerprint::SCHEMA_VERSION,
            'product' => CompatibilityFingerprint::PRODUCT,
            'app_version' => $appVersion,
            'build_revision' => $buildRevision,
            'format' => CompatibilityFingerprint::FORMAT,
            'format_version' => CompatibilityFingerprint::FORMAT_VERSION,
            'compatibility_profile' => CompatibilityFingerprint::IDENTITY_PROFILE,
            'pending_migrations' => $migrations->pendingMigrations !== [],
            'migration_set_hash' => $migrations->hash,
            'tenant_registry_version' => $tenantRegistry->version,
            'tenant_registry_hash' => $tenantRegistryHash,
            'tenant_schema_hash' => $tenantSchemaHash,
        ]);
    }
}
