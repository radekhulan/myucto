<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro „doklad vyrovnaný RUČNÍM zápisem, který na něj účetní
 * výslovně navázala" (`journal_entry_document_links`, migrace 1514).
 *
 * Ruční zápis (kurzový rozdíl 563/311, odpis 321/365, přeúčtování) nemá jinou vazbu
 * na doklad než tuhle měkkou vazbu. Bez ní uzávěrková kontrola K3 hlásila doklad
 * s vyrovnaným saldokontem jako nesoulad a saldokonto ho drželo otevřený, přestože
 * hlavní kniha je na nule. Počítá se čistý pohyb zápisu na saldokontním účtu na
 * straně, která saldo dokladu snižuje.
 *
 * Zápis navázaný na VÍC dokladů téhož typu se nepočítá: vazba nenese rozpad částky
 * a přiznat ji celou každému dokladu by nesoulad schovalo. Doklad pak zůstane
 * v kontrole i v saldu k ručnímu posouzení.
 *
 * Zápis, který doklad vlastní ve skupině zaúčtování (`journal_document_postings`, doklad
 * zaúčtovaný po řádcích víc zápisy), vyrovnáním není: je to další část předpisu.
 * Agregace ho proto vynechává a předpis z něj skládají volající.
 *
 * Zápis s rozpadem na doklady téhož druhu (`journal_settlement_allocations`) se tu
 * nepočítá: částky nese rozpad a {@see settledSql()} je přičítá zvlášť. Bez toho by se
 * zápis navázaný na jediný doklad a zároveň rozepsaný započetl dvakrát.
 *
 * Totéž platí pro zápis, který k témuž dokladu eviduje zápočet (`invoice_settlements`
 * s `journal_entry_id`): částku už nese kanál zápočtů v saldokontu i v K3. Vazba je pak
 * jen odkaz a počítat ji znamená hlásit doklad jako přeplacený.
 *
 * Volají ji `ClosingRepository` (K3) a `SaldoRepository` (otevřené položky), obojí přes
 * {@see settledSql()}.
 */
final class LinkedManualSettlementSql
{
    /**
     * Agregace `doc_id`, `settled` (CZK, kladné = snižuje saldo dokladu).
     *
     * Obsahuje placeholdery podle předaných výrazů: `$supplierExpr` a dvakrát `$asOfExpr`.
     * Podmínka účtu dostává aliasy `ca` (řádkový účet) a `pa` (jeho rodič).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param 'credit'|'debit' $settleSide strana, na které zápis saldo dokladu snižuje
     */
    public static function sql(
        string $docType,
        string $settleSide,
        string $accountPredicate,
        string $supplierExpr = '?',
        string $asOfExpr = '?',
        bool $currentRead = false,
    ): string {
        return self::projection($docType, $settleSide, $accountPredicate, $supplierExpr, $asOfExpr, $currentRead, false);
    }

    /**
     * Vyrovnání dokladů ručními zápisy celkem: vazba bez částky ({@see sql()}) a rozpad
     * řádků na doklady ({@see JournalSettlementAllocationSql::settledSql()}). Agregace
     * `doc_id`, `settled`; placeholdery dvakrát za sebou v pořadí `$supplierExpr`,
     * `$asOfExpr`, `$asOfExpr`.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param 'credit'|'debit' $settleSide
     */
    public static function settledSql(
        string $docType,
        string $settleSide,
        string $accountPredicate,
        string $supplierExpr = '?',
        string $asOfExpr = '?',
        bool $currentRead = false,
    ): string {
        $linked = self::sql($docType, $settleSide, $accountPredicate, $supplierExpr, $asOfExpr, $currentRead);
        $allocated = JournalSettlementAllocationSql::settledSql($docType, $accountPredicate, $supplierExpr, $asOfExpr, $currentRead);
        return "SELECT s.doc_id, SUM(s.settled) AS settled
                  FROM (({$linked}) UNION ALL ({$allocated})) s
                 GROUP BY s.doc_id";
    }

    public static function membersSql(
        string $docType,
        string $settleSide,
        string $accountPredicate,
        string $supplierExpr = '?',
        string $asOfExpr = '?',
        bool $currentRead = false,
    ): string {
        return self::projection($docType, $settleSide, $accountPredicate, $supplierExpr, $asOfExpr, $currentRead, true);
    }

    public static function lifecycleMembersSql(string $docType, string $settleSide, string $accountPredicate, bool $currentRead = false): string
    {
        return self::projection($docType, $settleSide, $accountPredicate, '?', '?', $currentRead, true, true);
    }

