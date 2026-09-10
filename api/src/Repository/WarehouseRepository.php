<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro warehouses — číselník skladů (Epic SKLAD).
 * Per tenant (supplier_id); UNIQUE (supplier_id, code). Právě jeden sklad smí
 * mít is_default=1 — volající před nastavením nové výchozí zavolá clearDefault().
 */
final class WarehouseRepository
{
    private const COLUMNS =
        'id, supplier_id, code, name, is_default, is_active, is_sellable, note, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM warehouses WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM warehouses WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Serializační zámek skladu sdílený inventurou a post/reverse pohybů.
     * Volat pouze uvnitř transakce, id se zamykají deterministicky.
     *
     * @param list<int> $warehouseIds
     */
    public function lockForStockOperation(int $supplierId, array $warehouseIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $warehouseIds), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM warehouses
              WHERE supplier_id = ? AND id IN ({$placeholders})
              ORDER BY id FOR UPDATE"
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
            throw new \RuntimeException('Sklad pro serializaci pohybu neexistuje nebo nepatří firmě.');
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM warehouses WHERE supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY is_default DESC, code ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Výchozí sklad — is_default=1 AND is_active=1; když žádný takový není
     * (např. právě deaktivovaný), spadne na první aktivní podle kódu.
     */
    public function getDefault(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM warehouses
              WHERE supplier_id = ? AND is_default = 1 AND is_active = 1 AND is_sellable = 1
              LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return self::cast($row);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM warehouses
              WHERE supplier_id = ? AND is_active = 1 AND is_sellable = 1
              ORDER BY code ASC
              LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @param array{code:string, name:string, is_default?:bool, is_active?:bool, is_sellable?:bool, note?:?string} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO warehouses (supplier_id, code, name, is_default, is_active, is_sellable, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['name'],
            (int) ($data['is_default'] ?? false),
            (int) ($data['is_active'] ?? true),
            (int) ($data['is_sellable'] ?? true),
            $data['note'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{code:string, name:string, is_default?:bool, is_active?:bool, is_sellable?:bool, note?:?string} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE warehouses SET code = ?, name = ?, is_default = ?, is_active = ?, is_sellable = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['name'],
            (int) ($data['is_default'] ?? false),
            (int) ($data['is_active'] ?? true),
            (int) ($data['is_sellable'] ?? true),
            $data['note'] ?? null,
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Shodí is_default u všech skladů firmy (volat v transakci před nastavením nové výchozí). */
    public function clearDefault(int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE warehouses SET is_default = 0 WHERE supplier_id = ?')
            ->execute([$supplierId]);
    }

    /**
     * True, pokud sklad drží nenulový stav (stock_levels.qty <> 0) nebo je
     * referencovaný jako zdrojový/cílový sklad libovolného skladového dokladu
     * — v obou případech nesmí jít smazat (jen deaktivovat).
     */
    public function hasStockOrMovements(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                EXISTS (
                    SELECT 1 FROM stock_levels
                     WHERE supplier_id = ? AND warehouse_id = ? AND qty <> 0
                )
                OR EXISTS (
                    SELECT 1 FROM stock_documents
                     WHERE supplier_id = ? AND (warehouse_id = ? OR warehouse_to_id = ?)
                )'
        );
        $stmt->execute([$supplierId, $id, $supplierId, $id, $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function deactivate(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE warehouses SET is_active = 0 WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Hard delete — volající MUSÍ nejdřív ověřit hasStockOrMovements() === false. */
    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM warehouses WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['is_default'] = (bool) $r['is_default'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['is_sellable'] = (bool) $r['is_sellable'];
        return $r;
    }
}
