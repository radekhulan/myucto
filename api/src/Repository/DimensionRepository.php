<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionValueHistoryGuard;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionGuard;
use PDO;

/**
 * Typy a hodnoty dimenzí (Firma → Dimenze, migrace 1860).
 *
 * Firma vidí svoje firemní typy (`supplier_id`) a globální typy své skupiny firem
 * (`supplier_group_id`). Každý dotaz proto nese predikát {@see visibleSql()}; skupinu
 * firmy čte {@see groupIdOf()} ze `supplier`, nikdy z požadavku.
 */
final class DimensionRepository
{
    public const KINDS = ['cost_center', 'project', 'vehicle', 'location', 'deal', 'custom'];

    private const TYPE_COLUMNS = 'id, supplier_id, supplier_group_id, code, name, kind, system_key, is_active, show_on_documents, sort_order, drives_accounts, drives_accounts_mask, requires_approval, approval_threshold, created_at, updated_at';
    private const VALUE_COLUMNS = 'v.id, v.type_id, v.semantic_key, v.supplier_id, v.supplier_group_id, v.parent_id, v.code, v.name, v.is_active,
        v.responsible_user_id, v.responsible_note, v.car_id, v.project_id, v.cost_center_id, v.note, v.sort_order,
        v.created_at, v.updated_at';

    /** @var array<int,?int> */
    private array $groupCache = [];

    private readonly DimensionValueHistoryGuard $history;

    public function __construct(private readonly Connection $db)
    {
        $this->history = new DimensionValueHistoryGuard($db);
    }

