<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataBusinessCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataBusinessCatalogTest extends TestCase
{
    public function testBusinessBoundaryHasExplicitPoliciesAndPrimaryKeys(): void
    {
        $expected = [
            'email_profiles' => [TenantDataPolicy::TenantOwned, ['id']],
            'branding_profiles' => [TenantDataPolicy::TenantOwned, ['id']],
            'revenue_categories' => [TenantDataPolicy::TenantOwned, ['id']],
            'price_list_items' => [TenantDataPolicy::TenantOwned, ['id']],
            'price_list_item_prices' => [TenantDataPolicy::TenantOwned, ['id']],
            'price_list_customer_overrides' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'projects' => [TenantDataPolicy::TenantOwnedIndirect, ['id']],
            'project_billing_emails' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['id'],
            ],
            'client_email_contacts' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['id'],
            ],
            'invoice_counters' => [
                TenantDataPolicy::TenantOwned,
                [
                    'supplier_id',
                    'client_id',
                    'revenue_category_id',
                    'invoice_type',
                    'period',
                ],
            ],
            'purchase_invoice_counters' => [
                TenantDataPolicy::TenantOwned,
                ['supplier_id', 'period'],
            ],
            'recurring_invoice_templates' => [
                TenantDataPolicy::TenantOwned,
                ['id'],
            ],
            'recurring_invoice_template_items' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['id'],
            ],
            'work_reports' => [TenantDataPolicy::TenantOwnedIndirect, ['id']],
            'work_report_items' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['id'],
            ],
            'work_report_materials' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['id'],
            ],
            'client_revenue_cache' => [
                TenantDataPolicy::RuntimeDerived,
                ['client_id', 'currency_id'],
            ],
            'project_revenue_cache' => [
                TenantDataPolicy::RuntimeDerived,
                ['project_id', 'currency_id'],
            ],
            'crm_monthly_summary' => [
                TenantDataPolicy::RuntimeDerived,
                ['supplier_id', 'period_ym', 'currency'],
            ],
            'crm_action_item_dismissals' => [
                TenantDataPolicy::InstanceOwned,
                ['id'],
            ],
            'work_report_links' => [TenantDataPolicy::RuntimeDerived, ['id']],
            'work_report_link_codes' => [
                TenantDataPolicy::RuntimeDerived,
                ['id'],
            ],
            'work_report_link_sessions' => [
                TenantDataPolicy::RuntimeDerived,
                ['id'],
            ],
        ];

        $actual = [];
        foreach (TenantDataBusinessCatalog::definitions() as $definition) {
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            $actual[self::tableName($definition)] = [
                $definition->policy,
                $definition->details['primary_key'] ?? null,
            ];
        }

        self::assertSame($expected, $actual);
    }

    public function testIndirectOwnershipPathsReachTenantRoot(): void
    {
        $definitions = self::byTable();

        self::assertSame(
            [
                'strategy' => 'foreign_key_path',
                'path' => [
                    self::path('client_id', 'clients'),
                    self::path('supplier_id', 'supplier'),
                ],
            ],
            $definitions['projects']->details['ownership'] ?? null,
        );
        self::assertSame(
            [
                'strategy' => 'foreign_key_path',
                'path' => [
                    self::path('work_report_id', 'work_reports'),
                    self::path('invoice_id', 'invoices'),
                    self::path('supplier_id', 'supplier'),
                ],
            ],
            $definitions['work_report_items']->details['ownership'] ?? null,
        );
    }

    public function testSecretsActorsAndAutomationAreFailSafe(): void
    {
        $definitions = self::byTable();

        self::assertSame(
            [
                'smtp_encryption' => [
                    'policy' => 'not_secret',
                    'reason' => 'transport_security_mode',
                ],
                'smtp_password_enc' => ['policy' => 'reencrypt_v1'],
                'imap_encryption' => [
                    'policy' => 'not_secret',
                    'reason' => 'transport_security_mode',
                ],
                'imap_password_enc' => ['policy' => 'reencrypt_v1'],
            ],
            $definitions['email_profiles']->details['secrets'] ?? null,
        );
        self::assertSame(
            ['created_by' => ['strategy' => 'map_existing_user_required']],
            $definitions['recurring_invoice_templates']
                ->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
                'force_columns' => [
                    'status' => 'paused',
                    'auto_issue' => false,
                    'auto_send_email' => false,
                    'last_error' => null,
                    'last_error_at' => null,
                ],
                'reason' => 'recurring_requires_manual_reactivation',
            ],
            $definitions['recurring_invoice_templates']
                ->details['post_import'] ?? null,
        );
        self::assertSame(
            ['token' => ['policy' => 'omit_and_reconfigure']],
            $definitions['work_report_links']->details['secrets'] ?? null,
        );
        self::assertSame(
            ['code_hash' => ['policy' => 'omit_and_reconfigure']],
            $definitions['work_report_link_codes']->details['secrets'] ?? null,
        );
        self::assertSame(
            ['session_hash' => ['policy' => 'omit_and_reconfigure']],
            $definitions['work_report_link_sessions']->details['secrets']
                ?? null,
        );
    }

    public function testFactoryPublishesBusinessCatalogOnlyForTransfer(): void
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

        self::assertContains('table:projects', $transfer);
        self::assertContains('table:recurring_invoice_templates', $transfer);
        self::assertContains('table:work_reports', $transfer);
        self::assertContains('table:email_profiles', $transfer);
        self::assertNotContains('table:projects', $archive);
        self::assertFalse($registry->isComplete(
            TenantDataRegistry::TRANSFER_PROFILE,
        ));
    }

    /** @return array<string,TenantDataDefinition> */
    private static function byTable(): array
    {
        $definitions = [];
        foreach (TenantDataBusinessCatalog::definitions() as $definition) {
            $definitions[self::tableName($definition)] = $definition;
        }
        return $definitions;
    }

    private static function tableName(
        TenantDataDefinition $definition,
    ): string {
        self::assertStringStartsWith('table:', $definition->key);
        return substr($definition->key, strlen('table:'));
    }

    /** @return array{from_column:string,to_table:string,to_column:string} */
    private static function path(string $fromColumn, string $toTable): array
    {
        return [
            'from_column' => $fromColumn,
            'to_table' => $toTable,
            'to_column' => 'id',
        ];
    }
}
