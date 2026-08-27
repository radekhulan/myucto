<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Read-only, tenant-scoped operational counts for the payroll dashboard. */
final class PayrollOperationalHealthService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   document_batches:array{queued:int,running:int,retry_wait:int,failed:int},
     *   period_export_jobs:array{queued:int,processing:int,retry_wait:int,failed:int},
     *   submissions:array{rejected:int,correction_required:int,open_blocker_or_error_issues:int},
     *   isds_outbox:array{failed:int,send_uncertain:int,rejected:int},
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
            'overdue_unpaid_liabilities' => $this->overdueUnpaidLiabilities($supplierId),
        ];
    }

    /** @return array{queued:int,running:int,retry_wait:int,failed:int} */
    private function documentBatches(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT
                SUM(status = "queued") AS queued,
                SUM(status = "running") AS running,
                SUM(status = "retry_wait") AS retry_wait,
                SUM(status = "failed") AS failed
               FROM payroll_document_batches
              WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'queued' => (int) ($row['queued'] ?? 0),
            'running' => (int) ($row['running'] ?? 0),
            'retry_wait' => (int) ($row['retry_wait'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    /** @return array{queued:int,processing:int,retry_wait:int,failed:int} */
    private function periodExportJobs(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT
                SUM(status = "queued") AS queued,
                SUM(status = "processing") AS processing,
                SUM(status = "retry_wait") AS retry_wait,
                SUM(status = "failed") AS failed
               FROM payroll_period_export_jobs
              WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'queued' => (int) ($row['queued'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'retry_wait' => (int) ($row['retry_wait'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
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
}
