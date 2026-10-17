<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

/** Avíza a jejich cíle sdílejí zámek importu; ostatní vazby chrání řádkový zámek pohybu. */
final class BankNoticeMutationGuard
{
    private static int $savepointSequence = 0;

    public function __construct(private readonly Connection|PDO $db) {}

    /**
     * @template T
     * @param callable():T $mutation
     * @return T
     */
    public function mutate(int $supplierId, ?int $transactionId, callable $mutation): mixed
    {
        if ($transactionId === null || $transactionId <= 0) {
            return $mutation();
        }
        if ($supplierId <= 0) throw new BankNoticeConfirmationException('unknown_supplier', 'Chybí firma bankovního pohybu.');
        $pdo = $this->db instanceof Connection ? $this->db->pdo() : $this->db;
        $statement = $pdo->prepare('SELECT source FROM bank_transactions WHERE id = ?');
        $statement->execute([$transactionId]);
        $source = $statement->fetchColumn();
        if ($source === false) throw new BankNoticeConfirmationException('transaction_not_found', 'Bankovní pohyb nebyl nalezen.', 404);
        $statement = $pdo->prepare('SELECT 1 FROM bank_notice_confirmations WHERE notice_transaction_id = ? OR authoritative_transaction_id = ? LIMIT 1');
        $statement->execute([$transactionId, $transactionId]);
        $related = $statement->fetchColumn() !== false;
        $lock = null;
        if ($source === 'email_notice' || $related) {
            $lock = NamedLockName::compose('bank-import', (string) $pdo->query('SELECT DATABASE()')->fetchColumn(), $supplierId);
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 30)');
            $statement->execute([$lock]);
            if ((int) $statement->fetchColumn() !== 1) throw new BankNoticeConfirmationException('bank_busy', 'Probíhá jiná změna bankovních pohybů. Opakujte akci později.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'bank_notice_mutation_' . ++self::$savepointSequence;
        try {
            if ($ownsTransaction) $pdo->beginTransaction();
            else $pdo->exec('SAVEPOINT ' . $savepoint);
            // Stejné pořadí jako BankTransactionContext: období před pohybem.
            // PDO varianta je nízkoúrovňový PurchasePaymentMatchWriter; účetní
            // kontext tam zajišťuje nadřazená párovací služba.
            if ($this->db instanceof Connection) (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            // Zůstává zamčené i po návratu do transakce volajícího.
            $statement = $pdo->prepare('SELECT id, source FROM bank_transactions WHERE id = ? FOR UPDATE');
            $statement->execute([$transactionId]);
            $transaction = $statement->fetch(PDO::FETCH_ASSOC);
            if ($transaction === false) throw new BankNoticeConfirmationException('transaction_not_found', 'Bankovní pohyb nebyl nalezen.', 404);
            // Locking read obejde i starší snapshot volajícího. Novou vazbu nelze
            // řešit čekáním na named lock pod row lockem: import je bere opačně.
            $statement = $pdo->prepare('SELECT notice_transaction_id, state, reason FROM bank_notice_confirmations WHERE notice_transaction_id = ? OR authoritative_transaction_id = ? FOR UPDATE');
            $statement->execute([$transactionId, $transactionId]);
            $confirmations = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($lock === null && ($transaction['source'] === 'email_notice' || $confirmations !== [])) {
                throw new BankNoticeConfirmationException('bank_busy', 'Stav bankovního pohybu se mezitím změnil. Opakujte akci.');
            }
            foreach ($confirmations as $confirmation) {
                if ((int) $confirmation['notice_transaction_id'] === $transactionId
                    && ($confirmation['state'] === 'confirmed' || $confirmation['reason'] === 'authoritative_removed')) {
                    throw new BankNoticeConfirmationException('notice_read_only', 'Nahrazené avízo nelze samostatně měnit.');
                }
            }
            $result = $mutation();
            if ($ownsTransaction) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (\Throwable $exception) {
            // MariaDB snapshot isolation může novější řádek odmítnout a zrušit celou
            // transakci (1020); deadlock dělá totéž. Původní savepoint už neexistuje.
            $transactionConflict = TransactionFailure::rollbackIfAborted($pdo, $exception);
            if (!$transactionConflict && $pdo->inTransaction()) {
                if ($ownsTransaction) $pdo->rollBack();
                else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            if ($transactionConflict) throw new BankNoticeConfirmationException('bank_busy', 'Stav bankovního pohybu se mezitím změnil. Opakujte akci.');
            throw $exception;
        } finally {
            if ($lock !== null) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
}
