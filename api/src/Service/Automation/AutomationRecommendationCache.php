<?php

declare(strict_types=1);

namespace MyInvoice\Service\Automation;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Throwable;

final class AutomationRecommendationCache
{
    public function __construct(
        private readonly Connection $db,
        private readonly AutomationFeedService $feed,
        private readonly AutomationRecommendationService $generator,
    ) {}

    public function recommendations(int $userId, bool $isSuperadmin, array $filters = []): array
    {
        $ids = $this->scope($userId, $isSuperadmin, $filters['suppliers'] ?? []);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 50)));
        $result = [
            'items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage,
            'summary' => ['sales' => 0, 'purchases' => 0, 'bank' => 0],
            'snapshots' => [],
        ];
        if ($ids === []) return $result;
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT supplier_id, generated_at, requested_version > completed_version refresh_pending
                FROM automation_recommendation_snapshots WHERE supplier_id IN ({$marks})");
            $stmt->execute($ids);
            $states = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $states[(int) $row['supplier_id']] = $row;
            foreach ($ids as $id) {
                $state = $states[$id] ?? null;
                $result['snapshots'][] = [
                    'supplier_id' => $id,
                    'generated_at' => $state['generated_at'] ?? null,
                    'refresh_pending' => $state === null || $state['generated_at'] === null || (bool) $state['refresh_pending'],
                ];
            }
            $where = "supplier_id IN ({$marks})";
            $params = $ids;
            foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
                if (!empty($filters[$field])) {
                    $where .= " AND document_date {$operator} ?";
                    $params[] = $filters[$field];
                }
            }
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(sales),0) sales, COALESCE(SUM(purchases),0) purchases,
                COALESCE(SUM(bank),0) bank FROM automation_recommendation_coverage WHERE {$where}");
            $stmt->execute($params);
            $result['summary'] = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));
            if (!empty($filters['type'])) {
                $where .= ' AND recommendation_type = ?';
                $params[] = $filters['type'];
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM automation_recommendation_items WHERE {$where}");
            $stmt->execute($params);
            $result['total'] = (int) $stmt->fetchColumn();
            $offset = ($page - 1) * $perPage;
            $stmt = $pdo->prepare("SELECT payload FROM automation_recommendation_items WHERE {$where}
                ORDER BY document_date DESC, recommendation_id DESC LIMIT {$perPage} OFFSET {$offset}");
            $stmt->execute($params);
            $result['items'] = array_map(
                static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
                $stmt->fetchAll(PDO::FETCH_COLUMN),
            );
            if ($ownTransaction) $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function requestRefresh(int $userId, bool $isSuperadmin, array $requested): array
    {
        $ids = $this->scope($userId, $isSuperadmin, $requested);
        $stmt = $this->db->pdo()->prepare('INSERT INTO automation_recommendation_snapshots (supplier_id, requested_version)
            VALUES (?, 1) ON DUPLICATE KEY UPDATE requested_version = requested_version + 1');
        foreach ($ids as $id) $stmt->execute([$id]);
        return ['queued' => count($ids)];
    }

    public function run(int $timeBudgetSeconds = 55, bool $force = false, ?array $supplierIds = null): array
    {
        $started = microtime(true);
        $report = ['refreshed' => 0, 'skipped' => 0, 'failed' => []];
        if ($supplierIds === []) return $report;
        $scope = $supplierIds === null ? '' : 'AND s.id IN (' . implode(',', array_map('intval', $supplierIds)) . ')';
        $refreshFilter = $force ? '' : 'AND (cache.generated_at IS NULL OR cache.requested_version > cache.completed_version
                   OR cache.generated_at < DATE_SUB(NOW(), INTERVAL 1 DAY))';
        $candidates = $this->db->pdo()->query("SELECT s.id FROM supplier s
            LEFT JOIN automation_recommendation_snapshots cache ON cache.supplier_id=s.id
            WHERE s.accounting_mode='double_entry'
              {$scope}
              {$refreshFilter}
            ORDER BY cache.last_attempt_at, s.id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($candidates as $id) {
            if (microtime(true) - $started >= $timeBudgetSeconds) break;
            try {
                ++$report[$this->rebuildSupplier((int) $id, null, 0, $force) ? 'refreshed' : 'skipped'];
            } catch (Throwable) {
                $report['failed'][] = (int) $id;
            }
        }
        return $report;
    }

    public function rebuildSupplier(int $supplierId, ?callable $progress = null, int $lockWaitSeconds = 0, bool $force = false): bool
    {
        $pdo = $this->db->pdo();
        $lockName = 'automation_recommendations:' . $supplierId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([$lockName, $lockWaitSeconds]);
        if ((int) $lock->fetchColumn() !== 1) return false;
        try {
            $eligible = $pdo->prepare("SELECT 1 FROM supplier WHERE id=? AND accounting_mode='double_entry'");
            $eligible->execute([$supplierId]);
            if (!$eligible->fetchColumn()) return false;
            $pdo->prepare('INSERT IGNORE INTO automation_recommendation_snapshots (supplier_id) VALUES (?)')->execute([$supplierId]);
            $state = $pdo->prepare('SELECT requested_version, completed_version, generated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) fresh
                FROM automation_recommendation_snapshots WHERE supplier_id=?');
            $state->execute([$supplierId]);
            $row = $state->fetch(PDO::FETCH_ASSOC);
            if (!$force && $row['fresh'] && $row['requested_version'] === $row['completed_version']) return false;
            $pdo->prepare('UPDATE automation_recommendation_snapshots SET last_attempt_at=NOW() WHERE supplier_id=?')->execute([$supplierId]);
            $snapshot = $this->generator->snapshotForSupplier($supplierId, $progress);
            if ($progress !== null) $progress('publishing', 5);
            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) $pdo->beginTransaction();
            else $pdo->exec('SAVEPOINT automation_recommendation_swap');
            try {
                $pdo->prepare('DELETE FROM automation_recommendation_items WHERE supplier_id=?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM automation_recommendation_coverage WHERE supplier_id=?')->execute([$supplierId]);
                $insert = $pdo->prepare('INSERT INTO automation_recommendation_items
                    (supplier_id,recommendation_id,recommendation_type,document_date,payload) VALUES (?,?,?,?,?)');
                foreach ($snapshot['items'] as $item) {
                    if ((int) $item['supplier_id'] !== $supplierId) throw new \LogicException('recommendation_supplier_mismatch');
                    $insert->execute([$supplierId, $item['id'], $item['type'], $item['date'], json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                }
                $insert = $pdo->prepare('INSERT INTO automation_recommendation_coverage
                    (supplier_id,document_date,sales,purchases,bank) VALUES (?,?,?,?,?)');
                foreach ($snapshot['coverage'] as $date => $counts) {
                    $insert->execute([$supplierId, $date, $counts['sales'], $counts['purchases'], $counts['bank']]);
                }
                $pdo->prepare('UPDATE automation_recommendation_snapshots SET generated_at=NOW(), completed_version=? WHERE supplier_id=?')
                    ->execute([$row['requested_version'], $supplierId]);
                if ($ownTransaction) $pdo->commit();
                else $pdo->exec('RELEASE SAVEPOINT automation_recommendation_swap');
            } catch (Throwable $e) {
                if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
                elseif (!$ownTransaction) $pdo->exec('ROLLBACK TO SAVEPOINT automation_recommendation_swap');
                throw $e;
            }
            return true;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    private function scope(int $userId, bool $isSuperadmin, array $requested): array
    {
        $allowed = $this->feed->allowedSupplierIds($userId, $isSuperadmin);
        return $requested === [] ? $allowed : array_values(array_intersect($allowed, array_map('intval', $requested)));
    }
}
