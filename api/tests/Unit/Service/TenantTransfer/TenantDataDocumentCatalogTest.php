<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDocumentCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataDocumentCatalogTest extends TestCase
{
    public function testDocumentBoundaryHasExplicitPoliciesAndPrimaryKeys(): void
    {
        $expected = [
            'document_dms_messages' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['id'],
            ],
            'document_embeddings' => [
                TenantDataPolicy::RuntimeDerived,
                ['id'],
            ],
            'document_files' => [TenantDataPolicy::TenantOwned, ['id']],
            'document_folders' => [TenantDataPolicy::TenantOwned, ['id']],
            'document_links' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['document_id', 'entity_type', 'entity_id'],
            ],
            'document_tag_map' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['document_id', 'tag_id'],
            ],
            'document_tags' => [TenantDataPolicy::TenantOwned, ['id']],
            'documents' => [TenantDataPolicy::TenantOwned, ['id']],
        ];

        $actual = [];
        foreach (self::tables() as $table => $definition) {
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

    public function testIndirectDocumentRowsFollowPhysicalAndSoftTenantPath(): void
    {
        $definitions = self::tables();
        $path = [
            [
                'from_column' => 'document_id',
                'to_table' => 'documents',
                'to_column' => 'id',
            ],
            [
                'from_column' => 'supplier_id',
                'to_table' => 'supplier',
                'to_column' => 'id',
                'reference' => 'soft',
            ],
        ];

        foreach ([
            'document_dms_messages',
            'document_links',
            'document_tag_map',
        ] as $table) {
            self::assertSame(
                ['strategy' => 'foreign_key_path', 'path' => $path],
                $definitions[$table]->details['ownership'] ?? null,
            );
        }
    }

    public function testUserScopedDocumentsRequireMappedOwner(): void
    {
        $documents = self::tables()['documents'];

        self::assertSame(
            [
                'uploaded_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'owner_user_id' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $documents->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
                'deleted_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $documents->details['soft_actor_references'] ?? null,
        );
        self::assertSame(
            [
                'owner_user_id' => [
                    'strategy' => 'map_existing_user_required_when',
                    'condition' => [
                        'column' => 'scope',
                        'operator' => 'equals',
                        'value' => 'user',
                    ],
                ],
            ],
            $documents->details['conditional_actor_references'] ?? null,
        );
    }

    public function testPolymorphicLinksHaveClosedTargetMap(): void
    {
        self::assertSame(
            [
                'entity' => [
                    'strategy' => 'polymorphic_tenant_entity',
                    'type_column' => 'entity_type',
                    'id_column' => 'entity_id',
                    'unknown_value' => 'block',
                    'targets' => [
                        'client' => 'clients',
                        'invoice' => 'invoices',
                        'purchase_invoice' => 'purchase_invoices',
                        'project' => 'projects',
                        'journal_entry' => 'journal_entries',
                        'bank_transaction' => 'bank_transactions',
                    ],
                ],
            ],
            self::tables()['document_links']->details['soft_references']
                ?? null,
        );
    }

    public function testDocumentFilesAreRehashedAndThumbnailsRegenerated(): void
    {
        $definitions = self::tables();

        self::assertSame(
            [
                'column_transforms' => ['filename' => 'verified_sha256'],
                'force_columns' => [
                    'thumb_path' => null,
                    'thumb_status' => 'none',
                ],
                'reason' =>
                    'document_files_reverified_and_thumbnails_regenerated',
            ],
            $definitions['documents']->details['post_import'] ?? null,
        );
        self::assertSame(
            [
                'column_transforms' => ['filename' => 'verified_sha256'],
                'reason' => 'document_files_reverified',
            ],
            $definitions['document_files']->details['post_import'] ?? null,
        );
        self::assertSame(
            'runtime_document_search_embeddings',
            $definitions['document_embeddings']->details['reason'] ?? null,
        );
    }

    public function testContentAddressedFileAreaIsRuntimePathBound(): void
    {
        $definition = self::files();

        self::assertSame(TenantDataObjectKind::FileArea, $definition->kind);
        self::assertSame(
            [
                'base' => 'runtime_paths.storage',
                'relative_root' => 'documents',
                'path_strategy' => 'template_from_columns',
                'path_template' =>
                    'sup-{supplier_id}/{sha256_prefix_2}/{filename}',
                'row_references' => [
                    [
                        'table' => 'documents',
                        'columns' => ['supplier_id', 'sha256', 'filename'],
                        'include_when' => 'all_rows',
                    ],
                    [
                        'table' => 'document_files',
                        'columns' => ['supplier_id', 'sha256', 'filename'],
                        'include_when' => 'deleted_at_is_null',
                    ],
                ],
                'require_relative_path' => true,
                'containment' => 'case_insensitive',
                'outside_symlink' => 'reject',
            ],
            $definition->details['source'] ?? null,
        );
        self::assertSame(
            [
                'strategy' => 'content_addressed_from_verified_sha256',
                'template' => 'documents/sup-{supplier_id}'
                    . '/{sha256_prefix_2}/{sha256}',
                'posix_mode' => '0600',
                'windows_acl' => 'owner_only',
            ],
            $definition->details['target'] ?? null,
        );
        self::assertSame(
            [
                'content_hash' => 'sha256',
                'hash_column' => 'sha256',
                'size_column' => 'size_bytes',
                'filename_policy' => 'normalize_to_verified_sha256',
                'deduplicate' => true,
                'payload' => 'end_to_end_file_section',
            ],
            $definition->details['validation'] ?? null,
        );
    }

    public function testFactoryPublishesDocumentsOnlyForTransfer(): void
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

        self::assertContains('table:documents', $transfer);
        self::assertContains('table:document_files', $transfer);
        self::assertContains('file_area:documents', $transfer);
        self::assertNotContains('table:documents', $archive);
        self::assertNotContains('file_area:documents', $archive);
    }

    /** @return array<string,TenantDataDefinition> */
    private static function tables(): array
    {
        $definitions = [];
        foreach (TenantDataDocumentCatalog::definitions() as $definition) {
            if ($definition->kind !== TenantDataObjectKind::Table) {
                continue;
            }
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }

    private static function files(): TenantDataDefinition
    {
        foreach (TenantDataDocumentCatalog::definitions() as $definition) {
            if ($definition->key === 'file_area:documents') {
                return $definition;
            }
        }
        self::fail('V dokumentovém katalogu chybí souborová oblast.');
    }
}
