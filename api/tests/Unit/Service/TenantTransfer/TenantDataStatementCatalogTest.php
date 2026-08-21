<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataStatementCatalog;
use PHPUnit\Framework\TestCase;

final class TenantDataStatementCatalogTest extends TestCase
{
    public function testGlobalStatementDefinitionsUseStableApplicationKeys(): void
    {
        $expected = [
            'statement_account_map' => [
                ['id'],
                [
                    'version_id',
                    'row_code',
                    'account_prefix',
                    'target',
                    'balance_condition',
                ],
                ['sign'],
            ],
            'statement_rows' => [
                ['id'],
                ['version_id', 'row_code'],
                [
                    'parent_row_code',
                    'section',
                    'label',
                    'level',
                    'position',
                    'row_type',
                    'calc_key',
                ],
            ],
            'statement_versions' => [
                ['id'],
                ['statement_type', 'version_code'],
                ['valid_from', 'valid_to'],
            ],
        ];

        $definitions = self::definitions();
        foreach ($expected as $table => [$primaryKey, $keys, $values]) {
            $definition = $definitions[$table];
            self::assertSame(
                TenantDataPolicy::GlobalReference,
                $definition->policy,
            );
            self::assertSame(
                $primaryKey,
                $definition->details['primary_key'] ?? null,
            );
            self::assertSame(
                'accounting',
                $definition->details['feature_group'] ?? null,
            );
            self::assertSame([], $definition->details['secrets'] ?? null);
            self::assertSame(
                [
                    'strategy' => 'natural_key',
                    'keys' => $keys,
                    'values' => [
                        'strategy' => 'require_equal',
                        'columns' => $values,
                    ],
                ],
                $definition->details['mapping'] ?? null,
            );
        }
    }

    public function testTenantStatementChoicesAndNotesArePreserved(): void
    {
        $definitions = self::definitions();
        $expected = [
            'statement_function_map' => [
                'created_by',
                'statement_function_codes',
                'preserve_statement_function_mapping',
            ],
            'statement_notes' => [
                'updated_by',
                'statement_note_sections',
                'preserve_financial_statement_notes',
            ],
        ];

        foreach ($expected as $table => [$actor, $registry, $invariant]) {
            $definition = $definitions[$table];
            $codeColumn = $table === 'statement_function_map'
                ? 'function_code'
                : 'section_key';
            self::assertSame(
                TenantDataPolicy::TenantOwned,
                $definition->policy,
            );
            self::assertSame(['id'], $definition->details['primary_key'] ?? null);
            self::assertSame(
                'accounting',
                $definition->details['feature_group'] ?? null,
            );
            self::assertSame(
                [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                $definition->details['ownership'] ?? null,
            );
            self::assertSame([], $definition->details['secrets'] ?? null);
            self::assertSame(
                [
                    $actor => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                ],
                $definition->details['soft_actor_references'] ?? null,
            );
            self::assertSame(
                [
                    $codeColumn => [
                        'strategy' => 'application_registry_code',
                        'registry' => $registry,
                        'unknown_value' => 'block',
                        'null_value' => 'forbid',
                    ],
                ],
                $definition->details['code_references'] ?? null,
            );
            self::assertSame(
                $invariant,
                $definition->details['transfer_invariant'] ?? null,
            );
        }
    }

    public function testFactoryPublishesStatementsOnlyForTenantTransfer(): void
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

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataStatementCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        self::assertSame(
            [
                'statement_account_map',
                'statement_function_map',
                'statement_notes',
                'statement_rows',
                'statement_versions',
            ],
            array_keys($definitions),
        );
        return $definitions;
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
