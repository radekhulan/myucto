<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Capabilities;

use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprint;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityProfileRegistry;

final readonly class TenantTransferCapabilities
{
    public const SCHEMA = 'myucto-tenant-transfer-capabilities';
    public const SCHEMA_VERSION = 1;
    public const FEATURE_REGISTRY_VERSION = 1;
    public const SECRET_REGISTRY_VERSION = 1;
    public const MAX_CHUNK_BYTES = 16 * 1024 * 1024;
    public const MAX_FILE_BYTES = 512 * 1024 * 1024;
    public const MAX_TRANSFER_BYTES = 20 * 1024 * 1024 * 1024;

    /**
     * @param list<string> $compatibilityProfiles
     * @param list<string> $cryptoSuites
     */
    public function __construct(
        public CompatibilityFingerprint $compatibility,
        public string $instanceFingerprint,
        public array $compatibilityProfiles,
        public array $cryptoSuites,
    ) {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $instanceFingerprint) !== 1) {
            throw new \InvalidArgumentException('Instance fingerprint není kanonický SHA-256.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $fingerprint = $this->compatibility;
        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'product' => $fingerprint->product,
            'app_version' => $fingerprint->appVersion,
            'build_revision' => $fingerprint->buildRevision,
            'format' => $fingerprint->format,
            'format_version' => $fingerprint->formatVersion,
            'compatibility_profile' => $fingerprint->compatibilityProfile,
            'pending_migrations' => $fingerprint->pendingMigrations,
            'migration_set_hash' => $fingerprint->migrationSetHash,
            'tenant_registry_version' => $fingerprint->tenantRegistryVersion,
            'tenant_registry_hash' => $fingerprint->tenantRegistryHash,
            'tenant_schema_hash' => $fingerprint->tenantSchemaHash,
            'instance_fingerprint' => $this->instanceFingerprint,
            'compatibility_registry' => [
                'version' => CompatibilityProfileRegistry::VERSION,
                'profiles' => $this->compatibilityProfiles,
            ],
            // Exportní skupiny a secret transformace se zveřejní až s jejich
            // úplnými registry; capabilities samotné nepředstírají hotový export.
            'feature_registry' => [
                'version' => self::FEATURE_REGISTRY_VERSION,
                'complete' => false,
                'groups' => [],
            ],
            'secret_registry' => [
                'version' => self::SECRET_REGISTRY_VERSION,
                'complete' => false,
                'personal_certificate_attachments' => false,
            ],
            'crypto_suites' => $this->cryptoSuites,
            'limits' => [
                'max_chunk_bytes' => self::MAX_CHUNK_BYTES,
                'max_file_bytes' => self::MAX_FILE_BYTES,
                'max_transfer_bytes' => self::MAX_TRANSFER_BYTES,
            ],
            'operations' => ['capabilities'],
            'resumable_download' => false,
            'cutover_lock' => false,
        ];
    }
}
