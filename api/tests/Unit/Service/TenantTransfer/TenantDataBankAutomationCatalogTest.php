<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataBankAutomationCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataBankAutomationCatalogTest extends TestCase
{
    public function testPostingRulesPreserveConfigurationAndLearning(): void
    {
        $rule = self::definitions()['bank_posting_rules'];

        self::assertSame(TenantDataPolicy::TenantOwned, $rule->policy);
        self::assertSame(['id'], $rule->details['primary_key'] ?? null);
        self::assertSame('bank', $rule->details['feature_group'] ?? null);
        self::assertSame(
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $rule->details['ownership'] ?? null,
        );
        self::assertSame([], $rule->details['secrets'] ?? null);
        self::assertSame(
            [
                'created_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $rule->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
                'last_rejected_transaction' => [
                    'strategy' => 'direct_tenant_entity',
                    'id_column' => 'last_rejected_tx_id',
                    'target_table' => 'bank_transactions',
                    'target_column' => 'id',
                    'null_value' => 'preserve',
                    'unresolved' => 'block',
                ],
            ],
            $rule->details['soft_references'] ?? null,
        );
        self::assertSame(
            [
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
            $rule->details['code_references'] ?? null,
        );
        self::assertSame(
            [
                'debit_account' => self::accountReference(
                    'debit_account_code',
                ),
                'credit_account' => self::accountReference(
                    'credit_account_code',
                ),
            ],
            $rule->details['natural_key_references'] ?? null,
        );
        self::assertSame(
            'preserve_bank_posting_rules_and_learning_state',
            $rule->details['transfer_invariant'] ?? null,
        );
    }

    public function testAutomationPolicyPreservesExplicitTenantChoice(): void
    {
        $policy = self::definitions()['auto_posting_policy'];

        self::assertSame(TenantDataPolicy::TenantOwned, $policy->policy);
        self::assertSame(['id'], $policy->details['primary_key'] ?? null);
        self::assertSame('bank', $policy->details['feature_group'] ?? null);
        self::assertSame(
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $policy->details['ownership'] ?? null,
        );
        self::assertSame([], $policy->details['secrets'] ?? null);
        self::assertSame(
            [
                'updated_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $policy->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
                'operation_type' => self::codeReference(
                    'accounting_operation_types',
                    'forbid',
                ),
            ],
            $policy->details['code_references'] ?? null,
        );
        self::assertSame(
            'preserve_explicit_bank_automation_policy',
            $policy->details['transfer_invariant'] ?? null,
        );
    }

    public function testPostingSuggestionsPreserveQueueAndProvenance(): void
    {
        $suggestion = self::definitions()['bank_posting_suggestions'];

        self::assertSame(TenantDataPolicy::TenantOwned, $suggestion->policy);
        self::assertSame(['id'], $suggestion->details['primary_key'] ?? null);
        self::assertSame('bank', $suggestion->details['feature_group'] ?? null);
        self::assertSame(
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $suggestion->details['ownership'] ?? null,
        );
        self::assertSame([], $suggestion->details['secrets'] ?? null);
        self::assertSame(
            [
                'reviewed_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'snoozed_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $suggestion->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
                'operation_type' => self::codeReference(
                    'accounting_operation_types',
                    'preserve',
                ),
            ],
            $suggestion->details['code_references'] ?? null,
        );
        self::assertSame(
            [
                'debit_account' => self::accountReference(
                    'debit_account_code',
                ),
                'credit_account' => self::accountReference(
                    'credit_account_code',
                ),
            ],
            $suggestion->details['natural_key_references'] ?? null,
        );
        self::assertSame(
            [
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
            $suggestion->details['structured_references'] ?? null,
        );
        self::assertSame(
            'preserve_bank_posting_queue_and_provenance',
            $suggestion->details['transfer_invariant'] ?? null,
        );
    }

    public function testChartOfAccountsHasStableImportIdentity(): void
    {
        $chart = self::factoryDefinition('table:chart_of_accounts');

        self::assertSame(
            [
                'strategy' => 'tenant_natural_key',
                'keys' => ['supplier_id', 'account_code'],
                'missing_row' => 'create_with_mapped_tenant',
                'existing_row' => 'reuse_target_id_and_apply_source',
            ],
            $chart->details['import_identity'] ?? null,
        );
    }

    public function testFactoryPublishesAutomationOnlyForTransfer(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $transfer = self::keys(
            $registry,
            TenantDataRegistry::TRANSFER_PROFILE,
        );
        $archive = self::keys(
            $registry,
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
        );

        foreach (array_keys(self::definitions()) as $table) {
            self::assertContains('table:' . $table, $transfer);
            self::assertNotContains('table:' . $table, $archive);
        }
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataBankAutomationCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        self::assertSame(
            [
                'auto_posting_policy',
                'bank_posting_rules',
                'bank_posting_suggestions',
            ],
            array_keys($definitions),
        );
        return $definitions;
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

    private static function factoryDefinition(
        string $key,
    ): TenantDataDefinition {
        foreach (TenantDataRegistryFactory::draftV1()->definitionsFor(
            TenantDataRegistry::TRANSFER_PROFILE,
        ) as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }
        self::fail('Factory neobsahuje očekávanou definici ' . $key . '.');
    }

    /** @return list<string> */
    private static function keys(
        TenantDataRegistry $registry,
        string $profile,
    ): array {
        return array_map(
            static fn (TenantDataDefinition $definition): string =>
                $definition->key,
            $registry->definitionsFor($profile),
        );
    }
}
