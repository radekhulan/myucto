<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Odložení pracovních vztahů z řádného měsíčního hlášení JMHZ a jejich vazba
 * na zmrazená podání (migrace 1902).
 *
 * Řádky se nemažou ani nepřepisují: odložení lze jen odvolat a vazba na podání
 * jen doplnit opravným hlášením. Hlídají to triggery v databázi.
 */
final class JmhzDeferralRepository
{
    private const COLUMNS = 'deferral.id, deferral.run_id, deferral.source_revision_id,
        deferral.period_start, deferral.office_id, deferral.employee_id,
        deferral.employment_id, deferral.reason, deferral.blocker_codes_json,
        deferral.decided_environment, deferral.decided_preparation_id,
        deferral.decided_snapshot_fingerprint, deferral.status,
        deferral.created_by, deferral.created_at, deferral.revoked_by,
        deferral.revoked_at, deferral.revoke_reason, deferral.row_version';

    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function activeForRevision(int $supplierId, int $revisionId, bool $forUpdate = false): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_jmhz_deferrals deferral
              WHERE deferral.supplier_id = ? AND deferral.source_revision_id = ?
                AND deferral.status = \'active\'
              ORDER BY deferral.employment_id, deferral.id'
                . ($forUpdate ? ' FOR UPDATE' : ''),
            [$supplierId, $revisionId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function forRun(int $supplierId, int $runId, string $periodStart): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . ', revision.revision_no
               FROM payroll_jmhz_deferrals deferral
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = deferral.supplier_id
                AND revision.id = deferral.source_revision_id
              WHERE deferral.supplier_id = ? AND deferral.run_id = ?
                AND deferral.period_start = ?
              ORDER BY deferral.status = \'active\' DESC, deferral.created_at DESC,
                       deferral.id DESC',
            [$supplierId, $runId, $periodStart],
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $deferralId, bool $forUpdate = false): ?array
    {
        $rows = $this->many(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_jmhz_deferrals deferral
              WHERE deferral.supplier_id = ? AND deferral.id = ?'
                . ($forUpdate ? ' FOR UPDATE' : ''),
            [$supplierId, $deferralId],
        );

        return $rows[0] ?? null;
    }

    /** @param array<string,mixed> $row */
    public function insert(array $row): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_deferrals
                (supplier_id, run_id, source_revision_id, period_start, office_id,
                 employee_id, employment_id, reason, blocker_codes_json,
                 decided_environment, decided_preparation_id,
                 decided_snapshot_fingerprint, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $row['supplier_id'],
            $row['run_id'],
            $row['source_revision_id'],
            $row['period_start'],
            $row['office_id'],
            $row['employee_id'],
            $row['employment_id'],
            $row['reason'],
            $row['blocker_codes_json'],
            $row['decided_environment'],
            $row['decided_preparation_id'],
            $row['decided_snapshot_fingerprint'],
            $row['created_by'],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function revoke(
        int $supplierId,
        int $deferralId,
        int $rowVersion,
        int $revokedBy,
        string $reason,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_jmhz_deferrals
                SET status = \'revoked\', revoked_by = ?, revoked_at = NOW(6),
                    revoke_reason = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND row_version = ?
                AND status = \'active\'',
        );
        $statement->execute([$revokedBy, $reason, $supplierId, $deferralId, $rowVersion]);

        return $statement->rowCount() === 1;
    }

