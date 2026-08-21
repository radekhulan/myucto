<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataLogbookCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataLogbookCatalogTest extends TestCase
{
    public function testCompleteLogbookGraphIsTenantOwned(): void
    {
        $expected = [
            'cars' => [TenantDataPolicy::TenantOwned, ['id']],
            'fuelings' => [TenantDataPolicy::TenantOwned, ['id']],
            'logbook_fuel_scans' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'trip_categories' => [TenantDataPolicy::TenantOwned, ['id']],
            'trips' => [TenantDataPolicy::TenantOwned, ['id']],
        ];

        $actual = [];
        foreach (self::definitions() as $table => $definition) {
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            $actual[$table] = [
                $definition->policy,
                $definition->details['primary_key'] ?? null,
            ];
        }

        self::assertSame($expected, $actual);
    }

    public function testActorAndSoftItemReferencesAreExplicit(): void
    {
        $definitions = self::definitions();
        $nullableActor = [
            'created_by' => [
                'strategy' => 'map_existing_user_or_null',
            ],
        ];

        self::assertSame(
            $nullableActor,
            $definitions['cars']->details['actor_references'] ?? null,
        );
        self::assertSame(
            $nullableActor,
            $definitions['trips']->details['actor_references'] ?? null,
        );
        self::assertSame(
            $nullableActor,
            $definitions['fuelings']->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
                'source_item' => [
                    'strategy' => 'direct_tenant_entity',
                    'id_column' => 'source_item_id',
                    'target_table' => 'purchase_invoice_items',
                    'target_column' => 'id',
                    'null_value' => 'preserve',
                    'unresolved' => 'block',
                ],
            ],
            $definitions['fuelings']->details['soft_references'] ?? null,
        );
    }

    public function testScanDedupeHistoryAndParserCodeArePreserved(): void
    {
        $definitions = self::definitions();

        self::assertSame(
            [
                'strategy' => 'opaque_source_hash',
                'columns' => ['supplier_id', 'dedup_hash'],
                'null_value' => 'preserve',
                'reason' => 'historical_scan_identity',
            ],
            $definitions['fuelings']->details['deduplication'] ?? null,
        );
        self::assertSame(
            [
                'parser' => [
                    'strategy' => 'application_registry_code',
                    'registry' => 'logbook_fuel_parsers',
                    'unknown_value' => 'block',
                    'null_value' => 'forbid',
                ],
            ],
            $definitions['logbook_fuel_scans']
                ->details['code_references'] ?? null,
        );
        self::assertSame(
            'preserve_to_prevent_historical_rescan',
            $definitions['logbook_fuel_scans']
                ->details['transfer_invariant'] ?? null,
        );
    }

    public function testFactoryPublishesLogbookOnlyForTransfer(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $transfer = array_map(
            static fn (TenantDataDefinition $definition): string =>
                $definition->key,
            $registry->definitionsFor(TenantDataRegistry::TRANSFER_PROFILE),
        );
        $archive = array_map(
            static fn (TenantDataDefinition $definition): string =>
                $definition->key,
            $registry->definitionsFor(
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
            ),
        );

        foreach ([
            'table:cars',
            'table:trip_categories',
            'table:trips',
            'table:fuelings',
            'table:logbook_fuel_scans',
        ] as $key) {
            self::assertContains($key, $transfer);
            self::assertNotContains($key, $archive);
        }
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataLogbookCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }
}
