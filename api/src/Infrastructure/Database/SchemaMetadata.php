<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;

/**
 * Metadata schématu aktuální databáze z jednoho hromadného načtení místo dotazu
 * do `information_schema` na každou otázku. Tabulky, sloupce a cizí klíče s pravidly
 * dává {@see Connection::schemaSnapshot()} (invalidace po DDL přes spojení aplikace
 * a po migraci), triggery `SHOW TRIGGERS` pro stejnou generaci schématu. Navíc se
 * hlídá otisk tabulky `migrations`, takže migrace jiného procesu načte schéma znovu
 * i tam, kde sdílená cache na disku není.
 */
final class SchemaMetadata
{
    /** @var array<string, string> generace schématu => otisk migrací */
    private static array $markers = [];

    /** @var array<string, list<array<string, mixed>>> generace schématu => triggery */
    private static array $triggers = [];

    public function __construct(private readonly Connection $db) {}

    public function database(): string
    {
        return (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
    }

    /**
     * @param list<string> $types
     * @return list<string>
     */
    public function tablesWithColumn(string $column, array $types = ['BASE TABLE', 'SYSTEM VERSIONED']): array
    {
        $snapshot = $this->snapshot();
        $tables = [];
        foreach ($snapshot['columns'] as $table => $columns) {
            if (isset($columns[$column]) && in_array($snapshot['tables'][$table] ?? null, $types, true)) {
                $tables[] = (string) $table;
            }
        }
        return $tables;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->snapshot()['columns'][$table][$column]);
    }

    /**
     * @return list<array{TABLE_NAME: string, CONSTRAINT_NAME: string, COLUMN_NAME: string, REFERENCED_TABLE_SCHEMA: string,
     *     REFERENCED_TABLE_NAME: string, REFERENCED_COLUMN_NAME: string, ORDINAL_POSITION: int|string, DELETE_RULE: ?string, UPDATE_RULE: ?string}>
     */
    public function foreignKeys(): array
    {
        $snapshot = $this->snapshot();
        $rules = $snapshot['referentialRules'];
        $out = [];
        foreach ($snapshot['foreignKeyRows'] as $row) {
            $rule = $rules[$row['TABLE_NAME'] . "\0" . $row['CONSTRAINT_NAME']] ?? null;
            $out[] = $row + ['DELETE_RULE' => $rule['DELETE_RULE'] ?? null, 'UPDATE_RULE' => $rule['UPDATE_RULE'] ?? null];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function localForeignKeys(): array
    {
        $database = $this->database();
        return array_values(array_filter($this->foreignKeys(),
            static fn (array $row): bool => $row['REFERENCED_TABLE_SCHEMA'] === $database));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function triggers(): array
    {
        $generation = $this->generation();
        return self::$triggers[$generation] ??= TriggerMetadata::read($this->db->pdo(), $this->database());
    }

    public function invalidate(): void
    {
        $this->db->invalidateSchemaCache();
        self::$markers = [];
        self::$triggers = [];
    }

    /** Pořadí jako `ORDER BY` nad information_schema (utf8mb3_general_ci). */
    public static function compareNames(string $left, string $right): int
    {
        return strcmp(strtoupper($left), strtoupper($right));
    }

    private function snapshot(): array
    {
        $this->generation();
        return $this->db->schemaSnapshot();
    }

    private function generation(): string
    {
        $generation = $this->db->schemaGeneration();
        $marker = $this->migrationMarker();
        if (isset(self::$markers[$generation]) && self::$markers[$generation] !== $marker) {
            $this->db->schemaSnapshot(true);
            unset(self::$triggers[$generation]);
            $generation = $this->db->schemaGeneration();
        }
        self::$markers[$generation] = $marker;
        return $generation;
    }

    private function migrationMarker(): string
    {
        if (!$this->db->hasTable('migrations')) {
            return '';
        }
        $row = $this->db->pdo()->query('SELECT COUNT(*), MAX(filename) FROM migrations')->fetch(PDO::FETCH_NUM);
        return is_array($row) ? $row[0] . ':' . $row[1] : '';
    }
}
