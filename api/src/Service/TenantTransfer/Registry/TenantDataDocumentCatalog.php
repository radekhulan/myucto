<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Dokumentová metadata, DMS vazby a content-addressed soubory firmy. */
final class TenantDataDocumentCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        $documentPath = [
            self::path('document_id', 'documents'),
            self::softPath('supplier_id', 'supplier'),
        ];

        return [
            self::owned(
                'document_folders',
                ['id'],
                actorReferences: [
                    'created_by' => 'map_existing_user_or_null',
                ],
            ),
            self::owned(
                'documents',
                ['id'],
                actorReferences: [
                    'uploaded_by' => 'map_existing_user_or_null',
                    'owner_user_id' => 'map_existing_user_or_null',
                ],
                softActorReferences: [
                    'deleted_by' => 'map_existing_user_or_null',
                ],
                postImport: [
                    'column_transforms' => [
                        'filename' => 'verified_sha256',
                    ],
                    'force_columns' => [
                        'thumb_path' => null,
                        'thumb_status' => 'none',
                    ],
                    'reason' =>
                        'document_files_reverified_and_thumbnails_regenerated',
                ],
                additionalDetails: [
                    'conditional_actor_references' => [
                        'owner_user_id' => [
                            'strategy' =>
                                'map_existing_user_required_when',
                            'condition' => [
                                'column' => 'scope',
                                'operator' => 'equals',
                                'value' => 'user',
                            ],
                        ],
                    ],
                ],
            ),
            self::owned(
                'document_files',
                ['id'],
                actorReferences: [
                    'uploaded_by' => 'map_existing_user_or_null',
                ],
                postImport: [
                    'column_transforms' => [
                        'filename' => 'verified_sha256',
                    ],
                    'reason' => 'document_files_reverified',
                ],
            ),
            self::indirect(
                'document_dms_messages',
                ['id'],
                $documentPath,
            ),
            self::owned('document_tags', ['id']),
            self::indirect(
                'document_tag_map',
                ['document_id', 'tag_id'],
                $documentPath,
            ),
            self::indirect(
                'document_links',
                ['document_id', 'entity_type', 'entity_id'],
                $documentPath,
                additionalDetails: [
                    'soft_references' => [
                        'entity' => [
                            'strategy' => 'polymorphic_tenant_entity',
                            'type_column' => 'entity_type',
                            'id_column' => 'entity_id',
                            'unknown_value' => 'block',
                            'targets' => [
                                'client' => 'clients',
                                'invoice' => 'invoices',
                                'purchase_invoice' => 'purchase_invoices',
                                'project' => 'projects',
                                'journal_entry' => 'journal_entries',
                                'bank_transaction' => 'bank_transactions',
                            ],
                        ],
                    ],
                ],
            ),
            self::runtime(
                'document_embeddings',
                ['id'],
                'runtime_document_search_embeddings',
            ),
            self::files(),
        ];
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,string> $actorReferences
     * @param array<string,string> $softActorReferences
     * @param array<string,mixed> $postImport
     * @param array<string,mixed> $additionalDetails
     */
    private static function owned(
        string $table,
        array $primaryKey,
        array $actorReferences = [],
        array $softActorReferences = [],
        array $postImport = [],
        array $additionalDetails = [],
    ): TenantDataDefinition {
        return self::transferable(
            $table,
            $primaryKey,
            TenantDataPolicy::TenantOwned,
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $actorReferences,
            $softActorReferences,
            $postImport,
            $additionalDetails,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param list<array{from_column:string,to_table:string,to_column:string,reference?:string}> $path
     * @param array<string,string> $actorReferences
     * @param array<string,string> $softActorReferences
     * @param array<string,mixed> $postImport
     * @param array<string,mixed> $additionalDetails
     */
    private static function indirect(
        string $table,
        array $primaryKey,
        array $path,
        array $actorReferences = [],
        array $softActorReferences = [],
        array $postImport = [],
        array $additionalDetails = [],
    ): TenantDataDefinition {
        return self::transferable(
            $table,
            $primaryKey,
            TenantDataPolicy::TenantOwnedIndirect,
            [
                'strategy' => 'foreign_key_path',
                'path' => $path,
            ],
            $actorReferences,
            $softActorReferences,
            $postImport,
            $additionalDetails,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,mixed> $ownership
     * @param array<string,string> $actorReferences
     * @param array<string,string> $softActorReferences
     * @param array<string,mixed> $postImport
     * @param array<string,mixed> $additionalDetails
     */
    private static function transferable(
        string $table,
        array $primaryKey,
        TenantDataPolicy $policy,
        array $ownership,
        array $actorReferences,
        array $softActorReferences,
        array $postImport,
        array $additionalDetails,
    ): TenantDataDefinition {
        $details = [
            'primary_key' => $primaryKey,
            'feature_group' => 'documents',
            'ownership' => $ownership,
            'secrets' => [],
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
        if ($postImport !== []) {
            $details['post_import'] = $postImport;
        }
        foreach ($additionalDetails as $key => $value) {
            if (array_key_exists($key, $details)) {
                throw new \LogicException(
                    'Doplňkový detail dokumentového katalogu přepisuje SSOT.',
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

    /** @param list<string> $primaryKey */
    private static function runtime(
        string $table,
        array $primaryKey,
        string $reason,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => $primaryKey,
                'feature_group' => 'documents',
                'reason' => $reason,
                'secrets' => [],
            ],
        );
    }

    private static function files(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'file_area:documents',
            TenantDataObjectKind::FileArea,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'feature_group' => 'documents',
                'source' => [
                    'base' => 'runtime_paths.storage',
                    'relative_root' => 'documents',
                    'path_strategy' => 'template_from_columns',
                    'path_template' =>
                        'sup-{supplier_id}/{sha256_prefix_2}/{filename}',
                    'row_references' => [
                        [
                            'table' => 'documents',
                            'columns' => [
                                'supplier_id',
                                'sha256',
                                'filename',
                            ],
                            'include_when' => 'all_rows',
                        ],
                        [
                            'table' => 'document_files',
                            'columns' => [
                                'supplier_id',
                                'sha256',
                                'filename',
                            ],
                            'include_when' => 'deleted_at_is_null',
                        ],
                    ],
                    'require_relative_path' => true,
                    'containment' => 'case_insensitive',
                    'outside_symlink' => 'reject',
                ],
                'target' => [
                    'strategy' =>
                        'content_addressed_from_verified_sha256',
                    'template' => 'documents/sup-{supplier_id}'
                        . '/{sha256_prefix_2}/{sha256}',
                    'posix_mode' => '0600',
                    'windows_acl' => 'owner_only',
                ],
                'validation' => [
                    'content_hash' => 'sha256',
                    'hash_column' => 'sha256',
                    'size_column' => 'size_bytes',
                    'filename_policy' => 'normalize_to_verified_sha256',
                    'deduplicate' => true,
                    'payload' => 'end_to_end_file_section',
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

    /**
     * @return array{
     *   from_column:string,
     *   to_table:string,
     *   to_column:string,
     *   reference:string
     * }
     */
    private static function softPath(string $column, string $table): array
    {
        return [
            ...self::path($column, $table),
            'reference' => 'soft',
        ];
    }
}
