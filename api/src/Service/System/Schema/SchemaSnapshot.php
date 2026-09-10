<?php

declare(strict_types=1);

namespace MyInvoice\Service\System\Schema;

use PDO;

/**
 * Otisk struktury databáze — tabulky, sloupce, indexy, cizí klíče, CHECK omezení,
 * triggery, rutiny a pohledy — v podobě, kterou jde porovnat napříč instalacemi.
 *
 * Referenční otisk (`db/schema.snapshot.json`) vzniká z čisté databáze postavené
 * výhradně z migrací (`api/bin/schema-snapshot.php`); diagnostika pak stejnou
 * funkcí zachytí živou databázi a {@see SchemaIntegrityService} je porovná.
 *
 * Normalizace je záměrná, jinak by se hlásil šum místo odchylek:
 *   - collation sloupce se ukládá jen tam, kde se liší od collation tabulky
 *     (jinak by každá instalace založená s jinou výchozí collation hlásila tisíce
 *     sloupců),
 *   - těla triggerů, rutin a pohledů se ukládají jako otisk SHA-1 po sjednocení
 *     bílých znaků a odstranění názvu schématu (pohled ho v definici nese),
 *   - AUTO_INCREMENT, komentáře, DEFINER a pořadí sloupců se neporovnávají.
 */
final class SchemaSnapshot
{
    public const FORMAT = 1;
    public const RELATIVE_PATH = 'db/schema.snapshot.json';

