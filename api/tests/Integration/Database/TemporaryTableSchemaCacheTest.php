<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Database;

use MyInvoice\Infrastructure\Database\SchemaMetadataProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6WorkingStore;
use MyInvoice\Tests\Support\J6CalculatorFactory;
use PHPUnit\Framework\TestCase;

/** Dočasné pracovní tabulky (J6) nesmí zneplatňovat cache struktury databáze. */
final class TemporaryTableSchemaCacheTest extends TestCase
{
    public function testTemporaryTableStatementsAreNotSchemaChanges(): void
    {
        foreach (['CREATE TEMPORARY TABLE x (id INT)', "  /* c */ create temporary table `x` ENGINE=InnoDB AS SELECT 1",
            'DROP TEMPORARY TABLE IF EXISTS x'] as $sql) {
            self::assertFalse(SchemaMetadataProvider::isSchemaStatement($sql), $sql);
        }
        foreach (['CREATE TABLE x (id INT)', 'DROP TABLE x', 'ALTER TABLE x ADD y INT', 'CREATE TEMPORARY_X TABLE'] as $sql) {
            self::assertTrue(SchemaMetadataProvider::isSchemaStatement($sql), $sql);
        }
    }

    public function testWorkingStoreKeepsSchemaGeneration(): void
    {
        $db = J6CalculatorFactory::connection();
        $before = $db->schemaGeneration();
        $store = new J6WorkingStore($db->pdo());
        $store->appendActivity('1', ['evidence' => 'Synthetic'], hash('sha256', 'synthetic'));
        iterator_to_array($store->activities(), false);
        $store->closeAndDelete();
        self::assertSame($before, $db->schemaGeneration());
    }
}
