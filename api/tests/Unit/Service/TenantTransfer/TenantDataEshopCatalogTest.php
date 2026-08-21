<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataEshopCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataEshopCatalogTest extends TestCase
{
    public function testCatalogOwnsRegistriesAndTheirConsumers(): void
    {
        $definitions = self::definitions();

        self::assertSame(
            [
                'stock_attribute_i18n',
                'stock_categories',
                'stock_category_i18n',
                'stock_currencies',
                'stock_item_fees',
                'stock_item_i18n',
                'stock_item_prices',
                'stock_item_promo_prices',
                'stock_locales',
            ],
            array_keys($definitions),
        );
        foreach ($definitions as $definition) {
            self::assertSame(TenantDataPolicy::TenantOwned, $definition->policy);
            self::assertSame(['id'], $definition->details['primary_key'] ?? null);
            self::assertSame('stock', $definition->details['feature_group'] ?? null);
            self::assertSame(
                [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                $definition->details['ownership'] ?? null,
            );
            self::assertSame([], $definition->details['secrets'] ?? null);
        }
    }

    public function testTenantRegistriesReuseNaturalKeyOnTarget(): void
    {
        $expected = [
            'strategy' => 'tenant_natural_key',
            'keys' => ['supplier_id', 'code'],
            'missing_row' => 'create_with_mapped_tenant',
            'existing_row' => 'reuse_target_id_and_apply_source',
        ];
        $definitions = self::definitions();

        self::assertSame(
            $expected,
            $definitions['stock_locales']->details['import_identity'] ?? null,
        );
        self::assertSame(
            $expected,
            $definitions['stock_currencies']->details['import_identity'] ?? null,
        );
    }

    public function testLocaleConsumersReferenceTenantLocaleRegistry(): void
    {
        $definitions = self::definitions();
        $expected = [
            'locale' => self::naturalReference(
                'locale',
                'stock_locales',
                'code',
            ),
        ];

        foreach ([
            'stock_item_i18n',
            'stock_category_i18n',
            'stock_attribute_i18n',
        ] as $table) {
            self::assertSame(
                $expected,
                $definitions[$table]->details['natural_key_references']
                    ?? null,
            );
        }
    }

    public function testPriceConsumersReferenceTenantCurrencyRegistry(): void
    {
        $definitions = self::definitions();
        $expected = [
            'currency' => self::naturalReference(
                'currency_code',
                'stock_currencies',
                'code',
            ),
        ];

        foreach ([
            'stock_item_fees',
            'stock_item_prices',
            'stock_item_promo_prices',
        ] as $table) {
            self::assertSame(
                $expected,
                $definitions[$table]->details['natural_key_references']
                    ?? null,
            );
        }
        self::assertSame(
            'preserve_promotional_pricing_rules',
            $definitions['stock_item_promo_prices']
                ->details['transfer_invariant'] ?? null,
        );
    }

    public function testCategoryPathIsRemappedAfterCategoryIds(): void
    {
        self::assertSame(
            [
                'strategy' => 'remap_materialized_path_ids',
                'column' => 'path',
                'id_table' => 'stock_categories',
                'unresolved' => 'block',
            ],
            self::definitions()['stock_categories']
                ->details['materialized_path_reference'] ?? null,
        );
    }

    public function testFactoryKeepsArchiveBoundaryWhileAddingEshopTables(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $transfer = self::keys($registry, TenantDataRegistry::TRANSFER_PROFILE);
        $archive = self::keys(
            $registry,
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
        );

        foreach (array_keys(self::definitions()) as $table) {
            self::assertContains('table:' . $table, $transfer);
        }
        foreach ([
            'stock_attribute_i18n',
            'stock_categories',
            'stock_category_i18n',
            'stock_item_fees',
            'stock_item_i18n',
            'stock_item_prices',
        ] as $table) {
            self::assertContains('table:' . $table, $archive);
        }
        foreach ([
            'stock_currencies',
            'stock_item_promo_prices',
            'stock_locales',
        ] as $table) {
            self::assertNotContains('table:' . $table, $archive);
        }
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataEshopCatalog::definitions() as $definition) {
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
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
    private static function naturalReference(
        string $sourceColumn,
        string $targetTable,
        string $targetColumn,
    ): array {
        return [
            'strategy' => 'tenant_natural_key',
            'source_scope_column' => 'supplier_id',
            'source_columns' => [$sourceColumn],
            'target_table' => $targetTable,
            'target_scope_column' => 'supplier_id',
            'target_columns' => [$targetColumn],
            'null_value' => 'forbid',
            'unresolved' => 'block',
        ];
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
