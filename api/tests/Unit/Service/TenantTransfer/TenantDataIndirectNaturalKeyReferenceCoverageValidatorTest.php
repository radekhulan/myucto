<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataNaturalKeyReferenceCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class TenantDataIndirectNaturalKeyReferenceCoverageValidatorTest extends TestCase
{
    public function testIndirectOwnershipCanProvideTenantScope(): void
    {
        self::assertSame(
            [],
            (new TenantDataNaturalKeyReferenceCoverageValidator())->issues(
                self::source(),
                self::sourceInventory(),
                self::inventories(),
                self::definitions(),
            ),
        );
    }

    public function testIndirectScopeRequiresTenantRootedOwnership(): void
    {
        $source = self::source();
        $details = $source->details;
        $ownership = $details['ownership'];
        self::assertIsArray($ownership);
        $ownership['path'] = [[
            'from_column' => 'template_id',
            'to_table' => 'journal_entry_templates',
            'to_column' => 'id',
        ]];
        $details['ownership'] = $ownership;

        self::assertSame(
            [
                'natural_key_reference_source_scope_mismatch:'
                    . 'journal_entry_template_lines.account',
            ],
            (new TenantDataNaturalKeyReferenceCoverageValidator())->issues(
                new TenantDataDefinition(
                    $source->key,
                    $source->kind,
                    $source->policy,
                    $source->profiles,
                    $details,
                ),
                self::sourceInventory(),
                self::inventories(),
                self::definitions(),
            ),
        );
    }

    private static function source(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:journal_entry_template_lines',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'foreign_key_path',
                    'path' => [
                        [
                            'from_column' => 'template_id',
                            'to_table' => 'journal_entry_templates',
                            'to_column' => 'id',
                        ],
                        [
                            'from_column' => 'supplier_id',
                            'to_table' => 'supplier',
                            'to_column' => 'id',
                        ],
                    ],
                ],
                'natural_key_references' => [
                    'account' => [
                        'strategy' => 'tenant_natural_key',
                        'source_scope' => [
                            'strategy' => 'ownership_tenant',
                        ],
                        'source_columns' => ['account_code'],
                        'target_table' => 'chart_of_accounts',
                        'target_scope_column' => 'supplier_id',
                        'target_columns' => ['account_code'],
                        'null_value' => 'forbid',
                        'unresolved' => 'block',
                    ],
                ],
            ],
        );
    }

    private static function target(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:chart_of_accounts',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'import_identity' => [
                    'strategy' => 'tenant_natural_key',
                    'keys' => ['supplier_id', 'account_code'],
                    'missing_row' => 'create_with_mapped_tenant',
                    'existing_row' =>
                        'reuse_target_id_and_apply_source',
                ],
            ],
        );
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        return [
            'journal_entry_template_lines' => self::source(),
            'chart_of_accounts' => self::target(),
        ];
    }

    /** @return array<string,TenantSchemaTableInventory> */
    private static function inventories(): array
    {
        return [
            'journal_entry_template_lines' => self::sourceInventory(),
            'chart_of_accounts' => self::targetInventory(),
        ];
    }

    private static function sourceInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'journal_entry_template_lines',
            'BASE TABLE',
            ['id', 'template_id', 'account_code'],
            ['id'],
            [],
            [['id']],
        );
    }

    private static function targetInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'chart_of_accounts',
            'BASE TABLE',
            ['id', 'supplier_id', 'account_code'],
            ['id'],
            [],
            [['id'], ['supplier_id', 'account_code']],
        );
    }
}
