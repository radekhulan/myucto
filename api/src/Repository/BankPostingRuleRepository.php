<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro bank_posting_rules — pravidla účtování opakovaných bankovních
 * transakcí (mini-epic AUTOMATIZACE). Per-tenant (supplier_id), plná kontace MD/D.
 */
final class BankPostingRuleRepository
{
    private const COLS = 'id, supplier_id, name, direction, bank_account_id, counterparty_account, counterparty_bank,
        variable_symbol, message_contains, amount_min, amount_max, debit_account_code, credit_account_code,
        description, mode, mode_set_manually_at, is_active, hit_count, last_hit_at, rejected_streak, last_rejected_tx_id,
        priority, operation_type, system_template_key, auto_amount_cap, applies_currency,
        counterparty_prefix, approved_streak, other_item_id, created_by, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /**
     * Aktivní pravidla pro směr v pořadí {@see \MyInvoice\Service\Accounting\Bank\BankRuleMatcher::rank()}
     * (priorita, pravidlo omezené na vlastní účet před obecným), mezi rovnocennými
     * podle hit_count DESC — konflikt bere vítěze.
     *
     * @return list<array<string,mixed>>
     */
    public function findActive(int $supplierId, string $direction): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM bank_posting_rules
              WHERE supplier_id = ? AND is_active = 1 AND direction = ?
              ORDER BY priority ASC, bank_account_id IS NULL, hit_count DESC, id ASC'
        );
        $stmt->execute([$supplierId, $direction]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM bank_posting_rules WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function findForUpdate(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM bank_posting_rules WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, ?string $direction = null, ?bool $active = null): array
    {
        $where = ['r.supplier_id = ?'];
        $params = [$supplierId];
        if ($direction !== null) {
            $where[] = 'r.direction = ?';
            $params[] = $direction;
        }
        if ($active !== null) {
            $where[] = 'r.is_active = ?';
            $params[] = $active ? 1 : 0;
        }
        $cols = implode(', ', array_map(
            static fn (string $c): string => 'r.' . trim($c),
            explode(',', self::COLS),
        ));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $cols . ', u.name AS created_by_name
               FROM bank_posting_rules r
               LEFT JOIN users u ON u.id = r.created_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY r.is_active DESC, r.name ASC, r.id ASC'
        );
        $stmt->execute($params);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function paginateForTenant(
        int $supplierId,
        ?string $direction,
        ?bool $active,
        int $limit,
        int $offset,
    ): array {
        $where = ['r.supplier_id = ?'];
        $params = [$supplierId];
        if ($direction !== null) {
            $where[] = 'r.direction = ?';
            $params[] = $direction;
        }
        if ($active !== null) {
            $where[] = 'r.is_active = ?';
            $params[] = $active ? 1 : 0;
        }
        $pdo = $this->db->pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM bank_posting_rules r WHERE ' . implode(' AND ', $where));
        $count->execute($params);

        $cols = implode(', ', array_map(
            static fn (string $column): string => 'r.' . trim($column),
            explode(',', self::COLS),
        ));
        $stmt = $pdo->prepare(
            'SELECT ' . $cols . ', u.name AS created_by_name
               FROM bank_posting_rules r
               LEFT JOIN users u ON u.id = r.created_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY r.is_active DESC, r.name ASC, r.id ASC
              LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $value) $stmt->bindValue($index + 1, $value);
        $stmt->bindValue(count($params) + 1, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /**
     * @param array<string,mixed> $data normalizovaná data pravidla (mode vždy 'suggest' u create)
     */
    public function insert(int $supplierId, array $data, ?int $createdBy): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_posting_rules
                (supplier_id, name, direction, bank_account_id, counterparty_account, counterparty_bank, variable_symbol,
                 message_contains, amount_min, amount_max, debit_account_code, credit_account_code,
                 description, mode, mode_set_manually_at, is_active, priority, operation_type, system_template_key,
                 auto_amount_cap, applies_currency, counterparty_prefix, other_item_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['name'],
            $data['direction'],
            $data['bank_account_id'] ?? null,
            $data['counterparty_account'] ?? null,
            $data['counterparty_bank'] ?? null,
            $data['variable_symbol'] ?? null,
            $data['message_contains'] ?? null,
            $data['amount_min'] ?? null,
            $data['amount_max'] ?? null,
            $data['debit_account_code'],
            $data['credit_account_code'],
            $data['description'] ?? null,
            $data['mode'] ?? 'suggest',
            $data['mode_set_manually_at'] ?? null,
            array_key_exists('is_active', $data) ? (int) $data['is_active'] : 1,
            $data['priority'] ?? 100,
            $data['operation_type'] ?? null,
            $data['system_template_key'] ?? null,
            $data['auto_amount_cap'] ?? null,
            $data['applies_currency'] ?? 'CZK',
            $data['counterparty_prefix'] ?? null,
            $data['other_item_id'] ?? null,
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
            'name', 'direction', 'bank_account_id', 'counterparty_account', 'counterparty_bank', 'variable_symbol',
            'message_contains', 'amount_min', 'amount_max', 'debit_account_code', 'credit_account_code',
            'description', 'mode', 'mode_set_manually_at', 'is_active',
            'priority', 'operation_type', 'auto_amount_cap', 'applies_currency', 'counterparty_prefix',
            'other_item_id',
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = ?";
                $params[] = $col === 'is_active' ? (int) $fields[$col] : $fields[$col];
            }
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_posting_rules SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM bank_posting_rules WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Úspěšné použití pravidla (approve / auto post): hit_count++, reset streaku. */
    public function recordHit(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE bank_posting_rules
                SET hit_count = hit_count + 1, last_hit_at = NOW(), rejected_streak = 0, last_rejected_tx_id = NULL
              WHERE id = ?'
        )->execute([$id]);
    }

    /** @return array{approved_streak:int,hit_count:int} */
    public function recordApprove(int $id, bool $clean): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE bank_posting_rules
                SET hit_count = hit_count + 1, last_hit_at = NOW(), rejected_streak = 0,
                    last_rejected_tx_id = NULL, approved_streak = IF(?, approved_streak + 1, 0)
              WHERE id = ?'
        )->execute([$clean ? 1 : 0, $id]);
        $stmt = $pdo->prepare('SELECT approved_streak, hit_count FROM bank_posting_rules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['approved_streak' => 0, 'hit_count' => 0];
        return ['approved_streak' => (int) $row['approved_streak'], 'hit_count' => (int) $row['hit_count']];
    }

    public function resetApprovedStreak(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE bank_posting_rules SET approved_streak = 0 WHERE id = ?')->execute([$id]);
    }

    /**
     * Odmítnutí návrhu pravidla. Streak++ jen při JINÉ tx než minule (M3b);
     * při 3. odmítnutí (distinct tx) pravidlo deaktivuje (R7).
     *
     * @return array{streak:int, disabled:bool}
     */
    public function recordReject(int $id, int $txId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT rejected_streak, last_rejected_tx_id, is_active FROM bank_posting_rules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['streak' => 0, 'disabled' => false];
        }
        $streak = (int) $row['rejected_streak'];
        $lastTx = $row['last_rejected_tx_id'] === null ? null : (int) $row['last_rejected_tx_id'];
        if ($lastTx !== $txId) {
            $streak++;
        }
        $disabled = $streak >= 3;
        $pdo->prepare(
            'UPDATE bank_posting_rules
                SET rejected_streak = ?, last_rejected_tx_id = ?, is_active = ?, approved_streak = 0
              WHERE id = ?'
        )->execute([$streak, $txId, $disabled ? 0 : (int) $row['is_active'], $id]);
        return ['streak' => $streak, 'disabled' => $disabled];
    }

    /**
     * Dimenze pravidel (typ => hodnota) pro více pravidel jedním dotazem.
     *
     * @param list<int> $ruleIds
     * @return array<int,array<int,int>> id pravidla => typ => hodnota
     */
    public function dimensionsFor(int $supplierId, array $ruleIds): array
    {
        $ruleIds = array_values(array_unique(array_filter(array_map('intval', $ruleIds), static fn (int $id): bool => $id > 0)));
        if ($ruleIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT rule_id, dimension_type_id, dimension_value_id
               FROM bank_posting_rule_dimensions
              WHERE supplier_id = ? AND rule_id IN (' . implode(',', array_fill(0, count($ruleIds), '?')) . ')
           ORDER BY rule_id, dimension_type_id'
        );
        $stmt->execute([$supplierId, ...$ruleIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['rule_id']][(int) $row['dimension_type_id']] = (int) $row['dimension_value_id'];
        }
        return $out;
    }

    /** @return array<int,int> typ => hodnota */
    public function dimensions(int $supplierId, int $ruleId): array
    {
        return $this->dimensionsFor($supplierId, [$ruleId])[$ruleId] ?? [];
    }

    /**
     * Nahradí dimenze pravidla. Hodnoty musí být ověřené volajícím
     * ({@see \MyInvoice\Service\Accounting\Dimension\DimensionService::normalize()}).
     *
     * @param array<int,int> $dimensions typ => hodnota
     */
    public function replaceDimensions(int $supplierId, int $ruleId, array $dimensions): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM bank_posting_rule_dimensions WHERE supplier_id = ? AND rule_id = ?')
            ->execute([$supplierId, $ruleId]);
        if ($dimensions === []) {
            return;
        }
        $rows = [];
        $params = [];
        foreach ($dimensions as $typeId => $valueId) {
            $rows[] = '(?, ?, ?, ?)';
            array_push($params, $ruleId, $supplierId, (int) $typeId, (int) $valueId);
        }
        $pdo->prepare(
            'INSERT INTO bank_posting_rule_dimensions (rule_id, supplier_id, dimension_type_id, dimension_value_id)
             VALUES ' . implode(',', $rows)
        )->execute($params);
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['bank_account_id'] = $r['bank_account_id'] === null ? null : (int) $r['bank_account_id'];
        $r['amount_min'] = $r['amount_min'] === null ? null : (float) $r['amount_min'];
        $r['amount_max'] = $r['amount_max'] === null ? null : (float) $r['amount_max'];
        $r['auto_amount_cap'] = $r['auto_amount_cap'] === null ? null : (float) $r['auto_amount_cap'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['hit_count'] = (int) $r['hit_count'];
        $r['priority'] = (int) $r['priority'];
        $r['approved_streak'] = (int) $r['approved_streak'];
        $r['rejected_streak'] = (int) $r['rejected_streak'];
        $r['last_rejected_tx_id'] = $r['last_rejected_tx_id'] === null ? null : (int) $r['last_rejected_tx_id'];
        $r['created_by'] = $r['created_by'] === null ? null : (int) $r['created_by'];
        $r['other_item_id'] = ($r['other_item_id'] ?? null) === null ? null : (int) $r['other_item_id'];
        if (array_key_exists('created_by_name', $r)) {
            $r['created_by_name'] = $r['created_by_name'] === null ? null : (string) $r['created_by_name'];
        }
        return $r;
    }
}
