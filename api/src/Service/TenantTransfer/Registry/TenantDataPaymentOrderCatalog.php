<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Historické snapshoty platebních příkazů a jejich bankovních položek. */
final class TenantDataPaymentOrderCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::orders(),
            self::items(),
        ];
    }

    private static function orders(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:payment_orders',
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
                'soft_actor_references' => [
                    'created_by_user_id' => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                ],
                'soft_references' => [
                    'payer_currency' => self::directReference(
                        'payer_currency_id',
                        'currencies',
                        'preserve',
                    ),
                ],
                'transfer_invariant' =>
                    'preserve_deterministic_payment_export_snapshot',
            ],
        );
    }

    private static function items(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:payment_order_items',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'ownership' => [
                    'strategy' => 'soft_reference_path',
                    'path' => [
                        [
                            'from_column' => 'payment_order_id',
                            'to_table' => 'payment_orders',
                            'to_column' => 'id',
                            'reference' => 'soft',
                        ],
                        [
                            'from_column' => 'supplier_id',
                            'to_table' => 'supplier',
                            'to_column' => 'id',
                            'reference' => 'soft',
                        ],
                    ],
                ],
                'secrets' => [],
                'soft_references' => [
                    'payment_order' => self::directReference(
                        'payment_order_id',
                        'payment_orders',
                        'forbid',
                    ),
                    'purchase_invoice' => self::directReference(
                        'purchase_invoice_id',
                        'purchase_invoices',
                        'forbid',
                    ),
                ],
                'transfer_invariant' =>
                    'preserve_deterministic_payment_export_snapshot',
            ],
        );
    }

    /**
     * @return array{
     *   strategy:string,
     *   id_column:string,
     *   target_table:string,
     *   target_column:string,
     *   null_value:string,
     *   unresolved:string
     * }
     */
    private static function directReference(
        string $idColumn,
        string $targetTable,
        string $nullValue,
    ): array {
        return [
            'strategy' => 'direct_tenant_entity',
            'id_column' => $idColumn,
            'target_table' => $targetTable,
            'target_column' => 'id',
            'null_value' => $nullValue,
            'unresolved' => 'block',
        ];
    }
}
