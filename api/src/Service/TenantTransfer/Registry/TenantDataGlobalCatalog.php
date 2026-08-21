<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Globální číselníky se mapují podle unikátního klíče a nikdy se nekopírují. */
final class TenantDataGlobalCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::reference(
                'bank_rule_templates',
                ['id'],
                ['template_key'],
                [
                    'name_cs',
                    'name_en',
                    'direction',
                    'operation_type',
                    'counterparty_bank',
                    'counterparty_prefix',
                    'vs_placeholder',
                    'message_contains',
                    'rule_key',
                    'default_priority',
                    'sort_order',
                    'is_active',
                ],
                'bank',
            ),
            self::reference(
                'cnb_repo_rates',
                ['valid_from'],
                ['valid_from'],
                ['rate'],
                'accounting',
            ),
            self::reference(
                'countries',
                ['id'],
                ['iso2'],
                ['iso3', 'name_cs', 'name_en', 'is_eu'],
                'core',
            ),
            self::reference(
                'ecb_exchange_rate_days',
                ['rate_date'],
                ['rate_date'],
                ['published'],
                'accounting',
            ),
            self::reference(
                'ecb_exchange_rates',
                ['rate_date', 'currency_code'],
                ['rate_date', 'currency_code'],
                ['units_per_eur'],
                'accounting',
            ),
            self::reference(
                'exchange_rates',
                ['rate_date', 'currency_code'],
                ['rate_date', 'currency_code'],
                ['rate'],
                'accounting',
            ),
            self::reference(
                'oss_member_state_rates',
                ['id'],
                ['country', 'rate_type', 'rate_percent', 'valid_from'],
                [
                    'valid_to',
                    'valid_to_override',
                    'is_custom',
                    'disabled_at',
                    'note',
                ],
                'tax',
            ),
            self::reference(
                'tax_constants',
                ['year'],
                ['year'],
                ['data'],
                'tax',
            ),
            self::reference(
                'units',
                ['id'],
                ['code'],
                ['label_cs', 'label_en', 'is_default', 'display_order'],
                'core',
            ),
            self::reference(
                'vat_rates',
                ['id'],
                ['code'],
                [
                    'rate_percent',
                    'country',
                    'label_cs',
                    'label_en',
                    'is_default',
                    'is_reverse_charge',
                    'valid_from',
                    'valid_to',
                    'display_order',
                ],
                'tax',
            ),
        ];
    }

    /**
     * @param list<string> $primaryKey
     * @param list<string> $naturalKey
     * @param list<string> $valueColumns
     */
    private static function reference(
        string $table,
        array $primaryKey,
        array $naturalKey,
        array $valueColumns,
        string $featureGroup,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::GlobalReference,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => $primaryKey,
                'feature_group' => $featureGroup,
                'mapping' => [
                    'strategy' => 'natural_key',
                    'keys' => $naturalKey,
                    'values' => [
                        'strategy' => 'require_equal',
                        'columns' => $valueColumns,
                    ],
                ],
                'secrets' => [],
            ],
        );
    }
}
