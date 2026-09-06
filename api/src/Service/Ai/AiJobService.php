<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\DbErrorLogger;
use PDO;
use PDOException;

final class AiJobService
{
    public const DAILY_JOB_LIMIT = 200;

    public function __construct(private readonly Connection $db) {}

    public function enqueue(int $supplierId, string $jobType, string $entityType, int $entityId): bool
    {
        try {
            // Duplicita na uq_aij_open znamená „úloha už ve frontě je" — očekávaný
            // výsledek, ne chyba. Bez tohohle rozsahu ji logovací PDO zapíše jako
            // ERROR dřív, než se k ní dostane catch níž.
            DbErrorLogger::expectingDuplicates(['uq_aij_open'], function () use ($supplierId, $jobType, $entityType, $entityId): void {
                $this->db->pdo()->prepare(
                    'INSERT INTO ai_jobs (supplier_id,job_type,entity_type,entity_id,available_at) VALUES (?,?,?,?,NOW())'
                )->execute([$supplierId, $jobType, $entityType, $entityId]);
            });
            return true;
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /** @return list<int> */
    public function enabledSuppliers(): array
    {
        $stmt = $this->db->pdo()->query('SELECT id FROM supplier WHERE ai_assist_enabled=1 ORDER BY id');
        return $stmt === false ? [] : array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> */
    public function claimBatch(int $supplierId, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE ai_jobs SET status=IF(attempts>=3,'failed','queued'),
                        last_error='stale_running_recovered',started_at=NULL,
                        finished_at=IF(attempts>=3,NOW(),NULL),available_at=NOW()
                  WHERE supplier_id=? AND status='running' AND started_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE)"
            )->execute([$supplierId]);
            $stmt = $pdo->prepare(
                "SELECT * FROM ai_jobs
                  WHERE supplier_id=? AND status='queued' AND attempts<3
                    AND (available_at IS NULL OR available_at<=NOW())
                  ORDER BY (job_type LIKE 'classify_%') ASC,id LIMIT {$limit} FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute([$supplierId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $update = $pdo->prepare(
                "UPDATE ai_jobs SET status='running',started_at=NOW(),attempts=attempts+1
                  WHERE id=? AND supplier_id=? AND status='queued'"
            );
            $claimed = [];
            foreach ($rows as $row) {
                $update->execute([(int) $row['id'], $supplierId]);
                if ($update->rowCount() === 1) {
                    $row['attempts'] = (int) $row['attempts'] + 1;
                    $claimed[] = $row;
                }
            }
            $pdo->commit();
            return $claimed;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function peekBatch(int $supplierId, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM ai_jobs
              WHERE supplier_id=? AND status='queued' AND attempts<3
                AND (available_at IS NULL OR available_at<=NOW())
              ORDER BY id LIMIT {$limit}"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function done(int $id): void
    {
        $this->finish($id, 'done', null);
    }

    public function skipped(int $id, string $error): void
    {
        $this->finish($id, 'skipped', $error);
    }

    public function failed(int $id, string $error, bool $transient, int $attempts): void
    {
        if ($transient && $attempts < 3) {
            $delay = 2 ** max(1, $attempts);
            $this->db->pdo()->prepare(
                "UPDATE ai_jobs SET status='queued',last_error=?,available_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),started_at=NULL WHERE id=? AND status='running'"
            )->execute([mb_substr($error, 0, 255), $delay, $id]);
            return;
        }
        $this->finish($id, 'failed', $error);
    }

    public function deferUntilTomorrow(int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE ai_jobs SET status='queued',attempts=GREATEST(attempts-1,0),last_error='daily_limit',
                    available_at=DATE_ADD(CURDATE(),INTERVAL 1 DAY),started_at=NULL
              WHERE id=? AND status='running'"
        )->execute([$id]);
    }

    public function todayUsed(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT classification_count FROM ai_daily_usage WHERE supplier_id=? AND usage_date=CURDATE()'
        );
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    public function tryReserveClassification(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ai_daily_usage (supplier_id,usage_date,classification_count) VALUES (?,CURDATE(),1)
             ON DUPLICATE KEY UPDATE classification_count=IF(classification_count<?,classification_count+1,classification_count)'
        );
        $stmt->execute([$supplierId, self::DAILY_JOB_LIMIT]);
        return $stmt->rowCount() > 0;
    }

    private function finish(int $id, string $status, ?string $error): void
    {
        $this->db->pdo()->prepare(
            'UPDATE ai_jobs SET status=?,last_error=?,finished_at=NOW() WHERE id=? AND status="running"'
        )->execute([$status, $error === null ? null : mb_substr($error, 0, 255), $id]);
    }
}
