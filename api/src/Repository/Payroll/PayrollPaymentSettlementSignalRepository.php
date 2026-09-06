<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Provizorní důkaz úhrady mzdového závazku — bankovní avízo nebo ruční
 * prohlášení účetní (migrace 1750).
 *
 * Signál NENÍ platba: nevstupuje do `settled_minor`, nezakládá protizápis
 * a nesnižuje saldo. Zhasne jen termín v hlídači, dokud nedorazí výpis.
 * Skutečnou úhradu drží dál výhradně `payroll_payment_matches`.
 */
final class PayrollPaymentSettlementSignalRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Živé signály (nevyřešené) k daným závazkům.
     *
     * @param list<int> $liabilityIds
     * @return array<int,array{
     *   id:int,liability_id:int,origin:string,bank_transaction_id:?int,
     *   paid_on:string,amount_minor:int,note:?string
     * }> klíčované liability_id
     */
    public function openForLiabilities(int $supplierId, array $liabilityIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $liabilityIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($supplierId <= 0 || $ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT id, liability_id, origin, bank_transaction_id,
                    paid_on, amount_minor, note
               FROM payroll_payment_settlement_signals
              WHERE supplier_id = ?
                AND resolved_at IS NULL
                AND liability_id IN (' . $placeholders . ')
              ORDER BY id ASC',
        );
        $statement->execute([$supplierId, ...$ids]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $liabilityId = (int) $row['liability_id'];
            $origin = (string) $row['origin'];
            // Ruční prohlášení má přednost před avízem: účetní ví víc než parser.
            if (isset($result[$liabilityId]) && $origin !== 'manual') {
                continue;
            }
            $result[$liabilityId] = [
                'id' => (int) $row['id'],
                'liability_id' => $liabilityId,
                'origin' => $origin,
                'bank_transaction_id' => $row['bank_transaction_id'] === null
                    ? null
                    : (int) $row['bank_transaction_id'],
                'paid_on' => (string) $row['paid_on'],
                'amount_minor' => (int) $row['amount_minor'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
            ];
        }

        return $result;
    }

    /**
     * Založí signál. Duplicitu (týž pohyb podruhé, druhé živé ruční prohlášení)
     * zachytí unikátní index a metoda vrátí `false` — opakované volání je
     * bezpečné. Ostatní chyby (cizí závazek, chybějící firma) propadnou dál,
     * ať se nekryje skutečná vada za „už tam bylo".
     */
    public function insert(
        int $supplierId,
        int $liabilityId,
        string $origin,
        ?int $bankTransactionId,
        string $paidOn,
        int $amountMinor,
        ?string $note,
        ?int $createdBy,
    ): bool {
        if ($supplierId <= 0 || $liabilityId <= 0 || $amountMinor <= 0) {
            throw new \InvalidArgumentException('Signál úhrady má neplatné identifikátory.');
        }
        if (!in_array($origin, ['bank_notice', 'manual'], true)) {
            throw new \InvalidArgumentException('Neznámý původ signálu úhrady.');
        }
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_settlement_signals
                (supplier_id, liability_id, origin, bank_transaction_id,
                 paid_on, amount_minor, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        try {
            $statement->execute([
                $supplierId,
                $liabilityId,
                $origin,
                $bankTransactionId,
                $paidOn,
                $amountMinor,
                $note,
                $createdBy,
            ]);
        } catch (\PDOException $exception) {
            if ($this->isDuplicateSignal($exception)) {
                return false;
            }
            throw $exception;
        }

        return true;
    }

    /** Zruší živé ruční prohlášení (účetní se spletla). Vrací, jestli něco zmizelo. */
    public function deleteManual(int $supplierId, int $liabilityId): bool
    {
        $statement = $this->db->pdo()->prepare(
            "DELETE FROM payroll_payment_settlement_signals
              WHERE supplier_id = ? AND liability_id = ?
                AND origin = 'manual' AND resolved_at IS NULL",
        );
        $statement->execute([$supplierId, $liabilityId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Uzavře signály závazku, který už má skutečnou úhradu. Nemaže je —
     * zůstávají jako auditní stopa, odkud se o platbě vědělo dřív.
     */
    public function resolveForLiability(
        int $supplierId,
        int $liabilityId,
        ?int $matchId,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_payment_settlement_signals
                SET resolved_at = CURRENT_TIMESTAMP,
                    resolved_match_id = COALESCE(resolved_match_id, ?)
              WHERE supplier_id = ? AND liability_id = ? AND resolved_at IS NULL',
        );
        $statement->execute([$matchId, $supplierId, $liabilityId]);

        return $statement->rowCount();
    }

    /**
     * Závazek pro ruční prohlášení o úhradě — jen to, co je k rozhodnutí potřeba.
     *
     * @return array{
     *   id:int,direction:string,amount_minor:int,settled_minor:int,
     *   due_on:string,period_start:string,currency_code:string
     * }|null
     */
    public function liabilityForDeclaration(int $supplierId, int $liabilityId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, liability.direction, liability.amount_minor,
                    liability.due_on, liability.currency_code,
                    run.period_start,
                    (SELECT COALESCE(SUM(payment_match.amount_minor), 0)
                       FROM payroll_payment_matches payment_match
                      WHERE payment_match.supplier_id = liability.supplier_id
                        AND payment_match.liability_id = liability.id
                    ) AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE liability.supplier_id = ? AND liability.id = ?',
        );
        $statement->execute([$supplierId, $liabilityId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'direction' => (string) $row['direction'],
            'amount_minor' => (int) $row['amount_minor'],
            'settled_minor' => (int) $row['settled_minor'],
            'due_on' => (string) $row['due_on'],
            'period_start' => (string) $row['period_start'],
            'currency_code' => (string) $row['currency_code'],
        ];
    }

    /** Je pohyb už použitý jako signál (u kteréhokoli závazku téhle firmy)? */
    public function isTransactionUsed(int $supplierId, int $bankTransactionId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                        SELECT 1
                          FROM payroll_payment_settlement_signals
                         WHERE supplier_id = ? AND bank_transaction_id = ?
                    )',
        );
        $statement->execute([$supplierId, $bankTransactionId]);

        return (bool) $statement->fetchColumn();
    }

    private function isDuplicateSignal(\PDOException $exception): bool
    {
        return $exception->getCode() === '23000'
            && str_contains(
                $exception->getMessage(),
                'uq_payroll_settlement_signal',
            );
    }
}
