<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;
use RuntimeException;

final class SchemaMetadataProvider
{
    private static int $generation = 0;

    public static function invalidate(): void
    {
        ++self::$generation;
    }

    public static function isSchemaStatement(string $sql): bool
    {
        return preg_match('/\A(?:\s+|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*(?:CREATE|ALTER|DROP|RENAME|TRUNCATE|USE)\b/is', $sql) === 1;
    }

    public static function generation(): int
    {
        return self::$generation;
    }

    public static function validSnapshot(array $snapshot): bool
    {
        foreach (['tables', 'columns', 'primaryKeys', 'foreignKeys', 'foreignKeyRows'] as $key) {
            if (!is_array($snapshot[$key] ?? null)) {
                return false;
            }
        }
        if ($snapshot['tables'] === []) {
            return false;
        }
        foreach ($snapshot['tables'] as $table => $type) {
            if (!is_string($table) || !is_string($type) || !is_array($snapshot['columns'][$table] ?? null)
                || $snapshot['columns'][$table] === []) {
                return false;
            }
            foreach ($snapshot['columns'][$table] as $column => $row) {
                if (!is_array($row) || ($row['COLUMN_NAME'] ?? null) !== $column || ($row['TABLE_NAME'] ?? null) !== $table) {
                    return false;
                }
                foreach (['DATA_TYPE', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_KEY', 'EXTRA'] as $field) {
                    if (!is_string($row[$field] ?? null)) {
                        return false;
                    }
                }
            }
        }
        foreach ($snapshot['foreignKeys'] as $table => $keys) {
            if (!is_array($keys)) {
                return false;
            }
            foreach ($keys as $key) {
                if (!is_array($key) || !is_string($key['column'] ?? null) || !is_string($key['refTable'] ?? null)
                    || !is_string($key['refColumn'] ?? null) || !is_bool($key['nullable'] ?? null)) {
                    return false;
                }
            }
        }
        foreach ($snapshot['primaryKeys'] as $key) {
            if (!is_array($key) || !is_array($key['cols'] ?? null)
                || !array_key_exists('autoInc', $key) || ($key['autoInc'] !== null && !is_string($key['autoInc']))) {
                return false;
            }
            foreach ($key['cols'] as $column) {
                if (!is_string($column)) {
                    return false;
                }
            }
        }
        foreach ($snapshot['foreignKeyRows'] as $row) {
            if (!is_array($row)) {
                return false;
            }
            foreach (['TABLE_NAME', 'CONSTRAINT_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME'] as $field) {
                if (!is_string($row[$field] ?? null)) {
                    return false;
                }
            }
        }
        return true;
    }

    public static function load(PDO $pdo): array
    {
        $tables = [];
        $columns = [];
        $primaryKeys = [];
        $foreignKeys = [];
        foreach (self::rows($pdo, 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $row) {
            $tables[$row['TABLE_NAME']] = $row['TABLE_TYPE'];
        }
        foreach (self::rows($pdo, 'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, GENERATION_EXPRESSION, ORDINAL_POSITION, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $row) {
            $table = $row['TABLE_NAME'];
            $column = $row['COLUMN_NAME'];
            $columns[$table][$column] = $row;
            if ($row['COLUMN_KEY'] === 'PRI') {
                $primaryKeys[$table] ??= ['cols' => [], 'autoInc' => null];
                $primaryKeys[$table]['cols'][] = $column;
                if (str_contains(strtolower((string) $row['EXTRA']), 'auto_increment')) {
                    $primaryKeys[$table]['autoInc'] = $column;
                }
            }
        }
        $foreignKeyRows = self::rows($pdo, 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
        $counts = [];
        foreach ($foreignKeyRows as $row) {
            $counts[$row['TABLE_NAME']][$row['CONSTRAINT_NAME']] = ($counts[$row['TABLE_NAME']][$row['CONSTRAINT_NAME']] ?? 0) + 1;
        }
        foreach ($foreignKeyRows as $row) {
            $table = $row['TABLE_NAME'];
            $column = $row['COLUMN_NAME'];
            if ($counts[$table][$row['CONSTRAINT_NAME']] !== 1) {
                continue;
            }
            if (!isset($columns[$table][$column])) {
                throw new RuntimeException('Databázové schéma se během načítání změnilo.');
            }
            $foreignKeys[$table][] = [
                'column' => $column,
                'refTable' => $row['REFERENCED_TABLE_NAME'],
                'refColumn' => $row['REFERENCED_COLUMN_NAME'],
                'nullable' => $columns[$table][$column]['IS_NULLABLE'] === 'YES',
            ];
        }
        foreach ($foreignKeys as $table => &$links) {
            usort($links, static fn (array $left, array $right): int
                => (int) $columns[$table][$left['column']]['ORDINAL_POSITION']
                    <=> (int) $columns[$table][$right['column']]['ORDINAL_POSITION']);
        }
        unset($links);
        if ($tables === []) {
            throw new RuntimeException('Databázové schéma je prázdné nebo není dostupné.');
        }
        foreach ($tables as $table => $type) {
            if (!isset($columns[$table])) {
                throw new RuntimeException('Databázové schéma se během načítání změnilo.');
            }
        }
        return compact('tables', 'columns', 'primaryKeys', 'foreignKeys', 'foreignKeyRows');
    }

    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Nepodařilo se načíst databázové schéma.');
        }
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
