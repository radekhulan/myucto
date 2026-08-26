<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class EldpStatementRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($owns) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function lockSupplier(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE'
        );
        $statement->execute([$supplierId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Zmrazené schválené revize roku pro daný pracovní vztah.
     *
     * Vrací i revize, které pracovní vztah neobsahují — o tom, které měsíce
     * jsou pro evidenční list povinné, rozhoduje builder ze zmrazených dat,
     * ne dotaz.
     *
     * @return list<array<string,mixed>>
     */
    public function revisionsForYear(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id, revision.run_id, revision.revision_no,
                    revision.revision_kind, revision.status,
                    revision.input_snapshot_json, revision.input_snapshot_hash,
                    revision.result_snapshot_json, revision.result_snapshot_hash,
                    run.period_start, run.current_revision_no
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND YEAR(run.period_start) = ?
                AND revision.status = \'approved\'
                AND revision.revision_kind IN (\'regular\', \'correction\')
                AND revision.revision_no = run.current_revision_no
              ORDER BY run.period_start, revision.id'
        );
        $statement->execute([$supplierId, $year]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['id', 'run_id', 'revision_no', 'current_revision_no'] as $field) {
                $row[$field] = (int) $row[$field];
            }
        }
        unset($row);

        /** @var list<array<string,mixed>> $rows */
        return $rows;
    }

    public function insertClaim(
        int $supplierId,
        string $environment,
        string $idempotencyHash,
        int $employmentId,
        int $year,
        string $confirmationFingerprint,
        int $createdBy,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_eldp_statement_claims
                (supplier_id, environment, idempotency_key_hash, employment_id,
                 statement_year, confirmation_fingerprint, created_by)
             SELECT ?, ?, ?, ?, ?, ?, ?
              WHERE NOT EXISTS (
                SELECT 1 FROM payroll_eldp_statement_claims existing
                 WHERE existing.supplier_id = ?
                   AND existing.environment = ?
                   AND existing.idempotency_key_hash = ?
              )'
        );
        $statement->execute([
            $supplierId, $environment, $idempotencyHash, $employmentId,
            $year, $confirmationFingerprint, $createdBy,
            $supplierId, $environment, $idempotencyHash,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    public function findClaimForUpdate(
        int $supplierId,
        string $environment,
        string $idempotencyHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment_id, statement_year, confirmation_fingerprint,
                    statement_id
               FROM payroll_eldp_statement_claims
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $environment, $idempotencyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['employment_id'] = (int) $row['employment_id'];
        $row['statement_year'] = (int) $row['statement_year'];
        $row['statement_id'] = $row['statement_id'] === null
            ? null
            : (int) $row['statement_id'];

        return $row;
    }

    public function bindClaim(
        int $supplierId,
        string $environment,
        string $idempotencyHash,
        int $statementId,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_eldp_statement_claims
                SET statement_id = ?
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
                AND statement_id IS NULL'
        );
        $statement->execute([
            $statementId, $supplierId, $environment, $idempotencyHash,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByScopeForUpdate(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $year,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_eldp_statements
              WHERE supplier_id = ? AND environment = ?
                AND employment_id = ? AND statement_year = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $environment, $employmentId, $year]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $environment, int $id): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_eldp_statements
              WHERE supplier_id = ? AND environment = ? AND id = ?'
        );
        $statement->execute([$supplierId, $environment, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * @param array<string,mixed> $data
     * @param list<array{period_start:string,revision_id:int,run_id:int,input_snapshot_hash:string,result_snapshot_hash:string}> $sources
     */
    public function insert(array $data, array $sources): int
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_eldp_statements (' . implode(', ', $columns) . ')'
                . ' VALUES (' . $placeholders . ')'
        );
        $statement->execute(array_values($data));
        $id = (int) $this->db->pdo()->lastInsertId();

        $sourceStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_eldp_statement_sources
                (supplier_id, environment, statement_id, period_start,
                 source_revision_id, run_id, input_snapshot_hash,
                 result_snapshot_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($sources as $source) {
            $sourceStatement->execute([
                $data['supplier_id'],
                $data['environment'],
                $id,
                $source['period_start'],
                $source['revision_id'],
                $source['run_id'],
                $source['input_snapshot_hash'],
                $source['result_snapshot_hash'],
            ]);
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'employee_id', 'employment_id',
            'statement_year', 'section_count', 'insurance_days',
            'excluded_days_total', 'deducted_days_total', 'created_by',
        ] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }

        return $row;
    }
}
