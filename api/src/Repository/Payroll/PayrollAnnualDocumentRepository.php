<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class PayrollAnnualDocumentRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function lockApprovedYearSources(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $periodFrom = sprintf('%04d-01-01', $taxYear);
        $periodUntil = sprintf('%04d-01-01', $taxYear + 1);
        $statement = $this->db->pdo()->prepare(
            'SELECT run.id AS run_id,
                    person.period_start,
                    run.payment_date,
                    revision.id AS revision_id,
                    revision.input_snapshot_json,
                    revision.input_snapshot_hash,
                    revision.result_snapshot_json,
                    revision.result_snapshot_hash,
                    person.result_json AS person_result_json,
                    person.result_hash AS person_result_hash
               FROM payroll_run_persons person
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = person.supplier_id
                AND revision.id = person.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE person.supplier_id = ?
                AND person.employee_id = ?
                AND person.period_start >= ?
                AND person.period_start < ?
                AND person.status = "calculated"
                AND person.result_json IS NOT NULL
                AND person.result_hash IS NOT NULL
                AND revision.status IN ("approved", "superseded")
                AND revision.result_snapshot_json IS NOT NULL
                AND revision.result_snapshot_hash IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions newer
                     WHERE newer.supplier_id = revision.supplier_id
                       AND newer.run_id = revision.run_id
                       AND newer.revision_no > revision.revision_no
                       AND newer.status IN ("approved", "superseded")
                       AND newer.result_snapshot_hash IS NOT NULL
                )
              ORDER BY person.period_start, run.id
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId, $periodFrom, $periodUntil]);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $annualRevisionId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_document_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $annualRevisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function findBySourceManifest(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $purpose,
        string $sourceManifestHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_document_revisions
              WHERE supplier_id = ?
                AND employee_id = ?
                AND tax_year = ?
                AND purpose = ?
                AND source_manifest_hash = ?
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $taxYear,
            $purpose,
            $sourceManifestHash,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function latest(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $purpose,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_document_revisions
              WHERE supplier_id = ?
                AND employee_id = ?
                AND tax_year = ?
                AND purpose = ?
              ORDER BY revision_no DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId, $taxYear, $purpose]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @param array<string,mixed> $record
     * @param list<array<string,mixed>> $sources
     * @return array<string,mixed>
     */
    public function insertApproved(array $record, array $sources): array
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 previous_revision_id, snapshot_ciphertext, snapshot_hash,
                 source_manifest_json, source_manifest_hash, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        try {
            $statement->execute([
                $record['supplier_id'],
                $record['employee_id'],
                $record['tax_year'],
                $record['purpose'],
                $record['revision_no'],
                $record['previous_revision_id'],
                $record['snapshot_ciphertext'],
                $record['snapshot_hash'],
                $record['source_manifest_json'],
                $record['source_manifest_hash'],
                $record['approved_by'],
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $existing = $this->findBySourceManifest(
                (int) $record['supplier_id'],
                (int) $record['employee_id'],
                (int) $record['tax_year'],
                (string) $record['purpose'],
                (string) $record['source_manifest_hash'],
            );
            if ($existing === null
                || !hash_equals((string) $existing['snapshot_hash'], (string) $record['snapshot_hash'])
            ) {
                throw new \RuntimeException(
                    'Roční zdrojový snapshot koliduje s jinou revizí.',
                    previous: $exception,
                );
            }
            return $existing;
        }

        $annualRevisionId = (int) $this->db->pdo()->lastInsertId();
        $sourceStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_document_sources
                (supplier_id, annual_revision_id, run_revision_id, employee_id,
                 period_start, person_result_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($sources as $source) {
            $sourceStatement->execute([
                $record['supplier_id'],
                $annualRevisionId,
                $source['revision_id'],
                $record['employee_id'],
                $source['period_start'],
                $source['person_result_hash'],
            ]);
        }

        return $this->find((int) $record['supplier_id'], $annualRevisionId)
            ?? throw new \RuntimeException('Roční zdrojový snapshot nelze načíst.');
    }

    /** @return list<array<string,mixed>> */
    public function listForYear(int $supplierId, int $taxYear): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id,
                    revision.employee_id,
                    employee.full_name AS employee_name,
                    revision.tax_year,
                    revision.purpose,
                    revision.revision_no,
                    revision.snapshot_hash,
                    revision.approved_at
               FROM payroll_annual_document_revisions revision
               JOIN payroll_employees employee
                 ON employee.supplier_id = revision.supplier_id
                AND employee.id = revision.employee_id
              WHERE revision.supplier_id = ? AND revision.tax_year = ?
              ORDER BY employee.full_name, revision.purpose, revision.revision_no DESC'
        );
        $statement->execute([$supplierId, $taxYear]);
        return array_values(array_map(
            self::cast(...),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'employee_id',
            'tax_year',
            'revision_no',
            'previous_revision_id',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        return $row;
    }
}
