<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class IntegrationChangeFeedService
{
    public function __construct(private readonly Connection $db) {}

    public function read(int $supplierId, int $afterCursor, int $limit = 250): array
    {
        $limit = max(1, min(1000, $limit));
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Čtení změnového feedu vyžaduje samostatnou transakci.');
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        try {
            $state = $pdo->prepare('SELECT last_cursor, retention_floor FROM integration_change_state WHERE supplier_id = ?');
            $state->execute([$supplierId]);
            $cursorState = $state->fetch(PDO::FETCH_ASSOC) ?: ['last_cursor' => 0, 'retention_floor' => 0];
            $maximumCursor = (int) $cursorState['last_cursor'];
            $retentionFloor = (int) $cursorState['retention_floor'];
            if ($afterCursor < $retentionFloor) {
                $pdo->commit();
                return [
                    'cursor_expired' => true,
                    'snapshot_required' => true,
                    'snapshot_path' => '/api/v1/catalog/products/batch',
                    'minimum_cursor' => $retentionFloor,
                    'next_cursor' => $afterCursor,
                    'has_more' => false,
                    'items' => [],
                ];
            }
            $stmt = $pdo->prepare('SELECT cursor_id, entity_type, entity_id, change_type, source_area, occurred_at
                FROM integration_change_log WHERE supplier_id = ? AND cursor_id > ? AND cursor_id <= ?
                ORDER BY cursor_id LIMIT ' . ($limit + 1));
            $stmt->execute([$supplierId, $afterCursor, $maximumCursor]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }
            foreach ($rows as &$row) {
                $row['cursor'] = (int) $row['cursor_id'];
                unset($row['cursor_id']);
                $row['entity_id'] = (int) $row['entity_id'];
            }
            unset($row);
            $pdo->commit();
            return [
                'cursor_expired' => false,
                'snapshot_required' => false,
                'minimum_cursor' => $retentionFloor,
                'next_cursor' => $rows === [] ? $afterCursor : (int) end($rows)['cursor'],
                'has_more' => $hasMore,
                'items' => $rows,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function purgeExpired(): int
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Retence změnového feedu vyžaduje samostatnou transakci.');
        }
        $candidates = $pdo->query('SELECT DISTINCT supplier_id FROM integration_change_log')->fetchAll(PDO::FETCH_COLUMN);
        $removed = 0;
        foreach ($candidates as $supplierId) {
            $pdo->beginTransaction();
            try {
                $state = $pdo->prepare('SELECT last_cursor FROM integration_change_state WHERE supplier_id = ? FOR UPDATE');
                $state->execute([(int) $supplierId]);
                if ($state->fetchColumn() === false) {
                    $pdo->commit();
                    continue;
                }
                $retention = $pdo->prepare('SELECT COALESCE(MAX(retention_days), 30) FROM integration_connections WHERE supplier_id = ?');
                $retention->execute([(int) $supplierId]);
                $days = max(1, (int) $retention->fetchColumn());
                $boundary = $pdo->prepare('SELECT COALESCE(MAX(cursor_id), 0) FROM integration_change_log
                    WHERE supplier_id = ? AND occurred_at < DATE_SUB(NOW(6), INTERVAL ? DAY)');
                $boundary->execute([(int) $supplierId, $days]);
                $purgeThrough = (int) $boundary->fetchColumn();
                if ($purgeThrough > 0) {
                    $delete = $pdo->prepare('DELETE FROM integration_change_log WHERE supplier_id = ? AND cursor_id <= ?');
                    $delete->execute([(int) $supplierId, $purgeThrough]);
                    $removed += $delete->rowCount();
                    $pdo->prepare('UPDATE integration_change_state SET retention_floor = GREATEST(retention_floor, ?)
                        WHERE supplier_id = ?')->execute([$purgeThrough, (int) $supplierId]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        return $removed;
    }
}
