<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čte pouze skalární souhrny z neměnných, aktuálně schválených revizí.
 * Osobní řádky ani snapshoty se z databáze nevynášejí.
 */
final class PayrollAnnualReportRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{
     *   period_start:string,
     *   headcount:mixed,
     *   gross_minor:mixed,
     *   employer_social_minor:mixed,
     *   employer_health_minor:mixed
     * }>
     */
    public function approvedCurrentRevisions(int $supplierId, int $year): array
    {
        $from = sprintf('%04d-01-01', $year);
        $until = sprintf('%04d-01-01', $year + 1);
        $statement = $this->db->pdo()->prepare(
            'SELECT run.period_start,
                    JSON_LENGTH(revision.result_snapshot_json, "$.people") AS headcount,
                    JSON_VALUE(revision.result_snapshot_json, "$.totals.source_amount_minor") AS gross_minor,
                    JSON_VALUE(social.result_snapshot_json, "$.employer_contribution_minor_units")
                        AS employer_social_minor,
                    JSON_VALUE(health.result_snapshot_json, "$.employer_contribution_minor_units")
                        AS employer_health_minor
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
          LEFT JOIN payroll_statutory_results social
                 ON social.supplier_id = revision.supplier_id
                AND social.revision_id = revision.id
                AND social.calculation_kind = "social_insurance"
                AND social.result_status = "calculated"
          LEFT JOIN payroll_statutory_results health
                 ON health.supplier_id = revision.supplier_id
                AND health.revision_id = revision.id
                AND health.calculation_kind = "health_insurance"
                AND health.result_status = "calculated"
              WHERE run.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
                AND revision.status = "approved"
                AND revision.result_snapshot_json IS NOT NULL
              ORDER BY run.period_start, run.id',
        );
        $statement->execute([$supplierId, $from, $until]);

        /** @var list<array{period_start:string,headcount:mixed,gross_minor:mixed,employer_social_minor:mixed,employer_health_minor:mixed}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
