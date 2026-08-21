<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataBankMatchingCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataBankMatchingCatalogTest extends TestCase
{
    public function testDurableMatchingHistoryIsTenantOwned(): void
    {
        $definitions = self::definitions();

        foreach ([
            'bank_counterparty_map' =>
                'preserve_counterparty_learning_history',
            'bank_transfer_matches' =>
                'preserve_own_transfer_pairing',
        ] as $table => $invariant) {
            $definition = $definitions[$table];
            self::assertSame(
                TenantDataPolicy::TenantOwned,
                $definition->policy,
            );
            self::assertSame(['id'], $definition->details['primary_key'] ?? null);
            self::assertSame('bank', $definition->details['feature_group'] ?? null);
            self::assertSame(
                [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                $definition->details['ownership'] ?? null,
            );
            self::assertSame([], $definition->details['secrets'] ?? null);
            self::assertSame(
                $invariant,
                $definition->details['transfer_invariant'] ?? null,
            );
        }
    }

    public function testCounterpartyObservationsFollowTheirTenantMap(): void
    {
        $observation = self::definitions()[
            'bank_counterparty_observations'
        ];

        self::assertSame(
            TenantDataPolicy::TenantOwnedIndirect,
            $observation->policy,
        );
        self::assertSame(['id'], $observation->details['primary_key'] ?? null);
        self::assertSame('bank', $observation->details['feature_group'] ?? null);
        self::assertSame(
            [
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
            $observation->details['ownership'] ?? null,
        );
        self::assertSame([], $observation->details['secrets'] ?? null);
        self::assertSame(
            'preserve_counterparty_learning_observations',
            $observation->details['transfer_invariant'] ?? null,
        );
    }

    public function testDecisionAuditPreservesJsonTargetsAndDropsQueueLink(): void
    {
        $audit = self::definitions()['bank_match_audit'];

        self::assertSame(TenantDataPolicy::TenantOwned, $audit->policy);
        self::assertSame(['id'], $audit->details['primary_key'] ?? null);
        self::assertSame('bank', $audit->details['feature_group'] ?? null);
        self::assertSame(
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $audit->details['ownership'] ?? null,
        );
        self::assertSame([], $audit->details['secrets'] ?? null);
        self::assertSame(
            [
                'created_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $audit->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
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
            $audit->details['soft_references'] ?? null,
        );
        self::assertSame(
            [
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
            $audit->details['structured_references'] ?? null,
        );
        self::assertSame(
            'preserve_bank_match_decision_audit',
            $audit->details['transfer_invariant'] ?? null,
        );
    }

    public function testScoredCandidateQueueIsRegenerated(): void
    {
        $suggestion = self::definitions()['bank_match_suggestions'];

        self::assertSame(
            TenantDataPolicy::RuntimeDerived,
            $suggestion->policy,
        );
        self::assertSame(['id'], $suggestion->details['primary_key'] ?? null);
        self::assertSame('bank', $suggestion->details['feature_group'] ?? null);
        self::assertSame(
            'runtime_bank_match_candidate_queue',
            $suggestion->details['reason'] ?? null,
        );
        self::assertSame([], $suggestion->details['secrets'] ?? null);
        self::assertArrayNotHasKey('ownership', $suggestion->details);
    }

    public function testFactoryPublishesMatchingOnlyForTransfer(): void
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

        foreach ([
            'bank_counterparty_map',
            'bank_counterparty_observations',
            'bank_match_audit',
            'bank_match_suggestions',
            'bank_transfer_matches',
        ] as $table) {
            self::assertContains('table:' . $table, $transfer);
            self::assertNotContains('table:' . $table, $archive);
        }
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataBankMatchingCatalog::definitions() as $definition) {
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
                'bank_counterparty_map',
                'bank_counterparty_observations',
                'bank_match_audit',
                'bank_match_suggestions',
                'bank_transfer_matches',
            ],
            array_keys($definitions),
        );
        return $definitions;
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
