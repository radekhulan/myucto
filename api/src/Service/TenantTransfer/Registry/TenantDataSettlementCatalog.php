<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Splátkové kalendáře, jednotlivá vypořádání a vzájemné zápočty. */
final class TenantDataSettlementCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        $nullableCreator = [
            'created_by' => 'map_existing_user_or_null',
        ];
        $settlementReferences = self::settlementReferences();

        return [
            self::owned('invoice_payment_schedule', 'core'),
            self::owned(
                'invoice_settlements',
                'accounting',
                softActorReferences: $nullableCreator,
                additionalDetails: [
                    'soft_references' => $settlementReferences,
                ],
            ),
            self::owned(
                'offset_agreements',
                'accounting',
                softActorReferences: $nullableCreator,
            ),
            self::owned(
                'offset_agreement_items',
                'accounting',
                additionalDetails: [
                    'soft_references' => $settlementReferences,
                ],
            ),
        ];
    }

    /**
     * @param array<string,string> $softActorReferences
     * @param array<string,mixed> $additionalDetails
     */
    private static function owned(
        string $table,
        string $featureGroup,
        array $softActorReferences = [],
        array $additionalDetails = [],
    ): TenantDataDefinition {
        $details = [
            'primary_key' => ['id'],
            'feature_group' => $featureGroup,
            'ownership' => [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            'secrets' => [],
            ...$additionalDetails,
        ];
        if ($softActorReferences !== []) {
            $details['soft_actor_references'] = self::references(
                $softActorReferences,
            );
        }

        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
    }

    /** @return array<string,array<string,mixed>> */
    private static function settlementReferences(): array
    {
        return [
            'document' => [
                'strategy' => 'polymorphic_tenant_entity',
                'type_column' => 'doc_type',
                'id_column' => 'doc_id',
                'unknown_value' => 'block',
                'targets' => [
                    'invoice' => 'invoices',
                    'purchase_invoice' => 'purchase_invoices',
                ],
            ],
            'invoice_payment' => [
                'strategy' => 'direct_tenant_entity',
                'id_column' => 'invoice_payment_id',
                'target_table' => 'invoice_payments',
                'target_column' => 'id',
                'null_value' => 'preserve',
                'unresolved' => 'block',
            ],
        ];
    }

    /**
     * @param array<string,string> $references
     * @return array<string,array{strategy:string}>
     */
    private static function references(array $references): array
    {
        $result = [];
        foreach ($references as $column => $strategy) {
            $result[$column] = ['strategy' => $strategy];
        }
        return $result;
    }
}
