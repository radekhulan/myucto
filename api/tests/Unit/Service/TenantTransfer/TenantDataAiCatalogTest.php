<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataAiCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataAiCatalogTest extends TestCase
{
    public function testSourceMutesPreserveTenantSafetyState(): void
    {
        $mute = self::definitions()['ai_source_mutes'];

        self::assertSame(TenantDataPolicy::TenantOwned, $mute->policy);
        self::assertSame(
            [TenantDataRegistry::TRANSFER_PROFILE],
            $mute->profiles,
        );
        self::assertSame(['id'], $mute->details['primary_key'] ?? null);
        self::assertSame('ai', $mute->details['feature_group'] ?? null);
        self::assertSame(
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $mute->details['ownership'] ?? null,
        );
        self::assertSame([], $mute->details['secrets'] ?? null);
        self::assertSame(
            [
                'unmuted_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $mute->details['actor_references'] ?? null,
        );
        self::assertSame(
            'preserve_ai_source_kill_switch_without_enabling_ai',
            $mute->details['transfer_invariant'] ?? null,
        );
    }

    public function testGeneratedAiStateIsRegeneratedInsteadOfTransferred(): void
    {
        $definitions = self::definitions();
        $expected = [
            'ai_daily_usage' => [
                ['supplier_id', 'usage_date'],
                'runtime_ai_daily_rate_limit',
            ],
            'ai_embeddings' => [
                ['id'],
                'runtime_ai_embedding_index',
            ],
            'ai_jobs' => [
                ['id'],
                'runtime_ai_job_queue',
            ],
            'ai_metrics' => [
                ['id'],
                'runtime_ai_aggregate_metrics',
            ],
            'ai_suggestions' => [
                ['id'],
                'runtime_ai_model_output_and_review_history',
            ],
        ];

        self::assertSame(
            [
                'ai_daily_usage',
                'ai_embeddings',
                'ai_jobs',
                'ai_metrics',
                'ai_source_mutes',
                'ai_suggestions',
            ],
            array_keys($definitions),
        );
        foreach ($expected as $table => [$primaryKey, $reason]) {
            $definition = $definitions[$table];
            self::assertSame(
                TenantDataPolicy::RuntimeDerived,
                $definition->policy,
            );
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            self::assertSame(
                $primaryKey,
                $definition->details['primary_key'] ?? null,
            );
            self::assertSame('ai', $definition->details['feature_group'] ?? null);
            self::assertSame($reason, $definition->details['reason'] ?? null);
            self::assertSame([], $definition->details['secrets'] ?? null);
            self::assertArrayNotHasKey('ownership', $definition->details);
        }
    }

    public function testSupplierAiRequiresFreshActivationAndConsent(): void
    {
        $supplier = self::factoryDefinition('table:supplier');
        $postImport = $supplier->details['post_import'] ?? null;
        self::assertIsArray($postImport);

        self::assertTrue($postImport['disable_integrations'] ?? null);
        self::assertSame(
            [
                'ai_assist_enabled' => false,
                'ai_pseudo_salt' => null,
                'ai_dpa_confirmations' => null,
            ],
            $postImport['force_columns'] ?? null,
        );
        $secrets = $supplier->details['secrets'] ?? null;
        self::assertIsArray($secrets);
        self::assertSame(
            ['policy' => 'omit_and_reconfigure'],
            $secrets['ai_pseudo_salt'] ?? null,
        );
    }

    public function testFactoryPublishesAiOnlyForTransfer(): void
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
        foreach (TenantDataAiCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
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
