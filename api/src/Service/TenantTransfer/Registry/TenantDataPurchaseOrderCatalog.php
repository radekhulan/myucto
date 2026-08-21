<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Objednávkové vazby, které se obnovují až z remapovaných dokladů. */
final class TenantDataPurchaseOrderCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            new TenantDataDefinition(
                'table:purchase_order_invoice_links',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRelation,
                [TenantDataRegistry::TRANSFER_PROFILE],
                [
                    'primary_key' => ['id'],
                    'feature_group' => 'stock',
                    'ownership' => [
                        'strategy' => 'supplier_relation',
                        'column' => 'supplier_id',
                    ],
                    'secrets' => [],
                    'soft_actor_references' => [
                        'linked_by' => [
                            'strategy' => 'map_existing_user_or_null',
                        ],
                    ],
                    'relation_import' => [
                        'strategy' => 'recreate_from_mapped_references',
                        'raw_insert' => false,
                        'unresolved_row' => 'skip',
                    ],
                    'transfer_invariant' =>
                        'recreate_after_order_and_purchase_invoice_are_mapped',
                ],
            ),
        ];
    }
}
