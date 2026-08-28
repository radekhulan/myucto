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
                            SET status = "queued", attempt_count = 0, failure_count = 0, available_at = UTC_TIMESTAMP(),
                                last_error_code = NULL, last_error_message = NULL,
                                started_at = NULL, completed_at = NULL
                          WHERE supplier_id = ? AND id = ? AND status = "failed"',
                    )->execute([$supplierId, $jobId]);
                    $pdo->prepare(
                        'UPDATE payroll_period_export_job_parts
                            SET status = "queued", attempt_count = 0,
                                available_at = UTC_TIMESTAMP(), lease_token = NULL,
                                locked_at = NULL, storage_key = NULL,
                                last_error_code = NULL, last_error_message = NULL
                          WHERE supplier_id = ? AND job_id = ? AND status = "failed"',
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
            'SELECT id, export_scope, period_start, period_end, status, attempt_count, failure_count,
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
                'SELECT id, supplier_id, export_scope, period_start, period_end, attempt_count, failure_count,
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
    public function fail(array $claim, string $errorCode, string $message): void
    {
        $failureCount = (int) ($claim['failure_count'] ?? 0) + 1;
        $retry = $failureCount < self::MAX_ATTEMPTS;
        $delay = min(3600, 30 * (2 ** max(0, $failureCount - 1)));
        $availableAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $delay . ' seconds')
            ->format('Y-m-d H:i:s');
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_jobs
                SET status = ?, failure_count = ?, available_at = ?, lease_token = NULL, locked_at = NULL,
                    last_error_code = ?, last_error_message = ?
              WHERE supplier_id = ? AND id = ? AND status = "processing" AND lease_token = ?',
        );
        $statement->execute([
            $retry ? 'retry_wait' : 'failed',
            $failureCount,
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

    /**
     * Persistuje plán neměnných zdrojových bajtů ještě před jejich čtením.
     * Opakovaný worker musí potvrdit shodný zmrazený plán.
     *
     * @param array<string,mixed> $claim
     * @param list<array{part_key:string,part_kind:string,source_id:int,source_sha256:?string,source_size_bytes:?int}> $parts
     */
    public function ensureParts(array $claim, array $parts): void
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_period_export_parts');
        }
        try {
            $this->assertActiveLease($claim, true);
            $existing = $pdo->prepare(
                'SELECT part_key, part_kind, source_id, source_sha256, source_size_bytes
                   FROM payroll_period_export_job_parts
                  WHERE supplier_id = ? AND job_id = ? FOR UPDATE',
            );
            $existing->execute([(int) $claim['supplier_id'], (int) $claim['id']]);
            $known = [];
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (is_array($row)) { $known[(string) $row['part_key']] = $row; }
            }
            $planned = [];
            foreach ($parts as $part) {
                $this->validatePartPlan($part);
                if (isset($planned[$part['part_key']])) {
                    throw new \InvalidArgumentException('Plán částí exportu mezd obsahuje duplicitní klíč.');
                }
                $planned[$part['part_key']] = $part;
            }
            if ($known !== []) {
                if (count($known) !== count($planned)) {
                    throw new \UnexpectedValueException('Existující plán částí exportu mezd se změnil.');
                }
                foreach ($planned as $partKey => $part) {
                    $row = $known[$partKey] ?? null;
                    if (!is_array($row) || !$this->samePartPlan($row, $part)) {
                        throw new \UnexpectedValueException('Existující plán části exportu mezd se změnil.');
                    }
                }
                $this->finish($pdo, $owns, 'payroll_period_export_parts');
                return;
            }
            $insert = $pdo->prepare(
                'INSERT INTO payroll_period_export_job_parts
                    (supplier_id, job_id, part_key, part_kind, source_id,
                     source_sha256, source_size_bytes, available_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            );
            foreach ($planned as $part) {
                try {
                    $insert->execute([
                        (int) $claim['supplier_id'], (int) $claim['id'],
                        $part['part_key'], $part['part_kind'], $part['source_id'],
                        $part['source_sha256'], $part['source_size_bytes'],
                    ]);
                } catch (\PDOException $exception) {
                    if ((string) $exception->getCode() !== '23000'
                        || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                        throw $exception;
                    }
                    $reload = $pdo->prepare(
                        'SELECT part_kind, source_id, source_sha256, source_size_bytes
                           FROM payroll_period_export_job_parts
                          WHERE supplier_id = ? AND job_id = ? AND part_key = ? FOR UPDATE',
                    );
                    $reload->execute([(int) $claim['supplier_id'], (int) $claim['id'], $part['part_key']]);
                    $row = $reload->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($row) || !$this->samePartPlan($row, $part)) {
                        throw new \UnexpectedValueException('Souběžně vložený plán části exportu mezd se změnil.');
                    }
                }
            }
            $this->finish($pdo, $owns, 'payroll_period_export_parts');
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $owns, 'payroll_period_export_parts');
            throw $exception;
        }
    }

    /** @param array<string,mixed> $claim
     *  @return array<string,mixed>|null
     */
    public function claimPart(array $claim): ?array
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_period_export_claim_part');
        }
        try {
            $this->assertActiveLease($claim, true);
            if ($this->recoverStalePartsLocked($claim)) {
                $this->finish($pdo, $owns, 'payroll_period_export_claim_part');
                return null;
            }
            $row = $pdo->prepare(
                'SELECT part.*
                   FROM payroll_period_export_job_parts part
                  WHERE part.supplier_id = ? AND part.job_id = ?
                    AND part.status IN ("queued", "retry_wait")
                    AND part.available_at <= UTC_TIMESTAMP()
                    AND (part.part_kind <> "archive" OR NOT EXISTS (
                        SELECT 1
                          FROM payroll_period_export_job_parts prerequisite
                         WHERE prerequisite.supplier_id = part.supplier_id
                           AND prerequisite.job_id = part.job_id
                           AND prerequisite.part_kind <> "archive"
                           AND prerequisite.status <> "completed"
                    ))
                  ORDER BY CASE part.part_kind WHEN "archive" THEN 1 ELSE 0 END,
                           part.id
                  LIMIT 1 FOR UPDATE',
            );
            $row->execute([(int) $claim['supplier_id'], (int) $claim['id']]);
            $part = $row->fetch(PDO::FETCH_ASSOC);
            if (!is_array($part)) {
                $this->finish($pdo, $owns, 'payroll_period_export_claim_part');
                return null;
            }
            $lease = random_bytes(16);
            $attemptNo = (int) $part['attempt_count'] + 1;
            $updated = $pdo->prepare(
                'UPDATE payroll_period_export_job_parts
                    SET status = "processing", attempt_count = ?, lease_token = ?,
                        locked_at = UTC_TIMESTAMP(), last_error_code = NULL,
                        last_error_message = NULL
                  WHERE supplier_id = ? AND id = ?
                    AND status IN ("queued", "retry_wait")',
            );
            $updated->execute([
                $attemptNo,
                $lease,
                (int) $claim['supplier_id'],
                (int) $part['id'],
            ]);
            if ($updated->rowCount() !== 1) {
                throw new \RuntimeException('Část exportu mezd se nepodařilo pronajmout.');
            }
            $pdo->prepare(
                'INSERT INTO payroll_period_export_job_part_attempts
                    (supplier_id, job_part_id, attempt_no, lease_token)
                 VALUES (?, ?, ?, ?)',
            )->execute([(int) $claim['supplier_id'], (int) $part['id'], $attemptNo, $lease]);
            $this->finish($pdo, $owns, 'payroll_period_export_claim_part');
            $part['attempt_count'] = $attemptNo;
            $part['lease_token'] = bin2hex($lease);

            return $part;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $owns, 'payroll_period_export_claim_part');
            throw $exception;
        }
    }

    /** @param array<string,mixed> $claim
     *  @return list<array<string,mixed>>
     */
    public function completedParts(array $claim): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, part_key, part_kind, source_id, source_sha256,
                    source_size_bytes, storage_key
               FROM payroll_period_export_job_parts
              WHERE supplier_id = ? AND job_id = ? AND status = "completed"
              ORDER BY id',
        );
        $statement->execute([(int) $claim['supplier_id'], (int) $claim['id']]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string,mixed> $claim
     *  @param array<string,mixed> $part
     */
    public function completePartAndRelease(array $claim, array $part, string $storageKey): void
    {
        $this->assertSameClaimPart($claim, $part);
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        $this->begin($pdo, $owns, 'payroll_period_export_complete_part');
        try {
            $this->completePartLocked($part, $storageKey);
            $this->releaseForNextPartLocked($claim, true);
            $this->finishPartAttempt($part, 'succeeded', null, null);
            $this->finishAttempt($claim, 'succeeded', null, null);
            $this->finish($pdo, $owns, 'payroll_period_export_complete_part');
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $owns, 'payroll_period_export_complete_part');
            throw $exception;
        }
    }

    /** @param array<string,mixed> $claim
     *  @param array<string,mixed> $part
     */
    public function completeArchivePartAndJob(array $claim, array $part, int $exportId): void
    {
        $this->assertSameClaimPart($claim, $part);
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        $this->begin($pdo, $owns, 'payroll_period_export_complete_archive');
        try {
            $this->completePartLocked($part, null);
            $this->completeJobLocked($claim, $exportId);
            $this->finishPartAttempt($part, 'succeeded', null, null);
            $this->finishAttempt($claim, 'succeeded', null, null);
            $this->finish($pdo, $owns, 'payroll_period_export_complete_archive');
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $owns, 'payroll_period_export_complete_archive');
            throw $exception;
        }
    }

    /** @param array<string,mixed> $claim
     *  @param array<string,mixed> $part
     *  @return bool|null true = retry, false = terminal failure, null = part already completed
     */
    public function failPartAndRelease(
        array $claim,
        array $part,
        string $errorCode,
        string $message,
    ): ?bool {
        $this->assertSameClaimPart($claim, $part);
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        $this->begin($pdo, $owns, 'payroll_period_export_fail_part');
        try {
            $current = $pdo->prepare(
                'SELECT job_id, status, lease_token
                   FROM payroll_period_export_job_parts
                  WHERE supplier_id = ? AND id = ? FOR UPDATE',
            );
            $current->execute([(int) $part['supplier_id'], (int) $part['id']]);
            $row = $current->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && (string) $row['status'] === 'completed') {
                if ((int) $row['job_id'] !== (int) $claim['id']) {
                    throw new \RuntimeException('Dokončená část exportu nepatří pronajatému jobu.');
                }
                $this->finish($pdo, $owns, 'payroll_period_export_fail_part');
                return null;
            }
            if (!is_array($row) || (int) $row['job_id'] !== (int) $claim['id']
                || (string) $row['status'] !== 'processing'
                || !is_string($row['lease_token'] ?? null)
                || !hash_equals($row['lease_token'], $this->partLease($part))) {
                throw new \RuntimeException('Selhání části exportu neodpovídá pronajatému workeru.');
            }

            $retry = (int) $part['attempt_count'] < self::MAX_ATTEMPTS;
            $delay = min(3600, 30 * (2 ** max(0, (int) $part['attempt_count'] - 1)));
            $availableAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s');
            $statement = $pdo->prepare(
                'UPDATE payroll_period_export_job_parts
                    SET status = ?, available_at = ?, lease_token = NULL, locked_at = NULL,
                        last_error_code = ?, last_error_message = ?
                  WHERE supplier_id = ? AND job_id = ? AND id = ?
                    AND status = "processing" AND lease_token = ?',
            );
            $statement->execute([
                $retry ? 'retry_wait' : 'failed',
                $availableAt,
                substr($errorCode, 0, 64),
                mb_substr($message, 0, 500),
                (int) $part['supplier_id'],
                (int) $part['job_id'],
                (int) $part['id'],
                $this->partLease($part),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Selhání části exportu neodpovídá pronajatému workeru.');
            }
            if ($retry) {
                $this->releaseForNextPartLocked($claim, false);
            } else {
                $this->failForTerminalPartLocked($claim, $errorCode, $message);
            }
            $this->finishPartAttempt($part, 'failed', $errorCode, $message);
            $this->finishAttempt($claim, 'failed', $errorCode, $message);
            $this->finish($pdo, $owns, 'payroll_period_export_fail_part');

            return $retry;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $owns, 'payroll_period_export_fail_part');
            throw $exception;
        }
    }

    /** @param array<string,mixed> $part */
    private function completePartLocked(array $part, ?string $storageKey): void
    {
        $archive = (string) $part['part_kind'] === 'archive';
        if (($archive && $storageKey !== null)
            || (!$archive && (!is_string($storageKey)
                || !hash_equals((string) $part['source_sha256'], $storageKey)))) {
            throw new \InvalidArgumentException('Dokončená část exportu nemá očekávaný neměnný obsah.');
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_job_parts
                SET status = "completed", storage_key = ?, completed_at = UTC_TIMESTAMP(),
                    lease_token = NULL, locked_at = NULL,
                    last_error_code = NULL, last_error_message = NULL
              WHERE supplier_id = ? AND job_id = ? AND id = ?
                AND status = "processing" AND lease_token = ?',
        );
        $statement->execute([
            $storageKey,
            (int) $part['supplier_id'],
            (int) $part['job_id'],
            (int) $part['id'],
            $this->partLease($part),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Dokončení části exportu neodpovídá pronajatému workeru.');
        }
    }

    /** @param array<string,mixed> $claim */
    private function releaseForNextPartLocked(array $claim, bool $succeeded): void
    {
        $next = $this->nextPartAvailability($claim);
        if ($next === null) {
            throw new \RuntimeException('Export mezd nemá další dostupnou část.');
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_jobs
                SET status = ?, available_at = ?, failure_count = ?,
                    lease_token = NULL, locked_at = NULL
              WHERE supplier_id = ? AND id = ? AND status = "processing" AND lease_token = ?',
        );
        $statement->execute([
            $next <= gmdate('Y-m-d H:i:s') ? 'queued' : 'retry_wait',
            $next,
            $succeeded ? 0 : (int) ($claim['failure_count'] ?? 0),
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            $this->lease($claim),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Uvolnění další části exportu neodpovídá pronajatému jobu.');
        }
    }

    /** @param array<string,mixed> $claim */
    private function completeJobLocked(array $claim, int $exportId): void
    {
        if ($exportId <= 0) {
            throw new \InvalidArgumentException('Dokončený export mezd není platný.');
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_jobs job
               JOIN payroll_period_exports export_row
                 ON export_row.supplier_id = job.supplier_id AND export_row.id = ?
                SET job.status = "completed", job.export_id = export_row.id,
                    job.completed_at = UTC_TIMESTAMP(), job.available_at = UTC_TIMESTAMP(),
                    job.lease_token = NULL, job.locked_at = NULL,
                    job.last_error_code = NULL, job.last_error_message = NULL, job.failure_count = 0
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
    }

    /** @param array<string,mixed> $claim */
    private function failForTerminalPartLocked(array $claim, string $errorCode, string $message): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_jobs
                SET status = "failed", available_at = UTC_TIMESTAMP(), lease_token = NULL, locked_at = NULL,
                    last_error_code = ?, last_error_message = ?
              WHERE supplier_id = ? AND id = ? AND status = "processing" AND lease_token = ?',
        );
        $statement->execute([
            substr($errorCode, 0, 64),
            mb_substr($message, 0, 500),
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            $this->lease($claim),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Terminální část exportu neodpovídá pronajatému jobu.');
        }
    }

    /** @param array<string,mixed> $claim
     *  @param array<string,mixed> $part
     */
    private function assertSameClaimPart(array $claim, array $part): void
    {
        if ((int) ($claim['supplier_id'] ?? 0) <= 0
            || (int) ($claim['supplier_id'] ?? 0) !== (int) ($part['supplier_id'] ?? 0)
            || (int) ($claim['id'] ?? 0) <= 0
            || (int) ($claim['id'] ?? 0) !== (int) ($part['job_id'] ?? 0)) {
            throw new \InvalidArgumentException('Část exportu nepatří pronajatému jobu.');
        }
    }

    /** @param array<string,mixed> $row
     *  @param array{part_key:string,part_kind:string,source_id:int,source_sha256:?string,source_size_bytes:?int} $part
     */
    private function samePartPlan(array $row, array $part): bool
    {
        return (string) $row['part_kind'] === $part['part_kind']
            && (int) $row['source_id'] === $part['source_id']
            && (string) ($row['source_sha256'] ?? '') === (string) ($part['source_sha256'] ?? '')
            && (int) ($row['source_size_bytes'] ?? 0) === (int) ($part['source_size_bytes'] ?? 0);
    }

    /** @param array<string,mixed> $claim */
    private function assertActiveLease(array $claim, bool $locked): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT lease_token
               FROM payroll_period_export_jobs
              WHERE supplier_id = ? AND id = ? AND status = "processing"' . ($locked ? ' FOR UPDATE' : ''),
        );
        $statement->execute([(int) $claim['supplier_id'], (int) $claim['id']]);
        $lease = $statement->fetchColumn();
        if (!is_string($lease) || !hash_equals($lease, $this->lease($claim))) {
            throw new \RuntimeException('Job exportu mezd už nevlastní tento worker.');
        }
    }

    /** @param array<string,mixed> $claim */
    private function nextPartAvailability(array $claim): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT MIN(part.available_at)
               FROM payroll_period_export_job_parts part
              WHERE part.supplier_id = ? AND part.job_id = ?
                AND part.status IN ("queued", "retry_wait")
                AND (part.part_kind <> "archive" OR NOT EXISTS (
                    SELECT 1 FROM payroll_period_export_job_parts prerequisite
                     WHERE prerequisite.supplier_id = part.supplier_id AND prerequisite.job_id = part.job_id
                       AND prerequisite.part_kind <> "archive" AND prerequisite.status <> "completed"
                ))',
        );
        $statement->execute([(int) $claim['supplier_id'], (int) $claim['id']]);
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $claim */
    private function recoverStalePartsLocked(array $claim): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, attempt_count, lease_token
               FROM payroll_period_export_job_parts
              WHERE supplier_id = ? AND job_id = ? AND status = "processing"
                AND locked_at < UTC_TIMESTAMP() - INTERVAL ' . self::STALE_AFTER_SECONDS . ' SECOND
              FOR UPDATE',
        );
        $statement->execute([(int) $claim['supplier_id'], (int) $claim['id']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $part) {
            if (!is_array($part) || !is_string($part['lease_token'] ?? null)) {
                continue;
            }
            $retry = (int) $part['attempt_count'] < self::MAX_ATTEMPTS;
            $this->db->pdo()->prepare(
                'UPDATE payroll_period_export_job_part_attempts
                    SET status = "stale", error_code = "worker_lease_expired",
                        error_message = "Worker lease expired before part completion.",
                        finished_at = UTC_TIMESTAMP()
                  WHERE supplier_id = ? AND job_part_id = ? AND lease_token = ? AND status = "running"',
            )->execute([(int) $claim['supplier_id'], (int) $part['id'], $part['lease_token']]);
            $this->db->pdo()->prepare(
                'UPDATE payroll_period_export_job_parts
                    SET status = ?, available_at = UTC_TIMESTAMP(), lease_token = NULL, locked_at = NULL,
                        last_error_code = "worker_lease_expired",
                        last_error_message = "Worker lease expired before part completion."
                  WHERE supplier_id = ? AND id = ? AND status = "processing"',
            )->execute([$retry ? 'retry_wait' : 'failed', (int) $claim['supplier_id'], (int) $part['id']]);
            if (!$retry) {
                $errorCode = 'worker_lease_expired';
                $message = 'Worker lease expired before part completion.';
                $this->failForTerminalPartLocked($claim, $errorCode, $message);
                $this->finishAttempt($claim, 'failed', $errorCode, $message);
                return true;
            }
        }

        return false;
    }

    /** @param array{part_key:string,part_kind:string,source_id:int,source_sha256:?string,source_size_bytes:?int} $part */
    private function validatePartPlan(array $part): void
    {
        $archive = ($part['part_kind'] ?? null) === 'archive';
        if (!is_string($part['part_key'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $part['part_key']) !== 1
            || !in_array($part['part_kind'] ?? null, ['document', 'submission_artifact', 'submission_protocol', 'archive'], true)
            || !is_int($part['source_id'] ?? null)
            || (!$archive && ($part['source_id'] <= 0
                || !is_string($part['source_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $part['source_sha256']) !== 1
                || !is_int($part['source_size_bytes'] ?? null)
                || $part['source_size_bytes'] <= 0))
            || ($archive && ($part['source_id'] !== 0
                || $part['source_sha256'] !== null || $part['source_size_bytes'] !== null))
        ) {
            throw new \InvalidArgumentException('Plán části exportu mezd není platný.');
        }
    }

    /** @param array<string,mixed> $part */
    private function partLease(array $part): string
    {
        $lease = hex2bin((string) ($part['lease_token'] ?? ''));
        if (!is_string($lease) || strlen($lease) !== 16) {
            throw new \InvalidArgumentException('Worker části exportu mezd není platný.');
        }

        return $lease;
    }

    /** @param array<string,mixed> $part */
    private function finishPartAttempt(
        array $part,
        string $status,
        ?string $errorCode,
        ?string $message,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_job_part_attempts
                SET status = ?, error_code = ?, error_message = ?, finished_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND job_part_id = ? AND attempt_no = ?
                AND lease_token = ? AND status = "running"',
        )->execute([
            $status,
            $errorCode === null ? null : substr($errorCode, 0, 64),
            $message === null ? null : mb_substr($message, 0, 500),
            (int) $part['supplier_id'],
            (int) $part['id'],
            (int) $part['attempt_count'],
            $this->partLease($part),
        ]);
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
            'SELECT supplier_id, id, attempt_count, failure_count, lease_token
               FROM payroll_period_export_jobs
              WHERE status = "processing"
                AND locked_at < UTC_TIMESTAMP() - INTERVAL ' . self::STALE_AFTER_SECONDS . ' SECOND
              FOR UPDATE',
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stale as $job) {
            if (!is_array($job) || !is_string($job['lease_token'] ?? null)) {
                continue;
            }
            $failureCount = (int) $job['failure_count'] + 1;
            $retry = $failureCount < self::MAX_ATTEMPTS;
            $pdo->prepare(
                'UPDATE payroll_period_export_job_attempts
                    SET status = "stale", error_code = "worker_lease_expired",
                        error_message = "Worker lease expired before completion.",
                        finished_at = UTC_TIMESTAMP()
                  WHERE supplier_id = ? AND job_id = ? AND lease_token = ? AND status = "running"',
            )->execute([(int) $job['supplier_id'], (int) $job['id'], $job['lease_token']]);
            $pdo->prepare(
                'UPDATE payroll_period_export_jobs
                    SET status = ?, failure_count = ?, available_at = UTC_TIMESTAMP(), lease_token = NULL, locked_at = NULL,
                        last_error_code = "worker_lease_expired",
                        last_error_message = "Worker lease expired before completion."
                  WHERE supplier_id = ? AND id = ? AND status = "processing"',
            )->execute([$retry ? 'retry_wait' : 'failed', $failureCount, (int) $job['supplier_id'], (int) $job['id']]);
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
        foreach (['id', 'attempt_count', 'failure_count'] as $field) {
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

    private function begin(PDO $pdo, bool $owns, string $savepoint): void
    {
        $owns ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT ' . $savepoint);
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
