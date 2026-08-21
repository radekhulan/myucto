<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** E-shopové číselníky a data s kódovými vazbami v rámci firmy. */
final class TenantDataEshopCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::registry('stock_locales'),
            self::registry('stock_currencies'),
            self::owned(
                'stock_categories',
                [
                    'materialized_path_reference' => [
                        'strategy' => 'remap_materialized_path_ids',
                        'column' => 'path',
                        'id_table' => 'stock_categories',
                        'unresolved' => 'block',
                    ],
                ],
            ),
            self::localeConsumer('stock_item_i18n'),
            self::localeConsumer('stock_category_i18n'),
            self::localeConsumer('stock_attribute_i18n'),
            self::currencyConsumer('stock_item_fees'),
            self::currencyConsumer('stock_item_prices'),
            self::currencyConsumer(
                'stock_item_promo_prices',
                [
                    'transfer_invariant' =>
                        'preserve_promotional_pricing_rules',
                ],
            ),
        ];
    }

    private static function registry(string $table): TenantDataDefinition
    {
        return self::owned(
            $table,
            [
                'import_identity' => [
                    'strategy' => 'tenant_natural_key',
                    'keys' => ['supplier_id', 'code'],
                    'missing_row' => 'create_with_mapped_tenant',
                    'existing_row' =>
                        'reuse_target_id_and_apply_source',
                ],
            ],
        );
    }

    private static function localeConsumer(
        string $table,
    ): TenantDataDefinition {
        return self::owned(
            $table,
            [
                'natural_key_references' => [
                    'locale' => self::naturalReference(
                        'locale',
                        'stock_locales',
                    ),
                ],
            ],
        );
    }

    /** @param array<string,mixed> $additionalDetails */
    private static function currencyConsumer(
        string $table,
        array $additionalDetails = [],
    ): TenantDataDefinition {
        return self::owned(
            $table,
            [
                'natural_key_references' => [
                    'currency' => self::naturalReference(
                        'currency_code',
                        'stock_currencies',
                    ),
                ],
                ...$additionalDetails,
            ],
        );
    }

    /** @param array<string,mixed> $additionalDetails */
    private static function owned(
        string $table,
        array $additionalDetails = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'stock',
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
    ): array {
        return [
            'strategy' => 'tenant_natural_key',
            'source_scope_column' => 'supplier_id',
            'source_columns' => [$sourceColumn],
            'target_table' => $targetTable,
            'target_scope_column' => 'supplier_id',
            'target_columns' => ['code'],
            'null_value' => 'forbid',
            'unresolved' => 'block',
        ];
    }
}
