<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class SamplePayrollPurger
{
    public function __construct(private readonly Connection $db) {}

    public static function ownershipType(string $table): string
    {
        return 'payroll_row_' . substr(hash('sha256', $table), 0, 24);
    }

    public static function tables(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, 8) = "payroll_" GROUP BY TABLE_NAME HAVING SUM(COLUMN_NAME = "supplier_id") > 0 AND SUM(COLUMN_NAME = "id") > 0')->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_fill_keys($rows, 'id');
        return $tables + [
            'payroll_employee_profiles' => 'employee_id',
            'payroll_employer_settings' => 'supplier_id',
            'payroll_module_state' => 'supplier_id',
            'payroll_period_ownership' => 'period_start',
        ];
    }

    public static function snapshot(PDO $pdo, int $supplierId): array
    {
        $result = [];
        foreach (self::tables($pdo) as $table => $key) {
            $expression = self::keyExpression($key);
            $stmt = $pdo->prepare("SELECT $expression FROM `$table` WHERE supplier_id = ?");
            $stmt->execute([$supplierId]);
            $result[$table] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        return $result;
    }

    public function purge(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Odebrání ukázkových mezd vyžaduje transakci.');
        $tables = self::tables($pdo);
        $tracking = $pdo->prepare('SELECT entity_type, entity_id FROM sample_data_entries WHERE supplier_id = ? AND entity_type LIKE "payroll_row_%"');
        $tracking->execute([$supplierId]);
        $types = [];
        foreach ($tables as $table => $key) $types[self::ownershipType($table)] = $table;
        $owned = [];
        foreach ($tracking->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($types[$row['entity_type']])) $owned[$types[$row['entity_type']]][] = (int) $row['entity_id'];
        }
        if ($owned === []) return [];
        $fks = $this->foreignKeys($pdo);
        $this->assertOwnedDependants($pdo, $supplierId, $tables, $owned, $fks);
        SamplePayrollModuleState::restore($pdo, $supplierId);
        $pdo->prepare('SET @payroll_sample_purge_supplier_id = ?')->execute([$supplierId]);
        try {
            $deleted = [];
            while ($owned !== []) {
                $progress = false;
                foreach ($owned as $table => $ids) {
                    if ($this->hasDependants($pdo, $supplierId, $table, $tables[$table], $ids, $fks)) continue;
                    $place = implode(',', array_fill(0, count($ids), '?'));
                    $key = $tables[$table];
                    $expression = self::keyExpression($key);
                    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE supplier_id = ? AND $expression IN ($place) ORDER BY `$key` DESC");
                    $stmt->execute([$supplierId, ...$ids]);
                    $deleted[$table] = $stmt->rowCount();
                    unset($owned[$table]);
                    $progress = true;
                }
                if (!$progress) throw new \DomainException('Ukázkové mzdy obsahují neodstranitelnou vazbu: ' . implode(', ', array_keys($owned)));
            }
            $restore = $pdo->prepare('SELECT entity_id FROM sample_data_entries WHERE supplier_id = ? AND entity_type = "payroll_restore_enabled"');
            $restore->execute([$supplierId]);
            $previous = $restore->fetchColumn();
            if ($previous !== false) $pdo->prepare('UPDATE supplier SET payroll_enabled = ? WHERE id = ?')->execute([(int) $previous - 1, $supplierId]);
            return $deleted;
        } finally {
            $pdo->exec('SET @payroll_sample_purge_supplier_id = NULL');
        }
    }

    private function foreignKeys(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['TABLE_NAME'] . ':' . $row['CONSTRAINT_NAME'];
            $result[$key]['child'] = $row['TABLE_NAME'];
            $result[$key]['parent'] = $row['REFERENCED_TABLE_NAME'];
            $result[$key]['columns'][] = [$row['COLUMN_NAME'], $row['REFERENCED_COLUMN_NAME']];
        }
        return array_values($result);
    }

    private function assertOwnedDependants(PDO $pdo, int $supplierId, array $tables, array $owned, array $fks): void
    {
        foreach ($fks as $fk) {
            $parent = $fk['parent'];
            $child = $fk['child'];
            if (!isset($owned[$parent])) continue;
            $join = $this->join($fk);
            $place = implode(',', array_fill(0, count($owned[$parent]), '?'));
            $key = $tables[$parent];
            $ownedClause = '1 = 1';
            $params = [$supplierId, ...$owned[$parent]];
            if (isset($tables[$child], $owned[$child])) {
                $childPlace = implode(',', array_fill(0, count($owned[$child]), '?'));
                $ownedClause = 'c.supplier_id <> ? OR ' . self::keyExpression($tables[$child], 'c.') . " NOT IN ($childPlace)";
                $params = [...$params, $supplierId, ...$owned[$child]];
            }
            $expression = self::keyExpression($key, 'p.');
            $stmt = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM `$child` c JOIN `$parent` p ON $join WHERE p.supplier_id = ? AND $expression IN ($place) AND ($ownedClause))");
            $stmt->execute($params);
            if ((bool) $stmt->fetchColumn()) throw new \DomainException("Ukázková mzdová data jsou propojena s neevidovanými daty v tabulce $child. Odebrání bylo zastaveno.");
        }
    }

    private function hasDependants(PDO $pdo, int $supplierId, string $table, string $key, array $ids, array $fks): bool
    {
        foreach ($fks as $fk) {
            if ($fk['parent'] !== $table || $fk['child'] === $table) continue;
            $join = $this->join($fk);
            $child = $fk['child'];
            $place = implode(',', array_fill(0, count($ids), '?'));
            $expression = self::keyExpression($key, 'p.');
            $stmt = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM `$child` c JOIN `$table` p ON $join WHERE p.supplier_id = ? AND $expression IN ($place))");
            $stmt->execute([$supplierId, ...$ids]);
            if ((bool) $stmt->fetchColumn()) return true;
        }
        return false;
    }

    private function join(array $fk): string
    {
        return implode(' AND ', array_map(static fn (array $columns): string => 'c.`' . $columns[0] . '` = p.`' . $columns[1] . '`', $fk['columns']));
    }

    private static function keyExpression(string $key, string $prefix = ''): string
    {
        return $key === 'period_start'
            ? "CAST(REPLACE({$prefix}`period_start`, '-', '') AS UNSIGNED)"
            : "{$prefix}`$key`";
    }
}
