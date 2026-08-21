<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataSigningCatalog;
use PHPUnit\Framework\TestCase;

final class TenantDataSigningCatalogTest extends TestCase
{
    public function testSigningBoundaryHasExplicitPoliciesAndPrimaryKeys(): void
    {
        $expected = [
            'epo_signing_credential_suppliers' => [
                TenantDataPolicy::TenantRelation,
                ['credential_id', 'supplier_id'],
            ],
            'epo_signing_credentials' => [
                TenantDataPolicy::PersonalSecretAttachment,
                ['id'],
            ],
            'payroll_submission_signing_profiles' => [
                TenantDataPolicy::TenantRelation,
                ['supplier_id', 'environment'],
            ],
            'pdf_signature_output_settings' => [
                TenantDataPolicy::TenantOwned,
                ['supplier_id', 'output_type'],
            ],
            'signature_document_overrides' => [
                TenantDataPolicy::TenantOwned,
                ['supplier_id', 'usage', 'entity_type', 'entity_id'],
            ],
            'signature_role_profiles' => [
                TenantDataPolicy::TenantOwned,
                ['supplier_id', 'usage', 'output_type', 'role'],
            ],
            'signature_user_profiles' => [
                TenantDataPolicy::TenantRelation,
                ['supplier_id', 'usage', 'output_type', 'user_id'],
            ],
            'signing_credentials' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['id'],
            ],
            'signing_profiles' => [TenantDataPolicy::TenantOwned, ['id']],
            'signing_settings' => [
                TenantDataPolicy::TenantOwned,
                ['supplier_id'],
            ],
        ];

        $actual = [];
        foreach (self::tableDefinitions() as $table => $definition) {
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            $actual[$table] = [
                $definition->policy,
                $definition->details['primary_key'] ?? null,
            ];
        }

