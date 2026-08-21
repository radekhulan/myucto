<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Legislativní definice výkazů a firemní obsah účetní závěrky. */
final class TenantDataStatementCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::globalReference(
                'statement_versions',
                ['id'],
                ['statement_type', 'version_code'],
                ['valid_from', 'valid_to'],
            ),
            self::globalReference(
                'statement_rows',
                ['id'],
                ['version_id', 'row_code'],
                [
                    'parent_row_code',
                    'section',
                    'label',
                    'level',
                    'position',
                    'row_type',
                    'calc_key',
                ],
            ),
            self::globalReference(
                'statement_account_map',
                ['id'],
                [
                    'version_id',
                    'row_code',
                    'account_prefix',
                    'target',
                    'balance_condition',
                ],
                ['sign'],
            ),
            self::tenantOwned(
                'statement_function_map',
                'created_by',
                'function_code',
                'statement_function_codes',
                'preserve_statement_function_mapping',
            ),
            self::tenantOwned(
                'statement_notes',
                'updated_by',
                'section_key',
                'statement_note_sections',
                'preserve_financial_statement_notes',
            ),
        ];
    }

    /**
     * @param list<string> $primaryKey
     * @param list<string> $naturalKey
     * @param list<string> $valueColumns
     */
    private static function globalReference(
        string $table,
        array $primaryKey,
        array $naturalKey,
        array $valueColumns,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::GlobalReference,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => $primaryKey,
                'feature_group' => 'accounting',
                'mapping' => [
                    'strategy' => 'natural_key',
                    'keys' => $naturalKey,
                    'values' => [
                        'strategy' => 'require_equal',
                        'columns' => $valueColumns,
                    ],
                ],
                'secrets' => [],
            ],
        );
    }

    private static function tenantOwned(
        string $table,
        string $actorColumn,
        string $codeColumn,
        string $codeRegistry,
        string $transferInvariant,
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
                'soft_actor_references' => [
                    $actorColumn => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                ],
                'code_references' => [
                    $codeColumn => [
                        'strategy' => 'application_registry_code',
                        'registry' => $codeRegistry,
                        'unknown_value' => 'block',
                        'null_value' => 'forbid',
                    ],
                ],
                'transfer_invariant' => $transferInvariant,
            ],
        );
    }
}
