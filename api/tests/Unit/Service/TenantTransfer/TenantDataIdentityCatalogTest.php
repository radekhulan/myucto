<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaForeignKeyInventory;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataIdentityCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataIdentityCatalogTest extends TestCase
{
    public function testMembershipAndDomainTablesHaveExplicitBoundaries(): void
    {
        $definitions = self::definitions();

        self::assertSame(
            [
                'supplier_domain_login_requests' => [
                    TenantDataPolicy::RuntimeDerived,
                    ['id'],
                ],
                'supplier_domains' => [
                    TenantDataPolicy::RuntimeDerived,
                    ['id'],
                ],
                'user_suppliers' => [
                    TenantDataPolicy::TenantRelation,
                    ['user_id', 'supplier_id'],
                ],
            ],
            array_map(
                static fn (TenantDataDefinition $definition): array => [
                    $definition->policy,
                    $definition->details['primary_key'] ?? null,
                ],
                $definitions,
            ),
        );
    }

    public function testMembershipIsRecreatedOnlyFromConfirmedMappings(): void
    {
        $membership = self::definitions()['user_suppliers'];

        self::assertSame(
            [
                'strategy' => 'supplier_relation',
                'column' => 'supplier_id',
            ],
            $membership->details['ownership'] ?? null,
        );
        self::assertSame(
            [
                'user_id' => [
                    'strategy' => 'map_existing_user_required',
                ],
            ],
            $membership->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
                'role_id' => [
                    'strategy' =>
                        'map_existing_by_natural_key_or_explicit',
                    'natural_key' => ['system_key'],
                    'natural_key_null' => 'require_explicit_mapping',
                    'values' => [
                        'strategy' => 'require_equal',
                        'columns' => ['role_type'],
                    ],
                    'source_null' => 'preserve',
                    'missing' => 'block',
                    'ambiguous' => 'block',
                ],
            ],
            $membership->details['instance_references'] ?? null,
        );
        self::assertSame(
            [
                'strategy' => 'recreate_from_mapped_references',
                'raw_insert' => false,
                'unresolved_row' => 'skip',
                'selection' => 'confirmed_actor_mappings_only',
                'ensure_importing_superadmin' => true,
            ],
            $membership->details['relation_import'] ?? null,
        );
        self::assertSame(
            [
                'force_columns' => ['role' => null],
                'reason' => 'legacy_role_column_not_authoritative',
            ],
            $membership->details['post_import'] ?? null,
        );
    }

    public function testExternalDomainsAndLoginChallengesAreNeverCopied(): void
    {
        $definitions = self::definitions();

        self::assertSame(
            [
                'verification_token' => [
                    'policy' => 'omit_and_reconfigure',
                ],
            ],
            $definitions['supplier_domains']->details['secrets'] ?? null,
        );
        self::assertSame(
            'runtime_external_hostname_routing',
            $definitions['supplier_domains']->details['reason'] ?? null,
        );
        self::assertSame(
            [
                'request_token_hash' => [
                    'policy' => 'omit_and_reconfigure',
                ],
                'state_hash' => [
                    'policy' => 'omit_and_reconfigure',
                ],
                'pkce_challenge' => [
                    'policy' => 'omit_and_reconfigure',
                ],
                'authorization_code_hash' => [
                    'policy' => 'omit_and_reconfigure',
                ],
                'auth_credential_id' => [
                    'policy' => 'not_secret',
                    'reason' => 'foreign_key_identifier_only',
                ],
            ],
            $definitions['supplier_domain_login_requests']
                ->details['secrets'] ?? null,
        );
    }

    public function testCoverageAcceptsMembershipRoleMappingAgainstSchema(): void
    {
        $definitions = self::definitions();
        $registry = new TenantDataRegistry(
            1,
            [
                self::supplier(),
                self::instance('users'),
                self::instance('roles'),
                $definitions['user_suppliers'],
            ],
            [TenantDataRegistry::TRANSFER_PROFILE],
        );

        (new TenantDataRegistryCoverageValidator())->assertComplete(
            $registry,
            [
                new TenantSchemaTableInventory(
                    'supplier',
                    'BASE TABLE',
                    ['id'],
                    ['id'],
                    [],
                    [['id']],
                ),
                new TenantSchemaTableInventory(
                    'users',
                    'BASE TABLE',
                    ['id'],
                    ['id'],
                    [],
                    [['id']],
                ),
                new TenantSchemaTableInventory(
                    'roles',
                    'BASE TABLE',
                    ['id', 'system_key', 'role_type'],
                    ['id'],
                    [],
                    [['id'], ['system_key']],
                    ['system_key'],
                ),
                new TenantSchemaTableInventory(
                    'user_suppliers',
                    'BASE TABLE',
                    [
                        'user_id',
                        'supplier_id',
                        'role',
                        'role_id',
                        'created_at',
                    ],
                    ['user_id', 'supplier_id'],
                    [
                        new TenantSchemaForeignKeyInventory(
                            'user_id',
                            'users',
                            'id',
                        ),
                        new TenantSchemaForeignKeyInventory(
                            'supplier_id',
                            'supplier',
                            'id',
                        ),
                        new TenantSchemaForeignKeyInventory(
                            'role_id',
                            'roles',
                            'id',
                        ),
                    ],
                    [['user_id', 'supplier_id']],
                    ['role', 'role_id'],
                    ['role' => ['accountant', 'readonly']],
                ),
            ],
        );

        self::addToAssertionCount(1);
    }

    public function testFactoryPublishesIdentityCatalogOnlyForTransfer(): void
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

        self::assertContains('table:user_suppliers', $transfer);
        self::assertContains('table:supplier_domains', $transfer);
        self::assertContains(
            'table:supplier_domain_login_requests',
            $transfer,
        );
        self::assertNotContains('table:user_suppliers', $archive);
        self::assertNotContains('table:supplier_domains', $archive);
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataIdentityCatalog::definitions() as $definition) {
            self::assertSame(TenantDataObjectKind::Table, $definition->kind);
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
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

    private static function instance(string $table): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::InstanceOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'reason' => 'instance_identity',
                'secrets' => [],
            ],
        );
    }
}
