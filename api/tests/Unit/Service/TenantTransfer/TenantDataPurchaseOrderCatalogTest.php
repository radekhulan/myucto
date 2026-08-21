<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPurchaseOrderCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataPurchaseOrderCatalogTest extends TestCase
{
    public function testInvoiceLinkIsRecreatedAsTenantRelation(): void
    {
        $link = self::invoiceLink();

        self::assertSame(TenantDataPolicy::TenantRelation, $link->policy);
        self::assertSame(
            [TenantDataRegistry::TRANSFER_PROFILE],
            $link->profiles,
        );
        self::assertSame(['id'], $link->details['primary_key'] ?? null);
        self::assertSame('stock', $link->details['feature_group'] ?? null);
        self::assertSame(
            [
                'strategy' => 'supplier_relation',
                'column' => 'supplier_id',
            ],
            $link->details['ownership'] ?? null,
        );
        self::assertSame([], $link->details['secrets'] ?? null);
    }

    public function testRelationNeverImportsRawSourceIdentifiers(): void
    {
        self::assertSame(
            [
                'strategy' => 'recreate_from_mapped_references',
                'raw_insert' => false,
                'unresolved_row' => 'skip',
            ],
            self::invoiceLink()->details['relation_import'] ?? null,
        );
        self::assertSame(
            'recreate_after_order_and_purchase_invoice_are_mapped',
            self::invoiceLink()->details['transfer_invariant'] ?? null,
        );
    }

    public function testHistoricalLinkAuthorIsOptional(): void
    {
        self::assertSame(
            [
                'linked_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            self::invoiceLink()->details['soft_actor_references'] ?? null,
        );
    }

    public function testFactoryPublishesInvoiceLinkOnlyForTransfer(): void
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

        self::assertContains(
            'table:purchase_order_invoice_links',
            $transfer,
        );
        self::assertNotContains(
            'table:purchase_order_invoice_links',
            $archive,
        );
    }

    private static function invoiceLink(): TenantDataDefinition
    {
        $definitions = TenantDataPurchaseOrderCatalog::definitions();
        self::assertCount(1, $definitions);
        self::assertSame(
            'table:purchase_order_invoice_links',
            $definitions[0]->key,
        );
        return $definitions[0];
    }
}
