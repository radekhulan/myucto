<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataSettlementCatalog;
use PHPUnit\Framework\TestCase;

final class TenantDataSettlementCatalogTest extends TestCase
{
    public function testCompleteSettlementGraphIsTenantOwned(): void
    {
        $expected = [
            'invoice_payment_schedule' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
                'core',
            ],
            'invoice_settlements' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
                'accounting',
            ],
            'offset_agreement_items' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
                'accounting',
            ],
            'offset_agreements' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
                'accounting',
            ],
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
                $definition->details['feature_group'] ?? null,
            ];
        }

        self::assertSame($expected, $actual);
    }

    public function testPolymorphicDocumentReferencesAreClosed(): void
    {
        $definitions = self::definitions();
        $expected = [
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

        self::assertSame(
            $expected,
            $definitions['invoice_settlements']
                ->details['soft_references'] ?? null,
        );
        self::assertSame(
            $expected,
            $definitions['offset_agreement_items']
                ->details['soft_references'] ?? null,
        );
    }

    public function testHistoricalCreatorsUseNullableSoftActorMapping(): void
    {
        $definitions = self::definitions();
        $expected = [
            'created_by' => [
                'strategy' => 'map_existing_user_or_null',
            ],
        ];

        self::assertSame(
            $expected,
            $definitions['invoice_settlements']
                ->details['soft_actor_references'] ?? null,
        );
        self::assertSame(
            $expected,
            $definitions['offset_agreements']
                ->details['soft_actor_references'] ?? null,
        );
    }

    public function testFactoryPublishesSettlementsOnlyForTransfer(): void
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

        foreach (array_keys(self::definitions()) as $table) {
            $key = 'table:' . $table;
            self::assertContains($key, $transfer);
            self::assertNotContains($key, $archive);
        }
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataSettlementCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }
}