    public function insertBinding(
        int $supplierId,
        int $deferralId,
        string $environment,
        int $regularSubmissionId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_jmhz_deferral_submissions
                (supplier_id, deferral_id, environment, regular_submission_id)
             VALUES (?, ?, ?, ?)',
        )->execute([$supplierId, $deferralId, $environment, $regularSubmissionId]);
    }

    /**
     * Vazby odložení na zmrazená řádná hlášení, i se stavem řádného a opravného
     * podání.
     *
     * @param list<int> $deferralIds
     * @return list<array<string,mixed>>
     */
    public function bindings(int $supplierId, array $deferralIds): array
    {
        if ($deferralIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($deferralIds), '?'));

        return $this->many(
            "SELECT binding.id, binding.deferral_id, binding.environment,
                    binding.regular_submission_id, regular.status AS regular_status,
                    binding.correction_submission_id,
                    correction.status AS correction_status,
                    binding.created_at, binding.completed_at
               FROM payroll_jmhz_deferral_submissions binding
               JOIN payroll_submissions regular
                 ON regular.supplier_id = binding.supplier_id
                AND regular.environment = binding.environment
                AND regular.id = binding.regular_submission_id
               LEFT JOIN payroll_submissions correction
                 ON correction.supplier_id = binding.supplier_id
                AND correction.environment = binding.environment
                AND correction.id = binding.correction_submission_id
              WHERE binding.supplier_id = ?
                AND binding.deferral_id IN ({$placeholders})
              ORDER BY binding.environment, binding.id",
            [$supplierId, ...$deferralIds],
        );
    }

    /**
     * Odložení promítnutá do daného řádného hlášení.
     *
     * @return list<array<string,mixed>>
     */
    public function bindingsForRegular(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
        bool $forUpdate = false,
    ): array {
        return $this->many(
            'SELECT binding.id AS binding_id, binding.correction_submission_id,
                    correction.status AS correction_status, ' . self::COLUMNS . '
               FROM payroll_jmhz_deferral_submissions binding
               JOIN payroll_jmhz_deferrals deferral
                 ON deferral.supplier_id = binding.supplier_id
                AND deferral.id = binding.deferral_id
               LEFT JOIN payroll_submissions correction
                 ON correction.supplier_id = binding.supplier_id
                AND correction.environment = binding.environment
                AND correction.id = binding.correction_submission_id
              WHERE binding.supplier_id = ? AND binding.environment = ?
                AND binding.regular_submission_id = ?
              ORDER BY deferral.employment_id, binding.id'
                . ($forUpdate ? ' FOR UPDATE' : ''),
            [$supplierId, $environment, $regularSubmissionId],
        );
    }

    public function completeBinding(
        int $supplierId,
        int $bindingId,
        string $environment,
        int $correctionSubmissionId,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE payroll_jmhz_deferral_submissions
                SET correction_submission_id = ?, completed_at = NOW(6)
              WHERE supplier_id = ? AND id = ? AND environment = ?',
        )->execute([$correctionSubmissionId, $supplierId, $bindingId, $environment]);
    }

    /**
     * Řádné hlášení JMHZ za mzdový běh a registraci, které už je zmrazené
     * a nebylo zrušené ani nahrazené. Odložení se po zmrazení nemění - co
     * hlášení vynechalo, doplní opravné.
     *
     * @return array{id:int,status:string}|null
     */
    public function liveRegularSubmission(
        int $supplierId,
        string $environment,
        int $runId,
        ?int $officeId,
        string $periodStart,
    ): ?array {
        $references = ["payroll_run:{$runId}"];
        if ($officeId !== null) {
            $references[] = "payroll_run:{$runId}:office:{$officeId}";
        }
        $placeholders = implode(', ', array_fill(0, count($references), '?'));
        $rows = $this->many(
            "SELECT submission.id, submission.status
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ? AND submission.environment = ?
                AND submission.submission_kind = 'regular'
                AND submission.status NOT IN ('cancelled_in_time', 'superseded')
                AND obligation.agenda_code = 'JMHZ25'
                AND obligation.period_start = ?
                AND obligation.subject_reference IN ({$placeholders})
              ORDER BY submission.id DESC
              LIMIT 1",
            [$supplierId, $environment, $periodStart, ...$references],
        );
        if ($rows === []) {
            return null;
        }

        return ['id' => (int) $rows[0]['id'], 'status' => (string) $rows[0]['status']];
    }

    /** @return array{run_id:int,period_start:string}|null */
    public function revisionScope(int $supplierId, int $revisionId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.run_id, run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ? AND revision.id = ?',
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return ['run_id' => (int) $row['run_id'], 'period_start' => (string) $row['period_start']];
    }

    /**
     * Aktuální schválená revize běhu - nad ní se po vyřešení vztahu staví
     * nová příprava pro opravné hlášení.
     */
    public function currentApprovedRevisionId(int $supplierId, int $runId): ?int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ? AND revision.run_id = ?
                AND revision.revision_no = run.current_revision_no
                AND revision.status = \'approved\'',
        );
        $statement->execute([$supplierId, $runId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Vztahy osob, které účetní vyřadila z mzdového běhu a schválená revize
     * šla bez nich: v měsíci trvají, ale revize za ně nemá výsledek.
     *
     * @return list<array{run_id:int,period_start:string,employee_id:int,employment_id:int,office_id:?int,reason:string,excluded_by:?int}>
     */
    public function settledRunExclusions(int $supplierId, int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.run_id, run.period_start, exclusion.employee_id,
                    employment.id AS employment_id, employment.office_id,
                    exclusion.reason, exclusion.excluded_by
               FROM payroll_run_exclusions exclusion
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = exclusion.supplier_id
                AND revision.id = exclusion.settled_revision_id
                AND revision.run_id = exclusion.run_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = exclusion.supplier_id
                AND employment.employee_id = exclusion.employee_id
              WHERE exclusion.supplier_id = ? AND exclusion.settled_revision_id = ?
                AND exclusion.lifted_at IS NULL
                AND (run.office_id IS NULL OR employment.office_id = run.office_id)
                AND COALESCE(employment.actual_start_date, employment.start_date) <= LAST_DAY(run.period_start)
                AND (employment.end_date IS NULL OR employment.end_date >= run.period_start)
              ORDER BY employment.id',
        );
        $statement->execute([$supplierId, $revisionId]);

        return array_values(array_map(static fn (array $row): array => [
            'run_id' => (int) $row['run_id'],
            'period_start' => (string) $row['period_start'],
            'employee_id' => (int) $row['employee_id'],
            'employment_id' => (int) $row['employment_id'],
            'office_id' => $row['office_id'] === null ? null : (int) $row['office_id'],
            'reason' => (string) $row['reason'],
            'excluded_by' => $row['excluded_by'] === null ? null : (int) $row['excluded_by'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string,mixed>>
     */
    private function many(string $sql, array $parameters): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException('Databáze vrátila neplatné odložení JMHZ.');
            }
            $rows[] = self::normalize($row);
        }

        return $rows;
    }

    /**
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $key = (string) $key;
            $normalized[$key] = match (true) {
                $value === null => null,
                in_array($key, [
                    'id', 'run_id', 'source_revision_id', 'office_id', 'employee_id',
                    'employment_id', 'decided_preparation_id', 'created_by', 'revoked_by',
                    'row_version', 'revision_no', 'deferral_id', 'regular_submission_id',
                    'correction_submission_id', 'binding_id',
                ], true) => (int) $value,
                default => $value,
            };
        }

        return $normalized;
    }
}
