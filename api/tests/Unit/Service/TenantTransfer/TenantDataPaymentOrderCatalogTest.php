<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPaymentOrderCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataPaymentOrderCatalogTest extends TestCase
{
    public function testHeaderIsOwnedAndItemsFollowSoftOrderPath(): void
    {
        $definitions = self::definitions();

        self::assertSame(
            TenantDataPolicy::TenantOwned,
            $definitions['payment_orders']->policy,
        );
        self::assertSame(
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $definitions['payment_orders']->details['ownership'] ?? null,
        );
        self::assertSame(
            TenantDataPolicy::TenantOwnedIndirect,
            $definitions['payment_order_items']->policy,
        );
        self::assertSame(
            [
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
            $definitions['payment_order_items']->details['ownership'] ?? null,
        );
    }

    public function testHeaderMapsCurrencyAndHistoricalCreator(): void
    {
        $order = self::definitions()['payment_orders'];

        self::assertSame(
            [
                'payer_currency' => [
                    'strategy' => 'direct_tenant_entity',
                    'id_column' => 'payer_currency_id',
                    'target_table' => 'currencies',
                    'target_column' => 'id',
                    'null_value' => 'preserve',
                    'unresolved' => 'block',
                ],
            ],
            $order->details['soft_references'] ?? null,
        );
        self::assertSame(
            [
                'created_by_user_id' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $order->details['soft_actor_references'] ?? null,
        );
    }

    public function testItemsMapOrderAndPurchaseInvoice(): void
    {
        self::assertSame(
            [
                'payment_order' => [
                    'strategy' => 'direct_tenant_entity',
                    'id_column' => 'payment_order_id',
                    'target_table' => 'payment_orders',
                    'target_column' => 'id',
                    'null_value' => 'forbid',
                    'unresolved' => 'block',
                ],
                'purchase_invoice' => [
                    'strategy' => 'direct_tenant_entity',
                    'id_column' => 'purchase_invoice_id',
                    'target_table' => 'purchase_invoices',
                    'target_column' => 'id',
                    'null_value' => 'forbid',
                    'unresolved' => 'block',
                ],
            ],
            self::definitions()['payment_order_items']
                ->details['soft_references'] ?? null,
        );
    }

    public function testHistoricalBankingSnapshotIsPreserved(): void
    {
        foreach (self::definitions() as $definition) {
            self::assertSame(
                'preserve_deterministic_payment_export_snapshot',
                $definition->details['transfer_invariant'] ?? null,
            );
            self::assertArrayNotHasKey('post_import', $definition->details);
        }
    }

    public function testFactoryPublishesPaymentOrdersOnlyForTransfer(): void
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

        foreach (['payment_orders', 'payment_order_items'] as $table) {
            $key = 'table:' . $table;
            self::assertContains($key, $transfer);
            self::assertNotContains($key, $archive);
        }
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataPaymentOrderCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            self::assertSame(['id'], $definition->details['primary_key']);
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        self::assertSame(
            ['payment_order_items', 'payment_orders'],
            array_keys($definitions),
        );
        return $definitions;
    }
}
