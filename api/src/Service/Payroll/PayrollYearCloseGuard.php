<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollYearCloseGuard
{
    public function __construct(private readonly Connection $db) {}

    public function assertOpenForDateRange(int $supplierId, string $from, string $to): void
    {
        $start = self::date($from);
        $end = self::date($to);
        if ($end < $start) {
            throw new \InvalidArgumentException('Konec mzdového období předchází začátku.');
        }
        for ($year = (int) $start->format('Y'); $year <= (int) $end->format('Y'); ++$year) {
            $this->assertOpenForYear($supplierId, $year);
        }
    }

    public function assertOpenForRevision(int $supplierId, int $revisionId): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE revision.supplier_id = ? AND revision.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $revisionId]);
        $periodStart = $statement->fetchColumn();
        if (!is_string($periodStart)) {
            throw new \DomainException('Mzdová revize nebyla nalezena ve stejné firmě.');
        }
        $this->assertOpenForDateRange($supplierId, $periodStart, $periodStart);
    }

    public function assertOpenForLiability(int $supplierId, int $liabilityId): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run.period_start
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE liability.supplier_id = ? AND liability.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $liabilityId]);
        $periodStart = $statement->fetchColumn();
        if (!is_string($periodStart)) {
            throw new \DomainException('Mzdový závazek nebyl nalezen ve stejné firmě.');
        }
        $this->assertOpenForDateRange($supplierId, $periodStart, $periodStart);
    }

    public function assertOpenForObligation(int $supplierId, int $obligationId): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT environment, period_start, period_end
               FROM payroll_obligations
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $obligationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Mzdová povinnost nebyla nalezena ve stejné firmě.');
        }
        if ($row['environment'] !== 'production') {
            return;
        }
        $this->assertOpenForDateRange($supplierId, (string) $row['period_start'], (string) $row['period_end']);
    }

    public function assertOpenForSubmission(int $supplierId, int $submissionId): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.environment, obligation.period_start, obligation.period_end,
                    run.period_start AS run_period_start
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = submission.supplier_id
                AND revision.id = submission.source_revision_id
          LEFT JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE submission.supplier_id = ? AND submission.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Mzdové podání nebylo nalezeno ve stejné firmě.');
        }
        if ($row['environment'] !== 'production') {
            return;
        }
        $this->assertOpenForDateRange($supplierId, (string) $row['period_start'], (string) $row['period_end']);
        if (is_string($row['run_period_start']) && $row['run_period_start'] !== '') {
            $this->assertOpenForDateRange($supplierId, $row['run_period_start'], $row['run_period_start']);
        }
    }

    public function assertOpenForYear(int $supplierId, int $year): void
    {
        if (!$this->db->hasTable('payroll_year_closures')) {
            return;
        }
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException(
                'Kontrola roční uzávěrky musí proběhnout ve stejné transakci jako chráněný zápis.',
            );
        }
        $statement = $pdo->prepare(
            "SELECT status FROM payroll_year_closures
              WHERE supplier_id = ? AND calendar_year = ? FOR UPDATE",
        );
        $statement->execute([$supplierId, $year]);
        if ($statement->fetchColumn() === 'closed') {
            throw new PayrollYearClosedException($year);
        }
    }

    private static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Mzdové období musí být datum YYYY-MM-DD.');
        }
        return $date;
    }
}
