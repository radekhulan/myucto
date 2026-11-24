<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\ReconciliationVerdict;
use MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation;

/**
 * Rekonciliace převodu roku - důkaz, že MyÚčto po převodu ukazuje totéž co PREMIER:
 *   1. obratová předvaha MyÚčta ({@see TrialBalanceService}) proti předvaze spočtené přímo
 *      z deníku PREMIER (počáteční stavy dopočtené z minulých let) - po syntetických účtech,
 *      PS / obrat / KS, na haléř;
 *   2. vnitřní kontroly předvahy (obraty MD = D, předvaha = deník, vyrovnané PS);
 *   3. doklady proti deníku: přijaté faktury × 321, vydané × 311, pokladna × 211,
 *      banka × 221 - jen převedené doklady a zápisy, na které jsou navázané;
 *   4. vyrovnaná rozvaha bez účtů, které mapa výkazů nezná;
 *   5. každý bankovní pohyb převodu má vlastní zápis deníku (zdroj `bank`) - jinak by ho
 *      Doúčtování zaúčtovalo podruhé.
 */
final class PremierReconciler
{
    public const STEP = 'reconciliation';

    public function __construct(
        private readonly Connection $db,
        private readonly TrialBalanceService $trialBalance,
        private readonly FinancialStatementService $statements,
    ) {}

    public function run(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->period === null) {
            $p->finish(self::STEP);
            return;
        }
        $periodId = $ctx->period['id'];
        $opening = $ctx->opening;
        foreach ($ctx->journal->openingRows($ctx->year) as $r) {
            foreach ([[$r['md'], 1], [$r['dal'], -1]] as [$code, $sign]) {
                if (!str_starts_with((string) $code, PremierJournal::OPENING_ACCOUNT)) {
                    $opening[$code] = ($opening[$code] ?? 0.0) + $sign * $r['amount'];
                }
            }
        }
        $premier = $ctx->journal->trialBalance($ctx->year, $opening);

        $tb = $this->trialBalance->build($ctx->supplierId, $periodId, null, null, false, false);
        // 701 otevíracího zápisu PREMIER v deníku nevede.
        $mine = TrialBalanceReconciliation::synthetic($tb['rows'], static fn (string $syn): bool => str_starts_with($syn, '7'));
        $journalDiffs = TrialBalanceReconciliation::compare($mine, $premier);
        $checks = TrialBalanceReconciliation::checks($tb, 'premier_journal', $journalDiffs, count($premier));
        $documents = $this->documentsAgainstJournal($ctx->supplierId, $periodId);
        foreach ($documents as $d) {
            $checks[] = ['key' => 'documents_' . $d['key'], 'ok' => $d['ok']];
        }
        $bank = $this->bankPosting($ctx->supplierId, $ctx->period['starts_on'], $ctx->period['ends_on']);
        $checks[] = ['key' => 'bank_transactions_posted', 'ok' => $bank['unposted'] === 0, 'transactions' => $bank['transactions'], 'unposted' => $bank['unposted']];
        if ($bank['unposted'] > 0) {
            $p->error(self::STEP, 'bank_transactions_unposted', sprintf('Rok %d: %d z %d bankovních pohybů převodu nemá vlastní zápis v deníku.', $ctx->year, $bank['unposted'], $bank['transactions']),
                ['year' => $ctx->year, 'transactions' => $bank['transactions'], 'unposted' => $bank['unposted'], 'ids' => $bank['ids']]);
        }
        $balanceSheet = TrialBalanceReconciliation::balanceSheet($this->statements->balanceSheet($ctx->supplierId, $periodId, null, 'full'));
        $unmapped = $balanceSheet['unmapped'];
        $checks[] = $balanceSheet['check'];

