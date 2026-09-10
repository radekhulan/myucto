<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ProductRelationService
{
    public const TYPES = ['accessory', 'replacement', 'related'];

    public function __construct(private readonly Connection $db) {}

    public function get(int $supplierId, int $itemId): array
    {
        $item = $this->item($supplierId, $itemId);
        if ($item === null) {
            throw new EshopException('not_found', 'Karta zboží nenalezena.', 404);
        }
        $stmt = $this->db->pdo()->prepare('SELECT r.relation_type AS type, r.target_stock_item_id, s.sku AS target_sku,
            s.name AS target_name, r.display_order FROM stock_item_relations r
            JOIN stock_items s ON s.id = r.target_stock_item_id AND s.supplier_id = r.supplier_id
            WHERE r.supplier_id = ? AND r.source_stock_item_id = ?
            ORDER BY FIELD(r.relation_type, \'accessory\', \'replacement\', \'related\'), r.display_order, r.id');
        $stmt->execute([$supplierId, $itemId]);
        $rows = array_map(static function (array $row): array {
            $row['target_stock_item_id'] = (int) $row['target_stock_item_id'];
            $row['display_order'] = (int) $row['display_order'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['row_version' => (int) $item['row_version'], 'items' => $rows];
    }

    public function replace(int $supplierId, int $itemId, array $payload): array
    {
        $expected = $payload['row_version'] ?? null;
        $rows = $payload['items'] ?? null;
        if (!is_int($expected) || $expected < 1 || !is_array($rows) || !array_is_list($rows) || count($rows) > 500) {
            throw new \InvalidArgumentException('Neplatná verze nebo seznam vazeb.');
        }
        $normalized = [];
        $targetIds = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !in_array($row['type'] ?? null, self::TYPES, true)
                || !is_int($row['target_stock_item_id'] ?? null) || $row['target_stock_item_id'] < 1
                || $row['target_stock_item_id'] === $itemId) {
                throw new EshopException('relation_invalid', 'Neplatná produktová vazba.', 422);
            }
            $key = $row['type'] . ':' . $row['target_stock_item_id'];
            if (isset($normalized[$key])) {
                throw new EshopException('relation_duplicate', 'Produktová vazba je v seznamu vícekrát.', 422);
            }
            $targetIds[$row['target_stock_item_id']] = $row['target_stock_item_id'];
            $normalized[$key] = [
                'type' => $row['type'],
                'target_stock_item_id' => $row['target_stock_item_id'],
                'display_order' => is_int($row['display_order'] ?? null) ? $row['display_order'] : $index,
            ];
        }
        return $this->tx(function () use ($supplierId, $itemId, $expected, $normalized, $targetIds): array {
            $item = $this->item($supplierId, $itemId, true);
            if ($item === null) {
                throw new EshopException('not_found', 'Karta zboží nenalezena.', 404);
            }
            if ((int) $item['row_version'] !== $expected) {
                throw new EshopException('version_conflict', 'Kartu mezitím změnil jiný uživatel.', 409);
            }
            if ($targetIds !== []) {
                $ids = array_values($targetIds);
                $stmt = $this->db->pdo()->prepare('SELECT id FROM stock_items WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $stmt->execute([$supplierId, ...$ids]);
                if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
                    throw new EshopException('relation_target_invalid', 'Cílová karta nepatří této firmě.', 422);
                }
            }
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE FROM stock_item_relations WHERE supplier_id = ? AND source_stock_item_id = ?')->execute([$supplierId, $itemId]);
            $stmt = $pdo->prepare('INSERT INTO stock_item_relations
                (supplier_id, source_stock_item_id, target_stock_item_id, relation_type, display_order) VALUES (?, ?, ?, ?, ?)');
            foreach ($normalized as $row) {
                $stmt->execute([$supplierId, $itemId, $row['target_stock_item_id'], $row['type'], $row['display_order']]);
            }
            $stmt = $pdo->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ? AND row_version = ?');
            $stmt->execute([$supplierId, $itemId, $expected]);
            if ($stmt->rowCount() !== 1) {
                throw new EshopException('version_conflict', 'Kartu mezitím změnil jiný uživatel.', 409);
            }
            return $this->get($supplierId, $itemId);
        });
    }

    private function item(int $supplierId, int $itemId, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, row_version FROM stock_items WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function tx(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
