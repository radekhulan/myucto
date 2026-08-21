<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Tenantový AI kill-switch a bezpečně regenerovatelný modelový runtime. */
final class TenantDataAiCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::runtime(
                'ai_daily_usage',
                ['supplier_id', 'usage_date'],
                'runtime_ai_daily_rate_limit',
            ),
            self::runtime(
                'ai_embeddings',
                ['id'],
                'runtime_ai_embedding_index',
            ),
            self::runtime(
                'ai_jobs',
                ['id'],
                'runtime_ai_job_queue',
            ),
            self::runtime(
                'ai_metrics',
                ['id'],
                'runtime_ai_aggregate_metrics',
            ),
            self::sourceMutes(),
            self::runtime(
                'ai_suggestions',
                ['id'],
                'runtime_ai_model_output_and_review_history',
            ),
        ];
    }

    private static function sourceMutes(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:ai_source_mutes',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'ai',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'actor_references' => [
                    'unmuted_by' => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                ],
                'transfer_invariant' =>
                    'preserve_ai_source_kill_switch_without_enabling_ai',
            ],
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
                'feature_group' => 'ai',
                'reason' => $reason,
                'secrets' => [],
            ],
        );
    }
}
