<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ActivityLogHashChain;
use PDO;

final class SystemDimensionGuard
{
    public function __construct(private readonly Connection $db) {}

    public function change(string $entity, int $id, array $changes, callable $write, ?int $actor = null, string $source = 'repository'): mixed
    {
        $scope = $this->typeScope($entity, $id);
        if ($scope !== null && empty($scope['system_key'])) {
            return $write();
        }
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($entity, $id, $changes, $write, $actor, $source, $scope): mixed {
            $this->lockPeriods($scope, $entity === 'value' && (array_key_exists('parent_id', $changes) || array_key_exists('cost_center_id', $changes)));
            $table = $entity === 'type' ? 'dimension_types' : 'dimension_values';
            $before = $this->row($table, $id);
            $type = $entity === 'type' ? $before : ($before === null ? null : $this->row('dimension_types', (int) $before['type_id']));
            if ($before === null || empty($type['system_key'])) {
                return $write();
            }
            $delta = array_filter($changes, static fn (mixed $value, string $key): bool => !array_key_exists($key, $before)
                || (is_bool($value) ? (bool) $before[$key] !== $value : (string) $before[$key] !== (string) $value), ARRAY_FILTER_USE_BOTH);
            if ($delta === []) {
                return null;
            }
            if ($entity === 'type') {
                if (array_intersect(array_keys($delta), ['system_key', 'kind', 'supplier_id', 'supplier_group_id', 'code']) !== []
                    || (isset($delta['is_active']) && !$delta['is_active'])
                    || (isset($delta['name']) && $this->used($this->valueIds((int) $type['id'])))) {
                    $this->refuse();
                }
            } else {
                $semantic = array_intersect(array_keys($delta), ['name', 'code', 'parent_id', 'car_id', 'project_id', 'cost_center_id', 'semantic_key', 'type_id']);
                if (array_key_exists('semantic_key', $delta) || array_key_exists('type_id', $delta)
                    || (!empty($before['semantic_key']) && ($semantic !== [] || (isset($delta['is_active']) && !$delta['is_active'])))
                    || ($semantic !== [] && $this->used($this->branch($before)))) {
                    $this->refuse();
                }
                foreach (['car_id', 'project_id', 'cost_center_id'] as $link) {
                    if (!empty($delta[$link])) {
                        $this->refuse();
                    }
                }
            }
            $result = $write();
            $after = $this->row($table, $id);
            if ($before !== $after) {
                $this->audit($entity, $id, (int) $type['supplier_id'], $before, $after, $actor, $source);
            }
            return $result;
        });
    }

    public function delete(string $entity, int $id, callable $write, ?int $actor = null, string $source = 'repository'): mixed
    {
        $scope = $this->typeScope($entity, $id);
        if ($scope !== null && empty($scope['system_key'])) {
            return $write();
        }
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($entity, $id, $write, $actor, $source, $scope): mixed {
            $this->lockPeriods($scope, true);
            $row = $this->row($entity === 'type' ? 'dimension_types' : 'dimension_values', $id);
            $type = $entity === 'type' ? $row : ($row === null ? null : $this->row('dimension_types', (int) $row['type_id']));
            if (!empty($type['system_key']) && ($entity === 'type' || !empty($row['semantic_key']) || $this->used($this->branch($row)))) {
                $this->refuse();
            }
            $result = $write();
            if (!empty($type['system_key']) && $row !== null && $result) {
                $this->audit($entity, $id, (int) $type['supplier_id'], $row, null, $actor, $source);
            }
            return $result;
        });
    }

    public function createValue(array $type, array $data, callable $write, ?int $actor = null, string $source = 'repository'): mixed
    {
        $scope = $this->typeScope('type', (int) $type['id']);
        if ($scope !== null && empty($scope['system_key'])) {
            return $write();
        }
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($type, $data, $write, $actor, $source, $scope): mixed {
            $this->lockPeriods($scope);
            $current = $this->row('dimension_types', (int) $type['id']);
            if (!empty($current['system_key']) && (in_array($current['system_key'], ['activity', 'tax_regime', 'unit', 'service'], true)
                || !empty($data['car_id']) || !empty($data['project_id']) || !empty($data['cost_center_id']) || !empty($data['semantic_key']))) {
                $this->refuse();
            }
            $id = $write();
            if (!empty($current['system_key'])) {
                $this->audit('value', $id, (int) $current['supplier_id'], null, $this->row('dimension_values', $id), $actor, $source);
            }
            return $id;
        });
    }

    public function setEnabled(int $supplierId, bool $enabled, callable $write): mixed
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $enabled, $write): mixed {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            if (!$enabled) {
                $stmt = $this->db->pdo()->prepare('SELECT id FROM dimension_types WHERE supplier_id = ? AND system_key IS NOT NULL LIMIT 1 FOR UPDATE');
                $stmt->execute([$supplierId]);
                if ($stmt->fetchColumn() !== false) {
                    $this->refuse();
                }
            }
            return $write();
        });
    }

    public function audit(string $entity, int $id, int $supplierId, ?array $before, ?array $after, ?int $actor, string $source): void
    {
        (new ActivityLogger($this->db, new ActivityLogHashChain($this->db)))->log(
            'dimension.system.' . $entity, $actor, 'dimension_' . $entity, $id,
            ['source' => $source, 'before' => $before, 'after' => $after], supplierId: $supplierId,
        );
    }

    private function typeScope(string $entity, int $id): ?array
    {
        $sql = $entity === 'type'
            ? 'SELECT id, supplier_id, system_key FROM dimension_types WHERE id = ?'
            : 'SELECT t.id, t.supplier_id, t.system_key FROM dimension_types t JOIN dimension_values v ON v.type_id = t.id WHERE v.id = ?';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function lockPeriods(?array $scope, bool $structural = false): void
    {
        if ($structural || $scope === null || empty($scope['supplier_id'])) {
            (new DimensionValueHistoryGuard($this->db))->lockPeriods();
            return;
        }
        (new DimensionPeriodGuard($this->db))->lockPeriods((int) $scope['supplier_id']);
    }

    private function row(string $table, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function valueIds(int $typeId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM dimension_values WHERE type_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$typeId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function branch(array $value): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, parent_id FROM dimension_values WHERE type_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$value['type_id']]);
        $children = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $children[(int) $row['parent_id']][] = (int) $row['id'];
        }
        $ids = [];
        $queue = [(int) $value['id']];
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($ids[$id])) {
                continue;
            }
            $ids[$id] = $id;
            array_push($queue, ...($children[$id] ?? []));
        }
        return array_values($ids);
    }

    private function used(array $ids): bool
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            foreach (['journal_entry_line_dimensions', 'journal_entry_line_dimension_splits', 'document_dimensions', 'document_dimension_splits',
                'dimension_defaults', 'dimension_account_map', 'payroll_dimensions', 'purchase_invoice_submission_dimensions', 'purchase_invoice_approvals',
                'dimension_account_rules'] as $table) {
                $column = $table === 'dimension_account_rules' ? 'default_value_id' : 'dimension_value_id';
                $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM {$table} WHERE {$column} IN ({$marks}) LIMIT 1 FOR UPDATE");
                $stmt->execute($chunk);
                if ($stmt->fetchColumn() !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    private function refuse(): never
    {
        throw new DimensionException('system_dimension_immutable', 'Systémovou dimenzi ani význam použité hodnoty nelze změnit. Pro nový význam založte novou hodnotu.', 409);
    }
}
