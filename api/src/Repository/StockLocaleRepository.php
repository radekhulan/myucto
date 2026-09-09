<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_locales — číselník jazykových mutací e-shopu (migrace 1370).
 * Per tenant (supplier_id); UNIQUE (supplier_id, code). Kód je shodný
 * s stock_item_i18n.locale / stock_category_i18n.locale.
 */
final class StockLocaleRepository
{
    private const COLUMNS =
        'id, supplier_id, code, name, display_order, is_default, archived,
         created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_locales WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_locales WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM stock_locales WHERE supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND archived = 0';
        }
        $sql .= ' ORDER BY display_order ASC, code ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Kódy jazyků, které smí zápisová cesta přijmout. Archivované jsou uvnitř
     * schválně: archivace jazyk jen stáhne z nabídky pro nové řádky, existující
     * překlad musí jít dál uložit (formulář posílá celou sadu zpátky).
     *
     * @return list<string>
     */
    public function codes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code FROM stock_locales WHERE supplier_id = ? ORDER BY display_order, code'
        );
        $stmt->execute([$supplierId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @param array{code:string, name:string, display_order?:int, is_default?:bool, archived?:bool} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_locales
                (supplier_id, code, name, display_order, is_default, archived)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['name'],
            (int) ($data['display_order'] ?? 0),
            (int) ($data['is_default'] ?? false),
            (int) ($data['archived'] ?? false),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Založí výchozí češtinu, jen pokud ještě neexistuje. INSERT IGNORE drží
     * operaci idempotentní i při souběžném prvním uložení na různých kartách.
     */
    public function ensureCzech(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO stock_locales
                (supplier_id, code, name, display_order, is_default, archived)
             SELECT ?, ?, ?, 0,
                    NOT EXISTS (SELECT 1 FROM stock_locales WHERE supplier_id = ?),
                    0'
        )->execute([$supplierId, 'cs', 'Čeština', $supplierId]);
    }

    /**
     * @param array{code:string, name:string, display_order?:int, is_default?:bool, archived?:bool} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_locales SET
                code = ?, name = ?, display_order = ?, is_default = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['name'],
            (int) ($data['display_order'] ?? 0),
            (int) ($data['is_default'] ?? false),
            (int) ($data['archived'] ?? false),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Výchozí jazyk je nejvýš jeden — ostatní se shodí. */
    public function clearDefaultExcept(int $supplierId, int $id): void
    {
        $this->db->pdo()
            ->prepare('UPDATE stock_locales SET is_default = 0 WHERE supplier_id = ? AND id <> ?')
            ->execute([$supplierId, $id]);
    }

    /** Má jazyk aspoň jeden překlad? (blokuje hard delete — smazáním by se ztratil obsah) */
    public function isReferenced(int $supplierId, string $code): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM stock_item_i18n WHERE supplier_id = ? AND locale = ?
                UNION ALL
                SELECT 1 FROM stock_category_i18n WHERE supplier_id = ? AND locale = ?
             )'
        );
        $stmt->execute([$supplierId, $code, $supplierId, $code]);
        return (bool) $stmt->fetchColumn();
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_locales WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['display_order'] = (int) $r['display_order'];
        $r['is_default'] = (bool) $r['is_default'];
        $r['archived'] = (bool) $r['archived'];
        return $r;
    }
}
