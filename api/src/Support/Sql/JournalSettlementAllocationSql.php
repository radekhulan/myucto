<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro rozpad řádku zápisu na doklady saldokonta
 * (`journal_settlement_allocations`, migrace 2192).
 *
 * Řádek se drží přes (entry_id, line_no), účet a stranu v okamžiku rozpadu. Rozpad,
 * kterému na tom pořadí neodpovídá účet nebo strana (přeúčtování mimo hlídané cesty),
 * se nepočítá ({@see lineJoin}). Platnost k rozvahovému dni měří stejně jako hlavní kniha:
 * `entry_date` zápisu a jeho protizápisu.
 *
 * Volají ji {@see LinkedManualSettlementSql::settledSql} (vyrovnání dokladů v saldokontu
 * i v uzávěrkové kontrole K3), `SaldoRepository` (předpis ostatní položky založené ze
 * zůstatku) a `JournalSettlementRepository` (volná částka řádku).
 */
final class JournalSettlementAllocationSql
{
    /**
     * Agregace `doc_id`, `settled` (Kč, kladné = snižuje saldo dokladu) z rozpadů druhu
     * `settlement`. Placeholdery podle předaných výrazů: `$supplierExpr` a dvakrát `$asOfExpr`.
     * Podmínka účtu dostává aliasy `ca` (účet řádku) a `pa` (jeho rodič).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     */
    public static function settledSql(
        string $docType,
        string $accountPredicate,
        string $supplierExpr = '?',
        string $asOfExpr = '?',
        bool $currentRead = false,
    ): string {
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true)) {
            throw new \InvalidArgumentException('journal_settlement_invalid');
        }
        $lock = $currentRead ? ' FOR UPDATE' : '';
        return "SELECT ja.doc_id, SUM(ja.amount) AS settled
                  FROM journal_settlement_allocations ja
                  JOIN journal_entries e ON e.id = ja.entry_id AND e.supplier_id = ja.supplier_id
                  " . self::lineJoin('ja', 'l') . "
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE ja.supplier_id = {$supplierExpr} AND ja.doc_type = '{$docType}' AND ja.kind = 'settlement'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= {$asOfExpr}
                   AND (e.reversed_by IS NULL OR rev.entry_date > {$asOfExpr})
                   AND ({$accountPredicate})
                 GROUP BY ja.doc_id{$lock}";
    }

    /**
     * Rozpad platí jen pro řádek, který na pořadí `line_no` pořád nese účet a stranu
     * z okamžiku rozpadu (vzor párování okruhů). `journal_entry_lines` nemá jedinečné
     * (entry_id, line_no), proto se bere nejnižší id: rozpad se nesmí započíst dvakrát
     * jen proto, že převod vložil dva řádky se stejným pořadím. `$a` = alias rozpadu,
     * `$l` = alias řádku.
     */
    public static function lineJoin(string $a, string $l): string
    {
        return "JOIN journal_entry_lines {$l} ON {$l}.id = (
                     SELECT MIN({$l}x.id) FROM journal_entry_lines {$l}x
                      WHERE {$l}x.supplier_id = {$a}.supplier_id AND {$l}x.entry_id = {$a}.entry_id
                        AND {$l}x.line_no = {$a}.line_no AND {$l}x.account_id = {$a}.account_id
                        AND {$l}x.side = {$a}.side
                 ) AND {$l}.supplier_id = {$a}.supplier_id";
    }

    /**
     * `NOT EXISTS`: zápis `$entryExpr` nemá živý rozpad (řádek pořád sedí) na doklady
     * druhu `$docTypeExpr`. Zápis s rozpadem se ve staré vazbě bez částky nepočítá, jinak by
     * se vyrovnání započetlo dvakrát; osiřelý rozpad starou vazbu nevypíná.
     */
    public static function noAllocationSql(string $supplierExpr, string $entryExpr, string $docTypeExpr, bool $currentRead = false): string
    {
        $lock = $currentRead ? ' FOR UPDATE' : '';
        return "NOT EXISTS (
                   SELECT 1 FROM journal_settlement_allocations jsa
                   " . self::lineJoin('jsa', 'jsl') . "
                    WHERE jsa.supplier_id = {$supplierExpr} AND jsa.entry_id = {$entryExpr}
                      AND jsa.doc_type = {$docTypeExpr} AND jsa.kind = 'settlement'{$lock}
               )";
    }

    /**
     * Použitá částka řádků (Kč): rozpady na doklady i nezrušené předpisy ostatních položek.
     * Výstup `entry_id`, `line_no`, `account_id`, `side`, `used`. Bez placeholderů, ID literálem.
     *
     * @param list<int> $accountIds
     */
    public static function usedByLineSql(int $supplierId, array $accountIds): string
    {
        $ids = implode(',', array_map('intval', $accountIds ?: [0]));
        return "SELECT u.entry_id, u.line_no, u.account_id, u.side, SUM(u.amount) AS used
                  FROM journal_settlement_allocations u
                 WHERE u.supplier_id = {$supplierId} AND u.account_id IN ({$ids})
                   AND (u.kind = 'settlement' OR u.released_on IS NULL)
                 GROUP BY u.entry_id, u.line_no, u.account_id, u.side";
    }

    /**
     * `NOT EXISTS`: zápis `$entryExpr` nenese zápočet převzatý z jiného programu
     * (`invoice_settlements.journal_entry_id`). Takový zápočet saldokonto i K3 počítají
     * samy, rozpad téhož zápisu by vyrovnání zdvojil.
     */
    public static function noImportedSettlementSql(string $supplierExpr, string $entryExpr): string
    {
        return "NOT EXISTS (SELECT 1 FROM invoice_settlements ist
                             WHERE ist.journal_entry_id = {$entryExpr} AND ist.supplier_id = {$supplierExpr})";
    }
}
