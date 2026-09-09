<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\SchemaMetadataProvider;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaMetadataProviderTest extends TestCase
{
    public function testBulkReadsPreserveOrderAndGroupConstraintsByTable(): void
    {
        $tables = array_map(static fn ($table) => ['TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE'], ['parent', 'single', 'composite']);
        $columns = [];
        foreach (['parent' => ['id'], 'single' => ['id', 'parent_id', 'alternate_id'], 'composite' => ['id', 'parent_id', 'second_id']] as $table => $names) {
            foreach ($names as $index => $name) {
                $columns[] = [
                    'TABLE_NAME' => $table, 'COLUMN_NAME' => $name, 'DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'int(11)',
                    'IS_NULLABLE' => $name === 'id' ? 'NO' : 'YES', 'COLUMN_KEY' => $name === 'id' ? 'PRI' : '',
                    'EXTRA' => $name === 'id' ? 'auto_increment' : '', 'GENERATION_EXPRESSION' => null,
                    'ORDINAL_POSITION' => $index + 1, 'COLUMN_DEFAULT' => null,
                ];
            }
        }
        $rows = [
            ['TABLE_NAME' => 'single', 'CONSTRAINT_NAME' => 'alternate_symbol', 'COLUMN_NAME' => 'alternate_id', 'REFERENCED_TABLE_NAME' => 'parent', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 1],
            ['TABLE_NAME' => 'composite', 'CONSTRAINT_NAME' => 'same_symbol', 'COLUMN_NAME' => 'parent_id', 'REFERENCED_TABLE_NAME' => 'parent', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 1],
            ['TABLE_NAME' => 'single', 'CONSTRAINT_NAME' => 'same_symbol', 'COLUMN_NAME' => 'parent_id', 'REFERENCED_TABLE_NAME' => 'parent', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 1],
            ['TABLE_NAME' => 'composite', 'CONSTRAINT_NAME' => 'same_symbol', 'COLUMN_NAME' => 'second_id', 'REFERENCED_TABLE_NAME' => 'parent', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 2],
        ];
        $pdo = $this->createMock(PDO::class);
        $sequence = [$tables, $columns, $rows];
        $pdo->expects(self::exactly(3))->method('query')->willReturnCallback(function (string $sql) use (&$sequence): PDOStatement {
            self::assertStringNotContainsString('JOIN', strtoupper($sql));
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetchAll')->willReturn(array_shift($sequence));
            return $statement;
        });
        $snapshot = SchemaMetadataProvider::load($pdo);
        self::assertTrue(SchemaMetadataProvider::validSnapshot($snapshot));
        self::assertSame($rows, $snapshot['foreignKeyRows']);
        self::assertSame(['single' => [
            ['column' => 'parent_id', 'refTable' => 'parent', 'refColumn' => 'id', 'nullable' => true],
            ['column' => 'alternate_id', 'refTable' => 'parent', 'refColumn' => 'id', 'nullable' => true],
        ]], $snapshot['foreignKeys']);
        self::assertSame(['cols' => ['id'], 'autoInc' => 'id'], $snapshot['primaryKeys']['single']);
        self::assertSame(['id', 'parent_id', 'second_id'], array_keys($snapshot['columns']['composite']));
    }

    public function testFailedMetadataQueryDoesNotBecomeAnEmptySnapshot(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('query')->willReturn(false);
        $this->expectException(RuntimeException::class);
        SchemaMetadataProvider::load($pdo);
    }

    public function testEmptyDatabaseIsNotAValidExportSnapshot(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);
        $pdo->expects(self::exactly(3))->method('query')->willReturn($statement);
        $this->expectException(RuntimeException::class);
        SchemaMetadataProvider::load($pdo);
    }
}
