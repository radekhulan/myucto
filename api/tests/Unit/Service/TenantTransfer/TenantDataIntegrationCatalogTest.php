<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataIntegrationCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataIntegrationCatalogTest extends TestCase
{
    public function testIntegrationBoundaryHasExplicitPoliciesAndKeys(): void
    {
        $expected = [
            'bank_email_account_mappings' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'bank_email_imap_settings' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'bank_email_notice_provider_overrides' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'bank_email_notice_providers' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'bank_email_processed_messages' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'email_templates' => [TenantDataPolicy::InstanceOwned, ['id']],
            'external_bank_account_mappings' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'monthly_report_sends' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'supplier_bank_accounts' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
        ];

        $actual = [];
        foreach (self::byTable() as $table => $definition) {
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            $actual[$table] = [
                $definition->policy,
                $definition->details['primary_key'] ?? null,
            ];
        }

        self::assertSame($expected, $actual);
    }

    public function testProviderTableSeparatesTenantRowsFromGlobalMapping(): void
    {
        $provider = self::byTable()['bank_email_notice_providers'];

        self::assertSame(
            [
                'strategy' => 'supplier_id_or_global_reference',
                'column' => 'supplier_id',
                'tenant_rows' => 'transfer',
                'global_rows' => [
                    'selector' => 'supplier_id_is_null',
                    'mapping' => [
                        'strategy' => 'natural_key',
                        'keys' => ['code'],
                        'values' => [
                            'strategy' => 'require_equal',
                            'columns' => [
                                'name',
                                'parser_type',
                                'enabled',
                                'sender_whitelist',
                                'subject_pattern',
                                'body_pattern',
                                'field_patterns',
                                'normalizer_config',
                            ],
                        ],
                        'missing' => 'block',
                        'ambiguous' => 'block',
                    ],
                ],
            ],
            $provider->details['ownership'] ?? null,
        );
        self::assertSame(
            [
                'parser_type' => [
                    'strategy' => 'application_registry_code',
                    'registry' => 'bank_email_notice_parsers',
                    'unknown_value' => 'block',
                    'null_value' => 'forbid',
                ],
            ],
            $provider->details['code_references'] ?? null,
        );
    }

    public function testScannersAndMappingsStayDisabledAfterImport(): void
    {
        $definitions = self::byTable();

        self::assertSame(
            ['password_enc' => ['policy' => 'reencrypt_v1']],
            $definitions['bank_email_imap_settings']->details['secrets']
                ?? null,
        );
        self::assertSame(
            [
                'force_columns' => [
                    'enabled' => false,
                    'last_scan_at' => null,
                    'last_scan_status' => null,
                    'last_scan_message' => null,
                ],
                'reason' => 'bank_email_scanner_requires_manual_reactivation',
            ],
            $definitions['bank_email_imap_settings']->details['post_import']
                ?? null,
        );
        self::assertSame(
            [
                'force_columns' => ['enabled' => false],
                'reason' => 'bank_email_mapping_requires_manual_reactivation',
            ],
            $definitions['bank_email_account_mappings']
                ->details['post_import'] ?? null,
        );
        self::assertSame(
            [
                'provider_code' => [
                    'strategy' => 'application_registry_code',
                    'registry' => 'bank_email_notice_system_providers',
                    'unknown_value' => 'block',
                    'null_value' => 'preserve',
                ],
            ],
            $definitions['bank_email_account_mappings']
                ->details['code_references'] ?? null,
        );
    }

    public function testHistoryMapsActorsAndGlobalTemplatesStayLocal(): void
    {
        $definitions = self::byTable();

        self::assertSame(
            [
                'sent_by_user_id' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $definitions['monthly_report_sends']
                ->details['actor_references'] ?? null,
        );
        self::assertSame(
            'instance_email_templates',
            $definitions['email_templates']->details['reason'] ?? null,
        );
    }

    public function testFactoryPublishesIntegrationCatalogOnlyForTransfer(): void
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

        self::assertContains('table:bank_email_imap_settings', $transfer);
        self::assertContains('table:bank_email_processed_messages', $transfer);
        self::assertContains('table:supplier_bank_accounts', $transfer);
        self::assertNotContains('table:bank_email_imap_settings', $archive);
    }

    /** @return array<string,TenantDataDefinition> */
    private static function byTable(): array
    {
        $definitions = [];
        foreach (TenantDataIntegrationCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[
                substr($definition->key, strlen('table:'))
            ] = $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }
}
