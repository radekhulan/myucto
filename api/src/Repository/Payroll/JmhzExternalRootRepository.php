<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Řádná podání předchozího programu převzatá jako kořen opravného JMHZ
 * (migrace 2289).
 */
final class JmhzExternalRootRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $row */
    public function insert(array $row): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_external_roots
                (supplier_id, environment, submission_id, mode, submission_guid, period,
                 form_count, unmatched_form_count, original_sha256, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $row['supplier_id'],
            $row['environment'],
            $row['submission_id'],
            $row['mode'],
            $row['submission_guid'],
            $row['period'],
            $row['form_count'],
            $row['unmatched_form_count'],
            $row['original_sha256'],
            $row['note'],
            $row['created_by'],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function forSubmission(int $supplierId, string $environment, int $submissionId): ?array
    {
        $rows = $this->many(
            'WHERE root.supplier_id = ? AND root.environment = ? AND root.submission_id = ?',
            [$supplierId, $environment, $submissionId],
        );

        return $rows[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, string $environment): array
    {
        return $this->many(
            'WHERE root.supplier_id = ? AND root.environment = ? ORDER BY root.period DESC, root.id DESC',
            [$supplierId, $environment],
        );
    }

    /**
     * Řádné podání k povinnosti JMHZ (z MyÚčta i převzaté), bez ohledu na stav.
     *
     * @return array{id:int,status:string,channel:string}|null
     */
    public function regularForObligation(int $supplierId, string $environment, int $obligationId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, channel FROM payroll_submissions
              WHERE supplier_id = ? AND environment = ? AND regular_scope_id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $obligationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'channel' => (string) $row['channel']]
            : null;
    }

    /**
     * Povinnost řádného JMHZ za běh a registraci, i už splněná.
     *
     * @return array{id:int,status:string}|null
     */
    public function regularObligation(
        int $supplierId,
        string $environment,
        string $subjectReference,
        string $periodStart,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status FROM payroll_obligations
              WHERE supplier_id = ? AND environment = ? AND agenda_code = ?
                AND subject_reference = ? AND period_start = ? AND obligation_kind = \'regular\'
                AND status <> \'cancelled\'
              ORDER BY id DESC
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, 'JMHZ25', $subjectReference, $periodStart]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['id' => (int) $row['id'], 'status' => (string) $row['status']] : null;
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string,mixed>>
     */
    private function many(string $where, array $parameters): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT root.id, root.environment, root.submission_id, root.mode, root.submission_guid,
                    root.period, root.form_count, root.unmatched_form_count, root.original_sha256,
                    root.note, root.created_by, root.created_at, submission.status AS submission_status
               FROM payroll_jmhz_external_roots root
               JOIN payroll_submissions submission
                 ON submission.supplier_id = root.supplier_id
                AND submission.environment = root.environment
                AND submission.id = root.submission_id
              ' . $where,
        );
        $statement->execute($parameters);

        return array_values(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'environment' => (string) $row['environment'],
            'submission_id' => (int) $row['submission_id'],
            'mode' => (string) $row['mode'],
            'submission_guid' => (string) $row['submission_guid'],
            'period' => (string) $row['period'],
            'form_count' => (int) $row['form_count'],
            'unmatched_form_count' => (int) $row['unmatched_form_count'],
            'original_sha256' => $row['original_sha256'] === null ? null : (string) $row['original_sha256'],
            'note' => (string) $row['note'],
            'created_by' => (int) $row['created_by'],
            'created_at' => (string) $row['created_at'],
            'submission_status' => (string) $row['submission_status'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)));
    }
}
