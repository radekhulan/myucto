<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_media — obrázky/dokumenty karty zboží (Epic ESHOP).
 * Per tenant (supplier_id). storage_key = sha256 obsahu (content-addressed,
 * vzor DocumentStorage). Max 1 is_primary na kartu vynucuje setPrimary().
 */
final class StockMediaRepository
{
    private const COLUMNS =
        'id, supplier_id, stock_item_id, media_type, storage_key, original_name,
         mime_type, size_bytes, title, alt_text, display_order, is_primary,
         export_eshop, created_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_media WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findForItemByStorageKey(int $supplierId, int $stockItemId, string $storageKey): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_media
              WHERE supplier_id = ? AND stock_item_id = ? AND storage_key = ?
              ORDER BY id LIMIT 1'
        );
        $stmt->execute([$supplierId, $stockItemId, $storageKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_media
              WHERE supplier_id = ? AND stock_item_id = ?
              ORDER BY is_primary DESC, display_order ASC, id ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Kolik dalších řádků (napříč kartami tenanta) sdílí tentýž storage_key (ref-count pro orphan delete). */
    public function countByStorageKey(int $supplierId, string $storageKey, int $excludeId = 0): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM stock_media
              WHERE supplier_id = ? AND storage_key = ? AND id <> ?'
        );
        $stmt->execute([$supplierId, $storageKey, $excludeId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{media_type:string, storage_key:string, original_name?:?string,
     *              mime_type?:?string, size_bytes?:?int, title?:?string, alt_text?:?string,
     *              display_order?:int, export_eshop?:bool} $data
     */
    public function add(int $supplierId, int $stockItemId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_media
                (supplier_id, stock_item_id, media_type, storage_key, original_name,
                 mime_type, size_bytes, title, alt_text, display_order, export_eshop)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $stockItemId,
            (string) $data['media_type'],
            (string) $data['storage_key'],
            $data['original_name'] ?? null,
            $data['mime_type'] ?? null,
            isset($data['size_bytes']) ? (int) $data['size_bytes'] : null,
            $data['title'] ?? null,
            $data['alt_text'] ?? null,
            (int) ($data['display_order'] ?? 0),
            (int) ($data['export_eshop'] ?? true),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{title?:?string, alt_text?:?string, export_eshop?:bool, display_order?:int} $data
     */
    public function updateMeta(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_media SET title = ?, alt_text = ?, export_eshop = ?, display_order = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $data['title'] ?? null,
            $data['alt_text'] ?? null,
            (int) ($data['export_eshop'] ?? true),
            (int) ($data['display_order'] ?? 0),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function setDisplayOrder(int $supplierId, int $id, int $order): void
    {
        $this->db->pdo()->prepare(
            'UPDATE stock_media SET display_order = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$order, $id, $supplierId]);
    }

    /** Shodí is_primary u všech médií karty (volat v tx před nastavením nového primary). */
    public function clearPrimary(int $supplierId, int $stockItemId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE stock_media SET is_primary = 0 WHERE supplier_id = ? AND stock_item_id = ?'
        )->execute([$supplierId, $stockItemId]);
    }

    public function setPrimaryFlag(int $supplierId, int $id, bool $isPrimary): void
    {
        $this->db->pdo()->prepare(
            'UPDATE stock_media SET is_primary = ? WHERE id = ? AND supplier_id = ?'
        )->execute([(int) $isPrimary, $id, $supplierId]);
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_media WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        $r['size_bytes'] = $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null;
        $r['display_order'] = (int) $r['display_order'];
        $r['is_primary'] = (bool) $r['is_primary'];
        $r['export_eshop'] = (bool) $r['export_eshop'];
        return $r;
    }
}
