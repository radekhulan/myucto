<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaForeignKeyInventory;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataInstanceReferenceCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class TenantDataInstanceReferenceCoverageValidatorTest extends TestCase
{
    public function testNullableNaturalKeyRoleMappingPasses(): void
    {
        $membership = $this->membership([
            'role_id' => $this->mapping(),
        ]);
        [$tables, $definitions] = $this->fixture($membership);

        self::assertSame(
            [],
            (new TenantDataInstanceReferenceCoverageValidator())->issues(
                $membership,
                $tables['user_suppliers'],
                $tables,
                $definitions,
            ),
        );
    }

    public function testMissingInstanceReferenceMappingFailsClosed(): void
    {
        $membership = $this->membership([]);
        [$tables, $definitions] = $this->fixture($membership);

        self::assertSame(
            ['instance_reference_policy_missing:user_suppliers.role_id'],
            (new TenantDataInstanceReferenceCoverageValidator())->issues(
                $membership,
                $tables['user_suppliers'],
                $tables,
                $definitions,
            ),
        );
    }

    public function testNaturalKeyAndBlockingRulesAreCheckedAgainstTarget(): void
    {
        $mapping = $this->mapping();
        $mapping['natural_key'] = ['name'];
        $mapping['missing'] = 'preserve';
        $membership = $this->membership(['role_id' => $mapping]);
        [$tables, $definitions] = $this->fixture($membership);

        self::assertSame(
            [
                'instance_reference_missing_not_blocked:'
                    . 'user_suppliers.role_id',
                'instance_reference_natural_key_column_missing:'
                    . 'user_suppliers.role_id->roles.name',
                'instance_reference_natural_key_not_unique:'
                    . 'user_suppliers.role_id',
            ],
            (new TenantDataInstanceReferenceCoverageValidator())->issues(
                $membership,
                $tables['user_suppliers'],
                $tables,
                $definitions,
            ),
        );
    }

    /**
     * @param array<string,array<string,mixed>> $references
     */
    private function membership(array $references): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:user_suppliers',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRelation,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['user_id', 'supplier_id'],
                'ownership' => [
                    'strategy' => 'supplier_relation',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'instance_references' => $references,
            ],
        );
    }

    /** @return array<string,mixed> */
    private function mapping(): array
    {
        return [
            'strategy' => 'map_existing_by_natural_key_or_explicit',
            'natural_key' => ['system_key'],
            'natural_key_null' => 'require_explicit_mapping',
            'values' => [
                'strategy' => 'require_equal',
                'columns' => ['role_type'],
            ],
            'source_null' => 'preserve',
            'missing' => 'block',
            'ambiguous' => 'block',
        ];
    }

    /**
     * @return array{
     *   array<string,TenantSchemaTableInventory>,
     *   array<string,TenantDataDefinition>
     * }
     */
    private function fixture(
        TenantDataDefinition $membership,
    ): array {
        $roles = new TenantSchemaTableInventory(
            'roles',
            'BASE TABLE',
            ['id', 'system_key', 'role_type'],
            ['id'],
            [],
            [['id'], ['system_key']],
            ['system_key'],
        );
        $membershipTable = new TenantSchemaTableInventory(
            'user_suppliers',
            'BASE TABLE',
            ['user_id', 'supplier_id', 'role_id'],
            ['user_id', 'supplier_id'],
            [
                new TenantSchemaForeignKeyInventory(
                    'role_id',
                    'roles',
                    'id',
                ),
            ],
            [['user_id', 'supplier_id']],
            ['role_id'],
        );
        $roleDefinition = new TenantDataDefinition(
            'table:roles',
            TenantDataObjectKind::Table,
            TenantDataPolicy::InstanceOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'reason' => 'instance_identity_authorization',
                'secrets' => [],
            ],
        );

        return [
            [
                'roles' => $roles,
                'user_suppliers' => $membershipTable,
            ],
            [
                'roles' => $roleDefinition,
                'user_suppliers' => $membership,
            ],
        ];
    }
}
