<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Read-only, tenant-scoped operational counts for the payroll dashboard. */
final class PayrollOperationalHealthService
{
    private readonly \DateTimeZone $appTimeZone;

    public function __construct(
        private readonly Connection $db,
        Config $config,
    ) {
        $timezone = (string) $config->get('app.timezone', 'Europe/Prague');
        try {
            $this->appTimeZone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $this->appTimeZone = new \DateTimeZone(date_default_timezone_get());
        }
    }

    /**
     * @return array{
     *   document_batches:array{
     *     queued:int,running:int,retry_wait:int,failed:int,
     *     oldest_pending_at:?string,oldest_pending_age_seconds:?int,last_completed_at:?string
     *   },
     *   period_export_jobs:array{
     *     queued:int,processing:int,retry_wait:int,failed:int,
     *     oldest_pending_at:?string,oldest_pending_age_seconds:?int,last_completed_at:?string
     *   },
     *   submissions:array{rejected:int,correction_required:int,open_blocker_or_error_issues:int},
     *   isds_outbox:array{failed:int,send_uncertain:int,rejected:int},
     *   archive_capacity:array{
     *     measured:bool,content_bytes:?int,object_count:?int,
     *     components:array<string,array{measured:bool,content_bytes:?int,object_count:?int}>
     *   },
     *   overdue_unpaid_liabilities:int
     * }
     */
    public function overview(int $supplierId): array
    {
        return [
            'document_batches' => $this->documentBatches($supplierId),
            'period_export_jobs' => $this->periodExportJobs($supplierId),
            'submissions' => $this->submissions($supplierId),
            'isds_outbox' => $this->isdsOutbox($supplierId),
            'archive_capacity' => $this->archiveCapacity($supplierId),
            'overdue_unpaid_liabilities' => $this->overdueUnpaidLiabilities($supplierId),
        ];
    }

