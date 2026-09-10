<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Stock;

use MyInvoice\Service\Accounting\Archive\ArchiveRestoreService;
use PHPUnit\Framework\TestCase;

final class OpeningStockImportArchiveRemapTest extends TestCase
{
    public function testOpeningIdentityInternalIdUsesRestoredDocumentLine(): void
    {
        $restore = (new \ReflectionClass(ArchiveRestoreService::class))->newInstanceWithoutConstructor();
        $maps = new \ReflectionProperty(ArchiveRestoreService::class, 'maps');
        $maps->setValue($restore, ['stock_document_lines' => [41 => 9041]]);
        (new \ReflectionProperty(ArchiveRestoreService::class, 'fkGraph'))->setValue($restore, []);
        (new \ReflectionProperty(ArchiveRestoreService::class, 'generated'))->setValue($restore, []);
        $method = new \ReflectionMethod(ArchiveRestoreService::class, 'buildInsert');
        $warnings = [];
        [$columns, $values] = $method->invokeArgs($restore, [
            'external_entity_map',
            ['supplier_id' => 8, 'entity_type' => 'stock_opening_line', 'internal_id' => 41],
            9,
            ['stock_document_lines' => true],
            &$warnings,
        ]);
        self::assertSame(9041, $values[array_search('internal_id', $columns, true)]);
    }
}
