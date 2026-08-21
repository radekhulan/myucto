<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaForeignKeyInventory;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataMixedOwnershipCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class TenantDataMixedOwnershipCoverageValidatorTest extends TestCase
{
    public function testTenantRowsAndGlobalReferencesHaveClosedSelectors(): void
    {
        self::assertSame(
            [],
            (new TenantDataMixedOwnershipCoverageValidator())->issues(
                self::definition(),
                self::inventory(),
            ),
        );
    }

    public function testNullableOwnerAndScopedUniqueKeyAreRequired(): void
    {
        self::assertSame(
            [
                'mixed_global_mapping_not_unique:'
                    . 'bank_email_notice_providers',
                'mixed_ownership_column_not_nullable:'
                    . 'bank_email_notice_providers.supplier_id',
            ],
            (new TenantDataMixedOwnershipCoverageValidator())->issues(
                self::definition(),
                self::inventory(nullableColumns: [], uniqueKeys: []),
            ),
        );
    }

    public function testOwnerColumnMustBeCanonicalSupplierId(): void
    {
        $definition = self::definition();
        $details = $definition->details;
        $ownership = $details['ownership'];
        self::assertIsArray($ownership);
        $ownership['column'] = 'owner_id';
        $details['ownership'] = $ownership;

        self::assertSame(
            [
                'mixed_ownership_column_missing:'
                    . 'bank_email_notice_providers',
            ],
            (new TenantDataMixedOwnershipCoverageValidator())->issues(
                new TenantDataDefinition(
                    $definition->key,
                    $definition->kind,
                    $definition->policy,
                    $definition->profiles,
                    $details,
                ),
                self::inventory(),
            ),
        );
    }

    private static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:bank_email_notice_providers',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
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
                                'columns' => ['parser_type', 'field_patterns'],
                            ],
                            'missing' => 'block',
                            'ambiguous' => 'block',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @param list<string> $nullableColumns
     * @param list<list<string>> $uniqueKeys
     */
    private static function inventory(
        array $nullableColumns = ['supplier_id'],
        array $uniqueKeys = [['supplier_id', 'code']],
    ): TenantSchemaTableInventory {
        return new TenantSchemaTableInventory(
            'bank_email_notice_providers',
            'BASE TABLE',
            ['id', 'supplier_id', 'code', 'parser_type', 'field_patterns'],
            ['id'],
            [
                new TenantSchemaForeignKeyInventory(
                    'supplier_id',
                    'supplier',
                    'id',
                ),
            ],
            $uniqueKeys,
            $nullableColumns,
        );
    }
}
