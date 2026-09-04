<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro expense_classification_rules — per-tenant pravidla klasifikace druhu
 * výdaje na řádku přijaté faktury (§DM). Vzor a kontrakt shodný s
 * {@see BankPostingRuleRepository}, ať se dvoje pravidla v aplikaci nechovají jinak.
 */
final class ExpenseClassificationRuleRepository
{
    private const COLS = 'id, supplier_id, name, vendor_client_id, vendor_name_contains,
        description_contains, amount_min, amount_max, expense_kind, target_account_code, recurring_prepaid, application_mode,
        priority, is_active, hit_count, last_hit_at, created_by, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /**
     * Aktivní pravidla tenanta v pořadí, v jakém je bere first-match-wins.
     *
     * hit_count DESC jako druhotné kritérium: při shodné prioritě rozhoduje osvědčené
     * pravidlo, ne náhodné pořadí v tabulce (týž tie-break jako u bankovních pravidel).
     *
     * @return list<array<string,mixed>>
     */
    public function activeFor(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM expense_classification_rules
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
            'SELECT ' . self::COLS . ' FROM expense_classification_rules WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, ?string $expenseKind = null, ?bool $active = null): array
    {
        [$filter, $params] = $this->filters($supplierId, $expenseKind, $active);
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->prefixedCols() . ', u.name AS created_by_name, c.company_name AS vendor_client_name
               FROM expense_classification_rules r
               LEFT JOIN users u ON u.id = r.created_by
               LEFT JOIN clients c ON c.id = r.vendor_client_id AND c.supplier_id = r.supplier_id
              WHERE r.supplier_id = ?' . $filter . '
              ORDER BY r.is_active DESC, r.priority ASC, r.name ASC, r.id ASC'
        );
        $stmt->execute($params);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function paginateForTenant(
        int $supplierId,
        ?string $expenseKind,
        ?bool $active,
        int $limit,
        int $offset,
    ): array {
        [$filter, $params] = $this->filters($supplierId, $expenseKind, $active);
        $pdo = $this->db->pdo();
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM expense_classification_rules r WHERE r.supplier_id = ?' . $filter
        );
        $count->execute($params);

        $stmt = $pdo->prepare(
            'SELECT ' . $this->prefixedCols() . ', u.name AS created_by_name, c.company_name AS vendor_client_name
               FROM expense_classification_rules r
               LEFT JOIN users u ON u.id = r.created_by
               LEFT JOIN clients c ON c.id = r.vendor_client_id AND c.supplier_id = r.supplier_id
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

    /**
     * Volitelné filtry jako PŘÍVĚSEK k pevnému `WHERE r.supplier_id = ?`, který zůstává
     * napsaný přímo v každém SQL. Záměrně jinak než BankPostingRuleRepository, kde je celý
     * WHERE skládaný polem — tam pak tenant predikát není ve stejném PHP statementu jako
     * SELECT a TenantPredicateTest ho musí whitelistovat. Whitelist je ale trvalá slepá
     * skvrna: jakmile tam repozitář jednou je, přestane ho hlídat i u budoucích dotazů.
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function filters(int $supplierId, ?string $expenseKind, ?bool $active): array
    {
        $sql = '';
        $params = [$supplierId];
        if ($expenseKind !== null) {
            $sql .= ' AND r.expense_kind = ?';
            $params[] = $expenseKind;
        }
        if ($active !== null) {
            $sql .= ' AND r.is_active = ?';
            $params[] = $active ? 1 : 0;
        }
        return [$sql, $params];
    }

    /**
     * @param array<string,mixed> $data normalizovaná data pravidla
     */
    public function insert(int $supplierId, array $data, ?int $createdBy): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO expense_classification_rules
                (supplier_id, name, vendor_client_id, vendor_name_contains, description_contains,
                 amount_min, amount_max, expense_kind, target_account_code, recurring_prepaid, application_mode, priority, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['name'],
            $data['vendor_client_id'] ?? null,
            $data['vendor_name_contains'] ?? null,
            $data['description_contains'] ?? null,
            $data['amount_min'] ?? null,
            $data['amount_max'] ?? null,
            $data['expense_kind'],
            $data['target_account_code'] ?? null,
            array_key_exists('recurring_prepaid', $data) ? (int) (bool) $data['recurring_prepaid'] : 0,
            $data['application_mode'] ?? 'auto',
            $data['priority'] ?? 100,
            array_key_exists('is_active', $data) ? (int) $data['is_active'] : 1,
            $createdBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Částečný update — jen předané klíče. Vrací true při zásahu do řádku tenanta.
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $supplierId, int $id, array $fields): bool
    {
        $allowed = [
            'name', 'vendor_client_id', 'vendor_name_contains', 'description_contains',
            'amount_min', 'amount_max', 'expense_kind', 'target_account_code', 'recurring_prepaid', 'application_mode',
            'priority', 'is_active',
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = ?";
                $params[] = in_array($col, ['is_active', 'recurring_prepaid'], true) ? (int) (bool) $fields[$col] : $fields[$col];
            }
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE expense_classification_rules SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM expense_classification_rules WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Pravidlo se prosadilo do uloženého řádku. Scoped na tenanta i tady — id samo
     * o sobě je cizí vstup z API a nesmí sáhnout přes hranici firmy.
     */
    public function recordHit(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE expense_classification_rules
                SET hit_count = hit_count + 1, last_hit_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        )->execute([$id, $supplierId]);
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
        $r['vendor_client_id'] = $r['vendor_client_id'] === null ? null : (int) $r['vendor_client_id'];
        $r['amount_min'] = $r['amount_min'] === null ? null : (float) $r['amount_min'];
        $r['amount_max'] = $r['amount_max'] === null ? null : (float) $r['amount_max'];
        $r['priority'] = (int) $r['priority'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['recurring_prepaid'] = (bool) $r['recurring_prepaid'];
        $r['hit_count'] = (int) $r['hit_count'];
        $r['created_by'] = $r['created_by'] === null ? null : (int) $r['created_by'];
        if (array_key_exists('created_by_name', $r)) {
            $r['created_by_name'] = $r['created_by_name'] === null ? null : (string) $r['created_by_name'];
        }
        if (array_key_exists('vendor_client_name', $r)) {
            $r['vendor_client_name'] = $r['vendor_client_name'] === null ? null : (string) $r['vendor_client_name'];
        }
        return $r;
    }
}
