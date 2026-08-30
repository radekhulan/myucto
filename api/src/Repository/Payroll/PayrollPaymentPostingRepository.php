<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtení a zápis kolem účetního protizápisu spárované platby (Ú-16).
 *
 * Vlastní třída, ne další metody v {@see PayrollPaymentMatchRepository}: ta drží
 * platební knihu (alokace, důkazy, idempotenci) a nemá o účetnictví vědět nic.
 * Tenhle repozitář naopak sahá i mimo mzdy — na `journal_entries`, na pokladnu
 * a na banku — a je jediným místem, kde se ty dva světy potkávají.
 *
 * Predikát firmy je v KAŽDÉM dotazu doslova, ne skládaný interpolací.
 */
final class PayrollPaymentPostingRepository
{
    /**
     * Mzdový závazek, který platba vypořádává.
     *
     * @return array{
     *   id:int,
     *   revision_id:int,
     *   employee_id:?int,
     *   liability_kind:string,
     *   direction:string,
     *   currency_code:string,
     *   recipient_reference:string
     * }|null
     */
    public function liability(int $supplierId, int $liabilityId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, revision_id, employee_id, liability_kind,
                    direction, currency_code, recipient_reference
               FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $liabilityId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'revision_id' => (int) $row['revision_id'],
            'employee_id' => $row['employee_id'] === null
                ? null
                : (int) $row['employee_id'],
            'liability_kind' => (string) $row['liability_kind'],
            'direction' => (string) $row['direction'],
            'currency_code' => (string) $row['currency_code'],
            'recipient_reference' => (string) $row['recipient_reference'],
        ];
    }

    /**
     * ZMRAZENÝ vstupní snapshot revize, ze kterého závazek vznikl.
     *
     * Protizápis musí trefit TÝŽ účet, který mzdový můstek u té revize
     * kreditoval — ne ten, který má firma nastavený dnes. Kdyby se bral dnešní,
     * úhrada by odúčtovala jiný účet, než na kterém závazek visí, a obě salda
     * by se rozešla. Zdroj je proto stejný jako u samotného zaúčtování mzdy:
     * `payroll_run_revisions.input_snapshot_json`, klíč `employer.accounting_accounts`.
     *
     * @return array<string,mixed>|null
     */
    public function revisionSnapshot(int $supplierId, int $revisionId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT input_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $revisionId]);
        $json = $statement->fetchColumn();
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Bankovní pohyb, o který se platba opírá — číslo VLASTNÍHO účtu výpisu
     * (kvůli analytice 221) a to, jestli už pohyb zaúčtoval bankovní modul.
     *
     * Vlastní účet nese HLAVIČKA výpisu (`bank_statements.account_number` /
     * `bank_code`), ne řádek pohybu — tam je protistrana. Pojmenování
     * `recipient_*` je převzaté z {@see \MyInvoice\Service\Accounting\Bank\BankPostingService::loadTx()},
     * aby {@see \MyInvoice\Service\Accounting\Bank\BankAnalyticResolver} dostal
     * přesně ten tvar, na který je stavěný.
     *
     * `reversed_by IS NULL` je podstatné: stornovaný bankovní zápis pohyb
     * neblokuje, protože v knihách po sobě nenechal zůstatek.
     *
     * @return array{
     *   recipient_account:?string,
     *   recipient_bank:?string,
     *   bank_entry_id:?int
     * }|null
     */
    public function bankEvidence(
        int $supplierId,
        int $bankStatementId,
        int $bankTransactionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT statement.account_number AS recipient_account,
                    statement.bank_code AS recipient_bank,
                    (SELECT entry.id
                       FROM journal_entries entry
                      WHERE entry.supplier_id = ?
                        AND entry.source_type = "bank"
                        AND entry.source_id = bank_tx.id
                        AND entry.reversed_by IS NULL
                      LIMIT 1) AS bank_entry_id
               FROM bank_statements statement
               JOIN bank_transactions bank_tx
                 ON bank_tx.statement_id = statement.id
                AND bank_tx.id = ?
              WHERE statement.supplier_id = ? AND statement.id = ?',
        );
        $statement->execute([
            $supplierId,
            $bankTransactionId,
            $supplierId,
            $bankStatementId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'recipient_account' => $row['recipient_account'] === null
                ? null
                : (string) $row['recipient_account'],
            'recipient_bank' => $row['recipient_bank'] === null
                ? null
                : (string) $row['recipient_bank'],
            'bank_entry_id' => $row['bank_entry_id'] === null
                ? null
                : (int) $row['bank_entry_id'],
        ];
    }

    /**
     * Zápis pokladního dokladu, o který se platba opírá.
     *
     * Pokladní doklad se BEZ zaúčtování vůbec nestane platebním důkazem —
     * {@see \MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService::assertEvidence()}
     * vyžaduje `status='posted'` (u storna `reversed`). Účetně je tedy pohyb
     * vždycky pokrytý pokladnou a mzdy do něj nesahají; hledá se jen ID zápisu,
     * aby šla vazba poznamenat.
     */
    public function cashEntryId(int $supplierId, int $cashDocumentId): ?int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM journal_entries
              WHERE supplier_id = ?
                AND source_type = "cash"
                AND source_id = ?
                AND reversed_by IS NULL
              LIMIT 1',
        );
        $statement->execute([$supplierId, $cashDocumentId]);
        $id = $statement->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Zapíše výsledek pokusu o zaúčtování.
     *
     * Do SATELITU, ne na řádek spárování: `payroll_payment_matches` je
     * append-only a UPDATE nad ním odmítne trigger
     * `trg_payroll_payment_match_immutable_update` (migrace 1269/1559).
     *
     * `ON DUPLICATE KEY UPDATE` drží idempotenci opakovaného párování: replay
     * téhož požadavku dojde k témuž výsledku a přepíše ho touž hodnotou.
     */
    public function markPosting(
        int $supplierId,
        int $matchId,
        string $status,
        ?int $journalEntryId,
        ?string $reason,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_match_postings
                (supplier_id, match_id, journal_entry_id,
                 posting_status, posting_skipped_reason)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                journal_entry_id = VALUES(journal_entry_id),
                posting_status = VALUES(posting_status),
                posting_skipped_reason = VALUES(posting_skipped_reason)',
        );
        $statement->execute([
            $supplierId,
            $matchId,
            $journalEntryId,
            $status,
            $reason,
        ]);
    }

    public function __construct(private readonly Connection $db) {}
}
