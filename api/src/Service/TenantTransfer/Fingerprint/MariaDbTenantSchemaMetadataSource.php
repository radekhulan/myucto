<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kanonický read-only popis registrovaných tabulek z MariaDB information_schema.
 *
 * @phpstan-type SchemaTable array{
 *   name:string,
 *   table_type:string,
 *   engine:?string,
 *   collation:?string,
 *   columns:list<array<string,mixed>>,
 *   indexes:list<array<string,mixed>>,
 *   foreign_keys:list<array<string,mixed>>,
 *   checks:list<array<string,mixed>>
 * }
 */
final class MariaDbTenantSchemaMetadataSource implements TenantSchemaMetadataSource
{
    public function __construct(private readonly Connection $db) {}

    public function inventory(): array
    {
        try {
            $pdo = $this->mariaDb();
            $rows = $this->rows(
                $pdo,
                'SELECT t.TABLE_NAME, t.TABLE_TYPE, c.COLUMN_NAME,
                        c.ORDINAL_POSITION, c.IS_NULLABLE, c.COLUMN_TYPE
                   FROM information_schema.TABLES t
                   JOIN information_schema.COLUMNS c
                     ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
                    AND c.TABLE_NAME = t.TABLE_NAME
                  WHERE t.TABLE_SCHEMA = DATABASE()
                  ORDER BY t.TABLE_NAME, c.ORDINAL_POSITION',
                [],
            );
            $tables = [];
            foreach ($rows as $row) {
                $name = self::text($row, 'TABLE_NAME');
                $type = self::text($row, 'TABLE_TYPE');
                if (!isset($tables[$name])) {
                    $tables[$name] = [
                        'type' => $type,
                        'columns' => [],
                        'primary_key' => [],
                        'foreign_keys' => [],
                        'unique_keys' => [],
                        'nullable_columns' => [],
                        'enum_values' => [],
                    ];
                } elseif ($tables[$name]['type'] !== $type) {
                    throw new TenantSchemaUnavailable(
                        'invalid_schema_inventory',
                        'Databázové schéma vrátilo nejednotný typ tabulky.',
                    );
                }
                $column = self::text($row, 'COLUMN_NAME');
                $tables[$name]['columns'][] = $column;
                if (self::yesNo($row, 'IS_NULLABLE')) {
                    $tables[$name]['nullable_columns'][] = $column;
                }
                $enumValues = self::enumValues(
                    self::text($row, 'COLUMN_TYPE'),
                );
                if ($enumValues !== null) {
                    $tables[$name]['enum_values'][$column] = $enumValues;
                }
            }
            if ($tables === []) {
                throw new TenantSchemaUnavailable(
                    'empty_schema_inventory',
                    'Databázové schéma neobsahuje žádné tabulky.',
                );
            }

            $primaryRows = $this->rows(
                $pdo,
                "SELECT TABLE_NAME, COLUMN_NAME, SEQ_IN_INDEX
                   FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND INDEX_NAME = 'PRIMARY'
                  ORDER BY TABLE_NAME, SEQ_IN_INDEX",
                [],
            );
            foreach ($primaryRows as $row) {
                $name = self::text($row, 'TABLE_NAME');
                $column = self::text($row, 'COLUMN_NAME');
                if (!isset($tables[$name])
                    || !in_array($column, $tables[$name]['columns'], true)
                ) {
                    throw new TenantSchemaUnavailable(
                        'invalid_schema_inventory',
                        'Primární klíč databázové inventury odkazuje na neznámý sloupec.',
                    );
                }
                $tables[$name]['primary_key'][] = $column;
            }

            $uniqueRows = $this->rows(
                $pdo,
                "SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
                   FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND NON_UNIQUE = 0
                  ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
                [],
            );
            $uniqueIndexes = [];
            foreach ($uniqueRows as $row) {
                $name = self::text($row, 'TABLE_NAME');
                $index = self::text($row, 'INDEX_NAME');
                $column = self::text($row, 'COLUMN_NAME');
                if (!isset($tables[$name])
                    || !in_array($column, $tables[$name]['columns'], true)
                ) {
                    throw new TenantSchemaUnavailable(
                        'invalid_schema_inventory',
                        'Unikátní klíč databázové inventury odkazuje na neznámý sloupec.',
                    );
                }
                $uniqueIndexes[$name][$index][] = $column;
            }
            foreach ($uniqueIndexes as $name => $indexes) {
                $seenKeys = [];
                foreach ($indexes as $columns) {
                    $signature = implode("\0", $columns);
                    if (isset($seenKeys[$signature])) {
                        continue;
                    }
                    $seenKeys[$signature] = true;
                    $tables[$name]['unique_keys'][] = $columns;
                }
            }

            $foreignRows = $this->rows(
                $pdo,
                'SELECT DISTINCT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME,
                        REFERENCED_COLUMN_NAME
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE CONSTRAINT_SCHEMA = DATABASE()
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                  ORDER BY TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME,
                           REFERENCED_COLUMN_NAME',
                [],
            );
            foreach ($foreignRows as $row) {
                $name = self::text($row, 'TABLE_NAME');
                if (!isset($tables[$name])) {
                    throw new TenantSchemaUnavailable(
                        'invalid_schema_inventory',
                        'Cizí klíč databázové inventury odkazuje z neznámé tabulky.',
                    );
                }
                $tables[$name]['foreign_keys'][] = new TenantSchemaForeignKeyInventory(
                    self::text($row, 'COLUMN_NAME'),
                    self::text($row, 'REFERENCED_TABLE_NAME'),
                    self::text($row, 'REFERENCED_COLUMN_NAME'),
                );
            }

            $inventory = [];
            foreach ($tables as $name => $table) {
                if (!isset(
                    $table['type'],
                    $table['columns'],
                    $table['primary_key'],
                    $table['foreign_keys'],
                    $table['nullable_columns'],
                    $table['enum_values'],
                )) {
                    throw new TenantSchemaUnavailable(
                        'invalid_schema_inventory',
                        'Databázová inventura tabulky není úplná.',
                    );
                }
                $inventory[] = new TenantSchemaTableInventory(
                    $name,
                    $table['type'],
                    $table['columns'],
                    $table['primary_key'],
                    $table['foreign_keys'],
                    $table['unique_keys'],
                    $table['nullable_columns'],
                    $table['enum_values'],
                );
            }
            return $inventory;
        } catch (TenantSchemaUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new TenantSchemaUnavailable(
                'schema_inventory_unavailable',
                'Inventura databázového schématu není dostupná.',
                $exception,
            );
        }
    }

