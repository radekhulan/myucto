<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

final class IdentityCompatibilityProfile implements CompatibilityProfile
{
    public function id(): string
    {
        return CompatibilityFingerprint::IDENTITY_PROFILE;
    }

    public function sourceProfile(): string
    {
        return CompatibilityFingerprint::IDENTITY_PROFILE;
    }

    public function targetProfile(): string
    {
        return CompatibilityFingerprint::IDENTITY_PROFILE;
    }

    public function evaluate(CompatibilityFingerprint $source, CompatibilityFingerprint $target): array
    {
        $issues = [];
        if ($source->format !== CompatibilityFingerprint::FORMAT
            || $target->format !== CompatibilityFingerprint::FORMAT
            || $source->formatVersion !== CompatibilityFingerprint::FORMAT_VERSION
            || $target->formatVersion !== CompatibilityFingerprint::FORMAT_VERSION
        ) {
            $issues[] = new CompatibilityIssue(
                'unsupported_format_version',
                'format',
                'Zdroj a cíl musí používat transfer formát verze 1.',
            );
        }
        if ($source->appVersion !== $target->appVersion) {
            $issues[] = new CompatibilityIssue(
                'application_version_mismatch',
                'app_version',
                'Zdroj a cíl nemají stejnou verzi aplikace.',
            );
        }
        if ($source->buildRevision !== $target->buildRevision) {
            $issues[] = new CompatibilityIssue(
                'build_revision_mismatch',
                'build_revision',
                'Zdroj a cíl nemají stejné sestavení aplikace.',
            );
        }
        if ($source->pendingMigrations || $target->pendingMigrations) {
            $issues[] = new CompatibilityIssue(
                'pending_migrations',
                'pending_migrations',
                'Na zdroji ani cíli nesmí čekat databázová migrace.',
            );
        }
        if ($source->migrationSetHash !== $target->migrationSetHash) {
            $issues[] = new CompatibilityIssue(
                'migration_set_mismatch',
                'migration_set_hash',
                'Zdroj a cíl nemají stejnou úplnou sadu aplikovaných migrací.',
            );
        }
        if ($source->tenantRegistryVersion !== $target->tenantRegistryVersion
            || $source->tenantRegistryHash !== $target->tenantRegistryHash
        ) {
            $issues[] = new CompatibilityIssue(
                'tenant_registry_mismatch',
                'tenant_registry_hash',
                'Zdroj a cíl nemají stejný tenantový registr.',
            );
        }
        if ($source->tenantSchemaHash !== $target->tenantSchemaHash) {
            $issues[] = new CompatibilityIssue(
                'tenant_schema_mismatch',
                'tenant_schema_hash',
                'Zdroj a cíl nemají stejné registrované tenantové schéma.',
            );
        }
        return $issues;
    }
}