    /**
     * @param list<string> $migrations aplikované migrace, ze kterých struktura vznikla
     * @return array<string,mixed>
     */
    public static function capture(PDO $pdo, array $migrations = []): array
    {
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $databaseCollation = (string) $pdo->query(
            'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
        )->fetchColumn();

        $tables = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
               FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        ) as $row) {
            $tables[(string) $row['TABLE_NAME']] = [
                'type'         => (string) $row['TABLE_TYPE'],
                'engine'       => (string) ($row['ENGINE'] ?? ''),
                'collation'    => (string) ($row['TABLE_COLLATION'] ?? ''),
                'columns'      => [],
                'indexes'      => [],
                'foreign_keys' => [],
                'checks'       => [],
            ];
        }

        foreach (self::rows($pdo,
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
                    COLLATION_NAME, GENERATION_EXPRESSION
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        ) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (!isset($tables[$table]) || $tables[$table]['type'] === 'VIEW') {
                continue;
            }
            $collation = (string) ($row['COLLATION_NAME'] ?? '');
            if ($collation === $tables[$table]['collation']) {
                $collation = '';
            }
            $tables[$table]['columns'][(string) $row['COLUMN_NAME']] = self::column(
                (string) $row['COLUMN_TYPE'],
                (string) $row['IS_NULLABLE'] === 'YES',
                $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'],
                (string) ($row['EXTRA'] ?? ''),
                $collation,
                self::normalizeSql((string) ($row['GENERATION_EXPRESSION'] ?? ''), $schema),
            );
        }

        $indexes = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
               FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
              ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        ) as $row) {
            $key = $row['TABLE_NAME'] . "\0" . $row['INDEX_NAME'];
            $indexes[$key] ??= [
                'table'  => (string) $row['TABLE_NAME'],
                'name'   => (string) $row['INDEX_NAME'],
                'unique' => (int) $row['NON_UNIQUE'] === 0,
                'type'   => (string) $row['INDEX_TYPE'],
                'cols'   => [],
            ];
            $indexes[$key]['cols'][] = $row['COLUMN_NAME'] . ($row['SUB_PART'] !== null ? '(' . $row['SUB_PART'] . ')' : '');
        }
        foreach ($indexes as $index) {
            if (isset($tables[$index['table']])) {
                $tables[$index['table']]['indexes'][$index['name']] =
                    ($index['unique'] ? 'UNIQUE ' : '') . $index['type'] . ' (' . implode(', ', $index['cols']) . ')';
            }
        }

        // Pravidla a sloupce klíčů se čtou dvěma dotazy a spojují v PHP: JOIN obou
        // pohledů information_schema trvá na ~500 tabulkách desítky sekund, každý
        // zvlášť desetiny.
        $rules = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE
               FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'
        ) as $row) {
            $rules[$row['TABLE_NAME'] . "\0" . $row['CONSTRAINT_NAME']] =
                'ON UPDATE ' . $row['UPDATE_RULE'] . ' ON DELETE ' . $row['DELETE_RULE'];
        }
        $foreignKeys = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
        ) as $row) {
            $key = $row['TABLE_NAME'] . "\0" . $row['CONSTRAINT_NAME'];
            $foreignKeys[$key] ??= [
                'table'     => (string) $row['TABLE_NAME'],
                'name'      => (string) $row['CONSTRAINT_NAME'],
                'cols'      => [],
                'ref_table' => (string) $row['REFERENCED_TABLE_NAME'],
                'ref_cols'  => [],
                'rules'     => $rules[$key] ?? '',
            ];
            $foreignKeys[$key]['cols'][] = (string) $row['COLUMN_NAME'];
            $foreignKeys[$key]['ref_cols'][] = (string) $row['REFERENCED_COLUMN_NAME'];
        }
        foreach ($foreignKeys as $fk) {
            if (isset($tables[$fk['table']])) {
                $tables[$fk['table']]['foreign_keys'][$fk['name']] = sprintf(
                    '(%s) -> %s (%s) %s',
                    implode(', ', $fk['cols']), $fk['ref_table'], implode(', ', $fk['ref_cols']), $fk['rules'],
                );
            }
        }

        foreach (self::rows($pdo,
            'SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE
               FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'
        ) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (isset($tables[$table])) {
                $tables[$table]['checks'][(string) $row['CONSTRAINT_NAME']] =
                    self::normalizeSql((string) $row['CHECK_CLAUSE'], $schema);
            }
        }

        $triggers = [];
        foreach (self::rows($pdo,
            'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
               FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        ) as $row) {
            $triggers[(string) $row['TRIGGER_NAME']] = sprintf(
                '%s %s ON %s #%s',
                $row['ACTION_TIMING'], $row['EVENT_MANIPULATION'], $row['EVENT_OBJECT_TABLE'],
                sha1(self::normalizeSql((string) $row['ACTION_STATEMENT'], $schema)),
            );
        }

        $routines = [];
        foreach (self::rows($pdo,
            'SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION
               FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()'
        ) as $row) {
            $routines[(string) $row['ROUTINE_NAME']] = $row['ROUTINE_TYPE'] . ' #'
                . sha1(self::normalizeSql((string) ($row['ROUTINE_DEFINITION'] ?? ''), $schema));
        }

        foreach (self::rows($pdo,
            'SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()'
        ) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (isset($tables[$table])) {
                $tables[$table]['definition'] = '#' . sha1(self::normalizeSql((string) $row['VIEW_DEFINITION'], $schema));
            }
        }

        foreach ($tables as &$table) {
            foreach (['columns', 'indexes', 'foreign_keys', 'checks'] as $part) {
                ksort($table[$part], SORT_STRING);
            }
        }
        unset($table);
        ksort($tables, SORT_STRING);
        ksort($triggers, SORT_STRING);
        ksort($routines, SORT_STRING);
        sort($migrations, SORT_STRING);

        return [
            'format'             => self::FORMAT,
            'database_collation' => $databaseCollation,
            'migrations'         => array_values($migrations),
            'tables'             => $tables,
            'triggers'           => $triggers,
            'routines'           => $routines,
        ];
    }

    /**
     * Sloupec jako jeden řetězec — otisk má stovky tabulek a řádek na sloupec
     * drží diff v gitu čitelný.
     */
    public static function column(
        string $type,
        bool $nullable,
        ?string $default,
        string $extra = '',
        string $collation = '',
        string $generated = '',
    ): string {
        $parts = [$type, $nullable ? 'NULL' : 'NOT NULL'];
        if ($default !== null) {
            $parts[] = 'DEFAULT ' . $default;
        }
        if ($extra !== '') {
            $parts[] = strtoupper($extra);
        }
        if ($collation !== '') {
            $parts[] = 'COLLATE ' . $collation;
        }
        if ($generated !== '') {
            $parts[] = 'AS (' . $generated . ')';
        }
        return implode(' ', $parts);
    }

    /** @return array<string,mixed>|null */
    public static function load(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['tables'] ?? null)) {
            return null;
        }
        return $data;
    }

    /** @param array<string,mixed> $snapshot */
    public static function encode(array $snapshot): string
    {
        return json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Komentáře se před otiskem zahazují: klient `mariadb` je při nahrávání dumpu
     * z těl triggerů a procedur vypouští, takže obnovená databáze by jinak hlásila
     * „změněný" trigger, přestože se jeho kód nezměnil ani o znak.
     */
    public static function normalizeSql(string $sql, string $schema = ''): string
    {
        if ($schema !== '') {
            $sql = str_replace('`' . $schema . '`.', '', $sql);
        }

        $out = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $end = $i + 1;
                while ($end < $len && $sql[$end] !== $ch) {
                    $end += $sql[$end] === '\\' ? 2 : 1;
                }
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if ($ch === '#' || ($ch === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $len || ctype_space($sql[$i + 2])))) {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol - 1;
                $out .= ' ';
                continue;
            }
            if ($ch === '/' && substr($sql, $i, 2) === '/*') {
                $close = strpos($sql, '*/', $i + 2);
                $i = $close === false ? $len : $close + 1;
                $out .= ' ';
                continue;
            }
            $out .= $ch;
        }

        return trim((string) preg_replace('/\s+/', ' ', $out));
    }

    /** @return list<array<string,mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