    private static function projection(
        string $docType,
        string $settleSide,
        string $accountPredicate,
        string $supplierExpr,
        string $asOfExpr,
        bool $currentRead,
        bool $members,
        bool $lifecycle = false,
    ): string {
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true)
            || !in_array($settleSide, ['credit', 'debit'], true)) {
            throw new \InvalidArgumentException('linked_manual_settlement_invalid');
        }

        $lock = $currentRead ? ' FOR UPDATE' : '';
        $unique = "NOT EXISTS (
                       SELECT 1 FROM journal_entry_document_links other
                        WHERE other.supplier_id = dl.supplier_id AND other.entry_id = dl.entry_id
                          AND other.doc_type = dl.doc_type AND other.doc_id <> dl.doc_id{$lock}
                   )";
        $select = $members
            ? "dl.doc_id, e.id AS entry_id, e.entry_date, l.id AS line_id, l.account_id, l.currency_code,
                       CASE WHEN l.side = '{$settleSide}' THEN l.signed_amount ELSE -l.signed_amount END AS settled,
                       CASE WHEN l.side = '{$settleSide}' THEN COALESCE(l.signed_amount_foreign, 0) ELSE -COALESCE(l.signed_amount_foreign, 0) END AS settled_foreign,
                       ({$unique}) AS allocation_complete"
            : "dl.doc_id,
                       SUM(CASE WHEN l.side = '{$settleSide}' THEN l.signed_amount ELSE -l.signed_amount END) AS settled";
        // Zápis ze skupiny zaúčtování téhož dokladu je jeho předpis, ne vyrovnání: volající
        // ho berou do předpisu přes DocumentPostingGroup::entriesSql(). Členové pro
        // kurzovou evidenci (members) zůstávají beze změny.
        $ownPosting = "NOT EXISTS (
                       SELECT 1 FROM journal_document_postings own
                        WHERE own.supplier_id = dl.supplier_id AND own.entry_id = dl.entry_id
                          AND own.doc_type = dl.doc_type AND own.doc_id = dl.doc_id{$lock}
                   )";
        $allocated = JournalSettlementAllocationSql::noAllocationSql('dl.supplier_id', 'dl.entry_id', 'dl.doc_type', $currentRead);
        // Vydaná faktura má zápočet v saldu přes evidovanou platbu, bez ní ho žádný kanál nečte.
        $settlementPayment = $docType === 'invoice'
            ? " JOIN invoice_payments sip ON sip.id = stl.invoice_payment_id AND sip.supplier_id = stl.supplier_id
                                                AND sip.source = 'settlement'"
            : '';
        $noSettlement = "NOT EXISTS (
                       SELECT 1 FROM invoice_settlements stl{$settlementPayment}
                        WHERE stl.journal_entry_id = dl.entry_id AND stl.supplier_id = dl.supplier_id
                          AND stl.doc_type = dl.doc_type AND stl.doc_id = dl.doc_id{$lock}
                   )";
        $tail = $members ? 'ORDER BY dl.doc_id, e.id, l.line_no, l.id' : "AND {$unique}
                   AND {$ownPosting}
                   AND {$allocated}
                   AND {$noSettlement}
                 GROUP BY dl.doc_id";
        $effective = $lifecycle ? '' : " AND e.entry_date <= {$asOfExpr}
                   AND (e.reversed_by IS NULL OR rev.entry_date > {$asOfExpr})";
        return "SELECT {$select}
                  FROM journal_entry_document_links dl
                  JOIN journal_entries e ON e.id = dl.entry_id AND e.supplier_id = dl.supplier_id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE dl.supplier_id = {$supplierExpr} AND dl.doc_type = '{$docType}'
                   AND e.source_type = 'manual'
                   AND e.posted_at IS NOT NULL{$effective}
                   AND ({$accountPredicate})
                   {$tail}{$lock}";
    }

    /** Podmínka „řádek je na saldokontním účtu s tímto prefixem" (vč. analytik). */
    public static function accountPrefixPredicate(string $prefix): string
    {
        if (preg_match('/^\d{3}$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('linked_manual_settlement_prefix');
        }
        return "ca.account_code LIKE '{$prefix}%' OR COALESCE(pa.account_code, '') LIKE '{$prefix}%'";
    }

    /** Podmínka „řádek je na účtu `$accountId` nebo jeho analytice". */
    public static function accountIdPredicate(int $accountId): string
    {
        return "ca.id = {$accountId} OR ca.parent_id = {$accountId}";
    }
}
