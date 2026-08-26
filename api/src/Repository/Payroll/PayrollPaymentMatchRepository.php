<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPaymentMatchRepository
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
            $savepoint = 'payroll_payment_match_'
                . ++$this->savepointSequence;
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
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *   id:int,
     *   liability_id:int,
     *   channel:string,
     *   amount_minor:int,
     *   direction:string,
     *   currency_code:string,
     *   settled_minor:int
     * }|null
     */
    public function lockAllocation(
        int $supplierId,
        int $allocationId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT allocation.id, allocation.liability_id,
                    batch.channel, allocation.amount_minor,
                    liability.direction, liability.currency_code
               FROM payroll_payment_allocations allocation
               JOIN payroll_payment_items item
                 ON item.supplier_id = allocation.supplier_id
                AND item.id = allocation.item_id
               JOIN payroll_payment_batches batch
                 ON batch.supplier_id = item.supplier_id
                AND batch.id = item.batch_id
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
              WHERE allocation.supplier_id = ? AND allocation.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $allocationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::row($row, 'platební alokace');

        $settled = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0)
               FROM payroll_payment_matches
              WHERE supplier_id = ? AND allocation_id = ?',
        );
        $settled->execute([$supplierId, $allocationId]);

        return [
            'id' => self::integer($row, 'id'),
            'liability_id' => self::integer($row, 'liability_id'),
            'channel' => self::text($row, 'channel'),
            'amount_minor' => self::integer($row, 'amount_minor'),
            'direction' => self::text($row, 'direction'),
            'currency_code' => self::text($row, 'currency_code'),
            'settled_minor' => self::scalarInteger(
                $settled->fetchColumn(),
                'součet úhrad alokace',
            ),
        ];
    }

    /**
     * @return array{
     *   id:int,
     *   amount_minor:int,
     *   direction:string,
     *   currency_code:string,
     *   settled_minor:int
     * }|null
     */
    public function lockIncomingLiability(
        int $supplierId,
        int $liabilityId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, amount_minor, direction, currency_code
               FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $liabilityId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::row($row, 'příchozí mzdový závazek');

        $settled = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0)
               FROM payroll_payment_matches
              WHERE supplier_id = ? AND liability_id = ?
                AND allocation_id IS NULL',
        );
        $settled->execute([$supplierId, $liabilityId]);

        return [
            'id' => self::integer($row, 'id'),
            'amount_minor' => self::integer($row, 'amount_minor'),
            'direction' => self::text($row, 'direction'),
            'currency_code' => self::text($row, 'currency_code'),
            'settled_minor' => self::scalarInteger(
                $settled->fetchColumn(),
                'součet přijatých vratek závazku',
            ),
        ];
    }

    /**
     * @return array{
     *   id:int,
     *   allocation_id:?int,
     *   liability_id:int,
     *   event_kind:string,
     *   source_match_id:?int,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string,
     *   evidence_amount_minor:int,
     *   evidence_currency_code:string,
     *   evidence_fact_hash:string,
     *   reversed_minor:int
     * }|null
     */
    public function lockMatch(
        int $supplierId,
        int $matchId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, allocation_id, liability_id,
                    event_kind, source_match_id,
                    amount_minor, bank_statement_id, bank_transaction_id,
                    cash_document_id, actual_payment_date,
                    evidence_amount_minor, evidence_currency_code,
                    evidence_fact_hash
               FROM payroll_payment_matches
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $matchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $reversed = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0)
               FROM payroll_payment_matches
              WHERE supplier_id = ? AND source_match_id = ?
                AND event_kind = "reversed"',
        );
        $reversed->execute([$supplierId, $matchId]);

        return $this->matchRow(
            $row,
            self::scalarInteger(
                $reversed->fetchColumn(),
                'součet reverzí platby',
            ),
        );
    }

    /**
     * @return array{
     *   id:int,
     *   allocation_id:?int,
     *   liability_id:int,
     *   event_kind:string,
     *   source_match_id:?int,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string,
     *   evidence_amount_minor:int,
     *   evidence_currency_code:string,
     *   evidence_fact_hash:string,
     *   reversed_minor:int
     * }|null
     */
    public function findByIdempotency(
        int $supplierId,
        string $idempotencyKeyHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, allocation_id, liability_id,
                    event_kind, source_match_id,
                    amount_minor, bank_statement_id, bank_transaction_id,
                    cash_document_id, actual_payment_date,
                    evidence_amount_minor, evidence_currency_code,
                    evidence_fact_hash
              FROM payroll_payment_matches
              WHERE supplier_id = ? AND idempotency_key_hash = ?',
        );
        $statement->execute([$supplierId, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->matchRow($row, 0);
    }

    /**
     * @return array{
     *   posted_at:string,
     *   amount_decimal:string,
     *   currency_code:string,
     *   match_status:string,
     *   matched_invoice_id:?int
     * }|null
     */
    public function lockBankEvidence(
        int $supplierId,
        int $bankStatementId,
        int $bankTransactionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT bank_tx.posted_at,
                    CAST(bank_tx.amount AS CHAR) AS amount_decimal,
                    COALESCE(bank_tx.currency, statement.currency)
                        AS currency_code,
                    bank_tx.match_status, bank_tx.matched_invoice_id
               FROM bank_statements statement
               JOIN bank_transactions bank_tx
                 ON bank_tx.statement_id = statement.id
                AND bank_tx.id = ?
              WHERE statement.supplier_id = ? AND statement.id = ?
              FOR UPDATE',
        );
        $statement->execute([
            $bankTransactionId,
            $supplierId,
            $bankStatementId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::row($row, 'bankovní důkaz');

        return [
            'posted_at' => self::text($row, 'posted_at'),
            'amount_decimal' => self::text($row, 'amount_decimal'),
            'currency_code' => self::text($row, 'currency_code'),
            'match_status' => self::text($row, 'match_status'),
            'matched_invoice_id' => self::nullableInteger(
                $row,
                'matched_invoice_id',
            ),
        ];
    }

    /**
     * @return array{invoice_payments:int,payment_matches:int,foreign_payroll:int}
     */
    public function bankEvidenceOwnership(
        int $supplierId,
        int $bankTransactionId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM invoice_payments
                  WHERE bank_transaction_id = ?) AS invoice_payments,
                (SELECT COUNT(*) FROM payment_matches
                  WHERE bank_transaction_id = ?) AS payment_matches,
                (SELECT COUNT(*) FROM payroll_payment_matches
                  WHERE bank_transaction_id = ? AND supplier_id <> ?)
                    AS foreign_payroll',
        );
        $statement->execute([
            $bankTransactionId,
            $bankTransactionId,
            $bankTransactionId,
            $supplierId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $row = self::row($row, 'vlastnictví bankovního důkazu');

        return [
            'invoice_payments' => self::integer(
                $row,
                'invoice_payments',
            ),
            'payment_matches' => self::integer(
                $row,
                'payment_matches',
            ),
            'foreign_payroll' => self::integer(
                $row,
                'foreign_payroll',
            ),
        ];
    }

    /**
     * @return array{
     *   issue_date:string,
     *   amount_decimal:string,
     *   currency_code:string,
     *   doc_type:string,
     *   purpose:string,
     *   status:string,
     *   invoice_id:?int,
     *   purchase_invoice_id:?int,
     *   invoice_payment_id:?int
     * }|null
     */
    public function lockCashEvidence(
        int $supplierId,
        int $cashDocumentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT issue_date, CAST(total_amount AS CHAR) AS amount_decimal,
                    currency_code, doc_type, purpose, status, invoice_id,
                    purchase_invoice_id, invoice_payment_id
               FROM cash_documents
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $cashDocumentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::row($row, 'pokladní důkaz');

        return [
            'issue_date' => self::text($row, 'issue_date'),
            'amount_decimal' => self::text($row, 'amount_decimal'),
            'currency_code' => self::text($row, 'currency_code'),
            'doc_type' => self::text($row, 'doc_type'),
            'purpose' => self::text($row, 'purpose'),
            'status' => self::text($row, 'status'),
            'invoice_id' => self::nullableInteger($row, 'invoice_id'),
            'purchase_invoice_id' => self::nullableInteger(
                $row,
                'purchase_invoice_id',
            ),
            'invoice_payment_id' => self::nullableInteger(
                $row,
                'invoice_payment_id',
            ),
        ];
    }

    public function evidenceUsedMinor(
        int $supplierId,
        string $eventKind,
        ?int $bankStatementId,
        ?int $bankTransactionId,
        ?int $cashDocumentId,
    ): int {
        if ($bankTransactionId !== null && $bankStatementId !== null) {
            $statement = $this->db->pdo()->prepare(
                'SELECT COALESCE(SUM(ABS(amount_minor)), 0)
                   FROM payroll_payment_matches
                  WHERE supplier_id = ? AND event_kind = ?
                    AND bank_statement_id = ? AND bank_transaction_id = ?',
            );
            $statement->execute([
                $supplierId,
                $eventKind,
                $bankStatementId,
                $bankTransactionId,
            ]);
        } elseif ($cashDocumentId !== null) {
            $statement = $this->db->pdo()->prepare(
                'SELECT COALESCE(SUM(ABS(amount_minor)), 0)
                   FROM payroll_payment_matches
                  WHERE supplier_id = ? AND event_kind = ?
                    AND cash_document_id = ?',
            );
            $statement->execute([
                $supplierId,
                $eventKind,
                $cashDocumentId,
            ]);
        } else {
            throw new \InvalidArgumentException(
                'Platební důkaz nemá úplnou referenci.',
            );
        }

        return self::scalarInteger(
            $statement->fetchColumn(),
            'využití platebního důkazu',
        );
    }

    public function insert(
        int $supplierId,
        ?int $allocationId,
        int $liabilityId,
        string $eventKind,
        ?int $sourceMatchId,
        int $amountMinor,
        ?int $bankStatementId,
        ?int $bankTransactionId,
        ?int $cashDocumentId,
        string $idempotencyKeyHash,
        ?int $matchedBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, liability_id,
                 event_kind, source_match_id,
                 amount_minor, bank_statement_id, bank_transaction_id,
                 cash_document_id, idempotency_key_hash, matched_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $allocationId,
            $liabilityId,
            $eventKind,
            $sourceMatchId,
            $amountMinor,
            $bankStatementId,
            $bankTransactionId,
            $cashDocumentId,
            $idempotencyKeyHash,
            $matchedBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array{
     *   id:int,
     *   allocation_id:?int,
     *   liability_id:int,
     *   event_kind:string,
     *   source_match_id:?int,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string,
     *   evidence_amount_minor:int,
     *   evidence_currency_code:string,
     *   evidence_fact_hash:string,
     *   reversed_minor:int
     * }
     */
    private function matchRow(mixed $value, int $reversedMinor): array
    {
        $row = self::row($value, 'spárování platby');

        return [
            'id' => self::integer($row, 'id'),
            'allocation_id' => self::nullableInteger(
                $row,
                'allocation_id',
            ),
            'liability_id' => self::integer($row, 'liability_id'),
            'event_kind' => self::text($row, 'event_kind'),
            'source_match_id' => self::nullableInteger(
                $row,
                'source_match_id',
            ),
            'amount_minor' => self::integer($row, 'amount_minor'),
            'bank_statement_id' => self::nullableInteger(
                $row,
                'bank_statement_id',
            ),
            'bank_transaction_id' => self::nullableInteger(
                $row,
                'bank_transaction_id',
            ),
            'cash_document_id' => self::nullableInteger(
                $row,
                'cash_document_id',
            ),
            'actual_payment_date' => self::text(
                $row,
                'actual_payment_date',
            ),
            'evidence_amount_minor' => self::integer(
                $row,
                'evidence_amount_minor',
            ),
            'evidence_currency_code' => self::text(
                $row,
                'evidence_currency_code',
            ),
            'evidence_fact_hash' => self::hash(
                $row,
                'evidence_fact_hash',
            ),
            'reversed_minor' => $reversedMinor,
        ];
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $context): array
    {
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

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        return self::scalarInteger(
            $row[$field] ?? null,
            "hodnota {$field}",
        );
    }

    private static function scalarInteger(mixed $value, string $context): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová {$context} není celé číslo.",
            );
        }
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($result)) {
            throw new \UnexpectedValueException(
                "Databázová {$context} není celé číslo.",
            );
        }

        return $result;
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
    private static function text(array $row, string $field): string
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
        $value = self::text($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }
}
