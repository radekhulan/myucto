<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPaymentLiabilityRepository
{
    private int $savepointSequence = 0;

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
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_payment_' . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *   run_id:int,
     *   revision_no:int,
     *   previous_revision_id:?int,
     *   revision_kind:string,
     *   revision_status:string,
     *   schema_version:string,
     *   current_revision_no:int,
     *   period_start:string,
     *   payment_date:string,
     *   input_snapshot_json:string,
     *   input_snapshot_hash:string,
     *   result_snapshot_json:string,
     *   result_snapshot_hash:string
     * }|null
     */
    public function lockRevision(
        int $supplierId,
        int $revisionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.run_id, revision.revision_no,
                    revision.previous_revision_id, revision.revision_kind,
                    revision.status AS revision_status,
                    revision.schema_version, revision.input_snapshot_json,
                    revision.input_snapshot_hash,
                    revision.result_snapshot_json,
                    revision.result_snapshot_hash,
                    run.current_revision_no, run.period_start, run.payment_date
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ? AND revision.id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow(
            $row,
            'kontext platebních závazků',
        );

        return [
            'run_id' => self::integer($row, 'run_id'),
            'revision_no' => self::integer($row, 'revision_no'),
            'previous_revision_id' => self::nullableInteger(
                $row,
                'previous_revision_id',
            ),
            'revision_kind' => self::string($row, 'revision_kind'),
            'revision_status' => self::string($row, 'revision_status'),
            'schema_version' => self::string($row, 'schema_version'),
            'current_revision_no' => self::integer(
                $row,
                'current_revision_no',
            ),
            'period_start' => self::string($row, 'period_start'),
            'payment_date' => self::string($row, 'payment_date'),
            'input_snapshot_json' => self::string(
                $row,
                'input_snapshot_json',
            ),
            'input_snapshot_hash' => self::hash(
                $row,
                'input_snapshot_hash',
            ),
            'result_snapshot_json' => self::string(
                $row,
                'result_snapshot_json',
            ),
            'result_snapshot_hash' => self::hash(
                $row,
                'result_snapshot_hash',
            ),
        ];
    }

    /**
     * @return list<array{
     *   employee_id:int,
     *   status:string,
     *   result_json:string,
     *   result_hash:string
     * }>
     */
    public function lockPersonResults(
        int $supplierId,
        int $revisionId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id, status, result_json, result_hash
               FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ?
              ORDER BY employee_id
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $revisionId]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::associativeRow($row, 'výsledek osoby');
            $result[] = [
                'employee_id' => self::integer($row, 'employee_id'),
                'status' => self::string($row, 'status'),
                'result_json' => self::string($row, 'result_json'),
                'result_hash' => self::hash($row, 'result_hash'),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *   id:int,
     *   revision_no:int,
     *   employee_id:int,
     *   liability_reference:string,
     *   direction:string,
     *   recipient_reference:string,
     *   amount_minor:int
     * }>
     */
    public function lockEarlierNetWageLiabilities(
        int $supplierId,
        int $runId,
        int $revisionNo,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, revision.revision_no,
                    liability.employee_id, liability.liability_reference,
                    liability.direction, liability.recipient_reference,
                    liability.amount_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
              WHERE liability.supplier_id = ?
                AND revision.run_id = ?
                AND revision.revision_no < ?
                AND liability.liability_kind = "net_wage"
              ORDER BY revision.revision_no, liability.id
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $runId, $revisionNo]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::associativeRow($row, 'dřívější závazek');
            $employeeId = self::nullableInteger($row, 'employee_id');
            if ($employeeId === null) {
                throw new \UnexpectedValueException(
                    'Závazek čisté mzdy nemá zaměstnance.',
                );
            }
            $result[] = [
                'id' => self::integer($row, 'id'),
                'revision_no' => self::integer($row, 'revision_no'),
                'employee_id' => $employeeId,
                'liability_reference' => self::string(
                    $row,
                    'liability_reference',
                ),
                'direction' => self::string($row, 'direction'),
                'recipient_reference' => self::string(
                    $row,
                    'recipient_reference',
                ),
                'amount_minor' => self::integer($row, 'amount_minor'),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *   id:int,
     *   revision_no:int,
     *   liability_reference:string,
     *   direction:string,
     *   recipient_reference:string,
     *   amount_minor:int,
     *   source_snapshot_json:string,
     *   source_snapshot_hash:string
     * }>
     */
    public function lockEarlierInstitutionalLiabilities(
        int $supplierId,
        int $runId,
        int $revisionNo,
        string $liabilityKind,
    ): array {
        if (!in_array($liabilityKind, [
            'social_insurance',
            'health_insurance',
            'advance_tax',
            'withholding_tax',
            'statutory_insurance',
            'enforcement',
            'risky_savings',
        ], true)) {
            throw new \InvalidArgumentException(
                'Druh institucionálního závazku není podporovaný.',
            );
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, revision.revision_no,
                    liability.liability_reference, liability.direction,
                    liability.recipient_reference, liability.amount_minor,
                    liability.source_snapshot_json,
                    liability.source_snapshot_hash
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
              WHERE liability.supplier_id = ?
                AND revision.run_id = ?
                AND revision.revision_no < ?
                AND liability.liability_kind = ?
              ORDER BY revision.revision_no, liability.id
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $runId,
            $revisionNo,
            $liabilityKind,
        ]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::associativeRow(
                $row,
                'dřívější institucionální závazek',
            );
            $result[] = [
                'id' => self::integer($row, 'id'),
                'revision_no' => self::integer($row, 'revision_no'),
                'liability_reference' => self::string(
                    $row,
                    'liability_reference',
                ),
                'direction' => self::string($row, 'direction'),
                'recipient_reference' => self::string(
                    $row,
                    'recipient_reference',
                ),
                'amount_minor' => self::integer($row, 'amount_minor'),
                'source_snapshot_json' => self::string(
                    $row,
                    'source_snapshot_json',
                ),
                'source_snapshot_hash' => self::hash(
                    $row,
                    'source_snapshot_hash',
                ),
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *   id:int,
     *   employee_id:int,
     *   direction:string,
     *   recipient_reference:string,
     *   due_on:string,
     *   amount_minor:int,
     *   previous_liability_id:?int,
     *   source_snapshot_hash:string,
     *   idempotency_key_hash:string
     * }|null
     */
    public function findForUpdate(
        int $supplierId,
        int $revisionId,
        string $liabilityReference,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, direction, recipient_reference, due_on,
                    amount_minor, previous_liability_id,
                    source_snapshot_hash, idempotency_key_hash
               FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND revision_id = ?
                AND liability_reference = ?
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $revisionId,
            $liabilityReference,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'závazek čisté mzdy');
        $employeeId = self::nullableInteger($row, 'employee_id');
        if ($employeeId === null) {
            throw new \UnexpectedValueException(
                'Závazek čisté mzdy nemá zaměstnance.',
            );
        }
        $idempotencyHash = $row['idempotency_key_hash'] ?? null;
        if (!is_string($idempotencyHash) || strlen($idempotencyHash) !== 32) {
            throw new \UnexpectedValueException(
                'Závazek čisté mzdy nemá platný idempotentní otisk.',
            );
        }

        return [
            'id' => self::integer($row, 'id'),
            'employee_id' => $employeeId,
            'direction' => self::string($row, 'direction'),
            'recipient_reference' => self::string(
                $row,
                'recipient_reference',
            ),
            'due_on' => self::string($row, 'due_on'),
            'amount_minor' => self::integer($row, 'amount_minor'),
            'previous_liability_id' => self::nullableInteger(
                $row,
                'previous_liability_id',
            ),
            'source_snapshot_hash' => self::hash(
                $row,
                'source_snapshot_hash',
            ),
            'idempotency_key_hash' => $idempotencyHash,
        ];
    }

    /**
     * @return array{
     *   id:int,
     *   employee_id:?int,
     *   liability_kind:string,
     *   direction:string,
     *   recipient_reference:string,
     *   due_on:string,
     *   amount_minor:int,
     *   previous_liability_id:?int,
     *   source_snapshot_hash:string,
     *   idempotency_key_hash:string
     * }|null
     */
    public function findAnyForUpdate(
        int $supplierId,
        int $revisionId,
        string $liabilityReference,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, liability_kind, direction,
                    recipient_reference, due_on, amount_minor,
                    previous_liability_id, source_snapshot_hash,
                    idempotency_key_hash
               FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND revision_id = ?
                AND liability_reference = ?
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $revisionId,
            $liabilityReference,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'platební závazek');
        $idempotencyHash = $row['idempotency_key_hash'] ?? null;
        if (!is_string($idempotencyHash) || strlen($idempotencyHash) !== 32) {
            throw new \UnexpectedValueException(
                'Platební závazek nemá platný idempotentní otisk.',
            );
        }

        return [
            'id' => self::integer($row, 'id'),
            'employee_id' => self::nullableInteger($row, 'employee_id'),
            'liability_kind' => self::string($row, 'liability_kind'),
            'direction' => self::string($row, 'direction'),
            'recipient_reference' => self::string(
                $row,
                'recipient_reference',
            ),
            'due_on' => self::string($row, 'due_on'),
            'amount_minor' => self::integer($row, 'amount_minor'),
            'previous_liability_id' => self::nullableInteger(
                $row,
                'previous_liability_id',
            ),
            'source_snapshot_hash' => self::hash(
                $row,
                'source_snapshot_hash',
            ),
            'idempotency_key_hash' => $idempotencyHash,
        ];
    }

    public function insert(
        int $supplierId,
        int $revisionId,
        int $employeeId,
        string $liabilityReference,
        string $direction,
        string $recipientReference,
        string $dueOn,
        int $amountMinor,
        ?int $previousLiabilityId,
        string $sourceSnapshotJson,
        string $sourceSnapshotHash,
        string $idempotencyKeyHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id,
                 liability_reference, liability_kind, direction,
                 recipient_reference, due_on, currency_code, amount_minor,
                 previous_liability_id, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, "net_wage", ?, ?, ?, "CZK", ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $liabilityReference,
            $direction,
            $recipientReference,
            $dueOn,
            $amountMinor,
            $previousLiabilityId,
            $sourceSnapshotJson,
            $sourceSnapshotHash,
            $idempotencyKeyHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertInstitutional(
        int $supplierId,
        int $revisionId,
        string $liabilityReference,
        string $liabilityKind,
        string $direction,
        string $recipientReference,
        string $dueOn,
        int $amountMinor,
        ?int $previousLiabilityId,
        string $sourceSnapshotJson,
        string $sourceSnapshotHash,
        string $idempotencyKeyHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id,
                 liability_reference, liability_kind, direction,
                 recipient_reference, due_on, currency_code, amount_minor,
                 previous_liability_id, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash, created_by)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, "CZK", ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $revisionId,
            $liabilityReference,
            $liabilityKind,
            $direction,
            $recipientReference,
            $dueOn,
            $amountMinor,
            $previousLiabilityId,
            $sourceSnapshotJson,
            $sourceSnapshotHash,
            $idempotencyKeyHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row */
    private static function nullableInteger(
        array $row,
        string $field,
    ): ?int {
        return ($row[$field] ?? null) === null
            ? null
            : self::integer($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::string($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function associativeRow(
        mixed $value,
        string $context,
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatný {$context}.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databázový {$context} nemá textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