    public function groupIdOf(int $supplierId): ?int
    {
        if (!array_key_exists($supplierId, $this->groupCache)) {
            $stmt = $this->db->pdo()->prepare('SELECT supplier_group_id FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $group = $stmt->fetchColumn();
            $this->groupCache[$supplierId] = $group === false || $group === null ? null : (int) $group;
        }
        return $this->groupCache[$supplierId];
    }

    public function forgetGroupCache(): void
    {
        $this->groupCache = [];
    }

    /**
     * Predikát viditelnosti typu/hodnoty pro firmu. Bez skupiny se porovnává s 0 —
     * žádná skupina id 0 nemá, takže globální řádky cizích skupin nikdy neprojdou.
     *
     * @return array{0:string,1:list<int>}
     */
    public function visibleSql(int $supplierId, string $alias): array
    {
        return [
            "({$alias}.supplier_id = ? OR {$alias}.supplier_group_id = ?)",
            [$supplierId, $this->groupIdOf($supplierId) ?? 0],
        ];
    }

    public function enabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT dimensions_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function supplierName(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare("SELECT COALESCE(NULLIF(display_name, ''), company_name) FROM supplier WHERE id = ?");
        $stmt->execute([$supplierId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    public function setEnabled(int $supplierId, bool $enabled): void
    {
        (new SystemDimensionGuard($this->db))->setEnabled($supplierId, $enabled, fn () => $this->db->pdo()->prepare('UPDATE supplier SET dimensions_enabled = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, $supplierId]));
    }

    // ── typy ─────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function listTypes(int $supplierId, bool $includeInactive = true): array
    {
        [$vis, $params] = $this->visibleSql($supplierId, 't');
        $sql = 'SELECT ' . self::prefixed(self::TYPE_COLUMNS, 't') . " FROM dimension_types t WHERE {$vis}";
        if (!$includeInactive) {
            $sql .= ' AND t.is_active = 1';
        }
        $sql .= ' ORDER BY t.supplier_group_id IS NULL, t.sort_order, t.name';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'castType'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findType(int $supplierId, int $typeId): ?array
    {
        [$vis, $params] = $this->visibleSql($supplierId, 't');
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::prefixed(self::TYPE_COLUMNS, 't') . " FROM dimension_types t WHERE t.id = ? AND {$vis}"
        );
        $stmt->execute([$typeId, ...$params]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castType($row);
    }

    /** Typ daného druhu na zvolené úrovni (firma, nebo skupina firmy). */
    public function findTypeByKind(int $supplierId, string $kind, bool $global): ?array
    {
        $group = $this->groupIdOf($supplierId);
        if ($global && $group === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::prefixed(self::TYPE_COLUMNS, 't') . ' FROM dimension_types t
              WHERE t.kind = ? AND ' . ($global ? 't.supplier_group_id = ?' : 't.supplier_id = ?') . '
              ORDER BY t.is_active DESC, t.id LIMIT 1'
        );
        $stmt->execute([$kind, $global ? $group : $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castType($row);
    }

    /**
     * @param array{code:string,name:string,kind:string,is_active?:bool,show_on_documents?:bool,sort_order?:int} $data
     */
    public function createType(int $supplierId, bool $global, array $data): int
    {
        $group = $this->groupIdOf($supplierId);
        if ($global && $group === null) {
            throw new \InvalidArgumentException('Firma nepatří do skupiny firem — globální typ nelze založit.');
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO dimension_types (supplier_id, supplier_group_id, code, name, kind, is_active, show_on_documents, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $global ? null : $supplierId,
            $global ? $group : null,
            $data['code'],
            $data['name'],
            $data['kind'],
            ($data['is_active'] ?? true) ? 1 : 0,
            ($data['show_on_documents'] ?? true) ? 1 : 0,
            (int) ($data['sort_order'] ?? 0),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $changes */
    public function updateType(int $supplierId, int $typeId, array $changes, ?int $actor = null, string $source = 'repository'): void
    {
        (new SystemDimensionGuard($this->db))->change('type', $typeId, $changes, fn () => $this->writeType($supplierId, $typeId, $changes), $actor, $source);
    }

    private function writeType(int $supplierId, int $typeId, array $changes): void
    {
        $sets = [];
        $params = [];
        foreach (['name' => 's', 'is_active' => 'b', 'show_on_documents' => 'b', 'sort_order' => 'i', 'drives_accounts' => 'b', 'drives_accounts_mask' => 's', 'requires_approval' => 'b', 'approval_threshold' => 'd'] as $col => $kind) {
            if (!array_key_exists($col, $changes)) {
                continue;
            }
            $sets[] = "{$col} = ?";
            $params[] = match ($kind) {
                'b' => $changes[$col] ? 1 : 0,
                'i' => (int) $changes[$col],
                'd' => $changes[$col] === null ? null : number_format((float) $changes[$col], 2, '.', ''),
                default => (string) $changes[$col],
            };
        }
        if ($sets === []) {
            return;
        }
        [$vis, $visParams] = $this->visibleSql($supplierId, 'dimension_types');
        $this->db->pdo()->prepare(
            'UPDATE dimension_types SET ' . implode(', ', $sets) . " WHERE id = ? AND {$vis}"
        )->execute([...$params, $typeId, ...$visParams]);
    }

    public function typeInUse(int $typeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM journal_entry_line_dimensions WHERE dimension_type_id = ?)
                 OR EXISTS (SELECT 1 FROM document_dimensions WHERE dimension_type_id = ?)
                 OR EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits WHERE dimension_type_id = ?)
                 OR EXISTS (SELECT 1 FROM document_dimension_splits WHERE dimension_type_id = ?)'
        );
        $stmt->execute([$typeId, $typeId, $typeId, $typeId]);
        return (bool) $stmt->fetchColumn();
    }

    public function deleteType(int $supplierId, int $typeId): bool
    {
        return (new SystemDimensionGuard($this->db))->delete('type', $typeId, fn () => $this->history->deleteType($typeId, function () use ($supplierId, $typeId): bool {
            $pdo = $this->db->pdo();
            [$vis, $visParams] = $this->visibleSql($supplierId, 'dimension_types');
            // Strom se maže od listů; i tento mezikrok musí respektovat viditelnost a rollback.
            $pdo->prepare("UPDATE dimension_values SET parent_id = NULL WHERE type_id = ?
                AND type_id IN (SELECT id FROM dimension_types WHERE {$vis})")->execute([$typeId, ...$visParams]);
            $stmt = $pdo->prepare("DELETE FROM dimension_types WHERE id = ? AND {$vis}");
            $stmt->execute([$typeId, ...$visParams]);
            return $stmt->rowCount() > 0;
        }));
    }

    // ── hodnoty ──────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function listValues(int $supplierId, ?int $typeId = null, bool $includeInactive = true): array
    {
        [$vis, $params] = $this->visibleSql($supplierId, 'v');
        $sql = 'SELECT ' . self::VALUE_COLUMNS . ",
                       c.registration AS car_registration,
                       cc.code AS cost_center_code,
                       p.name AS project_name,
                       u.name AS responsible_user_name
                  FROM dimension_values v
             LEFT JOIN cars c ON c.id = v.car_id AND c.supplier_id = v.supplier_id
             LEFT JOIN cost_centers cc ON cc.id = v.cost_center_id AND cc.supplier_id = v.supplier_id
             LEFT JOIN projects p ON p.id = v.project_id
             LEFT JOIN users u ON u.id = v.responsible_user_id
                 WHERE {$vis}";
        if ($typeId !== null) {
            $sql .= ' AND v.type_id = ?';
            $params[] = $typeId;
        }
        if (!$includeInactive) {
            $sql .= ' AND v.is_active = 1';
        }
        $sql .= ' ORDER BY v.type_id, v.sort_order, v.code';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'castValue'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findValue(int $supplierId, int $valueId): ?array
    {
        [$vis, $params] = $this->visibleSql($supplierId, 'v');
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::VALUE_COLUMNS . " FROM dimension_values v WHERE v.id = ? AND {$vis}"
        );
        $stmt->execute([$valueId, ...$params]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castValue($row);
    }

    public function findValueByCode(int $typeId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::VALUE_COLUMNS . ' FROM dimension_values v WHERE v.type_id = ? AND v.code = ?'
        );
        $stmt->execute([$typeId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castValue($row);
    }

    /**
     * Hodnoty viditelné firmě podle id — pro validaci vstupu (dimenze dokladu, řádku).
     *
     * @param list<int> $ids
     * @return array<int,array<string,mixed>> id => hodnota
     */
    public function valuesByIds(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        [$vis, $params] = $this->visibleSql($supplierId, 'v');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::VALUE_COLUMNS . " FROM dimension_values v WHERE v.id IN ({$marks}) AND {$vis}"
        );
        $stmt->execute([...$ids, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::castValue($row);
            $out[$row['id']] = $row;
        }
        return $out;
    }

    /**
     * Hodnota a všechny její podřízené hodnoty (celá větev stromu). Strom se skládá
     * v PHP nad hodnotami typu — typ má řádově stovky hodnot, rekurzivní CTE by tu
     * nic neušetřilo.
     *
     * @return list<int>
     */
    public function descendantIds(int $supplierId, int $valueId): array
    {
        $value = $this->findValue($supplierId, $valueId);
        if ($value === null) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, parent_id FROM dimension_values WHERE type_id = ?');
        $stmt->execute([$value['type_id']]);
        $children = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['parent_id'] !== null) {
                $children[(int) $r['parent_id']][] = (int) $r['id'];
            }
        }
        $out = [];
        $queue = [$valueId];
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($out[$id])) {
                continue;
            }
            $out[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }
        return array_keys($out);
    }

    /** @param array<string,mixed> $data */
    public function createValue(array $type, array $data, ?int $actor = null, string $source = 'repository'): int
    {
        return (new SystemDimensionGuard($this->db))->createValue($type, $data, fn () => $this->insertValue($type, $data), $actor, $source);
    }

    private function insertValue(array $type, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO dimension_values
                (type_id, supplier_id, supplier_group_id, parent_id, code, name, is_active,
                 responsible_user_id, responsible_note, car_id, project_id, cost_center_id, note, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $type['id'],
            $type['supplier_id'],
            $type['supplier_group_id'],
            $data['parent_id'] ?? null,
            $data['code'],
            $data['name'],
            ($data['is_active'] ?? true) ? 1 : 0,
            $data['responsible_user_id'] ?? null,
            $data['responsible_note'] ?? null,
            $data['car_id'] ?? null,
            $data['project_id'] ?? null,
            $data['cost_center_id'] ?? null,
            $data['note'] ?? null,
            (int) ($data['sort_order'] ?? 0),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $changes */
    public function updateValue(int $supplierId, int $valueId, array $changes, ?int $actor = null, string $source = 'repository'): void
    {
        (new SystemDimensionGuard($this->db))->change('value', $valueId, $changes, fn () => $this->history->changeValue($valueId, $changes, fn () => $this->writeValue($supplierId, $valueId, $changes)), $actor, $source);
    }

    private function writeValue(int $supplierId, int $valueId, array $changes): void
    {
        $allowed = ['parent_id', 'code', 'name', 'is_active', 'responsible_user_id', 'responsible_note',
            'car_id', 'project_id', 'cost_center_id', 'note', 'sort_order'];
        $sets = [];
        $params = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $changes)) {
                continue;
            }
            $sets[] = "{$col} = ?";
            $params[] = $col === 'is_active' ? ($changes[$col] ? 1 : 0) : $changes[$col];
        }
        if ($sets === []) {
            return;
        }
        [$vis, $visParams] = $this->visibleSql($supplierId, 'dimension_values');
        $this->db->pdo()->prepare(
            'UPDATE dimension_values SET ' . implode(', ', $sets) . " WHERE id = ? AND {$vis}"
        )->execute([...$params, $valueId, ...$visParams]);
    }

    public function valueInUse(int $valueId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM journal_entry_line_dimensions WHERE dimension_value_id = ?)
                 OR EXISTS (SELECT 1 FROM document_dimensions WHERE dimension_value_id = ?)
                 OR EXISTS (SELECT 1 FROM dimension_values WHERE parent_id = ?)
                 OR EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits WHERE dimension_value_id = ?)
                 OR EXISTS (SELECT 1 FROM document_dimension_splits WHERE dimension_value_id = ?)
                 OR EXISTS (SELECT 1 FROM dimension_account_rules WHERE default_value_id = ?)'
        );
        $stmt->execute([$valueId, $valueId, $valueId, $valueId, $valueId, $valueId]);
        return (bool) $stmt->fetchColumn();
    }

    public function deleteValue(int $supplierId, int $valueId, ?int $actor = null, string $source = 'repository'): bool
    {
        return (new SystemDimensionGuard($this->db))->delete('value', $valueId, fn () => $this->history->deleteValue($valueId, function () use ($supplierId, $valueId): bool {
            [$vis, $visParams] = $this->visibleSql($supplierId, 'dimension_values');
            $stmt = $this->db->pdo()->prepare("DELETE FROM dimension_values WHERE id = ? AND {$vis}");
            $stmt->execute([$valueId, ...$visParams]);
            return $stmt->rowCount() > 0;
        }), $actor, $source);
    }

    /**
     * Kódy středisek navázaných na hodnoty (id hodnoty => kód střediska). Podle nich
     * se v sestavách počítají i řádky, které nesou jen textový `cost_center`.
     *
     * @param list<int> $valueIds
     * @return array<int,string>
     */
    public function costCenterCodes(int $supplierId, array $valueIds): array
    {
        $valueIds = array_values(array_unique(array_map('intval', $valueIds)));
        if ($valueIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($valueIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT v.id, cc.code
               FROM dimension_values v
               JOIN dimension_types t ON t.id = v.type_id AND t.kind = 'cost_center'
               JOIN cost_centers cc ON cc.id = v.cost_center_id AND cc.supplier_id = ?
              WHERE v.id IN ({$marks})"
        );
        $stmt->execute([$supplierId, ...$valueIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = (string) $r['code'];
        }
        return $out;
    }

    // ── skupiny firem ────────────────────────────────────────────────────────

    public function findGroup(int $groupId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, created_at FROM supplier_groups WHERE id = ?');
        $stmt->execute([$groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @return list<array{id:int,company_name:string,ic:?string}> */
    public function groupMembers(int $groupId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, COALESCE(NULLIF(display_name, \'\'), company_name) AS company_name, ic
               FROM supplier WHERE supplier_group_id = ? ORDER BY company_name'
        );
        $stmt->execute([$groupId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'company_name' => (string) $r['company_name'],
            'ic' => $r['ic'] !== null ? (string) $r['ic'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Skupiny, do kterých patří aspoň jedna z firem — kandidáti pro připojení firmy.
     *
     * @param list<int> $supplierIds
     * @return list<array{id:int,name:string}>
     */
    public function groupsOfSuppliers(array $supplierIds): array
    {
        $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));
        if ($supplierIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($supplierIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT g.id, g.name FROM supplier_groups g
               JOIN supplier s ON s.supplier_group_id = g.id
              WHERE s.id IN ({$marks}) ORDER BY g.name"
        );
        $stmt->execute($supplierIds);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<int> všechny firmy instalace (superadmin) */
    public function allSupplierIds(): array
    {
        return array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function createGroup(string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO supplier_groups (name) VALUES (?)')->execute([$name]);
        return (int) $pdo->lastInsertId();
    }

    public function renameGroup(int $groupId, string $name): void
    {
        $this->db->pdo()->prepare('UPDATE supplier_groups SET name = ? WHERE id = ?')->execute([$name, $groupId]);
    }

    public function setSupplierGroup(int $supplierId, ?int $groupId): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET supplier_group_id = ? WHERE id = ?')->execute([$groupId, $supplierId]);
        unset($this->groupCache[$supplierId]);
    }

    // ── interní ──────────────────────────────────────────────────────────────

    private static function prefixed(string $columns, string $alias): string
    {
        return implode(', ', array_map(static fn (string $c): string => $alias . '.' . trim($c), explode(',', $columns)));
    }

    /** @param array<string,mixed> $row */
    private static function castType(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
        $row['supplier_group_id'] = $row['supplier_group_id'] !== null ? (int) $row['supplier_group_id'] : null;
        $row['level'] = $row['supplier_group_id'] !== null ? 'global' : 'company';
        $row['is_active'] = (bool) $row['is_active'];
        $row['show_on_documents'] = (bool) $row['show_on_documents'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['drives_accounts'] = (bool) $row['drives_accounts'];
        $row['drives_accounts_mask'] = (string) $row['drives_accounts_mask'];
        $row['requires_approval'] = (bool) ($row['requires_approval'] ?? false);
        $row['approval_threshold'] = isset($row['approval_threshold']) ? (float) $row['approval_threshold'] : null;
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function castValue(array $row): array
    {
        foreach (['id', 'type_id'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        foreach (['supplier_id', 'supplier_group_id', 'parent_id', 'responsible_user_id', 'car_id', 'project_id', 'cost_center_id'] as $k) {
            $row[$k] = isset($row[$k]) && $row[$k] !== null ? (int) $row[$k] : null;
        }
        $row['level'] = $row['supplier_group_id'] !== null ? 'global' : 'company';
        $row['is_active'] = (bool) $row['is_active'];
        $row['sort_order'] = (int) $row['sort_order'];
        return $row;
    }
}
