<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Podpisová data firmy a volitelné osobní certifikátové přílohy. */
final class TenantDataSigningCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::owned(
                'signing_profiles',
                ['id'],
                secrets: [
                    'pdf_tsa_password_enc' => self::reencrypt(),
                ],
                actorReferences: [
                    'owner_user_id' => 'map_existing_user_or_null',
                    'created_by' => 'map_existing_user_or_null',
                ],
                postImport: [
                    'force_columns' => ['is_active' => false],
                    'reason' => 'signing_requires_manual_reactivation',
                ],
            ),
            self::owned(
                'signing_settings',
                ['supplier_id'],
                postImport: [
                    'force_columns' => [
                        'accountant_profiles_enabled' => false,
                    ],
                    'reason' => 'signing_requires_manual_reactivation',
                ],
            ),
            self::indirect(
                'signing_credentials',
                ['id'],
                [
                    self::path('profile_id', 'signing_profiles'),
                    self::path('supplier_id', 'supplier'),
                ],
                secrets: [
                    'vault_credential_id' => self::notSecret(
                        'foreign_key_identifier_only',
                    ),
                    'passphrase_profile_id' => self::omit(),
                    'encrypted_passphrase' => self::reencrypt(),
                ],
                actorReferences: [
                    'created_by' => 'map_existing_user_or_null',
                ],
                personalAttachmentReferences: [
                    'vault_credential_id' => 'map_selected_or_null',
                ],
                postImport: [
                    'force_columns' => ['is_active' => false],
                    'reason' =>
                        'signing_credential_requires_manual_reactivation',
                ],
                additionalDetails: [
                    'tenant_file_passphrase' => [
                        'source' => 'resolve_current_policy',
                        'target' => 'reencrypt_as_encrypted_store',
                    ],
                ],
            ),
            self::owned(
                'pdf_signature_output_settings',
                ['supplier_id', 'output_type'],
                postImport: [
                    'force_columns' => ['enabled' => false],
                    'reason' =>
                        'signature_output_requires_manual_reactivation',
                ],
            ),
            self::owned(
                'signature_role_profiles',
                ['supplier_id', 'usage', 'output_type', 'role'],
            ),
            self::relation(
                'signature_user_profiles',
                ['supplier_id', 'usage', 'output_type', 'user_id'],
                actorReferences: [
                    'user_id' => 'map_existing_user_required',
                ],
            ),
            self::owned(
                'signature_document_overrides',
                ['supplier_id', 'usage', 'entity_type', 'entity_id'],
                actorReferences: [
                    'created_by' => 'map_existing_user_or_null',
                ],
                additionalDetails: [
                    'soft_references' => [
                        'entity' => [
                            'strategy' => 'polymorphic_tenant_entity',
                            'type_column' => 'entity_type',
                            'id_column' => 'entity_id',
                            'unknown_value' => 'block',
                            'targets' => [
                                'invoice' => 'invoices',
                                'work_report' => 'work_reports',
                            ],
                        ],
                    ],
                ],
            ),
            self::personalVault(),
            self::relation(
                'epo_signing_credential_suppliers',
                ['credential_id', 'supplier_id'],
                secrets: [
                    'credential_id' => self::notSecret(
                        'foreign_key_identifier_only',
                    ),
                ],
                actorReferences: [
                    'enabled_by' => 'map_existing_user_or_null',
                ],
                personalAttachmentReferences: [
                    'credential_id' => 'require_selected_or_skip_row',
                ],
            ),
            self::relation(
                'payroll_submission_signing_profiles',
                ['supplier_id', 'environment'],
                secrets: [
                    'credential_id' => self::notSecret(
                        'foreign_key_identifier_only',
                    ),
                ],
                actorReferences: [
                    'owner_user_id' => 'map_existing_user_required',
                ],
                softActorReferences: [
                    'created_by' => 'map_existing_user_or_null',
                ],
                personalAttachmentReferences: [
                    'credential_id' => 'require_selected_or_skip_row',
                ],
                additionalDetails: [
                    'reference_invariants' => [
                        'credential_owner' => [
                            'credential_column' => 'credential_id',
                            'owner_column' => 'owner_user_id',
                            'strategy' => 'require_mapped_owner_match',
                        ],
                    ],
                ],
            ),
            self::certificateFiles(),
        ];
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,array<string,string>> $secrets
     * @param array<string,string> $actorReferences
     * @param array<string,string> $softActorReferences
     * @param array<string,string> $personalAttachmentReferences
     * @param array<string,mixed> $postImport
     * @param array<string,mixed> $additionalDetails
     */
    private static function owned(
        string $table,
        array $primaryKey,
        array $secrets = [],
        array $actorReferences = [],
        array $softActorReferences = [],
        array $personalAttachmentReferences = [],
        array $postImport = [],
        array $additionalDetails = [],
    ): TenantDataDefinition {
        return self::table(
            $table,
            $primaryKey,
            TenantDataPolicy::TenantOwned,
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $secrets,
            $actorReferences,
            $softActorReferences,
            $personalAttachmentReferences,
            $postImport,
            $additionalDetails,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param list<array{from_column:string,to_table:string,to_column:string}> $path
     * @param array<string,array<string,string>> $secrets
     * @param array<string,string> $actorReferences
     * @param array<string,string> $softActorReferences
     * @param array<string,string> $personalAttachmentReferences
     * @param array<string,mixed> $postImport
     * @param array<string,mixed> $additionalDetails
     */
    private static function indirect(
        string $table,
        array $primaryKey,
        array $path,
        array $secrets = [],
        array $actorReferences = [],
        array $softActorReferences = [],
        array $personalAttachmentReferences = [],
        array $postImport = [],
        array $additionalDetails = [],
    ): TenantDataDefinition {
        return self::table(
            $table,
            $primaryKey,
            TenantDataPolicy::TenantOwnedIndirect,
            [
                'strategy' => 'foreign_key_path',
                'path' => $path,
            ],
            $secrets,
            $actorReferences,
            $softActorReferences,
            $personalAttachmentReferences,
            $postImport,
            $additionalDetails,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,array<string,string>> $secrets
     * @param array<string,string> $actorReferences
     * @param array<string,string> $softActorReferences
     * @param array<string,string> $personalAttachmentReferences
     * @param array<string,mixed> $postImport
     * @param array<string,mixed> $additionalDetails
     */
    private static function relation(
        string $table,
        array $primaryKey,
        array $secrets = [],
        array $actorReferences = [],
        array $softActorReferences = [],
        array $personalAttachmentReferences = [],
        array $postImport = [],
        array $additionalDetails = [],
    ): TenantDataDefinition {
        if (array_key_exists('relation_import', $additionalDetails)) {
            throw new \LogicException(
                'Podpisový katalog nesmí přepsat politiku obnovy vazby.',
            );
        }
        $additionalDetails = [
            'relation_import' => [
                'strategy' => 'recreate_from_mapped_references',
                'raw_insert' => false,
                'unresolved_row' => 'skip',
            ],
            ...$additionalDetails,
        ];
        return self::table(
            $table,
            $primaryKey,
            TenantDataPolicy::TenantRelation,
            [
                'strategy' => 'supplier_relation',
                'column' => 'supplier_id',
            ],
            $secrets,
            $actorReferences,
            $softActorReferences,
            $personalAttachmentReferences,
            $postImport,
            $additionalDetails,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,mixed> $ownership
     * @param array<string,array<string,string>> $secrets
     * @param array<string,string> $actorReferences
     * @param array<string,string> $softActorReferences
     * @param array<string,string> $personalAttachmentReferences
     * @param array<string,mixed> $postImport
     * @param array<string,mixed> $additionalDetails
     */
    private static function table(
        string $table,
        array $primaryKey,
        TenantDataPolicy $policy,
        array $ownership,
        array $secrets,
        array $actorReferences,
        array $softActorReferences,
        array $personalAttachmentReferences,
        array $postImport,
        array $additionalDetails,
    ): TenantDataDefinition {
        $details = [
            'primary_key' => $primaryKey,
            'feature_group' => 'signing',
            'ownership' => $ownership,
            'secrets' => $secrets,
        ];
        if ($actorReferences !== []) {
            $details['actor_references'] = self::references(
                $actorReferences,
            );
        }
        if ($softActorReferences !== []) {
            $details['soft_actor_references'] = self::references(
                $softActorReferences,
            );
        }
        if ($personalAttachmentReferences !== []) {
            $details['personal_attachment_references'] = self::references(
                $personalAttachmentReferences,
            );
        }
        if ($postImport !== []) {
            $details['post_import'] = $postImport;
        }
        foreach ($additionalDetails as $key => $value) {
            if (array_key_exists($key, $details)) {
                throw new \LogicException(
                    'Doplňkový detail podpisového katalogu přepisuje SSOT.',
                );
            }
            $details[$key] = $value;
        }
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
    }

    private static function personalVault(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:epo_signing_credentials',
            TenantDataObjectKind::Table,
            TenantDataPolicy::PersonalSecretAttachment,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'signing',
                'owner_column' => 'owner_user_id',
                'consent' => 'source_and_target_owner',
                'default_selected' => false,
                'candidate_selector' => [
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
                            'table' =>
                                'payroll_submission_signing_profiles',
                            'column' => 'credential_id',
                        ],
                    ],
                ],
                'deduplication' => [
                    'strategy' => 'target_owner_fingerprint',
                    'keys' => ['owner_user_id', 'fingerprint_sha256'],
                    'active_collision' => 'reuse_without_overwrite',
                    'soft_deleted_collision' =>
                        'require_target_owner_decision',
                ],
                'secrets' => [
                    'pfx_ciphertext' => self::reencryptPersonal(),
                    'passphrase_ciphertext' => self::reencryptPersonal(),
                ],
                'actor_references' => self::references([
                    'owner_user_id' => 'map_existing_user_required',
                ]),
                'post_import' => [
                    'timestamps' => 'target_now',
                    'reuse' => 'append_supplier_relation_only',
                ],
            ],
        );
    }

    private static function certificateFiles(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'file_area:signing_profile_certificates',
            TenantDataObjectKind::FileArea,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'feature_group' => 'signing',
                'source' => [
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
                'target' => [
                    'strategy' => 'regenerate_from_mapped_ids',
                    'template' => 'signing/profiles/supplier-{supplier_id}'
                        . '/profile-{profile_id}/profile.p12',
                    'posix_mode' => '0600',
                    'windows_acl' => 'owner_only',
                ],
                'validation' => [
                    'format' => 'pkcs12',
                    'private_key' => 'required',
                    'certificate_key_match' => 'required',
                    'fingerprint_column' => 'certificate_fingerprint',
                    'payload' => 'end_to_end_secret_section',
                    'passphrase' => 'source_resolve_target_reencrypt',
                ],
            ],
        );
    }

    /**
     * @param array<string,string> $references
     * @return array<string,array{strategy:string}>
     */
    private static function references(array $references): array
    {
        $result = [];
        foreach ($references as $column => $strategy) {
            $result[$column] = ['strategy' => $strategy];
        }
        return $result;
    }

    /** @return array{from_column:string,to_table:string,to_column:string} */
    private static function path(string $column, string $table): array
    {
        return [
            'from_column' => $column,
            'to_table' => $table,
            'to_column' => 'id',
        ];
    }

    /** @return array{policy:string} */
    private static function reencrypt(): array
    {
        return ['policy' => TenantSecretPolicy::ReencryptV1->value];
    }

    /** @return array{policy:string} */
    private static function reencryptPersonal(): array
    {
        return [
            'policy' => TenantSecretPolicy::ReencryptPersonalWithDualConsent
                ->value,
        ];
    }

    /** @return array{policy:string} */
    private static function omit(): array
    {
        return ['policy' => TenantSecretPolicy::OmitAndReconfigure->value];
    }

    /** @return array{policy:string,reason:string} */
    private static function notSecret(string $reason): array
    {
        return [
            'policy' => TenantSecretPolicy::NotSecret->value,
            'reason' => $reason,
        ];
    }
}
