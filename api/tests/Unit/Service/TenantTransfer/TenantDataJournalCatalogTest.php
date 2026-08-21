<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataJournalCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataJournalCatalogTest extends TestCase
{
    public function testJournalSupportDataHaveExplicitLifetimes(): void
    {
        $actual = [];
        foreach (self::definitions() as $table => $definition) {
            $actual[$table] = [
                $definition->policy,
                $definition->details['primary_key'] ?? null,
            ];
        }

        self::assertSame(
            [
                'journal_entry_notes' => [
                    TenantDataPolicy::TenantOwned,
                    ['id'],
                ],
                'journal_entry_template_lines' => [
                    TenantDataPolicy::TenantOwnedIndirect,
                    ['id'],
                ],
                'journal_entry_templates' => [
                    TenantDataPolicy::TenantOwned,
                    ['id'],
                ],
                'journal_integrity_findings' => [
                    TenantDataPolicy::RuntimeDerived,
                    ['id'],
                ],
            ],
            $actual,
        );
    }

    public function testTemplatesPreserveSeedIdentityAndCreator(): void
    {
        $template = self::definitions()['journal_entry_templates'];

        self::assertSame(
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $template->details['ownership'] ?? null,
        );
        self::assertSame([], $template->details['secrets'] ?? null);
        self::assertSame(
            [
                'created_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $template->details['soft_actor_references'] ?? null,
        );
        self::assertSame(
            [
                'seed_key' => [
                    'strategy' => 'application_registry_code',
                    'registry' => 'journal_template_seed_keys',
                    'unknown_value' => 'block',
                    'null_value' => 'preserve',
                ],
            ],
            $template->details['code_references'] ?? null,
        );
        self::assertSame(
            'preserve_seeded_identity_and_user_template_edits',
            $template->details['transfer_invariant'] ?? null,
        );
    }

    public function testTemplateLinesResolveCodesThroughInheritedTenant(): void
    {
        $line = self::definitions()['journal_entry_template_lines'];

        self::assertSame(
            [
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
            $line->details['ownership'] ?? null,
        );
        self::assertSame(
            [
                'account' => self::naturalReference(
                    'account_code',
                    'chart_of_accounts',
                    'account_code',
                    'forbid',
                ),
                'cost_center' => self::naturalReference(
                    'cost_center',
                    'cost_centers',
                    'code',
                    'preserve',
                ),
            ],
            $line->details['natural_key_references'] ?? null,
        );
        self::assertSame(
            'preserve_template_line_order_and_defaults',
            $line->details['transfer_invariant'] ?? null,
        );
    }

    public function testNotesPreserveAuditFieldsAndSoftDeletion(): void
    {
        $note = self::definitions()['journal_entry_notes'];

        self::assertSame(
            [
                'created_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'updated_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $note->details['soft_actor_references'] ?? null,
        );
        self::assertSame(
            'preserve_journal_note_history_and_soft_deletions',
            $note->details['transfer_invariant'] ?? null,
        );
    }

    public function testIntegrityFindingsAreRegenerated(): void
    {
        $finding = self::definitions()['journal_integrity_findings'];

        self::assertSame(
            'runtime_journal_integrity_snapshot',
            $finding->details['reason'] ?? null,
        );
        self::assertSame([], $finding->details['secrets'] ?? null);
    }

    public function testCostCentersExposeStableImportIdentity(): void
    {
        $costCenter = self::factoryDefinition('table:cost_centers');

        self::assertSame(
            [
                'strategy' => 'tenant_natural_key',
                'keys' => ['supplier_id', 'code'],
                'missing_row' => 'create_with_mapped_tenant',
                'existing_row' => 'reuse_target_id_and_apply_source',
            ],
            $costCenter->details['import_identity'] ?? null,
        );
    }

    public function testFactoryPublishesJournalSupportOnlyForTransfer(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $transfer = self::keys(
            $registry,
            TenantDataRegistry::TRANSFER_PROFILE,
        );
        $archive = self::keys(
            $registry,
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
        );

        foreach (array_keys(self::definitions()) as $table) {
            self::assertContains('table:' . $table, $transfer);
            self::assertNotContains('table:' . $table, $archive);
        }
    }

    /**
     * @return array{
     *   strategy:string,
     *   source_scope:array{strategy:string},
     *   source_columns:list<string>,
     *   target_table:string,
     *   target_scope_column:string,
     *   target_columns:list<string>,
     *   null_value:string,
     *   unresolved:string
     * }
     */
    private static function naturalReference(
        string $sourceColumn,
        string $targetTable,
        string $targetColumn,
        string $nullValue,
    ): array {
        return [
            'strategy' => 'tenant_natural_key',
            'source_scope' => [
                'strategy' => 'ownership_tenant',
            ],
            'source_columns' => [$sourceColumn],
            'target_table' => $targetTable,
            'target_scope_column' => 'supplier_id',
            'target_columns' => [$targetColumn],
            'null_value' => $nullValue,
            'unresolved' => 'block',
        ];
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataJournalCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            self::assertSame(
                'accounting',
                $definition->details['feature_group'] ?? null,
            );
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }

    private static function factoryDefinition(
        string $key,
    ): TenantDataDefinition {
        foreach (
            TenantDataRegistryFactory::draftV1()->definitionsFor(
                TenantDataRegistry::TRANSFER_PROFILE,
            ) as $definition
        ) {
            if ($definition->key === $key) {
                return $definition;
            }
        }
        self::fail('Chybí definice ' . $key . '.');
    }

    /** @return list<string> */
    private static function keys(
        TenantDataRegistry $registry,
        string $profile,
    ): array {
        return array_map(
            static fn (TenantDataDefinition $definition): string =>
                $definition->key,
            $registry->definitionsFor($profile),
        );
    }
}
