<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

final class PohodaMdbTables
{
    public readonly string $ico;
    public readonly int $year;
    public readonly string $version;
    private array $tables = [];
    private array $firstRowColumns = [];
    private readonly string $fingerprint;
    private int $passes = 0;

    /**
     * Úvodní průchod ověří strukturu celého souboru. Řádky tabulek `$inspect` při něm
     * dostane `$inspector`, takže kontrola obsahu nepotřebuje další průchod.
     *
     * @param list<string> $inspect
     * @param (callable(string,array<string,string>):void)|null $inspector
     */
    public function __construct(private readonly string $path, array $inspect = [], ?callable $inspector = null)
    {
        $this->fingerprint = $this->fingerprint();
        foreach ($this->scan($inspector === null ? null : array_fill_keys($inspect, true), true) as $table => $row) {
            $inspector($table, $row);
        }
        $this->unchanged();
    }

    /** Počet průchodů souborem od otevření (včetně úvodního). */
    public function passes(): int
    {
        return $this->passes;
    }

    public function has(string $table): bool
    {
        return $this->tables[$table] ?? false;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->firstRowColumns[$table][$column]);
    }

    public function rows(string $table): \Generator
    {
        foreach ($this->each([$table]) as $row) {
            yield $row;
        }
    }

    /**
     * Řádky několika tabulek v JEDNOM průchodu souborem, v pořadí ze souboru; klíč
     * generátoru je jméno tabulky (opakuje se, takže ne přes `iterator_to_array`).
     * Průchod celým XML stojí stejně bez ohledu na to, kolik tabulek se z něj čte.
     *
     * @param list<string> $tables
     * @return \Generator<string,array<string,string>>
     */
    public function each(array $tables): \Generator
    {
        $this->unchanged();
        yield from $this->scan(array_fill_keys($tables, true), false);
        $this->unchanged();
    }

    /** @param array<string,true>|null $wanted */
    private function scan(?array $wanted, bool $initialize): \Generator
    {
        $this->passes++;
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $opened = $reader->open($this->path, null, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$opened) {
            throw new PohodaException('mdb_xml_unreadable', 'XML převodu MDB nelze otevřít.');
        }
        $root = false;
        $closed = false;
        $tables = [];
        $table = null;
        $missing = false;
        $row = null;
        $columns = [];
        $field = null;
        $value = '';
        $nil = false;
        $count = 0;
        $advance = true;
        try {
            while (!$advance || $this->read($reader)) {
                $advance = true;
                $type = $reader->nodeType;
                if (in_array($type, [\XMLReader::DOC_TYPE, \XMLReader::ENTITY_REF, \XMLReader::ENTITY, \XMLReader::END_ENTITY], true)) {
                    throw new PohodaException('mdb_xml_entities', 'XML převodu MDB nesmí obsahovat DTD ani entity.');
                }
                if ($type === \XMLReader::ELEMENT) {
                    if ($reader->namespaceURI !== '' || $closed) {
                        $this->invalid();
                    }
                    $name = $reader->name;
                    $attrs = $this->attributes($reader);
                    switch ($reader->depth) {
                        case 0:
                            if ($root || $name !== 'pohodaMdbAccounting'
                                || ($attrs['formatVersion'] ?? '') !== '1'
                                || !preg_match('/\A[0-9]{6,8}\z/D', $attrs['ico'] ?? '')
                                || !preg_match('/\A[0-9]{4}\z/D', $attrs['year'] ?? '')
                                || (int) $attrs['year'] < 1990 || (int) $attrs['year'] > (int) date('Y') + 1
                                || !isset($attrs['programVersion']) || strlen($attrs['programVersion']) > 100
                                || array_diff(array_keys($attrs), ['formatVersion', 'ico', 'year', 'programVersion'])) {
                                throw new PohodaException('mdb_xml_metadata', 'XML převodu MDB má neplatná metadata nebo verzi formátu.');
                            }
                            if ($initialize) {
                                $this->ico = $attrs['ico'];
                                $this->year = (int) $attrs['year'];
                                $this->version = $attrs['programVersion'];
                            } elseif ($this->ico !== $attrs['ico'] || $this->year !== (int) $attrs['year'] || $this->version !== $attrs['programVersion']) {
                                $this->changed();
                            }
                            $root = true;
                            $closed = $reader->isEmptyElement;
                            break;
                        case 1:
                            if (!$root || $name !== 'table' || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $attrs['name'] ?? '')
                                || array_diff(array_keys($attrs), ['name', 'missing'])
                                || (isset($attrs['missing']) && $attrs['missing'] !== 'true')) {
                                $this->invalid();
                            }
                            $table = $attrs['name'];
                            if (array_key_exists($table, $tables)) {
                                throw new PohodaException('mdb_xml_duplicate_table', 'XML převodu MDB obsahuje duplicitní tabulku.');
                            }
                            $missing = isset($attrs['missing']);
                            $tables[$table] = !$missing;
                            if (count($tables) > 64) {
                                $this->limit();
                            }
                            if (!$initialize && !isset($wanted[$table])) {
                                $advance = !$this->read($reader, true);
                                $table = null;
                            }
                            break;
                        case 2:
                            if ($name !== 'row' || $attrs || $table === null || $missing) {
                                $this->invalid();
                            }
                            if (++$count > 1000000) {
                                $this->limit();
                            }
                            $row = [];
                            $columns = [];
                            if ($reader->isEmptyElement && isset($wanted[$table])) {
                                yield $table => [];
                            }
                            break;
                        case 3:
                            if ($row === null || array_diff(array_keys($attrs), ['nil'])
                                || (isset($attrs['nil']) && $attrs['nil'] !== 'true')) {
                                $this->invalid();
                            }
                            if (isset($columns[$name])) {
                                throw new PohodaException('mdb_xml_duplicate_column', 'XML převodu MDB obsahuje duplicitní sloupec.');
                            }
                            $columns[$name] = true;
                            $field = $name;
                            $nil = isset($attrs['nil']);
                            $value = '';
                            if ($reader->isEmptyElement) {
                                if (!$nil) {
                                    $row[$name] = '';
                                }
                                $field = null;
                            }
                            break;
                        default:
                            $this->invalid();
                    }
                } elseif ($type === \XMLReader::END_ELEMENT) {
                    if ($reader->depth === 3) {
                        if (!$nil) {
                            $row[$field] = $value;
                        }
                        $field = null;
                    } elseif ($reader->depth === 2) {
                        if ($initialize && $table !== null && $columns !== []) {
                            $this->firstRowColumns[$table] ??= $columns;
                        }
                        if ($table !== null && isset($wanted[$table])) {
                            yield $table => $row;
                        }
                        $row = null;
                    } elseif ($reader->depth === 1) {
                        $table = null;
                    } elseif ($reader->depth === 0) {
                        $closed = true;
                    }
                } elseif (in_array($type, [\XMLReader::TEXT, \XMLReader::CDATA, \XMLReader::WHITESPACE, \XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    if ($field !== null) {
                        if ($nil && $reader->value !== '') {
                            $this->invalid();
                        }
                        if (strlen($value) + strlen($reader->value) > 1048576) {
                            $this->limit();
                        }
                        $value .= $reader->value;
                    } elseif (trim($reader->value) !== '') {
                        $this->invalid();
                    }
                }
            }
            if (!$root || !$closed) {
                $this->invalid();
            }
            if ($initialize) {
                $this->tables = $tables;
            }
        } finally {
            $reader->close();
        }
    }

    private function attributes(\XMLReader $reader): array
    {
        $attrs = [];
        if ($reader->moveToFirstAttribute()) {
            do {
                if ($reader->namespaceURI !== '') {
                    $this->invalid();
                }
                $attrs[$reader->name] = $reader->value;
            } while ($reader->moveToNextAttribute());
            $reader->moveToElement();
        }
        return $attrs;
    }

    private function read(\XMLReader $reader, bool $next = false): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $result = $next ? $reader->next() : $reader->read();
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    $this->invalid();
                }
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function invalid(): never
    {
        throw new PohodaException('mdb_xml_invalid', 'XML převodu MDB má neplatnou strukturu.');
    }

    private function fingerprint(): string
    {
        $hash = @hash_file('sha256', $this->path);
        if ($hash === false) {
            throw new PohodaException('mdb_xml_unreadable', 'XML převodu MDB nelze otevřít.');
        }
        return $hash;
    }

    private function unchanged(): void
    {
        if (!hash_equals($this->fingerprint, $this->fingerprint())) {
            $this->changed();
        }
    }

    private function changed(): never
    {
        throw new PohodaException('mdb_xml_changed', 'XML převodu MDB se během čtení změnilo. Nahrajte export znovu.');
    }

    private function limit(): never
    {
        throw new PohodaException('mdb_xml_limit', 'XML převodu MDB překračuje povolený rozsah.');
    }
}
