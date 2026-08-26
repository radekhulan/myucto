<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * MZ-18-W07 — read-only zdroj dat pro reconciliation účetního můstku mezd.
 * Nic nezapisuje ani nemění; jen čte mzdový běh/revizi, zaúčtovaný deník a
 * platební závazky, aby je {@see \MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService}
 * mohl porovnat s kontrolními součty MZ-13-W06.
 */
final class PayrollPostingReconciliationRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{id:int,status:string,current_revision_no:int}|null */
    public function findRun(int $supplierId, string $periodStart): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, current_revision_no
               FROM payroll_runs
              WHERE supplier_id = ? AND period_start = ?
              LIMIT 1'
        );
        $statement->execute([$supplierId, $periodStart]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'current_revision_no' => (int) $row['current_revision_no'],
        ];
    }

    /**
     * @return array{
     *   id:int,
     *   status:string,
     *   result_snapshot_json:?string,
     *   result_snapshot_hash:?string
     * }|null
     */
    public function findRevisionByNo(
        int $supplierId,
        int $runId,
        int $revisionNo,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, result_snapshot_json, result_snapshot_hash
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND run_id = ? AND revision_no = ?
              LIMIT 1'
        );
        $statement->execute([$supplierId, $runId, $revisionNo]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'result_snapshot_json' => $row['result_snapshot_json'] === null
                ? null
                : (string) $row['result_snapshot_json'],
            'result_snapshot_hash' => $row['result_snapshot_hash'] === null
                ? null
                : (string) $row['result_snapshot_hash'],
        ];
    }

    /** @return list<int> */
    public function revisionIdsForRun(int $supplierId, int $runId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_run_revisions
              WHERE supplier_id = ? AND run_id = ?
              ORDER BY revision_no'
        );
        $statement->execute([$supplierId, $runId]);

        return array_map(
            static fn (mixed $id): int => (int) $id,
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * Aktuální (nejnovější) revize má vlastní účinnou zaúčtovanou/bezezměnovou
     * dávku — jen tehdy deník skutečně odráží nejnovější schválenou mzdu.
     * Starší dávky dřívějších revizí samy o sobě neznamenají „zaúčtováno".
     */
    public function currentRevisionHasEffectivePostingBatch(
        int $supplierId,
        int $currentRevisionId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_posting_batches
              WHERE supplier_id = ? AND revision_id = ?
                AND status IN ("posted", "no_change")
              LIMIT 1'
        );
        $statement->execute([$supplierId, $currentRevisionId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Obraty deníku po účtech (prefix syntetického účtu) a dimenzi srážky
     * napříč VŠEMI revizemi běhu — opravná revize účtuje jen rozdíl, takže
     * teprve součet přes revize odpovídá aktuálnímu stavu mzdy.
     *
     * @param list<int> $revisionIds
     * @return list<array{account_code:string,prefix:string,dimension:string,side:string,amount_minor:int}>
     */
    public function journalTotals(int $supplierId, array $revisionIds): array
    {
        if ($revisionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($revisionIds), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT account.account_code AS account_code,
                    LEFT(account.account_code, 3) AS prefix,
                    CASE
                      WHEN line.cost_center LIKE 'MZ-EX-%' THEN 'enforcement'
                      WHEN line.cost_center LIKE 'MZ-SR-%' THEN 'deduction'
                      ELSE 'none'
                    END AS dimension,
                    line.side AS side,
                    CAST(ROUND(SUM(line.amount) * 100) AS SIGNED) AS amount_minor
               FROM journal_entry_lines line
               JOIN journal_entries entry
                 ON entry.supplier_id = line.supplier_id
                AND entry.id = line.entry_id
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id
                AND account.id = line.account_id
              WHERE line.supplier_id = ?
                AND entry.source_type = 'payroll'
                AND entry.source_id IN ({$placeholders})
                AND entry.posted_at IS NOT NULL
              GROUP BY account.account_code, dimension, line.side
              ORDER BY account.account_code, dimension, line.side"
        );
        $statement->execute([$supplierId, ...$revisionIds]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'account_code' => (string) $row['account_code'],
                'prefix' => (string) $row['prefix'],
                'dimension' => (string) $row['dimension'],
                'side' => (string) $row['side'],
                'amount_minor' => (int) $row['amount_minor'],
            ];
        }

        return $result;
    }

    /**
     * Účty použité na debetní straně hrubé mzdy podle zmrazených cílových
     * alokací můstku. Nestačí syntetiky 521/522/523: explicitní předkontace
     * složky i výchozí účet mzdové dimenze mohou náklad vést například na 518.
     *
     * Berou se všechny účinně zaúčtované revize běhu. Opravná revize může starý
     * účet kreditovat a nový debitovat, takže pro správné zařazení obou stran
     * musí v množině zůstat i účet dřívější revize.
     *
     * @param list<int> $revisionIds
     * @return list<string>
     */
    public function grossDebitAccounts(int $supplierId, array $revisionIds): array
    {
        if ($revisionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($revisionIds), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT DISTINCT allocation.account_code
               FROM payroll_posting_allocations allocation
               JOIN payroll_posting_batches batch
                 ON batch.supplier_id = allocation.supplier_id
                AND batch.id = allocation.batch_id
              WHERE allocation.supplier_id = ?
                AND batch.revision_id IN ({$placeholders})
                AND batch.status IN ('posted', 'no_change')
                AND allocation.allocation_key LIKE 'gross:%:debit'
              ORDER BY allocation.account_code"
        );
        $statement->execute([$supplierId, ...$revisionIds]);

        return array_map(
            static fn (mixed $account): string => (string) $account,
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * Platební závazky (jen aktuální hlava opravného řetězce, tj. bez
     * dřívějších `previous_liability_id` verzí) a jejich čisté vypořádání
     * (matched − reversed) napříč revizemi běhu.
     *
     * @param list<int> $revisionIds
     * @return list<array{liability_kind:string,liability_minor:int,paid_minor:int}>
     */
    public function liabilityTotals(int $supplierId, array $revisionIds): array
    {
        if ($revisionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($revisionIds), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT liability.liability_kind AS liability_kind,
                    SUM(liability.amount_minor) AS liability_minor,
                    COALESCE(SUM(settled.settled_minor), 0) AS paid_minor
               FROM payroll_payment_liabilities liability
               LEFT JOIN (
                 SELECT allocation.supplier_id AS supplier_id,
                        allocation.liability_id AS liability_id,
                        SUM(payment_match.amount_minor) AS settled_minor
                   FROM payroll_payment_allocations allocation
                   JOIN payroll_payment_matches payment_match
                     ON payment_match.supplier_id = allocation.supplier_id
                    AND payment_match.allocation_id = allocation.id
                  WHERE allocation.supplier_id = ?
                  GROUP BY allocation.supplier_id, allocation.liability_id
               ) settled
                 ON settled.supplier_id = liability.supplier_id
                AND settled.liability_id = liability.id
              WHERE liability.supplier_id = ?
                AND liability.revision_id IN ({$placeholders})
                AND NOT EXISTS (
                  SELECT 1 FROM payroll_payment_liabilities newer
                   WHERE newer.supplier_id = liability.supplier_id
                     AND newer.previous_liability_id = liability.id
                )
              GROUP BY liability.liability_kind"
        );
        $statement->execute([$supplierId, $supplierId, ...$revisionIds]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'liability_kind' => (string) $row['liability_kind'],
                'liability_minor' => (int) $row['liability_minor'],
                'paid_minor' => (int) $row['paid_minor'],
            ];
        }

        return $result;
    }
}
