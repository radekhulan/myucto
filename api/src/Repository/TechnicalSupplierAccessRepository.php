<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Technický rozsah je oddělený od obchodního membershipu a čte se vždy živě. */
final class TechnicalSupplierAccessRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{all_suppliers:bool,supplier_ids:list<int>} */
    public function scopeForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(a.all_suppliers, 0) FROM users u
               JOIN roles r ON r.id = u.role_id
               LEFT JOIN user_technical_access a ON a.user_id = u.id
              WHERE u.id = ?
                AND r.role_type = 'instance_admin' AND r.system_key = 'instance_admin'"
        );
        $stmt->execute([$userId]);
        $all = $stmt->fetchColumn();
        if ($all === false) return ['all_suppliers' => false, 'supplier_ids' => []];
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM user_technical_suppliers WHERE user_id = ? ORDER BY supplier_id');
        $stmt->execute([$userId]);
        return ['all_suppliers' => (bool) $all, 'supplier_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))];
    }

    public function allows(int $userId, int $supplierId): bool
    {
        if ($userId <= 0 || $supplierId <= 0) return false;
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
               JOIN supplier s ON s.id = ?
               LEFT JOIN user_technical_access a ON a.user_id = u.id
              WHERE u.id = ? AND u.is_active = 1 AND r.is_active = 1
                AND r.role_type = 'instance_admin' AND r.system_key = 'instance_admin'
                AND (a.all_suppliers = 1 OR EXISTS (
                    SELECT 1 FROM user_technical_suppliers t WHERE t.user_id = u.id AND t.supplier_id = s.id))"
        );
        $stmt->execute([$supplierId, $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array{supplier_id:int,name:string}> */
    public function listForUser(int $userId, int $afterId = 0, int $limit = 50): array
    {
        // Jeden řádek navíc umožní API určit další kurzor bez COUNT nad celým seznamem.
        $limit = max(1, min(101, $limit));
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id AS supplier_id, COALESCE(NULLIF(s.display_name, ''), s.company_name) AS name
               FROM supplier s JOIN users u ON u.id = ? JOIN roles r ON r.id = u.role_id
               LEFT JOIN user_technical_access a ON a.user_id = u.id
              WHERE s.id > ? AND u.is_active = 1 AND r.is_active = 1
                AND r.role_type = 'instance_admin' AND r.system_key = 'instance_admin'
                AND (a.all_suppliers = 1 OR EXISTS (
                    SELECT 1 FROM user_technical_suppliers t WHERE t.user_id = u.id AND t.supplier_id = s.id))
              ORDER BY s.id LIMIT {$limit}"
        );
        $stmt->execute([$userId, max(0, $afterId)]);
        return array_map(static fn (array $row): array => [
            'supplier_id' => (int) $row['supplier_id'], 'name' => (string) $row['name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<int> $supplierIds */
    public function replaceForUser(int $userId, bool $allSuppliers, array $supplierIds): void
    {
        if (count($supplierIds) > 1000 || ($allSuppliers && $supplierIds !== [])) {
            throw new \InvalidArgumentException('Zvolte všechny firmy, nebo nejvýše 1000 jednotlivých firem.');
        }
        foreach ($supplierIds as $id) {
            if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Neplatné ID firmy.');
        }
        if (count(array_unique($supplierIds)) !== count($supplierIds)) {
            throw new \InvalidArgumentException('Duplicitní ID firmy.');
        }
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            // Stejný zámek jako převod role; souběh nemůže vrátit rozsah jinému typu účtu.
            $stmt = $pdo->prepare(
                "SELECT r.role_type, r.system_key FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.id = ? FOR UPDATE"
            );
            $stmt->execute([$userId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$role || $role['role_type'] !== 'instance_admin' || $role['system_key'] !== 'instance_admin') {
                throw new \InvalidArgumentException('Rozsah lze přiřadit jen správci instance.');
            }
            if ($supplierIds !== []) {
                $marks = implode(',', array_fill(0, count($supplierIds), '?'));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier WHERE id IN ({$marks})");
                $stmt->execute($supplierIds);
                if ((int) $stmt->fetchColumn() !== count($supplierIds)) {
                    throw new \InvalidArgumentException('Některá firma neexistuje.');
                }
            }
            $pdo->prepare('INSERT INTO user_technical_access (user_id, all_suppliers) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE all_suppliers = VALUES(all_suppliers)')->execute([$userId, (int) $allSuppliers]);
            $pdo->prepare('DELETE FROM user_technical_suppliers WHERE user_id = ?')->execute([$userId]);
            if ($supplierIds !== []) {
                $values = implode(',', array_fill(0, count($supplierIds), '(?, ?)'));
                $params = [];
                foreach ($supplierIds as $id) { $params[] = $userId; $params[] = $id; }
                $pdo->prepare('INSERT INTO user_technical_suppliers (user_id, supplier_id) VALUES ' . $values)->execute($params);
            }
            if ($owns) $pdo->commit();
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
