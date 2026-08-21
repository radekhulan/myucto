<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Trvalá historie bankovního párování a regenerovatelné návrhy shod. */
final class TenantDataBankMatchingCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::owned(
                'bank_counterparty_map',
                'preserve_counterparty_learning_history',
            ),
            self::observations(),
            self::audit(),
            self::runtime(
                'bank_match_suggestions',
                'runtime_bank_match_candidate_queue',
            ),
            self::owned(
                'bank_transfer_matches',
                'preserve_own_transfer_pairing',
            ),
        ];
    }

    private static function owned(
        string $table,
        string $transferInvariant,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'transfer_invariant' => $transferInvariant,
            ],
        );
    }

    private static function observations(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:bank_counterparty_observations',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'ownership' => [
                    'strategy' => 'foreign_key_path',
                    'path' => [
                        [
                            'from_column' => 'map_id',
                            'to_table' => 'bank_counterparty_map',
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
                'transfer_invariant' =>
                    'preserve_counterparty_learning_observations',
            ],
        );
    }

    private static function audit(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:bank_match_audit',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'actor_references' => [
                    'created_by' => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                ],
                'soft_references' => [
                    'purchase_invoice' => [
                        'strategy' => 'direct_tenant_entity',
                        'id_column' => 'purchase_invoice_id',
                        'target_table' => 'purchase_invoices',
                        'target_column' => 'id',
                        'null_value' => 'preserve',
                        'unresolved' => 'block',
                    ],
                    'match_suggestion' => [
                        'strategy' => 'runtime_derived_entity',
                        'id_column' => 'suggestion_id',
                        'target_table' => 'bank_match_suggestions',
                        'target_column' => 'id',
                        'null_value' => 'preserve',
                        'target_omitted' => 'set_null',
                    ],
                ],
                'structured_references' => [
                    'invoice_ids' => [
                        'strategy' => 'json_id_list',
                        'column' => 'invoice_ids',
                        'target_table' => 'invoices',
                        'target_column' => 'id',
                        'null_value' => 'preserve',
                        'invalid_value' => 'block',
                        'unresolved' => 'block',
                    ],
                ],
                'transfer_invariant' =>
                    'preserve_bank_match_decision_audit',
            ],
        );
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
                'feature_group' => 'bank',
                'reason' => $reason,
                'secrets' => [],
            ],
        );
    }
}
