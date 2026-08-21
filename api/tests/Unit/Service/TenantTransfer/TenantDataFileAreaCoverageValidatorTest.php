<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataFileAreaCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class TenantDataFileAreaCoverageValidatorTest extends TestCase
{
    public function testSafeRuntimeFileAreaMatchesRegisteredColumns(): void
    {
        $table = self::documentsTable();
        $definition = self::documentsDefinition();

        self::assertSame(
            [],
            (new TenantDataFileAreaCoverageValidator())->issues(
                self::fileArea(),
                ['documents' => $table],
                ['documents' => $definition],
            ),
        );
    }

    public function testTraversalAndUnknownReferenceColumnFailClosed(): void
    {
        $fileArea = self::fileArea();
        $details = $fileArea->details;
        $source = $details['source'];
        self::assertIsArray($source);
        $source['relative_root'] = '../documents';
        $references = $source['row_references'];
        self::assertIsArray($references);
        self::assertIsArray($references[0] ?? null);
        $references[0]['columns'] = ['supplier_id', 'sha256', 'ghost'];
        $source['row_references'] = $references;
        $details['source'] = $source;
        $fileArea = new TenantDataDefinition(
            $fileArea->key,
            $fileArea->kind,
            $fileArea->policy,
            $fileArea->profiles,
            $details,
        );

        self::assertSame(
            [
                'file_area_reference_column_missing:'
                    . 'file_area:documents.documents.ghost',
                'invalid_file_area_source:file_area:documents',
            ],
            (new TenantDataFileAreaCoverageValidator())->issues(
                $fileArea,
                ['documents' => self::documentsTable()],
                ['documents' => self::documentsDefinition()],
            ),
        );
    }

    public function testDeletedRowsFilterRequiresPhysicalMarkerColumn(): void
    {
        $fileArea = self::fileArea();
        $details = $fileArea->details;
        $source = $details['source'];
        self::assertIsArray($source);
        $references = $source['row_references'];
        self::assertIsArray($references);
        self::assertIsArray($references[0] ?? null);
        $references[0]['include_when'] = 'deleted_at_is_null';
        $source['row_references'] = $references;
        $details['source'] = $source;
        $fileArea = new TenantDataDefinition(
            $fileArea->key,
            $fileArea->kind,
            $fileArea->policy,
            $fileArea->profiles,
            $details,
        );

        self::assertSame(
            [
                'file_area_reference_filter_column_missing:'
                    . 'file_area:documents.documents.deleted_at',
            ],
            (new TenantDataFileAreaCoverageValidator())->issues(
                $fileArea,
                ['documents' => self::documentsTable()],
                ['documents' => self::documentsDefinition()],
            ),
        );
    }

    private static function fileArea(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'file_area:documents',
            TenantDataObjectKind::FileArea,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'source' => [
                    'base' => 'runtime_paths.storage',
                    'relative_root' => 'documents',
                    'path_strategy' => 'template_from_columns',
                    'path_template' =>
                        'sup-{supplier_id}/{sha256_prefix_2}/{filename}',
                    'row_references' => [[
                        'table' => 'documents',
                        'columns' => ['supplier_id', 'sha256', 'filename'],
                        'include_when' => 'all_rows',
                    ]],
                    'require_relative_path' => true,
                    'containment' => 'case_insensitive',
                    'outside_symlink' => 'reject',
                ],
                'target' => [
                    'strategy' => 'content_addressed_from_verified_sha256',
                    'template' => 'documents/sup-{supplier_id}'
                        . '/{sha256_prefix_2}/{sha256}',
                    'posix_mode' => '0600',
                    'windows_acl' => 'owner_only',
                ],
                'validation' => ['content_hash' => 'sha256'],
            ],
        );
    }

    private static function documentsDefinition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:documents',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
            ],
        );
    }

    private static function documentsTable(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'documents',
            'BASE TABLE',
            ['id', 'supplier_id', 'sha256', 'filename'],
            ['id'],
            [],
            [['id']],
        );
    }
}