    /**
     * @return array{
     *   queued:int,running:int,retry_wait:int,failed:int,
     *   oldest_pending_at:?string,oldest_pending_age_seconds:?int,last_completed_at:?string
     * }
     */
    private function documentBatches(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT
                SUM(status = "queued") AS queued,
                SUM(status = "running") AS running,
                SUM(status = "retry_wait") AS retry_wait,
                SUM(status = "failed") AS failed,
                DATE_FORMAT(
                    MIN(CASE WHEN status IN ("queued", "running", "retry_wait")
                        THEN created_at END),
                    "%Y-%m-%d %H:%i:%s"
                ) AS oldest_pending_local,
                DATE_FORMAT(
                    MAX(CASE WHEN status = "completed" THEN completed_at END),
                    "%Y-%m-%d %H:%i:%s"
                ) AS last_completed_utc
               FROM payroll_document_batches
              WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        [$oldestPendingAt, $oldestPendingAge] = $this->pendingTime(
            $row['oldest_pending_local'] ?? null,
        );
        return [
            'queued' => (int) ($row['queued'] ?? 0),
            'running' => (int) ($row['running'] ?? 0),
            'retry_wait' => (int) ($row['retry_wait'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'oldest_pending_at' => $oldestPendingAt,
            'oldest_pending_age_seconds' => $oldestPendingAge,
            'last_completed_at' => $this->completedTime($row['last_completed_utc'] ?? null),
        ];
    }

    /**
     * @return array{
     *   queued:int,processing:int,retry_wait:int,failed:int,
     *   oldest_pending_at:?string,oldest_pending_age_seconds:?int,last_completed_at:?string
     * }
     */
    private function periodExportJobs(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT
                SUM(status = "queued") AS queued,
                SUM(status = "processing") AS processing,
                SUM(status = "retry_wait") AS retry_wait,
                SUM(status = "failed") AS failed,
                DATE_FORMAT(
                    MIN(CASE WHEN status IN ("queued", "processing", "retry_wait")
                        THEN created_at END),
                    "%Y-%m-%d %H:%i:%s"
                ) AS oldest_pending_local,
                DATE_FORMAT(
                    MAX(CASE WHEN status = "completed" THEN completed_at END),
                    "%Y-%m-%d %H:%i:%s"
                ) AS last_completed_utc
               FROM payroll_period_export_jobs
              WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        [$oldestPendingAt, $oldestPendingAge] = $this->pendingTime(
            $row['oldest_pending_local'] ?? null,
        );
        return [
            'queued' => (int) ($row['queued'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'retry_wait' => (int) ($row['retry_wait'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'oldest_pending_at' => $oldestPendingAt,
            'oldest_pending_age_seconds' => $oldestPendingAge,
            'last_completed_at' => $this->completedTime($row['last_completed_utc'] ?? null),
        ];
    }

    /** @return array{0:?string,1:?int} */
    private function pendingTime(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [null, null];
        }
        $local = new \DateTimeImmutable($value, $this->appTimeZone);
        $utc = $local->setTimezone(new \DateTimeZone('UTC'));
        return [
            $utc->format('Y-m-d\TH:i:s\Z'),
            max(0, time() - $utc->getTimestamp()),
        ];
    }

    private function completedTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array{rejected:int,correction_required:int,open_blocker_or_error_issues:int} */
    private function submissions(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT
                (SELECT COUNT(*)
                   FROM payroll_submissions
                  WHERE supplier_id = ? AND status = "rejected") AS rejected,
                (SELECT COUNT(*)
                   FROM payroll_submissions
                  WHERE supplier_id = ? AND status = "correction_required") AS correction_required,
                (SELECT COUNT(*)
                   FROM payroll_submission_issues
                  WHERE supplier_id = ?
                    AND is_resolved = 0
                    AND severity IN ("blocker", "error")) AS open_issues',
        );
        $statement->execute([$supplierId, $supplierId, $supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'rejected' => (int) ($row['rejected'] ?? 0),
            'correction_required' => (int) ($row['correction_required'] ?? 0),
            'open_blocker_or_error_issues' => (int) ($row['open_issues'] ?? 0),
        ];
    }

    /** @return array{failed:int,send_uncertain:int,rejected:int} */
    private function isdsOutbox(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT
                SUM(dispatch_state = "failed") AS failed,
                SUM(dispatch_state = "send_uncertain") AS send_uncertain,
                SUM(acceptance_state = "rejected") AS rejected
               FROM submission_outbox
              WHERE supplier_id = ?
                AND channel = "isds"
                AND artifact_kind = "payroll_submission"',
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'failed' => (int) ($row['failed'] ?? 0),
            'send_uncertain' => (int) ($row['send_uncertain'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }

    private function overdueUnpaidLiabilities(int $supplierId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM (
                    SELECT liability.id
                      FROM payroll_payment_liabilities liability
                      LEFT JOIN payroll_payment_allocations allocation
                        ON allocation.supplier_id = liability.supplier_id
                       AND allocation.liability_id = liability.id
                      LEFT JOIN payroll_payment_matches payment_match
                        ON payment_match.supplier_id = allocation.supplier_id
                       AND payment_match.allocation_id = allocation.id
                     WHERE liability.supplier_id = ?
                       AND liability.direction = "outgoing"
                       AND liability.due_on < UTC_DATE()
                     GROUP BY liability.id, liability.amount_minor
                    HAVING COALESCE(SUM(payment_match.amount_minor), 0) < liability.amount_minor
               ) AS overdue_liabilities',
        );
        $statement->execute([$supplierId]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Logical size of canonical, re-downloadable payroll artifacts. CAS-backed
     * files count once per storage key; DB-backed submission artifacts count
     * once per immutable row. This is not the hosting/filesystem quota.
     *
     * @return array{
     *   measured:bool,content_bytes:?int,object_count:?int,
     *   components:array<string,array{measured:bool,content_bytes:?int,object_count:?int}>
     * }
     */
    private function archiveCapacity(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT "generated_documents" AS component,
                    COALESCE(SUM(cas.size_bytes), 0) AS content_bytes,
                    COUNT(*) AS object_count,
                    COALESCE(SUM(cas.min_size <> cas.max_size), 0) AS inconsistent
               FROM (
                    SELECT storage_key, MIN(size_bytes) AS size_bytes,
                           MIN(size_bytes) AS min_size, MAX(size_bytes) AS max_size
                      FROM payroll_generated_documents
                     WHERE supplier_id = ?
                     GROUP BY storage_key
               ) cas
             UNION ALL
             SELECT "payment_exports",
                    COALESCE(SUM(cas.size_bytes), 0), COUNT(*),
                    COALESCE(SUM(cas.min_size <> cas.max_size), 0)
               FROM (
                    SELECT storage_key, MIN(size_bytes) AS size_bytes,
                           MIN(size_bytes) AS min_size, MAX(size_bytes) AS max_size
                      FROM payroll_payment_exports
                     WHERE supplier_id = ?
                     GROUP BY storage_key
               ) cas
             UNION ALL
             SELECT "period_exports",
                    COALESCE(SUM(cas.size_bytes), 0), COUNT(*),
                    COALESCE(SUM(cas.min_size <> cas.max_size), 0)
               FROM (
                    SELECT storage_key, MIN(size_bytes) AS size_bytes,
                           MIN(size_bytes) AS min_size, MAX(size_bytes) AS max_size
                      FROM payroll_period_exports
                     WHERE supplier_id = ?
                     GROUP BY storage_key
               ) cas
             UNION ALL
             SELECT "submission_artifacts",
                    COALESCE(SUM(byte_size), 0), COUNT(*), 0
               FROM payroll_submission_artifacts
              WHERE supplier_id = ?',
        );
        $statement->execute([
            $supplierId,
            $supplierId,
            $supplierId,
            $supplierId,
        ]);

        $components = [];
        $measured = true;
        $contentBytes = 0;
        $objectCount = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $component = (string) ($row['component'] ?? '');
            if (!in_array($component, [
                'generated_documents',
                'payment_exports',
                'period_exports',
                'submission_artifacts',
            ], true)) {
                throw new \RuntimeException('Neznámá komponenta kapacity mzdového archivu.');
            }
            $componentMeasured = (int) ($row['inconsistent'] ?? 0) === 0;
            $componentBytes = (int) ($row['content_bytes'] ?? 0);
            $componentCount = (int) ($row['object_count'] ?? 0);
            $components[$component] = [
                'measured' => $componentMeasured,
                'content_bytes' => $componentMeasured ? $componentBytes : null,
                'object_count' => $componentMeasured ? $componentCount : null,
            ];
            if (!$componentMeasured) {
                $measured = false;
                continue;
            }
            $contentBytes += $componentBytes;
            $objectCount += $componentCount;
        }

        return [
            'measured' => $measured,
            'content_bytes' => $measured ? $contentBytes : null,
            'object_count' => $measured ? $objectCount : null,
            'components' => $components,
        ];
    }
}
