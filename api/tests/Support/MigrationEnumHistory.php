<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Staticky přehraje DDL migrací a sleduje množinu hodnot každého ENUM/SET sloupce.
 *
 * Soubory se přehrávají ve stejném pořadí jako v `api/bin/migrate.php`
 * (`sort($files, SORT_STRING)`), statementy se dělí stejně jako tam (DELIMITER
 * na začátku řádku, komentáře `--` a `/* *\/`, řetězce). Navíc se dělí i na `;`
 * uvnitř těl procedur a triggerů, aby se našel ALTER schovaný v bloku.
 *
 * Sleduje se existence VŠECH sloupců (i ne-ENUM), protože na ní závisí, jestli
 * `ADD COLUMN IF NOT EXISTS` a `CREATE TABLE IF NOT EXISTS` něco udělají.
 * Zúžení = pozdější definice sloupce, ve které chybí hodnota, kterou sloupec
 * v tu chvíli měl. Hodnoty se porovnávají bez ohledu na velikost písmen a bez
 * koncových mezer, stejně jako je porovná MariaDB při převodu dat.
 */
final class MigrationEnumHistory
{
    private const ELEMENT_KEYWORDS = [
        'PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'CHECK', 'FULLTEXT', 'SPATIAL',
    ];
    private const ADD_NON_COLUMN = [
        'PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'CHECK', 'FULLTEXT', 'SPATIAL',
        'PARTITION', 'PERIOD', 'SYSTEM',
    ];
    private const DROP_NON_COLUMN = [
        'PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'CHECK', 'PARTITION', 'PERIOD', 'SYSTEM',
    ];

    /** @var array<string, array<string, array{type: string, values: ?list<string>, file: string}>> */
    private array $tables = [];

    /** @var list<array{file: string, table: string, column: string, missing: list<string>, previousFile: string, remappedInFile: bool}> */
    private array $narrowings = [];

    /** @var array<string, true> tabulky, na které v aktuálním souboru už mířil UPDATE */
    private array $updatedInFile = [];

    private string $file = '';