        $ok = TrialBalanceReconciliation::allOk($checks);
        $p->set('reconciliation', [ReconciliationVerdict::apply($p, self::STEP, [
            'year' => $ctx->year,
            'period_id' => $periodId,
            'ok' => $ok,
            'checks' => $checks,
            'totals' => $tb['totals'],
            'journal_diffs' => $journalDiffs,
            'documents' => $documents,
            'unmapped_accounts' => $unmapped,
            'negative_net_rows' => $balanceSheet['negative_net_rows'],
        ])]);
        $warning = TrialBalanceReconciliation::negativeNetWarning($ctx->year, $balanceSheet['negative_net_rows']);
        if ($warning !== null) {
            $p->warn(self::STEP, 'negative_net_rows', $warning, ['year' => $ctx->year, 'rows' => $balanceSheet['negative_net_rows']]);
        }
        $p->finish(self::STEP);
    }

    /**
     * Bankovní pohyby převodu v období a kolik z nich nemá vlastní zápis deníku.
     *
     * @return array{transactions:int,unposted:int,ids:list<int>}
     */
    private function bankPosting(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, " . BankTransactionPostingScope::existsSql('s.supplier_id', 't.id') . " AS posted
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.posted_at BETWEEN ? AND ?
                AND EXISTS (SELECT 1 FROM premier_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)"
        );
        $stmt->execute([$supplierId, $from, $to]);
        $total = 0;
        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$id, $posted]) {
            $total++;
            if (!(bool) $posted) {
                $ids[] = (int) $id;
            }
        }
        return ['transactions' => $total, 'unposted' => count($ids), 'ids' => array_slice($ids, 0, 50)];
    }

    /**
     * Doklady proti zápisům, na které jsou navázané (stejně jako u převodu z POHODY).
     * Porovnávají se jen zápisy, které mají účet dokladu na JEDNÉ straně (vznik závazku
     * nebo pohledávky); doklad účtovaný jinak se počítá zvlášť (`other_accounts`).
     *
     * @return list<array{key:string,documents:float,journal:float,ok:bool,other_accounts:int}>
     */
    private function documentsAgainstJournal(int $supplierId, int $periodId): array
    {
        $pdo = $this->db->pdo();
        $notPayment = 'AND (k.note IS NULL OR k.note <> ' . $pdo->quote(DocumentLinker::PAYMENT_NOTE) . ')';
        $scalar = static function (string $sql, array $params) use ($pdo): float {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return round((float) $stmt->fetchColumn(), 2);
        };
        $oneSided = static fn (string $entry, string $prefix): string =>
            "(SELECT COUNT(DISTINCT l2.side) FROM journal_entry_lines l2 FORCE INDEX (ix_jel_closing_by_entry)
                JOIN chart_of_accounts a2 ON a2.id = l2.account_id AND a2.supplier_id = l2.supplier_id
               WHERE l2.supplier_id = l.supplier_id AND l2.entry_id = {$entry} AND a2.account_code LIKE '{$prefix}%') = 1";
        $mapped = static fn (string $alias, string $kinds): string =>
            "EXISTS (SELECT 1 FROM premier_import_map m WHERE m.supplier_id = {$alias}.supplier_id AND m.kind IN ({$kinds}) AND m.target_id = {$alias}.id)";
        $ledger = static fn (string $kinds, string $docType, string $prefix, string $sign): string =>
            "SELECT COALESCE(SUM({$sign}), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND a.account_code LIKE '{$prefix}%' AND e.source_type <> 'opening'
                AND " . $oneSided('e.id', $prefix) . "
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN premier_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = k.doc_id AND m.kind IN ({$kinds})
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id AND k.doc_type = '{$docType}' {$notPayment})";
        $docs = static fn (string $table, string $expr, string $kinds, string $docType, string $prefix, bool $onAccount): string =>
            'SELECT ' . ($onAccount ? "COALESCE(SUM({$expr}), 0)" : 'COUNT(*)') . "
               FROM {$table} d
              WHERE d.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                            WHERE k.supplier_id = d.supplier_id AND k.doc_id = d.id AND e.period_id = ? AND k.doc_type = '{$docType}' {$notPayment}
                              AND e.source_type <> 'opening'
                              AND " . ($onAccount ? '' : 'NOT ') . str_replace('l.supplier_id', 'k.supplier_id', $oneSided('k.entry_id', $prefix)) . ')
                AND ' . $mapped('d', $kinds);

        $total = ForeignCurrencyTakeover::homeAmountSql('d.total_with_vat', 'd.exchange_rate');
        $spec = [
            ['purchase_invoices', 'purchase_invoices', $total, "'purchase_invoice'", 'purchase_invoice', '321', "CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END"],
            ['issued_invoices', 'invoices', IssuedCzkAmounts::grossSql('d', $total), "'invoice'", 'invoice', '311', "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END"],
            ['cash', 'cash_documents', "CASE WHEN d.doc_type = 'in' THEN d.total_amount ELSE -d.total_amount END", "'cash_document'", 'cash', '211', "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END"],
        ];
        $out = [];
        foreach ($spec as [$key, $table, $expr, $kinds, $docType, $prefix, $sign]) {
            $documents = $scalar($docs($table, $expr, $kinds, $docType, $prefix, true), [$supplierId, $periodId]);
            $journal = $scalar($ledger($kinds, $docType, $prefix, $sign), [$supplierId, $periodId]);
            $out[] = TrialBalanceReconciliation::documentRow($key, $documents, $journal, (int) $scalar($docs($table, $expr, $kinds, $docType, $prefix, false), [$supplierId, $periodId]));
        }
        // Banka jen u účtů v Kč - pohyb účtu v cizí měně je v měně účtu, deník v Kč.
        $bankDocs = $scalar(
            "SELECT COALESCE(SUM(t.amount), 0) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.currency = 'CZK'
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                             WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id AND e.period_id = ?)
                AND EXISTS (SELECT 1 FROM premier_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)",
            [$supplierId, $periodId]
        );
        $bankJournal = $scalar(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND a.account_code LIKE '221%' AND l.currency_code IS NULL
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN bank_transactions t ON t.id = k.doc_id
                             JOIN premier_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = t.id AND m.kind = 'bank_transaction'
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id AND k.doc_type = 'bank' AND t.currency = 'CZK')",
            [$supplierId, $periodId]
        );
        $out[] = TrialBalanceReconciliation::documentRow('bank', $bankDocs, $bankJournal, 0);
        return $out;
    }
}
