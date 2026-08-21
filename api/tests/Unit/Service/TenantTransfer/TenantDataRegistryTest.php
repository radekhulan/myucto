<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\IncompleteTenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataRegistryTest extends TestCase
{
    public function testCompleteProfileHasStableFingerprintAcrossDefinitionOrder(): void
    {
        $supplier = $this->definition(
            'table:supplier',
            TenantDataPolicy::TenantRoot,
            ['ownership' => ['column' => 'id', 'strategy' => 'selected_supplier']],
        );
        $invoices = $this->definition(
            'table:invoices',
            TenantDataPolicy::TenantOwned,
            ['primary_key' => ['id'], 'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id']],
        );

        $first = new TenantDataRegistry(1, [$supplier, $invoices], [TenantDataRegistry::TRANSFER_PROFILE]);
        $reordered = new TenantDataRegistry(1, [$invoices, $supplier], [TenantDataRegistry::TRANSFER_PROFILE]);

        self::assertSame(
            $first->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE),
            $reordered->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE),
        );
        self::assertSame(
            ['table:invoices', 'table:supplier'],
            array_map(
                static fn (TenantDataDefinition $definition): string => $definition->key,
                $first->definitionsFor(TenantDataRegistry::TRANSFER_PROFILE),
            ),
        );
    }

    public function testChangedPolicyChangesFingerprint(): void
    {
        $owned = new TenantDataRegistry(
            1,
            [$this->definition('table:documents', TenantDataPolicy::TenantOwned, ['ownership' => ['strategy' => 'supplier_id']])],
            [TenantDataRegistry::TRANSFER_PROFILE],
        );
        $unsupported = new TenantDataRegistry(
            1,
            [$this->definition('table:documents', TenantDataPolicy::Unsupported, ['reason' => 'selector_missing'])],
            [TenantDataRegistry::TRANSFER_PROFILE],
        );

        self::assertNotSame(
            $owned->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE),
            $unsupported->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE),
        );
    }

    public function testDraftProfileCannotProduceTransferFingerprint(): void
    {
        $registry = new TenantDataRegistry(
            1,
            [$this->definition('table:supplier', TenantDataPolicy::TenantRoot, [])],
        );

        $this->expectException(IncompleteTenantDataRegistry::class);

        $registry->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE);
    }

    public function testPersonalSecretAttachmentIsExplicitPolicyNotTenantOwnedData(): void
    {
        $credential = $this->definition(
            'table:epo_signing_credentials',
            TenantDataPolicy::PersonalSecretAttachment,
            ['consent' => 'source_and_target_owner', 'default_selected' => false],
        );

        self::assertSame('personal_secret_attachment', $credential->toArray()['policy']);
        self::assertFalse($credential->toArray()['details']['default_selected']);
    }

    public function testEmptyProfileCannotBeMarkedComplete(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TenantDataRegistry(1, [], [TenantDataRegistry::TRANSFER_PROFILE]);
    }

    public function testDraftSupplierRootExplicitlyClassifiesKnownSensitiveColumns(): void
    {
        $definitions = TenantDataRegistryFactory::draftV1()->definitionsFor(
            TenantDataRegistry::TRANSFER_PROFILE,
        );
        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }
        self::assertGreaterThan(50, count($byKey));
        self::assertArrayHasKey('table:supplier', $byKey);
        $supplier = $byKey['table:supplier'];
        $secrets = self::secretDeclarations(
            $supplier->details['secrets'] ?? null,
        );

        $columns = array_keys($secrets);
        sort($columns, SORT_STRING);

        self::assertSame(
            [
                'ai_pseudo_salt',
                'anthropic_api_key_enc',
                'azure_openai_api_key_enc',
                'fakturoid_access_token_enc',
                'fakturoid_access_token_expires_at',
                'fakturoid_api_key_enc',
                'fakturoid_client_secret_enc',
                'gemini_api_key_enc',
                'idoklad_access_token',
                'idoklad_client_secret_enc',
                'idoklad_token_expires_at',
                'openai_api_key_enc',
            ],
            $columns,
        );
        self::assertSame(
            'omit_and_reconfigure',
            $secrets['idoklad_access_token']['policy'] ?? null,
        );
        self::assertSame(
            'omit_and_reconfigure',
            $secrets['fakturoid_access_token_enc']['policy'] ?? null,
        );
        self::assertSame(
            'omit_and_reconfigure',
            $secrets['ai_pseudo_salt']['policy'] ?? null,
        );
    }

    public function testDraftCatalogKeepsDirectIndirectAndDerivedPoliciesDistinct(): void
    {
        $definitions = [];
        foreach (TenantDataRegistryFactory::draftV1()->definitionsFor(
            TenantDataRegistry::TRANSFER_PROFILE,
        ) as $definition) {
            $definitions[$definition->key] = $definition;
        }

        self::assertSame(
            TenantDataPolicy::TenantOwned,
            $definitions['table:invoices']->policy ?? null,
        );
        self::assertSame(
            TenantDataPolicy::TenantOwnedIndirect,
            $definitions['table:invoice_items']->policy ?? null,
        );
        self::assertSame(
            TenantDataPolicy::RuntimeDerived,
            $definitions['table:accounting_archives']->policy ?? null,
        );
        self::assertSame(
            ['stock_item_id', 'category_id'],
            $definitions['table:stock_item_categories']->details['primary_key'] ?? null,
        );
        self::assertSame(
            ['supplier_id', 'warehouse_id', 'stock_item_id'],
            $definitions['table:stock_levels']->details['primary_key'] ?? null,
        );
    }

    public function testDraftClassifiesRemainingRegisteredReferenceTargets(): void
    {
        $definitions = [];
        foreach (TenantDataRegistryFactory::draftV1()->definitionsFor(
            TenantDataRegistry::TRANSFER_PROFILE,
        ) as $definition) {
            $definitions[$definition->key] = $definition;
        }

        self::assertSame(
            TenantDataPolicy::TenantOwnedIndirect,
            $definitions['table:bank_transactions']->policy ?? null,
        );
        self::assertSame(
            [
                'strategy' => 'foreign_key_path',
                'path' => [
                    [
                        'from_column' => 'statement_id',
                        'to_table' => 'bank_statements',
                        'to_column' => 'id',
                    ],
                    [
                        'from_column' => 'supplier_id',
                        'to_table' => 'supplier',
                        'to_column' => 'id',
                        'reference' => 'soft',
                    ],
                ],
            ],
            $definitions['table:bank_transactions']->details['ownership']
                ?? null,
        );
        self::assertSame(
            ['matched_by' => ['strategy' => 'map_existing_user_or_null']],
            $definitions['table:bank_transactions']
                ->details['actor_references'] ?? null,
        );
        self::assertSame(
            TenantDataPolicy::TenantOwned,
            $definitions['table:small_assets']->policy ?? null,
        );
        self::assertSame(
            TenantDataPolicy::TenantOwned,
            $definitions['table:purchase_orders']->policy ?? null,
        );
        self::assertSame(
            [
                'confirmed_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'closed_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'cancelled_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'created_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $definitions['table:purchase_orders']
                ->details['soft_actor_references'] ?? null,
        );
        self::assertSame(
            [
                'submitted_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'generated_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $definitions['table:tax_submissions']
                ->details['soft_actor_references'] ?? null,
        );
    }

    public function testDraftKeepsTransferIncompleteButArchiveProfileComplete(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();

        self::assertFalse($registry->isComplete(
            TenantDataRegistry::TRANSFER_PROFILE,
        ));
        self::assertTrue($registry->isComplete(
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
        ));
        self::assertNotSame(
            '',
            $registry->fingerprintFor(
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
            ),
        );
    }

    /** @param array<string,mixed> $details */
    private function definition(
        string $key,
        TenantDataPolicy $policy,
        array $details,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
    }

    /** @return array<string,array<string,mixed>> */
    private static function secretDeclarations(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $column => $declaration) {
            self::assertIsString($column);
            self::assertIsArray($declaration);
            $fields = [];
            foreach ($declaration as $field => $fieldValue) {
                self::assertIsString($field);
                $fields[$field] = $fieldValue;
            }
            $result[$column] = $fields;
        }
        return $result;
    }
}
