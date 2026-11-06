<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro revenue_classification_rules: per-tenant pravidla účtu položky
 * vydané faktury (migrace 2217). Kontrakt shodný s {@see ExpenseClassificationRuleRepository}:
 * priority ASC, při shodě rozhoduje hit_count, tenant predikát je v každém SQL.
 */
final class RevenueClassificationRuleRepository
{
    private const COLS = 'id, supplier_id, name, item_code, description_contains, vat_rate,
        target_account_code, priority, is_active, hit_count, last_hit_at, created_by, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /**
     * Aktivní pravidla tenanta v pořadí, v jakém je bere first-match-wins.
     *
     * @return list<array<string,mixed>>
     */
    public function activeFor(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM revenue_classification_rules
              WHERE supplier_id = ? AND is_active = 1
              ORDER BY priority ASC, hit_count DESC, id ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM revenue_classification_rules WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function paginateForTenant(int $supplierId, ?bool $active, int $limit, int $offset): array
    {
        $filter = '';
        $params = [$supplierId];
        if ($active !== null) {
            $filter = ' AND r.is_active = ?';
            $params[] = $active ? 1 : 0;
        }
        $pdo = $this->db->pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM revenue_classification_rules r WHERE r.supplier_id = ?' . $filter);
        $count->execute($params);

        $stmt = $pdo->prepare(
            'SELECT ' . $this->prefixedCols() . ', u.name AS created_by_name
               FROM revenue_classification_rules r
               LEFT JOIN users u ON u.id = r.created_by
              WHERE r.supplier_id = ?' . $filter . '
              ORDER BY r.is_active DESC, r.priority ASC, r.name ASC, r.id ASC
              LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** @param array<string,mixed> $data normalizovaná data pravidla */
    public function insert(int $supplierId, array $data, ?int $createdBy): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO revenue_classification_rules
                (supplier_id, name, item_code, description_contains, vat_rate, target_account_code,
                 priority, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['name'],
            $data['item_code'] ?? null,
            $data['description_contains'] ?? null,
            $data['vat_rate'] ?? null,
            $data['target_account_code'],
            $data['priority'] ?? 100,
            array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
            $createdBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    public function update(int $supplierId, int $id, array $fields): bool
    {
        $allowed = ['name', 'item_code', 'description_contains', 'vat_rate', 'target_account_code', 'priority', 'is_active'];
        $sets = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = ?";
                $params[] = $col === 'is_active' ? (int) (bool) $fields[$col] : $fields[$col];
            }
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE revenue_classification_rules SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM revenue_classification_rules WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Pravidla se prosadila do uložených položek, jedním dotazem za pravidlo.
     *
     * @param array<int,int> $hits id pravidla => počet položek
     */
    public function recordHits(int $supplierId, array $hits): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE revenue_classification_rules
                SET hit_count = hit_count + ?, last_hit_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        );
        foreach ($hits as $ruleId => $count) {
            if ($ruleId > 0 && $count > 0) {
                $stmt->execute([$count, $ruleId, $supplierId]);
            }
        }
    }

    private function prefixedCols(): string
    {
        return implode(', ', array_map(
            static fn (string $c): string => 'r.' . trim($c),
            explode(',', preg_replace('/\s+/', ' ', self::COLS) ?? self::COLS),
        ));
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['vat_rate'] = $r['vat_rate'] === null ? null : (float) $r['vat_rate'];
        $r['priority'] = (int) $r['priority'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['hit_count'] = (int) $r['hit_count'];
        $r['created_by'] = $r['created_by'] === null ? null : (int) $r['created_by'];
        if (array_key_exists('created_by_name', $r)) {
            $r['created_by_name'] = $r['created_by_name'] === null ? null : (string) $r['created_by_name'];
        }
        return $r;
    }
}
