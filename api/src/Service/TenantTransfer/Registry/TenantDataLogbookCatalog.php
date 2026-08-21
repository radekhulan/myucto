<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Vozidla, cesty, tankování a historie automatického skenování paliva. */
final class TenantDataLogbookCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        $nullableCreator = [
            'created_by' => 'map_existing_user_or_null',
        ];

        return [
            self::owned('cars', actorReferences: $nullableCreator),
            self::owned('trip_categories'),
            self::owned('trips', actorReferences: $nullableCreator),
            self::owned(
                'fuelings',
                actorReferences: $nullableCreator,
                additionalDetails: [
                    'soft_references' => [
                        'source_item' => [
                            'strategy' => 'direct_tenant_entity',
                            'id_column' => 'source_item_id',
                            'target_table' => 'purchase_invoice_items',
                            'target_column' => 'id',
                            'null_value' => 'preserve',
                            'unresolved' => 'block',
                        ],
                    ],
                    'deduplication' => [
                        'strategy' => 'opaque_source_hash',
                        'columns' => ['supplier_id', 'dedup_hash'],
                        'null_value' => 'preserve',
                        'reason' => 'historical_scan_identity',
                    ],
                ],
            ),
            self::owned(
                'logbook_fuel_scans',
                additionalDetails: [
                    'code_references' => [
                        'parser' => [
                            'strategy' => 'application_registry_code',
                            'registry' => 'logbook_fuel_parsers',
                            'unknown_value' => 'block',
                            'null_value' => 'forbid',
                        ],
                    ],
                    'transfer_invariant' =>
                        'preserve_to_prevent_historical_rescan',
                ],
            ),
        ];
    }

    /**
     * @param array<string,string> $actorReferences
     * @param array<string,mixed> $additionalDetails
     */
    private static function owned(
        string $table,
        array $actorReferences = [],
        array $additionalDetails = [],
    ): TenantDataDefinition {
        $details = [
            'primary_key' => ['id'],
            'feature_group' => 'logbook',
            'ownership' => [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            'secrets' => [],
            ...$additionalDetails,
        ];
        if ($actorReferences !== []) {
            $details['actor_references'] = self::references(
                $actorReferences,
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