        self::assertSame($expected, $actual);
    }

    public function testPersonalVaultIsOptionalBoundedAndUsesDualConsent(): void
    {
        $vault = self::tableDefinitions()['epo_signing_credentials'];

        self::assertSame('owner_user_id', $vault->details['owner_column'] ?? null);
        self::assertSame(
            'source_and_target_owner',
            $vault->details['consent'] ?? null,
        );
        self::assertFalse($vault->details['default_selected'] ?? true);
        self::assertSame(
            [
                'strategy' => 'tenant_reference_union',
                'references' => [
                    [
                        'table' => 'epo_signing_credential_suppliers',
                        'column' => 'credential_id',
                    ],
                    [
                        'table' => 'signing_credentials',
                        'column' => 'vault_credential_id',
                        'filters' => [
                            [
                                'table' => 'signing_credentials',
                                'column' => 'deleted_at',
                                'operator' => 'is_null',
                            ],
                            [
                                'table' => 'signing_profiles',
                                'column' => 'deleted_at',
                                'operator' => 'is_null',
                            ],
                        ],
                    ],
                    [
                        'table' => 'payroll_submission_signing_profiles',
                        'column' => 'credential_id',
                    ],
                ],
            ],
            $vault->details['candidate_selector'] ?? null,
        );
        self::assertSame(
            [
                'strategy' => 'target_owner_fingerprint',
                'keys' => ['owner_user_id', 'fingerprint_sha256'],
                'active_collision' => 'reuse_without_overwrite',
                'soft_deleted_collision' => 'require_target_owner_decision',
            ],
            $vault->details['deduplication'] ?? null,
        );
        self::assertSame(
            [
                'pfx_ciphertext' => [
                    'policy' => 'reencrypt_personal_with_dual_consent',
                ],
                'passphrase_ciphertext' => [
                    'policy' => 'reencrypt_personal_with_dual_consent',
                ],
            ],
            $vault->details['secrets'] ?? null,
        );
        self::assertSame(
            [
                'owner_user_id' => [
                    'strategy' => 'map_existing_user_required',
                ],
            ],
            $vault->details['actor_references'] ?? null,
        );
    }

    public function testPersonalReferencesAndSigningAutomationFailClosed(): void
    {
        $definitions = self::tableDefinitions();

        $relationImport = [
            'strategy' => 'recreate_from_mapped_references',
            'raw_insert' => false,
            'unresolved_row' => 'skip',
        ];
        self::assertSame(
            $relationImport,
            $definitions['signature_user_profiles']
                ->details['relation_import'] ?? null,
        );
        self::assertSame(
            $relationImport,
            $definitions['epo_signing_credential_suppliers']
                ->details['relation_import'] ?? null,
        );
        self::assertSame(
            $relationImport,
            $definitions['payroll_submission_signing_profiles']
                ->details['relation_import'] ?? null,
        );

        self::assertSame(
            [
                'vault_credential_id' => [
                    'strategy' => 'map_selected_or_null',
                ],
            ],
            $definitions['signing_credentials']
                ->details['personal_attachment_references'] ?? null,
        );
        self::assertSame(
            [
                'credential_id' => [
                    'strategy' => 'require_selected_or_skip_row',
                ],
            ],
            $definitions['epo_signing_credential_suppliers']
                ->details['personal_attachment_references'] ?? null,
        );
        self::assertSame(
            [
                'credential_id' => [
                    'strategy' => 'require_selected_or_skip_row',
                ],
            ],
            $definitions['payroll_submission_signing_profiles']
                ->details['personal_attachment_references'] ?? null,
        );
        self::assertSame(
            [
                'force_columns' => ['is_active' => false],
                'reason' => 'signing_requires_manual_reactivation',
            ],
            $definitions['signing_profiles']->details['post_import'] ?? null,
        );
        self::assertSame(
            [
                'force_columns' => ['enabled' => false],
                'reason' => 'signature_output_requires_manual_reactivation',
            ],
            $definitions['pdf_signature_output_settings']
                ->details['post_import'] ?? null,
        );
        self::assertSame(
            [
                'force_columns' => ['is_active' => false],
                'reason' => 'signing_credential_requires_manual_reactivation',
            ],
            $definitions['signing_credentials']->details['post_import']
                ?? null,
        );
        $softReferences = $definitions['signature_document_overrides']
            ->details['soft_references'] ?? null;
        self::assertIsArray($softReferences);
        $entityReference = $softReferences['entity'] ?? null;
        self::assertIsArray($entityReference);
        self::assertSame('block', $entityReference['unknown_value'] ?? null);
    }

    public function testTenantCertificateFilesStayUnderRuntimeStorage(): void
    {
        $definition = self::fileDefinition();

        self::assertSame(TenantDataObjectKind::FileArea, $definition->kind);
        self::assertSame(
            TenantDataPolicy::TenantOwnedIndirect,
            $definition->policy,
        );
        self::assertSame(
            [
                'base' => 'runtime_paths.storage',
                'relative_root' => 'signing/profiles',
                'row_reference' => [
                    'table' => 'signing_credentials',
                    'column' => 'certificate_path',
                ],
                'require_relative_path' => true,
                'containment' => 'case_insensitive',
                'outside_symlink' => 'reject',
            ],
            $definition->details['source'] ?? null,
        );
        self::assertSame(
            [
                'strategy' => 'regenerate_from_mapped_ids',
                'template' => 'signing/profiles/supplier-{supplier_id}'
                    . '/profile-{profile_id}/profile.p12',
                'posix_mode' => '0600',
                'windows_acl' => 'owner_only',
            ],
            $definition->details['target'] ?? null,
        );
        self::assertSame(
            [
                'format' => 'pkcs12',
                'private_key' => 'required',
                'certificate_key_match' => 'required',
                'fingerprint_column' => 'certificate_fingerprint',
                'payload' => 'end_to_end_secret_section',
                'passphrase' => 'source_resolve_target_reencrypt',
            ],
            $definition->details['validation'] ?? null,
        );
    }

    public function testFactoryPublishesSigningCatalogOnlyForTransfer(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $transfer = array_map(
            static fn (TenantDataDefinition $definition): string =>
                $definition->key,
            $registry->definitionsFor(TenantDataRegistry::TRANSFER_PROFILE),
        );
        $archive = array_map(
            static fn (TenantDataDefinition $definition): string =>
                $definition->key,
            $registry->definitionsFor(
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
            ),
        );

        self::assertContains('table:signing_profiles', $transfer);
        self::assertContains('table:epo_signing_credentials', $transfer);
        self::assertContains(
            'file_area:signing_profile_certificates',
            $transfer,
        );
        self::assertNotContains('table:signing_profiles', $archive);
        self::assertNotContains('table:epo_signing_credentials', $archive);
    }

    /** @return array<string,TenantDataDefinition> */
    private static function tableDefinitions(): array
    {
        $definitions = [];
        foreach (TenantDataSigningCatalog::definitions() as $definition) {
            if ($definition->kind !== TenantDataObjectKind::Table) {
                continue;
            }
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }

    private static function fileDefinition(): TenantDataDefinition
    {
        foreach (TenantDataSigningCatalog::definitions() as $definition) {
            if ($definition->key === 'file_area:signing_profile_certificates') {
                return $definition;
            }
        }
        self::fail('V podpisovém katalogu chybí souborová oblast certifikátů.');
    }
}
