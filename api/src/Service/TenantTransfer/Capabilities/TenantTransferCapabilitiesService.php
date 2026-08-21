<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Capabilities;

use MyInvoice\Service\TenantTransfer\Compatibility\ApplicationVersionProvider;
use MyInvoice\Service\TenantTransfer\Compatibility\ApplicationVersionUnavailable;
use MyInvoice\Service\TenantTransfer\Compatibility\BuildRevisionProvider;
use MyInvoice\Service\TenantTransfer\Compatibility\BuildRevisionUnavailable;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprintFactory;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprintUnavailable;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityProfileRegistry;
use MyInvoice\Service\TenantTransfer\Compatibility\InstanceFingerprintProvider;
use MyInvoice\Service\TenantTransfer\Compatibility\InstanceFingerprintUnavailable;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetStateProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetUnavailable;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaFingerprintProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaUnavailable;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;

/** Jediný zdroj capabilities pro handshake, budoucí plán i exportní manifest. */
final class TenantTransferCapabilitiesService
{
    public function __construct(
        private readonly ApplicationVersionProvider $applicationVersion,
        private readonly BuildRevisionProvider $buildRevision,
        private readonly MigrationSetStateProvider $migrationState,
        private readonly TenantDataRegistry $tenantRegistry,
        private readonly TenantSchemaFingerprintProvider $tenantSchema,
        private readonly CompatibilityFingerprintFactory $fingerprints,
        private readonly InstanceFingerprintProvider $instanceFingerprint,
        private readonly CompatibilityProfileRegistry $compatibilityProfiles,
    ) {}

    public function current(): TenantTransferCapabilities
    {
        if (!$this->tenantRegistry->isComplete(TenantDataRegistry::TRANSFER_PROFILE)) {
            throw new TenantTransferCapabilitiesUnavailable(
                'tenant_registry_incomplete',
                'Tenantový registr zatím nepokrývá úplný transfer profil.',
            );
        }

        try {
            $compatibility = $this->fingerprints->create(
                $this->applicationVersion->current(),
                $this->buildRevision->current(),
                $this->migrationState->current(),
                $this->tenantRegistry,
                $this->tenantSchema->current($this->tenantRegistry),
            );
            return new TenantTransferCapabilities(
                $compatibility,
                $this->instanceFingerprint->current(),
                $this->compatibilityProfiles->profileIds(),
                self::cryptoSuites(),
            );
        } catch (ApplicationVersionUnavailable $exception) {
            throw self::unavailable('application_version_unavailable', $exception);
        } catch (BuildRevisionUnavailable $exception) {
            throw self::unavailable('build_revision_unavailable', $exception);
        } catch (MigrationSetUnavailable $exception) {
            throw self::unavailable('migration_set_unavailable', $exception);
        } catch (TenantSchemaUnavailable $exception) {
            throw self::unavailable($exception->reason, $exception);
        } catch (CompatibilityFingerprintUnavailable $exception) {
            throw self::unavailable($exception->reason, $exception);
        } catch (InstanceFingerprintUnavailable $exception) {
            throw self::unavailable('instance_fingerprint_unavailable', $exception);
        }
    }

    /** @return list<string> */
    private static function cryptoSuites(): array
    {
        if (function_exists('sodium_crypto_scalarmult')
            && function_exists('sodium_crypto_scalarmult_base')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
            && function_exists('sodium_crypto_sign_keypair')
        ) {
            return ['x25519-xchacha20poly1305-ed25519'];
        }
        return [];
    }

    private static function unavailable(
        string $reason,
        \Throwable $previous,
    ): TenantTransferCapabilitiesUnavailable {
        return new TenantTransferCapabilitiesUnavailable(
            $reason,
            'Capability metadata instance nyní nelze bezpečně sestavit.',
            $previous,
        );
    }
}