    public static function fromDirectory(string $directory): self
    {
        $files = glob(rtrim($directory, '/\\') . '/*.sql');
        if ($files === false || $files === []) {
            throw new \RuntimeException('Žádné migrace v ' . $directory);
        }
        sort($files, SORT_STRING);
        $history = new self();
        foreach ($files as $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new \RuntimeException('Nelze přečíst ' . $path);
            }
            $history->apply(basename($path), $sql);
        }
        return $history;
    }

    public function apply(string $file, string $sql): void
    {
        $this->file = $file;
        $this->updatedInFile = [];
        foreach (self::statements($sql) as $tokens) {
            $this->applyStatement($tokens);
        }
    }

    /** @return list<array{file: string, table: string, column: string, missing: list<string>, previousFile: string, remappedInFile: bool}> */
    public function narrowings(): array
    {
        return $this->narrowings;
    }

    /** @return ?list<string> hodnoty ENUM/SET sloupce, null = sloupec neexistuje nebo není ENUM/SET */
    public function values(string $table, string $column): ?array
    {
        return $this->tables[strtolower($table)][strtolower($column)]['values'] ?? null;
    }

    public function enumColumnCount(): int
    {
        $count = 0;
        foreach ($this->tables as $columns) {
            foreach ($columns as $column) {
                if ($column['values'] !== null) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Rozloží SQL na statementy jako seznamy tokenů [druh, hodnota]:
     * `w` slovo/číslo, `i` identifikátor v backticku, `s` řetězec (už bez escapů), `p` interpunkce.
     *
     * @return list<list<array{0: string, 1: string}>>
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = [];
        $delimiter = ';';
        $len = strlen($sql);
        $i = 0;
        $lineStart = true;

        while ($i < $len) {
            if ($lineStart) {
                $lineStart = false;
                if (preg_match('/[ \t]*DELIMITER[ \t]+(\S+)[^\n]*/Ai', $sql, $m, 0, $i) === 1) {
                    if ($current !== []) {
                        $statements[] = $current;
                        $current = [];
                    }
                    $delimiter = $m[1];
                    $i += strlen($m[0]);
                    continue;
                }
            }

            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($ch === "\n") {
                $lineStart = true;
                $i++;
                continue;
            }
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if (($ch === '-' && $next === '-') || $ch === '#') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                [$value, $i] = self::readQuoted($sql, $i, $ch, true);
                $current[] = ['s', $value];
                continue;
            }
            if ($ch === '`') {
                [$value, $i] = self::readQuoted($sql, $i, '`', false);
                $current[] = ['i', $value];
                continue;
            }
            if ($ch === ';' || substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
                if ($current !== []) {
                    $statements[] = $current;
                    $current = [];
                }
                $i += $ch === ';' ? 1 : strlen($delimiter);
                continue;
            }
            if (preg_match('/[A-Za-z0-9_$]+/A', $sql, $m, 0, $i) === 1) {
                $current[] = ['w', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            $current[] = ['p', $ch];
            $i++;
        }
        if ($current !== []) {
            $statements[] = $current;
        }
        return $statements;
    }

    /** @return array{0: string, 1: int} hodnota a pozice za uzavírací uvozovkou */
    private static function readQuoted(string $sql, int $start, string $quote, bool $backslashEscapes): array
    {
        $len = strlen($sql);
        $value = '';
        $i = $start + 1;
        while ($i < $len) {
            $ch = $sql[$i];
            if ($backslashEscapes && $ch === '\\' && $i + 1 < $len) {
                $value .= $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($ch === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $value .= $quote;
                    $i += 2;
                    continue;
                }
                return [$value, $i + 1];
            }
            $value .= $ch;
            $i++;
        }
        return [$value, $len];
    }

    /** @param list<array{0: string, 1: string}> $t */
    private function applyStatement(array $t): void
    {
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i][0] !== 'w') {
                continue;
            }
            $keyword = strtoupper($t[$i][1]);
            if ($keyword === 'ALTER') {
                $j = $i + 1;
                while (self::isWord($t, $j, 'ONLINE') || self::isWord($t, $j, 'IGNORE')) {
                    $j++;
                }
                if (self::isWord($t, $j, 'TABLE')) {
                    $this->alterTable($t, $j + 1);
                    return;
                }
            } elseif ($keyword === 'CREATE') {
                $j = $i + 1;
                $replace = false;
                if (self::isWord($t, $j, 'OR') && self::isWord($t, $j + 1, 'REPLACE')) {
                    $replace = true;
                    $j += 2;
                }
                if (self::isWord($t, $j, 'TEMPORARY')) {
                    return;
                }
                if (self::isWord($t, $j, 'TABLE')) {
                    $this->createTable($t, $j + 1, $replace);
                    return;
                }
            } elseif ($keyword === 'DROP') {
                if (self::isWord($t, $i + 1, 'TEMPORARY')) {
                    return;
                }
                if (self::isWord($t, $i + 1, 'TABLE')) {
                    $this->dropTables($t, $i + 2);
                    return;
                }
            } elseif ($keyword === 'RENAME' && self::isWord($t, $i + 1, 'TABLE')) {
                $this->renameTables($t, $i + 2);
                return;
            } elseif ($keyword === 'UPDATE') {
                $j = $i + 1;
                while (self::isWord($t, $j, 'LOW_PRIORITY') || self::isWord($t, $j, 'IGNORE')) {
                    $j++;
                }
                $name = self::readName($t, $j);
                if ($name !== null && $name !== 'on') {
                    $this->updatedInFile[$name] = true;
                }
            }
        }
    }

    /** @param list<array{0: string, 1: string}> $t */
    private function createTable(array $t, int $j, bool $replace): void
    {
        $ifNotExists = false;
        if (self::isWord($t, $j, 'IF') && self::isWord($t, $j + 1, 'NOT') && self::isWord($t, $j + 2, 'EXISTS')) {
            $ifNotExists = true;
            $j += 3;
        }
        $table = self::readName($t, $j);
        if ($table === null) {
            return;
        }
        if ($ifNotExists && !$replace && isset($this->tables[$table])) {
            return;
        }

        $likeAt = self::isPunct($t, $j, '(') && self::isWord($t, $j + 1, 'LIKE') ? $j + 2
            : (self::isWord($t, $j, 'LIKE') ? $j + 1 : null);
        if ($likeAt !== null) {
            $source = self::readName($t, $likeAt);
            $this->tables[$table] = $source !== null ? ($this->tables[$source] ?? []) : [];
            return;
        }

        $this->tables[$table] = [];
        if (!self::isPunct($t, $j, '(')) {
            return;
        }
        $close = self::matchingParen($t, $j);
        foreach (self::splitTopLevel(array_slice($t, $j + 1, $close - $j - 1)) as $element) {
            if ($element === []) {
                continue;
            }
            if ($element[0][0] === 'w') {
                $first = strtoupper($element[0][1]);
                if (in_array($first, self::ELEMENT_KEYWORDS, true)
                    || ($first === 'PERIOD' && self::isWord($element, 1, 'FOR'))
                ) {
                    continue;
                }
            }
            $definition = self::columnDefinition($element, 0);
            if ($definition !== null) {
                $this->define($table, $definition[0], $definition[1], $definition[2], null);
            }
        }
    }

    /** @param list<array{0: string, 1: string}> $t */
    private function alterTable(array $t, int $j): void
    {
        if (self::isWord($t, $j, 'IF') && self::isWord($t, $j + 1, 'EXISTS')) {
            $j += 2;
        }
        $table = self::readName($t, $j);
        if ($table === null) {
            return;
        }
        if (self::isWord($t, $j, 'WAIT')) {
            $j += 2;
        } elseif (self::isWord($t, $j, 'NOWAIT')) {
            $j++;
        }

        $renameTo = null;
        foreach (self::splitTopLevel(array_slice($t, $j)) as $clause) {
            if ($clause === [] || $clause[0][0] !== 'w') {
                continue;
            }
            $verb = strtoupper($clause[0][1]);
            $k = 1;
            if ($verb === 'ADD') {
                $hasColumnKeyword = self::isWord($clause, $k, 'COLUMN');
                if ($hasColumnKeyword) {
                    $k++;
                } elseif (self::isAnyWord($clause, $k, self::ADD_NON_COLUMN)) {
                    continue;
                }
                $ifNotExists = false;
                if (self::isWord($clause, $k, 'IF') && self::isWord($clause, $k + 1, 'NOT') && self::isWord($clause, $k + 2, 'EXISTS')) {
                    $ifNotExists = true;
                    $k += 3;
                }
                $definitions = [];
                if (self::isPunct($clause, $k, '(')) {
                    $close = self::matchingParen($clause, $k);
                    $definitions = self::splitTopLevel(array_slice($clause, $k + 1, $close - $k - 1));
                } else {
                    $definitions[] = array_slice($clause, $k);
                }
                foreach ($definitions as $element) {
                    $definition = self::columnDefinition($element, 0);
                    if ($definition === null) {
                        continue;
                    }
                    if ($ifNotExists && isset($this->tables[$table][$definition[0]])) {
                        continue;
                    }
                    $this->define($table, $definition[0], $definition[1], $definition[2], $definition[0]);
                }
            } elseif ($verb === 'MODIFY') {
                $k = self::skipColumnIfExists($clause, $k);
                $definition = self::columnDefinition($clause, $k);
                if ($definition !== null) {
                    $this->define($table, $definition[0], $definition[1], $definition[2], $definition[0]);
                }
            } elseif ($verb === 'CHANGE') {
                $k = self::skipColumnIfExists($clause, $k);
                $old = self::readName($clause, $k);
                $definition = self::columnDefinition($clause, $k);
                if ($old !== null && $definition !== null) {
                    $this->define($table, $definition[0], $definition[1], $definition[2], $old);
                }
            } elseif ($verb === 'DROP') {
                if (self::isWord($clause, $k, 'COLUMN')) {
                    $k++;
                } elseif (self::isAnyWord($clause, $k, self::DROP_NON_COLUMN)) {
                    continue;
                }
                if (self::isWord($clause, $k, 'IF') && self::isWord($clause, $k + 1, 'EXISTS')) {
                    $k += 2;
                }
                $column = self::readName($clause, $k);
                if ($column !== null) {
                    unset($this->tables[$table][$column]);
                }
            } elseif ($verb === 'RENAME') {
                if (self::isWord($clause, $k, 'COLUMN')) {
                    $k = self::skipColumnIfExists($clause, $k);
                    $old = self::readName($clause, $k);
                    if (self::isWord($clause, $k, 'TO')) {
                        $k++;
                    }
                    $new = self::readName($clause, $k);
                    if ($old !== null && $new !== null && isset($this->tables[$table][$old])) {
                        $this->tables[$table][$new] = $this->tables[$table][$old];
                        unset($this->tables[$table][$old]);
                    }
                } elseif (!self::isWord($clause, $k, 'INDEX') && !self::isWord($clause, $k, 'KEY')) {
                    if (self::isWord($clause, $k, 'TO') || self::isWord($clause, $k, 'AS')) {
                        $k++;
                    }
                    $renameTo = self::readName($clause, $k);
                }
            }
        }
        if ($renameTo !== null && $renameTo !== $table) {
            $this->tables[$renameTo] = $this->tables[$table] ?? [];
            unset($this->tables[$table]);
        }
    }

    /** @param list<array{0: string, 1: string}> $t */
    private function dropTables(array $t, int $j): void
    {
        if (self::isWord($t, $j, 'IF') && self::isWord($t, $j + 1, 'EXISTS')) {
            $j += 2;
        }
        while (($table = self::readName($t, $j)) !== null) {
            unset($this->tables[$table]);
            if (!self::isPunct($t, $j, ',')) {
                break;
            }
            $j++;
        }
    }

    /** @param list<array{0: string, 1: string}> $t */
    private function renameTables(array $t, int $j): void
    {
        while (($from = self::readName($t, $j)) !== null) {
            if (!self::isWord($t, $j, 'TO')) {
                return;
            }
            $j++;
            $to = self::readName($t, $j);
            if ($to === null) {
                return;
            }
            $this->tables[$to] = $this->tables[$from] ?? [];
            unset($this->tables[$from]);
            if (!self::isPunct($t, $j, ',')) {
                return;
            }
            $j++;
        }
    }

    /**
     * Nastaví novou definici sloupce. `$previousName` je jméno, pod kterým sloupec
     * existoval před změnou (u CHANGE se liší); null = nový sloupec v CREATE TABLE.
     *
     * @param ?list<string> $values
     */
    private function define(string $table, string $column, string $type, ?array $values, ?string $previousName): void
    {
        $previous = $previousName !== null ? ($this->tables[$table][$previousName] ?? null) : null;
        if ($previous !== null && $previous['values'] !== null && $values !== null) {
            $kept = array_map(self::normalize(...), $values);
            $missing = [];
            foreach ($previous['values'] as $value) {
                if (!in_array(self::normalize($value), $kept, true)) {
                    $missing[] = $value;
                }
            }
            if ($missing !== []) {
                $this->narrowings[] = [
                    'file' => $this->file,
                    'table' => $table,
                    'column' => $column,
                    'missing' => $missing,
                    'previousFile' => $previous['file'],
                    'remappedInFile' => isset($this->updatedInFile[$table]),
                ];
            }
        }
        if ($previousName !== null && $previousName !== $column) {
            unset($this->tables[$table][$previousName]);
        }
        $this->tables[$table][$column] = ['type' => $type, 'values' => $values, 'file' => $this->file];
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(rtrim($value, ' '));
    }

    /**
     * @param list<array{0: string, 1: string}> $t
     * @return ?array{0: string, 1: string, 2: ?list<string>} jméno, typ, hodnoty ENUM/SET
     */
    private static function columnDefinition(array $t, int $k): ?array
    {
        $column = self::readName($t, $k);
        if ($column === null || !isset($t[$k]) || $t[$k][0] !== 'w') {
            return null;
        }
        $type = strtoupper($t[$k][1]);
        $values = null;
        if (($type === 'ENUM' || $type === 'SET') && self::isPunct($t, $k + 1, '(')) {
            $close = self::matchingParen($t, $k + 1);
            $values = [];
            for ($x = $k + 2; $x < $close; $x++) {
                if ($t[$x][0] === 's') {
                    $values[] = $t[$x][1];
                }
            }
        }
        return [$column, $type, $values];
    }

    /** @param list<array{0: string, 1: string}> $t */
    private static function skipColumnIfExists(array $t, int $k): int
    {
        if (self::isWord($t, $k, 'COLUMN')) {
            $k++;
        }
        if (self::isWord($t, $k, 'IF') && self::isWord($t, $k + 1, 'EXISTS')) {
            $k += 2;
        }
        return $k;
    }

    /**
     * Přečte (případně schématem kvalifikované) jméno a posune index za něj.
     *
     * @param list<array{0: string, 1: string}> $t
     */
    private static function readName(array $t, int &$k): ?string
    {
        if (!isset($t[$k]) || ($t[$k][0] !== 'w' && $t[$k][0] !== 'i')) {
            return null;
        }
        $name = $t[$k][1];
        $k++;
        if (self::isPunct($t, $k, '.') && isset($t[$k + 1]) && ($t[$k + 1][0] === 'w' || $t[$k + 1][0] === 'i')) {
            $name = $t[$k + 1][1];
            $k += 2;
        }
        return strtolower($name);
    }

    /**
     * @param list<array{0: string, 1: string}> $t
     * @return list<list<array{0: string, 1: string}>>
     */
    private static function splitTopLevel(array $t): array
    {
        $parts = [];
        $current = [];
        $depth = 0;
        foreach ($t as $token) {
            if ($token[0] === 'p') {
                if ($token[1] === '(') {
                    $depth++;
                } elseif ($token[1] === ')') {
                    $depth--;
                } elseif ($token[1] === ',' && $depth === 0) {
                    $parts[] = $current;
                    $current = [];
                    continue;
                }
            }
            $current[] = $token;
        }
        $parts[] = $current;
        return $parts;
    }

    /** @param list<array{0: string, 1: string}> $t */
    private static function matchingParen(array $t, int $open): int
    {
        $depth = 0;
        $n = count($t);
        for ($x = $open; $x < $n; $x++) {
            if ($t[$x][0] !== 'p') {
                continue;
            }
            if ($t[$x][1] === '(') {
                $depth++;
            } elseif ($t[$x][1] === ')' && --$depth === 0) {
                return $x;
            }
        }
        return $n;
    }

    /** @param list<array{0: string, 1: string}> $t */
    private static function isWord(array $t, int $k, string $word): bool
    {
        return isset($t[$k]) && $t[$k][0] === 'w' && strcasecmp($t[$k][1], $word) === 0;
    }

    /**
     * @param list<array{0: string, 1: string}> $t
     * @param list<string> $words
     */
    private static function isAnyWord(array $t, int $k, array $words): bool
    {
        return isset($t[$k]) && $t[$k][0] === 'w' && in_array(strtoupper($t[$k][1]), $words, true);
    }

    /** @param list<array{0: string, 1: string}> $t */
    private static function isPunct(array $t, int $k, string $char): bool
    {
        return isset($t[$k]) && $t[$k][0] === 'p' && $t[$k][1] === $char;
    }
}
