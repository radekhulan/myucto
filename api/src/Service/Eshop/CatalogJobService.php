<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CatalogJobService
{
    public function __construct(private readonly Connection $db) {}

    public function enqueue(int $supplierId, string $kind, array $input, int $total = 0, int $inputVersion = 1): int
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $kind) || $total < 0 || $inputVersion < 1) {
            throw new \InvalidArgumentException('Neplatná katalogová úloha.');
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO catalog_jobs (supplier_id, kind, input_json, total, input_version) VALUES (?, ?, ?, ?, ?)')
            ->execute([$supplierId, $kind, json_encode($input, JSON_THROW_ON_ERROR), $total, $inputVersion]);
        return (int) $pdo->lastInsertId();
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM catalog_jobs WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function history(int $supplierId, int $beforeId = PHP_INT_MAX, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->pdo()->prepare('SELECT * FROM catalog_jobs WHERE supplier_id = ? AND id < ? ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([$supplierId, $beforeId]);
        return array_map($this->cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function claim(int $supplierId, string $kind, int $leaseSeconds = 60): ?array
    {
        $leaseSeconds = max(5, min(3600, $leaseSeconds));
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Převzetí úlohy vyžaduje samostatnou transakci.');
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT IGNORE INTO catalog_job_lanes (supplier_id, kind) VALUES (?, ?)')->execute([$supplierId, $kind]);
            $lane = $pdo->prepare('SELECT kind FROM catalog_job_lanes WHERE supplier_id = ? AND kind = ? FOR UPDATE');
            $lane->execute([$supplierId, $kind]);
            $stmt = $pdo->prepare("SELECT * FROM catalog_jobs WHERE supplier_id = ? AND kind = ? AND status = 'running' ORDER BY id LIMIT 1 FOR UPDATE");
            $stmt->execute([$supplierId, $kind]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $active = $pdo->prepare('SELECT lease_until > NOW(6) FROM catalog_jobs WHERE id = ?');
                $active->execute([$row['id']]);
                if ((bool) $active->fetchColumn()) {
                    $pdo->commit();
                    return null;
                }
            } else {
                $stmt = $pdo->prepare("SELECT * FROM catalog_jobs WHERE supplier_id = ? AND kind = ? AND status = 'queued' ORDER BY id LIMIT 1 FOR UPDATE");
                $stmt->execute([$supplierId, $kind]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($row === false) {
                $pdo->commit();
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE catalog_jobs SET status = 'running', lease_token = ?, lease_until = DATE_ADD(NOW(6), INTERVAL ? SECOND), attempts = attempts + 1 WHERE id = ?")
                ->execute([$token, $leaseSeconds, $row['id']]);
            $result = $this->find($supplierId, (int) $row['id']);
            $pdo->commit();
            $result['lease_token'] = $token;
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function batch(int $supplierId, int $id, string $token, callable $handler): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Dávka úlohy vyžaduje samostatnou transakci.');
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM catalog_jobs WHERE supplier_id = ? AND id = ? AND status = 'running' AND lease_token = ? AND lease_until > NOW(6) FOR UPDATE");
            $stmt->execute([$supplierId, $id, $token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new \RuntimeException('catalog_job_lease_lost');
            }
            if ($row['cancel_requested']) {
                $pdo->prepare("UPDATE catalog_jobs SET status = 'cancelled', lease_token = NULL, lease_until = NULL, finished_at = NOW(6) WHERE id = ?")
                    ->execute([$id]);
            } else {
                $result = $handler($this->cast($row));
                $checkpoint = (int) ($result['checkpoint'] ?? -1);
                if ($checkpoint < (int) $row['checkpoint'] || $checkpoint > (int) $row['total']) {
                    throw new \LogicException('Neplatný checkpoint úlohy.');
                }
                $done = (bool) ($result['done'] ?? false);
                $pdo->prepare("UPDATE catalog_jobs SET checkpoint = ?, report_json = ?, status = ?, lease_until = IF(?, NULL, DATE_ADD(NOW(6), INTERVAL 60 SECOND)), lease_token = IF(?, NULL, lease_token), finished_at = IF(?, NOW(6), NULL) WHERE id = ?")
                    ->execute([$checkpoint, json_encode($result['report'] ?? [], JSON_THROW_ON_ERROR), $done ? 'completed' : 'running', (int) $done, (int) $done, (int) $done, $id]);
            }
            $result = $this->find($supplierId, $id);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function heartbeat(int $supplierId, int $id, string $token): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE catalog_jobs SET lease_until = DATE_ADD(NOW(6), INTERVAL 60 SECOND) WHERE supplier_id = ? AND id = ? AND status = 'running' AND lease_token = ? AND lease_until > NOW(6)");
        $stmt->execute([$supplierId, $id, $token]);
        return $stmt->rowCount() === 1;
    }

    public function release(int $supplierId, int $id, string $token): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE catalog_jobs SET status = 'queued', lease_token = NULL, lease_until = NULL WHERE supplier_id = ? AND id = ? AND status = 'running' AND lease_token = ? AND lease_until > NOW(6)");
        $stmt->execute([$supplierId, $id, $token]);
        return $stmt->rowCount() === 1;
    }

    public function cancel(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE catalog_jobs SET cancel_requested = 1, finished_at = IF(status = 'queued', NOW(6), finished_at), status = IF(status = 'queued', 'cancelled', status) WHERE supplier_id = ? AND id = ? AND status IN ('queued','running')");
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() === 1;
    }

    public function fail(int $supplierId, int $id, string $token, string $errorCode): bool
    {
        if (!preg_match('/^[a-z0-9_]{1,100}$/D', $errorCode)) {
            throw new \InvalidArgumentException('Neplatný kód chyby.');
        }
        $stmt = $this->db->pdo()->prepare("UPDATE catalog_jobs SET status = 'failed', error_code = ?, lease_token = NULL, lease_until = NULL, finished_at = NOW(6) WHERE supplier_id = ? AND id = ? AND status = 'running' AND lease_token = ? AND lease_until > NOW(6)");
        $stmt->execute([$errorCode, $supplierId, $id, $token]);
        return $stmt->rowCount() === 1;
    }

    public function retry(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE catalog_jobs SET status = 'queued', error_code = NULL, cancel_requested = 0, finished_at = NULL WHERE supplier_id = ? AND id = ? AND status IN ('failed','cancelled')");
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() === 1;
    }

    private function cast(array $row): array
    {
        unset($row['lease_token']);
        foreach (['id', 'supplier_id', 'input_version', 'checkpoint', 'total', 'attempts'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['input'] = json_decode($row['input_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['report'] = json_decode($row['report_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['input_json'], $row['report_json']);
        $row['cancel_requested'] = (bool) $row['cancel_requested'];
        return $row;
    }
}
