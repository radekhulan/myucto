<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use PDO;

/**
 * Ví, jestli bankovní pohyb už spotřebovaly mzdy.
 *
 * Mzdová a fakturační rekonciliace se dosud viděly jen jedním směrem:
 * {@see PayrollPaymentReconciliationService} odmítne pohyb, který už vlastní
 * bankovní modul (`match_status <> 'unmatched'`, `matched_invoice_id`,
 * `invoice_payments`, `payment_matches`), ale opačně nic — bankovní matcher
 * o mzdách nevěděl a týž odchozí pohyb mohl podruhé přiřadit k přijaté
 * faktuře. Výsledkem je jedna platba použitá dvakrát a rozpadlé saldo.
 *
 * Mzdy `match_status` samy nepřepisují záměrně: `bank_transactions` je sdílená
 * účetní vrstva a hodnoty `auto_exact`/`manual` mají v účtování a v posting
 * backfillu vlastní význam (viz BankPostingService) — označit jimi mzdovou
 * platbu by znamenalo tvrdit, že pohyb patří k faktuře. Místo přepisu stavu
 * se proto bankovní strana ptá téhle stráže.
 *
 * Čte se přes VŠECHNY firmy záměrně: pohyb je fyzicky jeden a to, že si ho
 * nárokovaly mzdy jiné firmy, je pro fakturační párování stejná překážka
 * (stejný pohled má `bankEvidenceOwnership()` na mzdové straně).
 *
 * @see \MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService
 */
final class PayrollBankEvidenceGuard
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Je pohyb spotřebovaný mzdovou platbou?
     *
     * Stačí jediný záznam v `payroll_payment_matches`, a to i storno
     * (`event_kind = 'reversed'`): storno se podle
     * {@see PayrollPaymentReconciliationService::assertEvidence()} vždy opírá
     * o VLASTNÍ pohyb opačného směru (vrácenou částku), ne o ten původní.
     * Obsazený je tedy jak původní odchozí pohyb, tak příchozí vratka —
     * ani jeden z nich už nesmí jít podruhé na fakturu.
     */
    public function isUsedByPayroll(int $bankTransactionId): bool
    {
        if ($bankTransactionId <= 0) {
            return false;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                        SELECT 1
                          FROM payroll_payment_matches
                         WHERE bank_transaction_id = ?
                    )',
        );
        $statement->execute([$bankTransactionId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * Tolerantní varianta pro volání ze sdílené bankovní vrstvy: chybějící
     * mzdová tabulka (instance bez mzdového modulu, starší schéma) nesmí
     * shodit párování faktur.
     */
    public function isUsedByPayrollSafely(int $bankTransactionId): bool
    {
        try {
            return $this->isUsedByPayroll($bankTransactionId);
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) {
                throw $exception;
            }
            return false;
        }
    }

    public function assertAvailableForBankPosting(int $bankTransactionId): void
    {
        $statement = $this->db->pdo()->prepare('SELECT id FROM bank_transactions WHERE id = ? FOR UPDATE');
        $statement->execute([$bankTransactionId]);
        try {
            $statement = $this->db->pdo()->prepare(
                'SELECT payment_match.id FROM payroll_payment_matches payment_match
                   JOIN journal_entries entry
                     ON entry.supplier_id = payment_match.supplier_id
                    AND entry.source_type = "payroll_payment" AND entry.source_id = payment_match.id
                    AND entry.reversed_by IS NULL AND entry.posted_at IS NOT NULL
                  WHERE payment_match.bank_transaction_id = ? LIMIT 1 FOR UPDATE',
            );
            $statement->execute([$bankTransactionId]);
            if ($statement->fetchColumn() !== false) {
                throw new PostingException(
                    'payroll_payment',
                    'Pohyb je spárovaný se mzdami. Použijte jeho existující mzdovou vazbu.',
                    409,
                );
            }
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) {
                throw $exception;
            }
        }
    }

    public function postingInfo(int $supplierId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        try {
            $statement = $this->db->pdo()->prepare(
                "SELECT payment_match.bank_transaction_id, entry.id AS journal_entry_id, entry.document_no,
                        entry.source_type,
                        " . BankTransactionPostingScope::payrollFullyPostedSql('payment_match.supplier_id', 'payment_match.bank_transaction_id') . " AS fully_posted
                   FROM payroll_payment_matches payment_match
              LEFT JOIN payroll_payment_match_postings posting
                     ON posting.supplier_id = payment_match.supplier_id AND posting.match_id = payment_match.id
              LEFT JOIN journal_entries entry
                     ON entry.supplier_id = payment_match.supplier_id AND entry.id = posting.journal_entry_id
                    AND entry.reversed_by IS NULL AND entry.posted_at IS NOT NULL
                    AND ((entry.source_type = 'payroll_payment' AND entry.source_id = payment_match.id)
                         OR (entry.source_type = 'bank' AND entry.source_id = payment_match.bank_transaction_id))
                  WHERE payment_match.supplier_id = ? AND payment_match.bank_transaction_id IN ($placeholders)
                  ORDER BY payment_match.id",
            );
            $statement->execute([$supplierId, ...$transactionIds]);
            $result = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $transactionId = (int) $row['bank_transaction_id'];
                $result[$transactionId] ??= ['status' => null, 'payroll_matched' => true, 'payroll_posting_blocked' => false];
                if ($row['journal_entry_id'] !== null) {
                    $result[$transactionId] += [
                        'journal_entry_id' => (int) $row['journal_entry_id'],
                        'document_no' => $row['document_no'],
                    ];
                    if ($row['source_type'] === 'payroll_payment') {
                        $result[$transactionId]['payroll_posting_blocked'] = true;
                    }
                    if ($row['source_type'] === 'bank' || (bool) $row['fully_posted']) {
                        $result[$transactionId]['status'] = 'posted';
                    }
                }
            }
            foreach ($result as &$posting) {
                if ($posting['payroll_posting_blocked'] && $posting['status'] === null) {
                    $posting['note'] = 'payroll_partial_posting';
                }
            }
            unset($posting);
            return $result;
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) {
                throw $exception;
            }
            return [];
        }
    }
}
