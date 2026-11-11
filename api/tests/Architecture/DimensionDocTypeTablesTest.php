<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\DimensionAssignmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Polymorfní `doc_type` přiřazení dimenzí nemá cizí klíč. Obnova archivu podle
 * DOC_TYPE_TABLES překládá `doc_id` na obnovené doklady, takže nový člen ENUMu
 * bez tabulky by jeho přiřazení při obnově tiše vynechal.
 */
final class DimensionDocTypeTablesTest extends TestCase
{
    public function testEveryDocTypeHasHeaderTable(): void
    {
        $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.snapshot.json'), true);
        foreach (['document_dimensions', 'document_dimension_splits'] as $table) {
            $type = (string) $snapshot['tables'][$table]['columns']['doc_type'];
            self::assertSame(1, preg_match("/^enum\\((.*)\\)/", $type, $m), $table);
            $members = array_map(static fn (string $v): string => trim($v, "'"), explode(',', $m[1]));
            self::assertSame($members, DimensionAssignmentRepository::DOC_TYPES, $table);
        }
        self::assertSame(DimensionAssignmentRepository::DOC_TYPES, array_keys(DimensionAssignmentRepository::DOC_TYPE_TABLES));
        foreach (DimensionAssignmentRepository::DOC_TYPE_TABLES as $docType => $table) {
            self::assertArrayHasKey($table, $snapshot['tables'], $docType);
        }
    }
}
