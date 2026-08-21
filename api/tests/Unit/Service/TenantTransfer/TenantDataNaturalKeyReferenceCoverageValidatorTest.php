<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataNaturalKeyReferenceCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryCoverageValidator;
use PHPUnit\Framework\TestCase;

final class TenantDataNaturalKeyReferenceCoverageValidatorTest extends TestCase
{
    public function testTenantNaturalKeyReferenceIsFailClosed(): void
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

    public function testNullAndUnresolvedPoliciesCannotDrift(): void
    {
        self::assertSame(
            [
                'natural_key_reference_null_policy_mismatch:'
                    . 'stock_item_i18n.locale',
                'natural_key_reference_unresolved_not_blocked:'
                    . 'stock_item_i18n.locale',
            ],
            (new TenantDataNaturalKeyReferenceCoverageValidator())->issues(
                self::source([
                    'null_value' => 'preserve',
                    'unresolved' => 'preserve',
                ]),
                self::sourceInventory(),
                self::inventories(),
                self::definitions(),
            ),
        );
    }

    public function testTargetMustExposeMatchingTenantUniqueIdentity(): void
    {
        $target = self::target([
            'keys' => ['supplier_id', 'name'],
        ]);
        self::assertSame(
            [
                'natural_key_reference_target_identity_mismatch:'
                    . 'stock_item_i18n.locale->stock_locales',
                'natural_key_reference_target_not_unique:'
                    . 'stock_item_i18n.locale->stock_locales',
            ],
            (new TenantDataNaturalKeyReferenceCoverageValidator())->issues(
                self::source(),
                self::sourceInventory(),
                [
                    'stock_item_i18n' => self::sourceInventory(),
                    'stock_locales' => self::targetInventory(false),
                ],
                [
                    'stock_item_i18n' => self::source(),
                    'stock_locales' => $target,
                ],
            ),
        );
    }

    public function testRegistryCoverageRunsNaturalKeyValidator(): void
    {
        $source = self::source(['unresolved' => 'preserve']);
        $registry = new TenantDataRegistry(
            1,
            [self::supplier(), $source, self::target()],
        );

        self::assertSame(
            [
                'natural_key_reference_unresolved_not_blocked:'
                    . 'stock_item_i18n.locale',
            ],
            (new TenantDataRegistryCoverageValidator())->issues(
                $registry,
                [
                    self::supplierInventory(),
                    self::sourceInventory(),
                    self::targetInventory(),
                ],
            ),
        );
    }

    /** @param array<string,mixed> $overrides */
    private static function source(array $overrides = []): TenantDataDefinition
    {
        return self::owned(
            'stock_item_i18n',
            [
                'natural_key_references' => [
                    'locale' => [
                        'strategy' => 'tenant_natural_key',
                        'source_scope_column' => 'supplier_id',
                        'source_columns' => ['locale'],
                        'target_table' => 'stock_locales',
                        'target_scope_column' => 'supplier_id',
                        'target_columns' => ['code'],
                        'null_value' => 'forbid',
                        'unresolved' => 'block',
                        ...$overrides,
                    ],
                ],
            ],
        );
    }

    /** @param array<string,mixed> $overrides */
    private static function target(array $overrides = []): TenantDataDefinition
    {
        return self::owned(
            'stock_locales',
            [
                'import_identity' => [
                    'strategy' => 'tenant_natural_key',
                    'keys' => ['supplier_id', 'code'],
                    'missing_row' => 'create_with_mapped_tenant',
                    'existing_row' => 'reuse_target_id_and_apply_source',
                    ...$overrides,
                ],
            ],
        );
    }

    /** @param array<string,mixed> $additionalDetails */
    private static function owned(
        string $table,
        array $additionalDetails,
    ): TenantDataDefinition {
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
                'secrets' => [],
                ...$additionalDetails,
            ],
        );
    }

    private static function supplier(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:supplier',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'selected_supplier',
                    'column' => 'id',
                ],
                'secrets' => [],
            ],
        );
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        return [
            'stock_item_i18n' => self::source(),
            'stock_locales' => self::target(),
        ];
    }

    /** @return array<string,TenantSchemaTableInventory> */
    private static function inventories(): array
    {
        return [
            'stock_item_i18n' => self::sourceInventory(),
            'stock_locales' => self::targetInventory(),
        ];
    }

    private static function supplierInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'supplier',
            'BASE TABLE',
            ['id'],
            ['id'],
            [],
            [['id']],
        );
    }

    private static function sourceInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'stock_item_i18n',
            'BASE TABLE',
            ['id', 'supplier_id', 'locale'],
            ['id'],
            [],
            [['id']],
        );
    }

    private static function targetInventory(
        bool $naturalKeyIsUnique = true,
    ): TenantSchemaTableInventory {
        $uniqueKeys = [['id']];
        if ($naturalKeyIsUnique) {
            $uniqueKeys[] = ['supplier_id', 'code'];
        }
        return new TenantSchemaTableInventory(
            'stock_locales',
            'BASE TABLE',
            ['id', 'supplier_id', 'code', 'name'],
            ['id'],
            [],
            $uniqueKeys,
        );
    }
}
