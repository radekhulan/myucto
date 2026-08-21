<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataSoftReferenceCoverageValidator;
use PHPUnit\Framework\TestCase;

final class TenantDataSoftReferenceCoverageValidatorTest extends TestCase
{
    public function testClosedPolymorphicTargetMapPasses(): void
    {
        $source = self::source(['client' => 'clients']);
        $client = self::target('clients');

        self::assertSame(
            [],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                $source,
                self::inventory(),
                ['clients' => self::targetInventory('clients')],
                ['clients' => $client],
            ),
        );
    }

    public function testUnregisteredTargetAndMissingColumnsFailClosed(): void
    {
        $source = self::source(['client' => 'clients']);
        $details = $source->details;
        $references = $details['soft_references'];
        self::assertIsArray($references);
        self::assertIsArray($references['entity'] ?? null);
        $references['entity']['id_column'] = 'ghost_id';
        $details['soft_references'] = $references;
        $source = new TenantDataDefinition(
            $source->key,
            $source->kind,
            $source->policy,
            $source->profiles,
            $details,
        );

        self::assertSame(
            [
                'soft_reference_column_missing:document_links.ghost_id',
                'soft_reference_target_unregistered:'
                    . 'document_links.entity.client->clients',
            ],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                $source,
                self::inventory(),
                [],
                [],
            ),
        );
    }

    public function testEnumAndTargetMapMustStayInExactParity(): void
    {
        $source = self::source(['client' => 'clients']);
        $client = self::target('clients');

        self::assertSame(
            ['soft_reference_target_map_mismatch:document_links.entity'],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                $source,
                self::inventory(['client', 'invoice']),
                ['clients' => self::targetInventory('clients')],
                ['clients' => $client],
            ),
        );
    }

    public function testUnknownDiscriminatorValuesMustBeBlocked(): void
    {
        $source = self::source(['client' => 'clients']);
        $details = $source->details;
        $references = $details['soft_references'];
        self::assertIsArray($references);
        self::assertIsArray($references['entity'] ?? null);
        unset($references['entity']['unknown_value']);
        $details['soft_references'] = $references;

        self::assertSame(
            [
                'soft_reference_unknown_value_not_blocked:'
                    . 'document_links.entity',
            ],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                new TenantDataDefinition(
                    $source->key,
                    $source->kind,
                    $source->policy,
                    $source->profiles,
                    $details,
                ),
                self::inventory(),
                ['clients' => self::targetInventory('clients')],
                ['clients' => self::target('clients')],
            ),
        );
    }

    public function testDirectNullableTenantReferencePasses(): void
    {
        $source = self::directSource();
        $item = self::target('purchase_invoice_items');

        self::assertSame(
            [],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                $source,
                self::directInventory(),
                [
                    'purchase_invoice_items' => self::targetInventory(
                        'purchase_invoice_items',
                    ),
                ],
                ['purchase_invoice_items' => $item],
            ),
        );
    }

    public function testDirectReferenceChecksTargetNullAndUnresolvedPolicy(): void
    {
        $source = self::directSource([
            'target_column' => 'ghost_id',
            'null_value' => 'forbid',
            'unresolved' => 'preserve',
        ]);

        self::assertSame(
            [
                'soft_reference_null_policy_mismatch:'
                    . 'fuelings.source_item',
                'soft_reference_target_column_missing:'
                    . 'fuelings.source_item->purchase_invoice_items.ghost_id',
                'soft_reference_target_not_primary:'
                    . 'fuelings.source_item->purchase_invoice_items.ghost_id',
                'soft_reference_unresolved_not_blocked:'
                    . 'fuelings.source_item',
            ],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                $source,
                self::directInventory(),
                [
                    'purchase_invoice_items' => self::targetInventory(
                        'purchase_invoice_items',
                    ),
                ],
                [
                    'purchase_invoice_items' => self::target(
                        'purchase_invoice_items',
                    ),
                ],
            ),
        );
    }

    public function testRuntimeDerivedReferenceCanBeExplicitlyNullified(): void
    {
        self::assertSame(
            [],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                self::runtimeSource(),
                self::runtimeSourceInventory(),
                [
                    'bank_match_suggestions' => self::targetInventory(
                        'bank_match_suggestions',
                    ),
                ],
                [
                    'bank_match_suggestions' => self::runtimeTarget(
                        'bank_match_suggestions',
                    ),
                ],
            ),
        );
    }

    public function testRuntimeDerivedReferenceRequiresExplicitNullification(): void
    {
        self::assertSame(
            [
                'soft_reference_omitted_value_not_nullified:'
                    . 'bank_match_audit.match_suggestion',
                'soft_reference_target_not_runtime_derived:'
                    . 'bank_match_audit.match_suggestion'
                    . '->bank_match_suggestions.id',
            ],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                self::runtimeSource([
                    'target_omitted' => 'preserve_source_id',
                ]),
                self::runtimeSourceInventory(),
                [
                    'bank_match_suggestions' => self::targetInventory(
                        'bank_match_suggestions',
                    ),
                ],
                [
                    'bank_match_suggestions' => self::target(
                        'bank_match_suggestions',
                    ),
                ],
            ),
        );
    }

    /** @param array<string,string> $targets */
    private static function source(array $targets): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:document_links',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['document_id', 'entity_type', 'entity_id'],
                'soft_references' => [
                    'entity' => [
                        'strategy' => 'polymorphic_tenant_entity',
                        'type_column' => 'entity_type',
                        'id_column' => 'entity_id',
                        'unknown_value' => 'block',
                        'targets' => $targets,
                    ],
                ],
            ],
        );
    }

    private static function target(string $table): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
            ],
        );
    }

    private static function runtimeTarget(
        string $table,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'reason' => 'runtime_regenerated_queue',
                'secrets' => [],
            ],
        );
    }

    /** @param array<string,string> $overrides */
    private static function directSource(array $overrides = []): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:fuelings',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'soft_references' => [
                    'source_item' => [
                        'strategy' => 'direct_tenant_entity',
                        'id_column' => 'source_item_id',
                        'target_table' => 'purchase_invoice_items',
                        'target_column' => 'id',
                        'null_value' => 'preserve',
                        'unresolved' => 'block',
                        ...$overrides,
                    ],
                ],
            ],
        );
    }

    /** @param array<string,string> $overrides */
    private static function runtimeSource(
        array $overrides = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:bank_match_audit',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'soft_references' => [
                    'match_suggestion' => [
                        'strategy' => 'runtime_derived_entity',
                        'id_column' => 'suggestion_id',
                        'target_table' => 'bank_match_suggestions',
                        'target_column' => 'id',
                        'null_value' => 'preserve',
                        'target_omitted' => 'set_null',
                        ...$overrides,
                    ],
                ],
            ],
        );
    }

    /** @param list<string> $entityTypes */
    private static function inventory(
        array $entityTypes = ['client'],
    ): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'document_links',
            'BASE TABLE',
            ['document_id', 'entity_type', 'entity_id'],
            ['document_id', 'entity_type', 'entity_id'],
            [],
            [['document_id', 'entity_type', 'entity_id']],
            [],
            ['entity_type' => $entityTypes],
        );
    }

    private static function targetInventory(
        string $table,
    ): TenantSchemaTableInventory {
        return new TenantSchemaTableInventory(
            $table,
            'BASE TABLE',
            ['id', 'supplier_id'],
            ['id'],
            [],
            [['id']],
        );
    }

    private static function directInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'fuelings',
            'BASE TABLE',
            ['id', 'supplier_id', 'source_item_id'],
            ['id'],
            [],
            [['id']],
            ['source_item_id'],
        );
    }

    private static function runtimeSourceInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'bank_match_audit',
            'BASE TABLE',
            ['id', 'supplier_id', 'suggestion_id'],
            ['id'],
            [],
            [['id']],
            ['suggestion_id'],
        );
    }
}
