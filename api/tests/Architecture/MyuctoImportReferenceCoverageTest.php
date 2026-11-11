<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Migration\Myucto\MyuctoImportProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Obnova z exportu MyÚčta odmítne celý export, když narazí na vazbu (`*_id`,
 * `*_by`), kterou neumí přemapovat. Nový sloupec s vazbou v převáděné tabulce
 * tak zablokoval obnovu všech exportů z verze, která ho už plní.
 *
 * Každá vazba převáděné tabulky proto musí mít rozhodnutí: cizí klíč ve
 * schématu, EXTRA_REFERENCES, PRESERVED_IDS, POLYMORPHIC_REFERENCES, nebo
 * DETACHED_REFERENCES (nastavení mimo export, obnova ho vynuluje a vykáže).
 */
#[Group('architecture')]
final class MyuctoImportReferenceCoverageTest extends TestCase
{
    public function testEveryReferenceOfImportedTableHasDecision(): void
    {
        $tables = $this->snapshotTables();
        $missing = [];
        foreach (MyuctoImportProfile::TABLES as $table) {
            self::assertArrayHasKey($table, $tables, "Převáděná tabulka {$table} ve schématu není.");
            $foreign = $this->foreignKeyColumns($tables[$table]);
            foreach ($tables[$table]['columns'] as $column => $definition) {
                if ($column === 'id' || $column === 'supplier_id'
                    || !(str_ends_with($column, '_id') || str_ends_with($column, '_by'))
                    || str_contains(strtoupper((string) $definition), 'GENERATED')) {
                    continue;
                }
                if (isset($foreign[$column])
                    || isset(MyuctoImportProfile::EXTRA_REFERENCES[$table][$column])
                    || in_array($column, MyuctoImportProfile::PRESERVED_IDS, true)
                    || in_array($column, MyuctoImportProfile::POLYMORPHIC_REFERENCES[$table] ?? [], true)
                    || in_array($column, MyuctoImportProfile::DETACHED_REFERENCES[$table] ?? [], true)
                    || in_array($column, MyuctoImportProfile::PERIOD_KEYS[$table] ?? [], true)) {
                    continue;
                }
                $missing[] = $table . '.' . $column;
            }
        }

        self::assertSame(
            [],
            $missing,
            'Vazba bez rozhodnutí v MyuctoImportProfile: obnova by export s ní odmítla. Doplňte ji do EXTRA_REFERENCES '
            . '(převáděná tabulka), POLYMORPHIC_REFERENCES (cíl podle druhu dokladu) nebo DETACHED_REFERENCES (nastavení mimo export).',
        );
    }

    public function testDeclaredDecisionsReferToExistingColumnsOfImportedTables(): void
    {
        $tables = $this->snapshotTables();
        foreach (['POLYMORPHIC_REFERENCES' => MyuctoImportProfile::POLYMORPHIC_REFERENCES, 'DETACHED_REFERENCES' => MyuctoImportProfile::DETACHED_REFERENCES,
            'PERIOD_KEYS' => MyuctoImportProfile::PERIOD_KEYS] as $name => $decisions) {
            foreach ($decisions as $table => $columns) {
                self::assertContains($table, MyuctoImportProfile::TABLES, "{$name}: {$table} obnova nepřenáší.");
                foreach ($columns as $column) {
                    self::assertArrayHasKey($column, $tables[$table]['columns'] ?? [], "{$name}: {$table}.{$column} ve schématu není.");
                }
            }
        }
    }

    /**
     * Tabulky bez vlastního `id` mají rozhodnutí v definici KEYLESS_TABLES: vazba na graf,
     * polymorfní vazba, autor (obnova ho přiřadí aktérovi), nebo odpojené nastavení. Jinak by obnova sloupec přenesla se
     * zdrojovým ID, které v cílové instalaci znamená jiný řádek.
     */
    public function testEveryReferenceOfKeylessTableHasDecision(): void
    {
        $tables = $this->snapshotTables();
        $missing = [];
        foreach (MyuctoImportProfile::KEYLESS_TABLES as $table => $definition) {
            self::assertArrayHasKey($table, $tables, "Převáděná tabulka {$table} ve schématu není.");
            foreach ($definition['key'] as $column) {
                self::assertArrayHasKey($column, $tables[$table]['columns'], "{$table}: klíč {$column} ve schématu není.");
            }
            foreach ($tables[$table]['columns'] as $column => $definitionSql) {
                if ($column === 'supplier_id' || !(str_ends_with($column, '_id') || str_ends_with($column, '_by'))
                    || str_contains(strtoupper((string) $definitionSql), 'GENERATED')) {
                    continue;
                }
                if (isset($definition['refs'][$column]) || isset($definition['polymorphic'][$column])
                    || in_array($column, $definition['detached'] ?? [], true) || in_array($column, $definition['users'] ?? [], true)) {
                    continue;
                }
                $missing[] = $table . '.' . $column;
            }
            foreach (array_keys($definition['refs'] ?? []) as $column) {
                self::assertArrayHasKey($column, $tables[$table]['columns'], "{$table}.{$column} ve schématu není.");
            }
        }
        self::assertSame([], $missing, 'Vazba tabulky bez id nemá rozhodnutí v MyuctoImportProfile::KEYLESS_TABLES.');
    }

    public function testDerivedDecisionsAreConsistent(): void
    {
        foreach (MyuctoImportProfile::POLYMORPHIC_TARGETS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                self::assertContains($column, MyuctoImportProfile::POLYMORPHIC_REFERENCES[$table] ?? [], "{$table}.{$column} chybí v POLYMORPHIC_REFERENCES.");
            }
        }
        foreach (MyuctoImportProfile::SOFT_REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                self::assertArrayHasKey($column, MyuctoImportProfile::EXTRA_REFERENCES[$table] ?? [], "Měkká vazba {$table}.{$column} chybí v EXTRA_REFERENCES.");
            }
        }
        foreach (MyuctoImportProfile::TOLERANT_TABLES as $table) {
            self::assertArrayHasKey($table, MyuctoImportProfile::POLYMORPHIC_TARGETS, "Tolerantní tabulka {$table} nemá polymorfní vazbu.");
        }
        foreach (array_keys(MyuctoImportProfile::REPLACED_SETUP_TABLES) as $table) {
            self::assertTrue(in_array($table, MyuctoImportProfile::OPTIONAL_GRAPH_TABLES, true) || isset(MyuctoImportProfile::KEYLESS_TABLES[$table]),
                "Nahrazované výchozí nastavení {$table} obnova nepřenáší.");
        }
        self::assertStringNotContainsString(':', substr(MyuctoImportProfile::VERSION . ':', 0, 16), 'Prvních 16 znaků otisku je v cestě souborů.');
    }

    /** @return array<string,array<string,mixed>> */
    private function snapshotTables(): array
    {
        $snapshot = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return (array) $snapshot['tables'];
    }

    /**
     * @param array<string,mixed> $table
     * @return array<string,true>
     */
    private function foreignKeyColumns(array $table): array
    {
        $columns = [];
        foreach ((array) ($table['foreign_keys'] ?? []) as $definition) {
            if (preg_match('/^\(([^)]+)\)/', (string) $definition, $match) === 1) {
                foreach (explode(',', $match[1]) as $column) {
                    $columns[trim($column)] = true;
                }
            }
        }

        return $columns;
    }
}
