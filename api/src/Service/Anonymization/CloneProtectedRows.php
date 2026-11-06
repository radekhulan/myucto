<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use PDO;

/**
 * Primární klíče finančních archivů, které obecná pseudonymizace přeskočí.
 *
 * Seznam leží v pomocné tabulce cílové databáze, ne v poli PHP: archivů mohou být
 * statisíce a obecný průchod se k nim ptá po stránkách přes `NOT EXISTS` po primárním
 * klíči. Tabulka nese náhodný token běhu, aby ji šlo odstranit jen tím, kdo ji založil,
 * a zmizí dřív, než kopie dostane pohledy, triggery a značku dokončení.
 */
final class CloneProtectedRows
{
    public const TABLE_PREFIX = '__myucto_clone_protected_';

    private const MAX_BATCH = 500;

    private bool $dropped = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
        private readonly string $table,
        private readonly string $runToken,
    ) {}

    public static function create(PDO $pdo, string $database): self
    {
        if (!AnonymizationOptions::isSafeDatabaseName($database) || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new \RuntimeException('Seznam chráněných řádků vyžaduje vybranou cílovou databázi.');
        }
        $table = self::TABLE_PREFIX . bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));
        $quoted = DatabaseCloner::quote($table);
        $pdo->exec("CREATE TABLE {$quoted} ("
            . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'table_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'row_id BIGINT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (run_token, table_name, row_id)) ENGINE=InnoDB');
        $pdo->prepare("INSERT INTO {$quoted} (run_token, table_name, row_id) VALUES (?, '__run', 0)")->execute([$token]);

        return new self($pdo, $database, $table, $token);
    }

    /** @param list<int> $rowIds */
    public function protectMany(FinancialArchiveProtectedTable $table, array $rowIds): void
    {
        $this->assertActive();
        foreach (array_chunk(array_values(array_unique($rowIds)), self::MAX_BATCH) as $chunk) {
            $parameters = [];
            foreach ($chunk as $rowId) {
                if (!is_int($rowId) || $rowId <= 0) {
                    throw new \InvalidArgumentException('Chráněný primární klíč musí být kladné celé číslo.');
                }
                array_push($parameters, $this->runToken, $table->value, $rowId);
            }
            $this->pdo->prepare('INSERT IGNORE INTO ' . DatabaseCloner::quote($this->table)
                . ' (run_token, table_name, row_id) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?,?)')))
                ->execute($parameters);
        }
    }

    public function has(FinancialArchiveProtectedTable $table, int $rowId): bool
    {
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT 1 FROM ' . DatabaseCloner::quote($this->table)
            . ' WHERE run_token = ? AND table_name = ? AND row_id = ?');
        $statement->execute([$this->runToken, $table->value, $rowId]);

        return $statement->fetchColumn() !== false;
    }

    public function count(FinancialArchiveProtectedTable $table): int
    {
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($this->table)
            . ' WHERE run_token = ? AND table_name = ?');
        $statement->execute([$this->runToken, $table->value]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Další stránka chráněných klíčů jedné tabulky (keyset podle klíče).
     *
     * @return list<int>
     */
    public function idsAfter(FinancialArchiveProtectedTable $table, int $afterId, int $limit): array
    {
        $this->assertActive();
        $limit = max(1, min(self::MAX_BATCH, $limit));
        $statement = $this->pdo->prepare('SELECT row_id FROM ' . DatabaseCloner::quote($this->table)
            . " WHERE run_token = ? AND table_name = ? AND row_id > ? ORDER BY row_id LIMIT {$limit}");
        $statement->execute([$this->runToken, $table->value, $afterId]);

        return array_map(intval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Řádky stránky obecného průchodu (rozsah klíčů z {@see lastIdOfPage()}) bez chráněných řádků.
     *
     * @param list<string> $columns
     * @return list<array<string,mixed>>
     */
    public function selectUnprotectedRange(FinancialArchiveProtectedTable $table, array $columns, ?int $afterId, int $untilId): array
    {
        $this->assertActive();
        if ($columns === [] || $untilId < 1) {
            throw new \InvalidArgumentException('Neplatný výběr stránky bez chráněných řádků.');
        }
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', $column) !== 1) {
                throw new \InvalidArgumentException('Neplatný sloupec výběru bez chráněných řádků.');
            }
        }
        $select = implode(', ', array_map(
            static fn (string $column): string => 't.' . DatabaseCloner::quote($column) . ' AS ' . DatabaseCloner::quote($column),
            $columns,
        ));
        $sql = "SELECT {$select} FROM " . DatabaseCloner::quote($table->value) . ' t'
            . ' WHERE t.id <= ?' . ($afterId === null ? '' : ' AND t.id > ?')
            . ' AND NOT EXISTS (SELECT 1 FROM ' . DatabaseCloner::quote($this->table) . ' p'
            . ' WHERE p.run_token = ? AND p.table_name = ? AND p.row_id = t.id)'
            . ' ORDER BY t.id';
        $parameters = $afterId === null
            ? [$untilId, $this->runToken, $table->value]
            : [$untilId, $afterId, $this->runToken, $table->value];
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Poslední klíč stránky obecného průchodu i s chráněnými řádky — keyset musí pokračovat
     * za celou stránku tabulky, jinak by stránka plná chráněných řádků ukončila průchod.
     */
    public function lastIdOfPage(FinancialArchiveProtectedTable $table, ?int $afterId, int $limit): ?int
    {
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT MAX(id) FROM (SELECT id FROM ' . DatabaseCloner::quote($table->value)
            . ($afterId === null ? '' : ' WHERE id > ?') . ' ORDER BY id LIMIT ' . max(1, $limit) . ') page');
        $statement->execute($afterId === null ? [] : [$afterId]);
        $last = $statement->fetchColumn();

        return $last === false || $last === null ? null : (int) $last;
    }

    public function drop(): void
    {
        if ($this->dropped) {
            return;
        }
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($this->table)
            . " WHERE run_token = ? AND table_name = '__run' AND row_id = 0");
        $statement->execute([$this->runToken]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Nelze doložit vlastnictví seznamu chráněných řádků.');
        }
        $this->pdo->exec('DROP TABLE ' . DatabaseCloner::quote($this->table));
        $this->dropped = true;
    }

    private function assertActive(): void
    {
        if ($this->dropped) {
            throw new \LogicException('Seznam chráněných řádků už byl odstraněn.');
        }
        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->database) {
            throw new \RuntimeException('Seznam chráněných řádků patří jiné databázi.');
        }
    }
}
