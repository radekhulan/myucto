<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Analytika 221 bankovního účtu podle deníku a její počáteční stav v období.
 *
 * Převzaté výpisy nenesou vazbu na analytiku: ta se pozná ze zápisů navázaných na jejich
 * pohyby (`journal_entry_document_links`, druh `bank`). Sdílí ji oprava účtů v cizí měně
 * převedených z POHODY ({@see \MyInvoice\Service\Migration\Pohoda\PohodaForeignBankRepair})
 * i kontrola stavů převzatých výpisů proti deníku
 * ({@see \MyInvoice\Service\Migration\Check\Checks\ImportedStatementBalanceCheck}).
 */
final class BankLedgerAccount
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Analytika 221, na kterou jdou zápisy pohybů účtu: nejčastější účet řádků 221 v zápisech
     * navázaných na pohyby, s podílem zápisů, které na ni jdou.
     *
     * @param list<int> $txIds
     * @return array{0:?int,1:?string,2:float} [id účtu, číslo účtu, podíl zápisů]
     */
    public function forTransactions(int $supplierId, array $txIds): array
    {
        $counts = [];
        $entries = [];
        foreach (array_chunk($txIds, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.account_id, a.account_code, k.entry_id
                   FROM journal_entry_document_links k
                   JOIN journal_entry_lines l ON l.entry_id = k.entry_id AND l.supplier_id = k.supplier_id
                   JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                  WHERE k.supplier_id = ? AND k.doc_type = 'bank' AND k.doc_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND a.account_code LIKE '221%'
                  GROUP BY l.account_id, a.account_code, k.entry_id"
            );
            $stmt->execute(array_merge([$supplierId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[(int) $r['account_id']] = ['code' => (string) $r['account_code'], 'n' => ($counts[(int) $r['account_id']]['n'] ?? 0) + 1];
                $entries[(int) $r['entry_id']] = true;
            }
        }
        if ($counts === []) {
            return [null, null, 0.0];
        }
        uasort($counts, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
        $id = (int) array_key_first($counts);
        return [$id, $counts[$id]['code'], $counts[$id]['n'] / max(1, count($entries))];
    }

    /**
     * Počáteční stav analytiky v období podle otevíracích zápisů (v haléřích, MD − D).
     * `null`, když období otevírací řádek analytiky nemá.
     */
    public function openingCents(int $supplierId, int $periodId, int $accountId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*), COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND l.account_id = ? AND e.source_type = 'opening'"
        );
        $stmt->execute([$supplierId, $periodId, $accountId]);
        [$count, $sum] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return (int) $count === 0 ? null : (int) round((float) $sum * 100);
    }
}
