<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MyuctoImporterMappingTest extends TestCase
{
    public function testClassificationRemapsMovementAndActor(): void
    {
        $reflection = new ReflectionClass(MyuctoImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $schema = ['columns' => ['de_movement_classification' => ['supplier_id' => []]], 'foreignKeyRows' => []];
        $row = $reflection->getMethod('remap')->invoke(
            $importer,
            'de_movement_classification',
            ['id' => 21, 'supplier_id' => 1, 'source_type' => 'bank', 'bank_transaction_id' => 12,
                'cash_document_id' => null, 'classified_by' => 99, 'tax_bucket' => 'private'],
            ['de_movement_classification' => [21 => 41], 'bank_transactions' => [12 => 32], 'supplier' => [1 => 4]],
            7,
            $schema
        );
        self::assertSame(32, $row['bank_transaction_id']);
        self::assertSame(7, $row['classified_by']);
        self::assertSame('private', $row['tax_bucket']);
        self::assertNull($row['cash_document_id']);
    }

    public function testHistoryRemapsBothMovementTypesWithoutCurrentClassification(): void
    {
        $reflection = new ReflectionClass(MyuctoImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $schema = ['columns' => ['de_movement_classification_history' => ['supplier_id' => []]], 'foreignKeyRows' => []];
        foreach (['bank' => 'bank_transactions', 'cash' => 'cash_documents'] as $type => $table) {
            $row = $reflection->getMethod('remap')->invoke(
                $importer,
                'de_movement_classification_history',
                ['id' => 22, 'supplier_id' => 1, 'source_type' => $type, 'source_id' => 12,
                    'changed_by' => 99, 'previous_tax_bucket' => 'private', 'new_tax_bucket' => null],
                ['de_movement_classification_history' => [22 => 42], $table => [12 => 32], 'supplier' => [1 => 4]],
                7,
                $schema
            );
            self::assertSame(32, $row['source_id']);
            self::assertSame(7, $row['changed_by']);
            self::assertNull($row['new_tax_bucket']);
        }
    }

    public function testOwnerWithoutPhysicalForeignKeyIsMapped(): void
    {
        $reflection = new ReflectionClass(MyuctoImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $schema = ['columns' => ['bank_statements' => ['supplier_id' => []]], 'foreignKeyRows' => []];
        $row = $reflection->getMethod('remap')->invoke($importer, 'bank_statements',
            ['id' => 11, 'supplier_id' => 1], ['bank_statements' => [11 => 31], 'supplier' => [1 => 4]], 1, $schema);
        self::assertSame(4, $row['supplier_id']);
    }

    public function testBankFingerprintMovesToTargetTenantAndPreservesPortableIdentity(): void
    {
        $reflection = new ReflectionClass(MyuctoImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $portable = hash('sha256', 'synthetic-bank-transaction');
        $schema = ['columns' => ['bank_transactions' => []], 'foreignKeyRows' => []];
        $row = $reflection->getMethod('remap')->invoke($importer, 'bank_transactions',
            ['id' => 12, 'import_fingerprint' => hash('sha256', 'supplier:1:' . $portable), 'portable_fingerprint' => $portable],
            ['bank_transactions' => [12 => 32], 'supplier' => [1 => 4]], 1, $schema);
        self::assertSame(hash('sha256', 'supplier:4:' . $portable), $row['import_fingerprint']);
        self::assertSame($portable, $row['portable_fingerprint']);
    }
}