    public function describe(array $tableNames): array
    {
        if ($tableNames === [] || count(array_unique($tableNames, SORT_STRING)) !== count($tableNames)) {
            throw new TenantSchemaUnavailable(
                'invalid_registered_tables',
                'Seznam registrovaných tabulek není platný.',
            );
        }
        sort($tableNames, SORT_STRING);

        try {
            $pdo = $this->mariaDb();

            $placeholders = implode(', ', array_fill(0, count($tableNames), '?'));
            $tables = $this->emptyTables($pdo, $placeholders, $tableNames);
            $tables = $this->appendColumns($pdo, $placeholders, $tableNames, $tables);
            $tables = $this->appendIndexes($pdo, $placeholders, $tableNames, $tables);
            $tables = $this->appendForeignKeys($pdo, $placeholders, $tableNames, $tables);
            $tables = $this->appendChecks($pdo, $placeholders, $tableNames, $tables);

            return array_values($tables);
        } catch (TenantSchemaUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new TenantSchemaUnavailable(
                'schema_metadata_unavailable',
                'Metadata registrovaného tenantového schématu nejsou dostupná.',
                $exception,
            );
        }
    }

    private function mariaDb(): PDO
    {
        $pdo = $this->db->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new TenantSchemaUnavailable(
                'unsupported_database',
                'Fingerprint tenantového schématu vyžaduje MariaDB.',
            );
        }
        $versionStatement = $pdo->query('SELECT VERSION()');
        $serverVersion = $versionStatement === false
            ? false
            : $versionStatement->fetchColumn();
        if (!is_string($serverVersion)
            || !str_contains(strtolower($serverVersion), 'mariadb')
        ) {
            throw new TenantSchemaUnavailable(
                'unsupported_database',
                'Fingerprint tenantového schématu vyžaduje MariaDB.',
            );
        }
        return $pdo;
    }

    /**
     * @param list<string> $tableNames
     * @return array<string,SchemaTable>
     */
    private function emptyTables(PDO $pdo, string $placeholders, array $tableNames): array
    {
        $rows = $this->rows(
            $pdo,
            "SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ({$placeholders})
              ORDER BY TABLE_NAME",
            $tableNames,
        );
        $tables = [];
        foreach ($rows as $row) {
            $name = self::text($row, 'TABLE_NAME');
            if (isset($tables[$name])) {
                throw new TenantSchemaUnavailable(
                    'duplicate_schema_table',
                    'Databázové schéma vrátilo duplicitní tabulku.',
                );
            }
            $tables[$name] = [
                'name' => $name,
                'table_type' => self::text($row, 'TABLE_TYPE'),
                'engine' => self::nullableText($row, 'ENGINE'),
                'collation' => self::nullableText($row, 'TABLE_COLLATION'),
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => [],
                'checks' => [],
            ];
        }
        if (array_keys($tables) !== $tableNames) {
            throw new TenantSchemaUnavailable(
                'registered_table_missing',
                'Některá registrovaná tenantová tabulka v databázi chybí.',
            );
        }
        return $tables;
    }

    /**
     * @param list<string> $tableNames
     * @param array<string,SchemaTable> $tables
     * @return array<string,SchemaTable>
     */
    private function appendColumns(
        PDO $pdo,
        string $placeholders,
        array $tableNames,
        array $tables,
    ): array {
        $rows = $this->rows(
            $pdo,
            "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT,
                    IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, CHARACTER_SET_NAME,
                    COLLATION_NAME, EXTRA, GENERATION_EXPRESSION
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ({$placeholders})
              ORDER BY TABLE_NAME, ORDINAL_POSITION",
            $tableNames,
        );
        foreach ($rows as $row) {
            $table = self::knownTable($tables, $row);
            $metadata = $tables[$table];
            $metadata['columns'][] = [
                'name' => self::text($row, 'COLUMN_NAME'),
                'position' => self::integer($row, 'ORDINAL_POSITION'),
                'default' => self::nullableText($row, 'COLUMN_DEFAULT'),
                'nullable' => self::yesNo($row, 'IS_NULLABLE'),
                'data_type' => self::text($row, 'DATA_TYPE'),
                'column_type' => self::text($row, 'COLUMN_TYPE'),
                'character_set' => self::nullableText($row, 'CHARACTER_SET_NAME'),
                'collation' => self::nullableText($row, 'COLLATION_NAME'),
                'extra' => self::text($row, 'EXTRA', allowEmpty: true),
                'generation_expression' => self::text(
                    $row,
                    'GENERATION_EXPRESSION',
                    allowEmpty: true,
                ),
            ];
            $tables[$table] = $metadata;
        }
        foreach ($tables as $table) {
            if ($table['columns'] === []) {
                throw new TenantSchemaUnavailable(
                    'registered_table_has_no_columns',
                    'Registrovaná tenantová tabulka nemá čitelné sloupce.',
                );
            }
        }
        return $tables;
    }

    /**
     * @param list<string> $tableNames
     * @param array<string,SchemaTable> $tables
     * @return array<string,SchemaTable>
     */
    private function appendIndexes(
        PDO $pdo,
        string $placeholders,
        array $tableNames,
        array $tables,
    ): array {
        $rows = $this->rows(
            $pdo,
            "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX,
                    COLUMN_NAME, COLLATION, SUB_PART, NULLABLE, INDEX_TYPE, IGNORED
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ({$placeholders})
              ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            $tableNames,
        );
        foreach ($rows as $row) {
            $table = self::knownTable($tables, $row);
            $metadata = $tables[$table];
            $metadata['indexes'][] = [
                'name' => self::text($row, 'INDEX_NAME'),
                'non_unique' => self::integer($row, 'NON_UNIQUE') === 1,
                'position' => self::integer($row, 'SEQ_IN_INDEX'),
                'column' => self::nullableText($row, 'COLUMN_NAME'),
                'collation' => self::nullableText($row, 'COLLATION'),
                'prefix_length' => self::nullableInteger($row, 'SUB_PART'),
                'nullable' => self::nullableText($row, 'NULLABLE') === 'YES',
                'type' => self::text($row, 'INDEX_TYPE'),
                'ignored' => self::text($row, 'IGNORED') === 'YES',
            ];
            $tables[$table] = $metadata;
        }
        return $tables;
    }

    /**
     * @param list<string> $tableNames
     * @param array<string,SchemaTable> $tables
     * @return array<string,SchemaTable>
     */
    private function appendForeignKeys(
        PDO $pdo,
        string $placeholders,
        array $tableNames,
        array $tables,
    ): array {
        $rows = $this->rows(
            $pdo,
            "SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME,
                    k.ORDINAL_POSITION, k.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                AND r.TABLE_NAME = k.TABLE_NAME
              WHERE k.CONSTRAINT_SCHEMA = DATABASE()
                AND k.TABLE_NAME IN ({$placeholders})
                AND k.REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
            $tableNames,
        );
        foreach ($rows as $row) {
            $table = self::knownTable($tables, $row);
            $metadata = $tables[$table];
            $metadata['foreign_keys'][] = [
                'name' => self::text($row, 'CONSTRAINT_NAME'),
                'column' => self::text($row, 'COLUMN_NAME'),
                'position' => self::integer($row, 'ORDINAL_POSITION'),
                'referenced_table' => self::text($row, 'REFERENCED_TABLE_NAME'),
                'referenced_column' => self::text($row, 'REFERENCED_COLUMN_NAME'),
                'update_rule' => self::text($row, 'UPDATE_RULE'),
                'delete_rule' => self::text($row, 'DELETE_RULE'),
            ];
            $tables[$table] = $metadata;
        }
        return $tables;
    }

    /**
     * @param list<string> $tableNames
     * @param array<string,SchemaTable> $tables
     * @return array<string,SchemaTable>
     */
    private function appendChecks(
        PDO $pdo,
        string $placeholders,
        array $tableNames,
        array $tables,
    ): array {
        $rows = $this->rows(
            $pdo,
            "SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
               FROM information_schema.TABLE_CONSTRAINTS tc
               JOIN information_schema.CHECK_CONSTRAINTS cc
                 ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                AND cc.TABLE_NAME = tc.TABLE_NAME
              WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
                AND tc.TABLE_NAME IN ({$placeholders})
                AND tc.CONSTRAINT_TYPE = 'CHECK'
              ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME",
            $tableNames,
        );
        foreach ($rows as $row) {
            $table = self::knownTable($tables, $row);
            $metadata = $tables[$table];
            $metadata['checks'][] = [
                'name' => self::text($row, 'CONSTRAINT_NAME'),
                'clause' => self::text($row, 'CHECK_CLAUSE'),
            ];
            $tables[$table] = $metadata;
        }
        return $tables;
    }

    /**
     * @param list<string> $parameters
     * @return list<array<string,mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new TenantSchemaUnavailable(
                    'invalid_schema_metadata',
                    'Databáze vrátila neplatná metadata schématu.',
                );
            }
            $normalized = [];
            foreach ($row as $key => $value) {
                if (!is_string($key)) {
                    throw new TenantSchemaUnavailable(
                        'invalid_schema_metadata',
                        'Databáze vrátila neplatná metadata schématu.',
                    );
                }
                $normalized[$key] = $value;
            }
            $result[] = $normalized;
        }
        return $result;
    }

    /**
     * @param array<string,SchemaTable> $tables
     * @param array<string,mixed> $row
     */
    private static function knownTable(array $tables, array $row): string
    {
        $table = self::text($row, 'TABLE_NAME');
        if (!isset($tables[$table])) {
            throw new TenantSchemaUnavailable(
                'unexpected_schema_table',
                'Databáze vrátila metadata neregistrované tabulky.',
            );
        }
        return $table;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field, bool $allowEmpty = false): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || (!$allowEmpty && $value === '')) {
            throw new TenantSchemaUnavailable(
                'invalid_schema_metadata',
                'Databáze vrátila neplatná metadata schématu.',
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableText(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new TenantSchemaUnavailable(
                'invalid_schema_metadata',
                'Databáze vrátila neplatná metadata schématu.',
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new TenantSchemaUnavailable(
            'invalid_schema_metadata',
            'Databáze vrátila neplatná metadata schématu.',
        );
    }

    /** @param array<string,mixed> $row */
    private static function nullableInteger(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : self::integer($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function yesNo(array $row, string $field): bool
    {
        $value = self::text($row, $field);
        if (!in_array($value, ['YES', 'NO'], true)) {
            throw new TenantSchemaUnavailable(
                'invalid_schema_metadata',
                'Databáze vrátila neplatná metadata schématu.',
            );
        }
        return $value === 'YES';
    }

    /** @return list<string>|null */
    private static function enumValues(string $columnType): ?array
    {
        if (preg_match('/^enum\((.*)\)$/isD', $columnType, $matches) !== 1) {
            return null;
        }
        $parsed = str_getcsv($matches[1], ',', "'", '\\');
        $values = [];
        foreach ($parsed as $value) {
            if (!is_string($value)) {
                throw new TenantSchemaUnavailable(
                    'invalid_schema_inventory',
                    'Databázové schéma obsahuje neplatnou definici ENUM.',
                );
            }
            $values[] = $value;
        }
        return $values;
    }
}
