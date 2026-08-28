<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class EldpManualCompletionRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @template T @param callable():T $callback @return T */
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
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function lockSupplier(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function contextForUpdate(int $supplierId, string $environment, int $statementId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT statement.id AS statement_id,
                    obligation.id AS obligation_id,
                    obligation.status AS obligation_status,
                    obligation.row_version AS obligation_row_version,
                    submission.id AS submission_id,
                    submission.status AS local_submission_status
               FROM payroll_eldp_statements statement
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = statement.supplier_id
                AND obligation.environment = statement.environment
                AND obligation.agenda_code = "ELDP"
                AND obligation.source_event_type = "eldp_statement"
                AND obligation.source_event_reference = CONCAT("eldp_statement:", statement.id)
               JOIN payroll_submissions submission
                 ON submission.supplier_id = obligation.supplier_id
                AND submission.environment = obligation.environment
                AND submission.obligation_id = obligation.id
                AND submission.submission_kind = "regular"
              WHERE statement.supplier_id = ?
                AND statement.environment = ?
                AND statement.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $environment, $statementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrateContext($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function context(int $supplierId, string $environment, int $statementId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT statement.id AS statement_id,
                    obligation.id AS obligation_id,
                    obligation.status AS obligation_status,
                    obligation.row_version AS obligation_row_version,
                    submission.id AS submission_id,
                    submission.status AS local_submission_status
               FROM payroll_eldp_statements statement
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = statement.supplier_id
                AND obligation.environment = statement.environment
                AND obligation.agenda_code = "ELDP"
                AND obligation.source_event_type = "eldp_statement"
                AND obligation.source_event_reference = CONCAT("eldp_statement:", statement.id)
               JOIN payroll_submissions submission
                 ON submission.supplier_id = obligation.supplier_id
                AND submission.environment = obligation.environment
                AND submission.obligation_id = obligation.id
                AND submission.submission_kind = "regular"
              WHERE statement.supplier_id = ? AND statement.environment = ? AND statement.id = ?'
        );
        $stmt->execute([$supplierId, $environment, $statementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrateContext($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function byIdempotencyForUpdate(int $supplierId, string $environment, string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_eldp_manual_completions
              WHERE supplier_id = ? AND environment = ? AND idempotency_key_hash = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $environment, $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::completion($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function bySlotForUpdate(int $supplierId, string $environment, int $statementId, string $status): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_eldp_manual_completions
              WHERE supplier_id = ? AND environment = ? AND statement_id = ?
                AND authority_status = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $environment, $statementId, $status]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::completion($row) : null;
    }

    /** @param array<string,mixed> $row */
    public function insert(array $row): int
    {
        $columns = array_keys($row);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_eldp_manual_completions (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($row));
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateObligationStatus(int $supplierId, string $environment, int $obligationId, int $expectedVersion, string $status): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_obligations SET status = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND environment = ? AND id = ? AND row_version = ?'
        );
        $stmt->execute([$status, $supplierId, $environment, $obligationId, $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException('Povinnost ELDP se mezitím změnila.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(int $supplierId, string $environment, int $statementId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_eldp_manual_completions
              WHERE supplier_id = ? AND environment = ? AND statement_id = ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $environment, $statementId]);
        return array_map(self::completion(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateContext(array $row): array
    {
        foreach (['statement_id', 'obligation_id', 'obligation_row_version', 'submission_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function completion(array $row): array
    {
        foreach (['id', 'supplier_id', 'statement_id', 'obligation_id', 'confirmation_document_id', 'confirmation_byte_size', 'obligation_row_version_before', 'recorded_by'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        return $row;
    }
}
