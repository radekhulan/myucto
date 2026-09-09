<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CatalogPricingRuleRepository
{
    private const COLUMNS = 'r.id, r.supplier_id, r.profile_id, r.match_type, r.match_id,
        r.priority, r.is_active, r.created_at, r.updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . '
            FROM stock_pricing_rules r WHERE r.supplier_id = ? AND r.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM stock_pricing_rules r WHERE r.supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND r.is_active = 1';
        }
        $sql .= " ORDER BY FIELD(r.match_type, 'product','category','manufacturer','vendor','default'),
            r.priority DESC, r.id ASC";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO stock_pricing_rules
            (supplier_id, profile_id, match_type, match_id, priority, is_active)
            VALUES (?, ?, ?, ?, ?, ?)')->execute([
                $supplierId, $data['profile_id'], $data['match_type'], $data['match_id'],
                $data['priority'], (int) $data['is_active'],
            ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE stock_pricing_rules SET
            profile_id = ?, match_type = ?, match_id = ?, priority = ?, is_active = ?
            WHERE supplier_id = ? AND id = ?');
        $stmt->execute([
            $data['profile_id'], $data['match_type'], $data['match_id'], $data['priority'],
            (int) $data['is_active'], $supplierId, $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_pricing_rules WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    public function itemContext(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT manufacturer_id FROM stock_items
            WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $stockItemId]);
        $manufacturerId = $stmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare('SELECT category_id FROM stock_item_categories
            WHERE supplier_id = ? AND stock_item_id = ?');
        $stmt->execute([$supplierId, $stockItemId]);
        $categoryIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt = $this->db->pdo()->prepare('SELECT client_id FROM stock_item_vendors
            WHERE supplier_id = ? AND stock_item_id = ? AND purchase_price IS NOT NULL
            ORDER BY is_preferred DESC, purchase_price ASC, id ASC');
        $stmt->execute([$supplierId, $stockItemId]);
        $vendorIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return [
            'product_id' => $stockItemId,
            'manufacturer_id' => $manufacturerId === false || $manufacturerId === null ? null : (int) $manufacturerId,
            'category_ids' => $categoryIds,
            'vendor_ids' => $vendorIds,
        ];
    }

    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'profile_id', 'priority'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['match_id'] = $row['match_id'] === null ? null : (int) $row['match_id'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
