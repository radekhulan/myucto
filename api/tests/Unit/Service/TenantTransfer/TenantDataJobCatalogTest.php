<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataJobCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataJobCatalogTest extends TestCase
{
    public function testTechnicalJobTablesAreRegenerated(): void
    {
        $expected = [
            'accounting_backfill_jobs' => [
                'accounting',
                'runtime_accounting_activation_job',
            ],
            'import_jobs' => [
                'operations',
                'runtime_unified_import_export_job_queue',
            ],
        ];
        $definitions = self::definitions();

        self::assertSame(array_keys($expected), array_keys($definitions));
        foreach ($expected as $table => [$featureGroup, $reason]) {
            $definition = $definitions[$table];
            self::assertSame(
                TenantDataPolicy::RuntimeDerived,
                $definition->policy,
            );
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            self::assertSame(['id'], $definition->details['primary_key'] ?? null);
            self::assertSame(
                $featureGroup,
                $definition->details['feature_group'] ?? null,
            );
            self::assertSame($reason, $definition->details['reason'] ?? null);
            self::assertSame([], $definition->details['secrets'] ?? null);
            self::assertArrayNotHasKey('ownership', $definition->details);
            self::assertArrayNotHasKey(
                'actor_references',
                $definition->details,
            );
        }
    }

    public function testRunningAccountingActivationNeverSurvivesImport(): void
    {
        $supplier = self::factoryDefinition('table:supplier');
        $postImport = $supplier->details['post_import'] ?? null;
        self::assertIsArray($postImport);

        self::assertSame(
            [
                'accounting_activation_status' => [
                    'running' => 'failed',
                ],
            ],
            $postImport['state_transforms'] ?? null,
        );
    }

    public function testFactoryPublishesJobsOnlyForTransfer(): void
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
        foreach (TenantDataJobCatalog::definitions() as $definition) {
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
