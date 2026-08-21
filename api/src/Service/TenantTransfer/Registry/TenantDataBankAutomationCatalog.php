<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Pravidla bankovního účtování, jejich learning stav a tenantová policy. */
final class TenantDataBankAutomationCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::automationPolicy(),
            self::postingRules(),
            self::postingSuggestions(),
        ];
    }

    private static function automationPolicy(): TenantDataDefinition
    {
        return self::owned(
            'auto_posting_policy',
            [
                'actor_references' => self::references([
                    'updated_by' => 'map_existing_user_or_null',
                ]),
                'code_references' => [
                    'operation_type' => self::codeReference(
                        'accounting_operation_types',
                        'forbid',
                    ),
                ],
                'transfer_invariant' =>
                    'preserve_explicit_bank_automation_policy',
            ],
        );
    }

    private static function postingRules(): TenantDataDefinition
    {
        return self::owned(
            'bank_posting_rules',
            [
                'actor_references' => self::references([
                    'created_by' => 'map_existing_user_or_null',
                ]),
                'soft_references' => [
                    'last_rejected_transaction' => [
                        'strategy' => 'direct_tenant_entity',
                        'id_column' => 'last_rejected_tx_id',
                        'target_table' => 'bank_transactions',
                        'target_column' => 'id',
                        'null_value' => 'preserve',
                        'unresolved' => 'block',
                    ],
                ],
                'code_references' => [
                    'operation_type' => self::codeReference(
                        'accounting_operation_types',
                        'preserve',
                    ),
                    'system_template_key' => self::codeReference(
                        'bank_rule_templates',
                        'preserve',
                    ),
                    'applies_currency' => self::codeReference(
                        'iso_4217_currencies',
                        'forbid',
                    ),
                ],
                'natural_key_references' => [
                    'debit_account' => self::accountReference(
                        'debit_account_code',
                    ),
                    'credit_account' => self::accountReference(
                        'credit_account_code',
                    ),
                ],
                'transfer_invariant' =>
                    'preserve_bank_posting_rules_and_learning_state',
            ],
        );
    }

    private static function postingSuggestions(): TenantDataDefinition
    {
        return self::owned(
            'bank_posting_suggestions',
            [
                'actor_references' => self::references([
                    'reviewed_by' => 'map_existing_user_or_null',
                    'snoozed_by' => 'map_existing_user_or_null',
                ]),
                'code_references' => [
                    'operation_type' => self::codeReference(
                        'accounting_operation_types',
                        'preserve',
                    ),
                ],
                'natural_key_references' => [
                    'debit_account' => self::accountReference(
                        'debit_account_code',
                    ),
                    'credit_account' => self::accountReference(
                        'credit_account_code',
                    ),
                ],
                'structured_references' => [
                    'note_reference' => [
                        'strategy' => 'tagged_decimal_id',
                        'column' => 'note',
                        'targets' => [
                            'corrected_from:#' => [
                                'target_table' => 'bank_transactions',
                                'target_column' => 'id',
                            ],
                            'looks_like:#' => [
                                'target_table' => 'bank_transactions',
                                'target_column' => 'id',
                            ],
                            'duplicate_suspect:#' => [
                                'target_table' => 'journal_entries',
                                'target_column' => 'id',
                            ],
                            'duplicate_suspect:' => [
                                'target_table' => 'journal_entries',
                                'target_column' => 'id',
                            ],
                        ],
                        'tag_matching' => 'longest_prefix',
                        'null_value' => 'preserve',
                        'unmatched_value' => 'preserve',
                        'unknown_tag' => 'block',
                        'invalid_value' => 'block',
                        'unresolved' => 'block',
                    ],
                ],
                'transfer_invariant' =>
                    'preserve_bank_posting_queue_and_provenance',
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
                'feature_group' => 'bank',
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
     *   registry:string,
     *   unknown_value:string,
     *   null_value:string
     * }
     */
    private static function codeReference(
        string $registry,
        string $nullValue,
    ): array {
        return [
            'strategy' => 'application_registry_code',
            'registry' => $registry,
            'unknown_value' => 'block',
            'null_value' => $nullValue,
        ];
    }

    /**
     * @return array{
     *   strategy:string,
     *   source_scope_column:string,
     *   source_columns:list<string>,
     *   target_table:string,
     *   target_scope_column:string,
     *   target_columns:list<string>,
     *   null_value:string,
     *   unresolved:string
     * }
     */
    private static function accountReference(string $sourceColumn): array
    {
        return [
            'strategy' => 'tenant_natural_key',
            'source_scope_column' => 'supplier_id',
            'source_columns' => [$sourceColumn],
            'target_table' => 'chart_of_accounts',
            'target_scope_column' => 'supplier_id',
            'target_columns' => ['account_code'],
            'null_value' => 'forbid',
            'unresolved' => 'block',
        ];
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
}
