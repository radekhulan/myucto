<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataGlobalCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataGlobalCatalogTest extends TestCase
{
    public function testGlobalReferencesHaveStableNaturalAndValueKeys(): void
    {
        $expected = [
            'bank_rule_templates' => [['template_key'], [
                'name_cs', 'name_en', 'direction', 'operation_type',
                'counterparty_bank', 'counterparty_prefix', 'vs_placeholder',
                'message_contains', 'rule_key', 'default_priority',
                'sort_order', 'is_active',
            ]],
            'cnb_repo_rates' => [['valid_from'], ['rate']],
            'countries' => [['iso2'], ['iso3', 'name_cs', 'name_en', 'is_eu']],
            'ecb_exchange_rate_days' => [['rate_date'], ['published']],
            'ecb_exchange_rates' => [
                ['rate_date', 'currency_code'],
                ['units_per_eur'],
            ],
            'exchange_rates' => [
                ['rate_date', 'currency_code'],
                ['rate'],
            ],
            'oss_member_state_rates' => [
                ['country', 'rate_type', 'rate_percent', 'valid_from'],
                ['valid_to', 'valid_to_override', 'is_custom', 'disabled_at', 'note'],
            ],
            'tax_constants' => [['year'], ['data']],
            'units' => [
                ['code'],
                ['label_cs', 'label_en', 'is_default', 'display_order'],
            ],
            'vat_rates' => [
                ['code'],
                [
                    'rate_percent', 'country', 'label_cs', 'label_en',
                    'is_default', 'is_reverse_charge', 'valid_from',
                    'valid_to', 'display_order',
                ],
            ],
        ];

        $actual = [];
        foreach (TenantDataGlobalCatalog::definitions() as $definition) {
            self::assertSame(TenantDataPolicy::GlobalReference, $definition->policy);
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            $mapping = $definition->details['mapping'] ?? null;
            self::assertIsArray($mapping);
            self::assertSame('natural_key', $mapping['strategy'] ?? null);
            $values = $mapping['values'] ?? null;
            self::assertIsArray($values);
            self::assertSame('require_equal', $values['strategy'] ?? null);
            $actual[self::tableName($definition)] = [
                $mapping['keys'] ?? null,
                $values['columns'] ?? null,
            ];
        }

        self::assertSame($expected, $actual);
    }

    public function testExchangeRatesShareTransferAndArchiveDefinition(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $transfer = self::definitionByKey(
            $registry->definitionsFor(TenantDataRegistry::TRANSFER_PROFILE),
            'table:exchange_rates',
        );
        $archive = self::definitionByKey(
            $registry->definitionsFor(
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
            ),
            'table:exchange_rates',
        );

        self::assertSame($transfer->policy, $archive->policy);
        self::assertSame(
            $transfer->details['mapping'] ?? null,
            $archive->details['mapping'] ?? null,
        );
        self::assertArrayHasKey('accounting_archive', $archive->details);
    }

    /** @param list<TenantDataDefinition> $definitions */
    private static function definitionByKey(
        array $definitions,
        string $key,
    ): TenantDataDefinition {
        foreach ($definitions as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }
        self::fail('V registru chybí definice ' . $key . '.');
    }

    private static function tableName(TenantDataDefinition $definition): string
    {
        self::assertStringStartsWith('table:', $definition->key);
        return substr($definition->key, strlen('table:'));
    }
}
