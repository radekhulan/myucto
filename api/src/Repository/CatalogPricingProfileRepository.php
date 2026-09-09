<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CatalogPricingProfileRepository
{
    private const COLUMNS = 'id, supplier_id, code, name, currency_code, calculation_mode,
        percentage, rounding, fx_source, max_rate_age_days, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . '
            FROM stock_pricing_profiles WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . '
            FROM stock_pricing_profiles WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM stock_pricing_profiles WHERE supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY currency_code, name, id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO stock_pricing_profiles
            (supplier_id, code, name, currency_code, calculation_mode, percentage,
             rounding, fx_source, max_rate_age_days, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $supplierId, $data['code'], $data['name'], $data['currency_code'],
                $data['calculation_mode'], $data['percentage'], $data['rounding'],
                $data['fx_source'], $data['max_rate_age_days'], (int) $data['is_active'],
            ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE stock_pricing_profiles SET
            code = ?, name = ?, currency_code = ?, calculation_mode = ?, percentage = ?,
            rounding = ?, fx_source = ?, max_rate_age_days = ?, is_active = ?
            WHERE supplier_id = ? AND id = ?');
        $stmt->execute([
            $data['code'], $data['name'], $data['currency_code'], $data['calculation_mode'],
            $data['percentage'], $data['rounding'], $data['fx_source'],
            $data['max_rate_age_days'], (int) $data['is_active'], $supplierId, $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_pricing_profiles WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'max_rate_age_days'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
