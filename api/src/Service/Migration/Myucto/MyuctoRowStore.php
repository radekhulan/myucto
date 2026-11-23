<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use RuntimeException;

/**
 * Řádky převáděné firmy mimo paměť PHP. Řádky leží v dočasném souboru (spool), v paměti
 * zůstává jen index `tabulka => id => pozice`, takže obnova velké firmy nedrží celý graf
 * v jednom poli. Soubor je jen pro přidávání: změna řádku zapíše novou verzi na konec
 * a přesune index. Díky tomu {@see fork()} dává levnou nezávislou kopii (sdílí soubor,
 * kopíruje jen index), kterou importér může upravovat bez zásahu do načteného balíčku.
 *
 * Pořadí tabulek i řádků odpovídá pořadí vložení (pořadí exportu), stejně jako u pole.
 */
final class MyuctoRowStore
{
    /** @var array<string,array<int,int>> tabulka => id => pozice v souboru */
    private array $index = [];

    private function __construct(private readonly MyuctoRowSpool $spool)
    {
    }

    public static function create(?string $dir = null): self
    {
        return new self(new MyuctoRowSpool($dir));
    }

    /**
     * Balíček s tabulkami v polích (převod zálohy MyInvoice, testy) do stejné podoby.
     *
     * @param array<string,array<int|string,array<string,mixed>>> $tables
     */
    public static function fromArrays(array $tables, ?string $dir = null): self
    {
        $store = self::create($dir);
        foreach ($tables as $table => $rows) {
            $store->addTable((string) $table);
            foreach ($rows as $id => $row) {
                $store->put((string) $table, (int) $id, $row);
            }
        }
        return $store;
    }

    /** Nezávislá kopie nad stejným souborem; změny kopie původní úložiště neuvidí. */
    public function fork(): self
    {
        $copy = new self($this->spool);
        $copy->index = $this->index;
        return $copy;
    }

    /** Dočasný soubor, který zanikne spolu s úložištěm (přílohy exportu mimo paměť). */
    public function tempFile(): string
    {
        return $this->spool->tempFile();
    }

    public function addTable(string $table): void
    {
        $this->index[$table] ??= [];
    }

    public function hasTable(string $table): bool
    {
        return isset($this->index[$table]);
    }

    public function dropTable(string $table): void
    {
        unset($this->index[$table]);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_keys($this->index);
    }

    public function put(string $table, int $id, array $row): void
    {
        $this->index[$table][$id] = $this->spool->append(serialize($row));
    }

    public function has(string $table, int|string $id): bool
    {
        return isset($this->index[$table][$id]);
    }

    /** @return array<string,mixed>|null */
    public function get(string $table, int|string $id): ?array
    {
        $offset = $this->index[$table][$id] ?? null;
        return $offset === null ? null : $this->decode($this->spool->read($offset));
    }

    /** První řádek tabulky (kořenová firma exportu). */
    public function first(string $table): ?array
    {
        foreach ($this->rows($table) as $row) {
            return $row;
        }
        return null;
    }

    public function remove(string $table, int|string $id): void
    {
        unset($this->index[$table][$id]);
    }

    public function count(string $table): int
    {
        return count($this->index[$table] ?? []);
    }

    public function isEmpty(string $table): bool
    {
        return ($this->index[$table] ?? []) === [];
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        return array_map('count', $this->index);
    }

    /** @return list<int> */
    public function ids(string $table): array
    {
        return array_keys($this->index[$table] ?? []);
    }

    /**
     * Řádky tabulky v pořadí exportu. Iteruje nad stavem indexu v okamžiku zahájení,
     * stejně jako `foreach` nad polem, takže změny během průchodu ho neovlivní.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function rows(string $table): \Generator
    {
        foreach ($this->index[$table] ?? [] as $id => $offset) {
            yield $id => $this->decode($this->spool->read($offset));
        }
    }

    /**
     * Celá tabulka v poli, jen pro malé tabulky nastavení (historie DPH, rámce).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(string $table): array
    {
        return iterator_to_array($this->rows($table), true);
    }

    /**
     * Celé úložiště v polích. Jen pro malé balíčky (testy, diagnostika); obnova pracuje
     * proudem přes {@see rows()}.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function toArrays(): array
    {
        $out = [];
        foreach ($this->tables() as $table) {
            $out[$table] = $this->all($table);
        }
        return $out;
    }

    public function sortById(string $table): void
    {
        if (isset($this->index[$table])) {
            ksort($this->index[$table], SORT_NUMERIC);
        }
    }

    /** @return array<string,mixed> */
    private function decode(string $raw): array
    {
        $row = unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($row)) {
            throw new RuntimeException('Dočasné úložiště obnovy je poškozené.');
        }
        return $row;
    }
}
