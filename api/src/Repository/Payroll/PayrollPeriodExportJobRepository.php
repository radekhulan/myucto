<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPeriodExportJobRepository
{
    public const STATUSES = ['queued', 'processing', 'retry_wait', 'failed', 'completed'];
    public const MAX_ATTEMPTS = 3;
    public const STALE_AFTER_SECONDS = 1800;

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> */
    public function enqueue(
        int $supplierId,
        string $scope,
        string $periodStart,
        string $periodEnd,
        int $requestedBy,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_period_export_enqueue');
        }
        try {
            $existing = $this->byPeriodLocked($supplierId, $scope, $periodStart, $periodEnd);
            if ($existing === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO payroll_period_export_jobs
                        (supplier_id, export_scope, period_start, period_end, requested_by, available_at)
                     VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                );
                $insert->execute([$supplierId, $scope, $periodStart, $periodEnd, $requestedBy]);
                $jobId = (int) $pdo->lastInsertId();
            } else {
                $jobId = (int) $existing['id'];
                if ((string) $existing['status'] === 'failed') {
                    $pdo->prepare(
                        'UPDATE payroll_period_export_jobs
                            SET status = "queued", attempt_count = 0, available_at = UTC_TIMESTAMP(),
                                last_error_code = NULL, last_error_message = NULL,
                                started_at = NULL, completed_at = NULL
                          WHERE supplier_id = ? AND id = ? AND status = "failed"',
                    )->execute([$supplierId, $jobId]);
                }
            }
            $this->finish($pdo, $owns, 'payroll_period_export_enqueue');

            return $this->detail($supplierId, $jobId)
                ?? throw new \RuntimeException('Frontu exportu mezd nelze načíst.');
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $owns, 'payroll_period_export_enqueue');
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $jobId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, export_scope, period_start, period_end, status, attempt_count,
                    available_at, export_id, last_error_code, last_error_message,
                    created_at, started_at, completed_at, updated_at
               FROM payroll_period_export_jobs
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->publicRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function claimNext(): ?array
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_period_export_claim');
        }
        try {
            $this->recoverStaleLocked();
            $row = $pdo->query(
                'SELECT id, supplier_id, export_scope, period_start, period_end, attempt_count,
                        requested_by
                   FROM payroll_period_export_jobs
                  WHERE status IN ("queued", "retry_wait")
                    AND available_at <= UTC_TIMESTAMP()
                  ORDER BY available_at, id
                  LIMIT 1 FOR UPDATE',
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->finish($pdo, $owns, 'payroll_period_export_claim');
                return null;
            }
            $lease = random_bytes(16);
            $attemptNo = (int) $row['attempt_count'] + 1;
            $claim = $pdo->prepare(
                'UPDATE payroll_period_export_jobs
                    SET status = "processing", attempt_count = ?, lease_token = ?,
                        locked_at = UTC_TIMESTAMP(), started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                        last_error_code = NULL, last_error_message = NULL
                  WHERE supplier_id = ? AND id = ?
                    AND status IN ("queued", "retry_wait")',
            );
            $claim->execute([$attemptNo, $lease, (int) $row['supplier_id'], (int) $row['id']]);
            if ($claim->rowCount() !== 1) {
                throw new \RuntimeException('Job exportu mezd se nepodařilo pronajmout.');
            }
            $attempt = $pdo->prepare(
                'INSERT INTO payroll_period_export_job_attempts
                    (supplier_id, job_id, attempt_no, lease_token)
                 VALUES (?, ?, ?, ?)',
            );
            $attempt->execute([(int) $row['supplier_id'], (int) $row['id'], $attemptNo, $lease]);
            $this->finish($pdo, $owns, 'payroll_period_export_claim');
            $row['attempt_count'] = $attemptNo;
            $row['lease_token'] = bin2hex($lease);

            return $row;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $owns, 'payroll_period_export_claim');
            throw $exception;
        }
    }

    /** @param array<string,mixed> $claim */
    public function complete(array $claim, int $exportId): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_jobs job
               JOIN payroll_period_exports export_row
                 ON export_row.supplier_id = job.supplier_id AND export_row.id = ?
                SET job.status = "completed", job.export_id = export_row.id,
                    job.completed_at = UTC_TIMESTAMP(), job.available_at = UTC_TIMESTAMP(),
                    job.lease_token = NULL, job.locked_at = NULL,
                    job.last_error_code = NULL, job.last_error_message = NULL
              WHERE job.supplier_id = ? AND job.id = ? AND job.status = "processing"
                AND job.lease_token = ?',
        );
        $statement->execute([
            $exportId,
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            $this->lease($claim),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Dokončení exportu neodpovídá pronajatému jobu.');
        }
        $this->finishAttempt($claim, 'succeeded', null, null);
    }

    /** @param array<string,mixed> $claim */
    public function fail(array $claim, string $errorCode, string $message): void
    {
        $attemptNo = (int) $claim['attempt_count'];
        $retry = $attemptNo < self::MAX_ATTEMPTS;
        $delay = min(3600, 30 * (2 ** max(0, $attemptNo - 1)));
        $availableAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $delay . ' seconds')
            ->format('Y-m-d H:i:s');
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_jobs
                SET status = ?, available_at = ?, lease_token = NULL, locked_at = NULL,
                    last_error_code = ?, last_error_message = ?
              WHERE supplier_id = ? AND id = ? AND status = "processing" AND lease_token = ?',
        );
        $statement->execute([
            $retry ? 'retry_wait' : 'failed',
            $availableAt,
            substr($errorCode, 0, 64),
            mb_substr($message, 0, 500),
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            $this->lease($claim),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Selhání exportu neodpovídá pronajatému jobu.');
        }
        $this->finishAttempt($claim, 'failed', $errorCode, $message);
    }

    /** @return array<string,mixed>|null */
    private function byPeriodLocked(
        int $supplierId,
        string $scope,
        string $periodStart,
        string $periodEnd,
    ): ?array {
        $supplier = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $supplier->execute([$supplierId]);
        if ($supplier->fetchColumn() === false) {
            throw new \DomainException('Firma exportu mezd nebyla nalezena.');
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_period_export_jobs
              WHERE supplier_id = ? AND export_scope = ? AND period_start = ? AND period_end = ?
                AND status IN ("queued", "processing", "retry_wait", "failed")
              ORDER BY id DESC
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $scope, $periodStart, $periodEnd]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function recoverStaleLocked(): void
    {
        $pdo = $this->db->pdo();
        $stale = $pdo->query(
            'SELECT supplier_id, id, attempt_count, lease_token
               FROM payroll_period_export_jobs
              WHERE status = "processing"
                AND locked_at < UTC_TIMESTAMP() - INTERVAL ' . self::STALE_AFTER_SECONDS . ' SECOND
              FOR UPDATE',
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stale as $job) {
            if (!is_array($job) || !is_string($job['lease_token'] ?? null)) {
                continue;
            }
            $retry = (int) $job['attempt_count'] < self::MAX_ATTEMPTS;
            $pdo->prepare(
                'UPDATE payroll_period_export_job_attempts
                    SET status = "stale", error_code = "worker_lease_expired",
                        error_message = "Worker lease expired before completion.",
                        finished_at = UTC_TIMESTAMP()
                  WHERE supplier_id = ? AND job_id = ? AND lease_token = ? AND status = "running"',
            )->execute([(int) $job['supplier_id'], (int) $job['id'], $job['lease_token']]);
            $pdo->prepare(
                'UPDATE payroll_period_export_jobs
                    SET status = ?, available_at = UTC_TIMESTAMP(), lease_token = NULL, locked_at = NULL,
                        last_error_code = "worker_lease_expired",
                        last_error_message = "Worker lease expired before completion."
                  WHERE supplier_id = ? AND id = ? AND status = "processing"',
            )->execute([$retry ? 'retry_wait' : 'failed', (int) $job['supplier_id'], (int) $job['id']]);
        }
    }

    /** @param array<string,mixed> $claim */
    private function finishAttempt(
        array $claim,
        string $status,
        ?string $errorCode,
        ?string $message,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_job_attempts
                SET status = ?, error_code = ?, error_message = ?, finished_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND job_id = ? AND attempt_no = ?
                AND lease_token = ? AND status = "running"',
        );
        $statement->execute([
            $status,
            $errorCode === null ? null : substr($errorCode, 0, 64),
            $message === null ? null : mb_substr($message, 0, 500),
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            (int) $claim['attempt_count'],
            $this->lease($claim),
        ]);
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function publicRow(array $row): array
    {
        foreach (['id', 'attempt_count'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['export_id'] = $row['export_id'] === null ? null : (int) $row['export_id'];
        unset($row['lease_token'], $row['locked_at'], $row['requested_by']);
        return $row;
    }

    /** @param array<string,mixed> $claim */
    private function lease(array $claim): string
    {
        $lease = hex2bin((string) ($claim['lease_token'] ?? ''));
        if (!is_string($lease) || strlen($lease) !== 16) {
            throw new \InvalidArgumentException('Worker lease exportu mezd není platný.');
        }
        return $lease;
    }

    private function finish(PDO $pdo, bool $owns, string $savepoint): void
    {
        $owns ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollback(PDO $pdo, bool $owns, string $savepoint): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($owns) {
            $pdo->rollBack();
            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }
}
