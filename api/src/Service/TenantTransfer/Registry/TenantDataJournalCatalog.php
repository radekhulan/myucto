<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Trvalé pomůcky účetního deníku a jeho regenerovatelná diagnostika. */
final class TenantDataJournalCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::templates(),
            self::templateLines(),
            self::notes(),
            self::runtime(
                'journal_integrity_findings',
                'runtime_journal_integrity_snapshot',
            ),
        ];
    }

    private static function templates(): TenantDataDefinition
    {
        return self::owned(
            'journal_entry_templates',
            [
                'soft_actor_references' => [
                    'created_by' => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                ],
                'code_references' => [
                    'seed_key' => [
                        'strategy' => 'application_registry_code',
                        'registry' => 'journal_template_seed_keys',
                        'unknown_value' => 'block',
                        'null_value' => 'preserve',
                    ],
                ],
                'transfer_invariant' =>
                    'preserve_seeded_identity_and_user_template_edits',
            ],
        );
    }

    private static function templateLines(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:journal_entry_template_lines',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'accounting',
                'ownership' => [
                    'strategy' => 'foreign_key_path',
                    'path' => [
                        [
                            'from_column' => 'template_id',
                            'to_table' => 'journal_entry_templates',
                            'to_column' => 'id',
                        ],
                        [
                            'from_column' => 'supplier_id',
                            'to_table' => 'supplier',
                            'to_column' => 'id',
                        ],
                    ],
                ],
                'secrets' => [],
                'natural_key_references' => [
                    'account' => self::naturalReference(
                        'account_code',
                        'chart_of_accounts',
                        'account_code',
                        'forbid',
                    ),
                    'cost_center' => self::naturalReference(
                        'cost_center',
                        'cost_centers',
                        'code',
                        'preserve',
                    ),
                ],
                'transfer_invariant' =>
                    'preserve_template_line_order_and_defaults',
            ],
        );
    }

    private static function notes(): TenantDataDefinition
    {
        return self::owned(
            'journal_entry_notes',
            [
                'soft_actor_references' => [
                    'created_by' => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                    'updated_by' => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                ],
                'transfer_invariant' =>
                    'preserve_journal_note_history_and_soft_deletions',
            ],
        );
    }

    /** @param array<string,mixed> $additionalDetails */
    private static function owned(
        string $table,
        array $additionalDetails,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'accounting',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...$additionalDetails,
            ],
        );
    }

    /**
     * @return array{
     *   strategy:string,
     *   source_scope:array{strategy:string},
     *   source_columns:list<string>,
     *   target_table:string,
     *   target_scope_column:string,
     *   target_columns:list<string>,
     *   null_value:string,
     *   unresolved:string
     * }
     */
    private static function naturalReference(
        string $sourceColumn,
        string $targetTable,
        string $targetColumn,
        string $nullValue,
    ): array {
        return [
            'strategy' => 'tenant_natural_key',
            'source_scope' => [
                'strategy' => 'ownership_tenant',
            ],
            'source_columns' => [$sourceColumn],
            'target_table' => $targetTable,
            'target_scope_column' => 'supplier_id',
            'target_columns' => [$targetColumn],
            'null_value' => $nullValue,
            'unresolved' => 'block',
        ];
    }

    private static function runtime(
        string $table,
        string $reason,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'accounting',
                'reason' => $reason,
                'secrets' => [],
            ],
        );
    }
}
