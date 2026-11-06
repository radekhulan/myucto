<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use MyInvoice\Service\Migration\Shared\ReconciliationVerdict;
use MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation;

/**
 * Rekonciliace převodu — důkaz, že MyÚčto po převodu ukazuje totéž co Money.
 *
 * Pro každý převedený rok:
 *   1. obratová předvaha MyÚčta ({@see TrialBalanceService}, tatáž sestava, kterou
 *      účetní vidí v aplikaci) proti předvaze spočtené přímo z deníku Money v záloze —
 *      po syntetických účtech, PS / obrat / KS netto, na haléř;
 *   2. volitelně proti obratové předvaze vyexportované z Money ({@see MoneyReportParser});
 *   3. vnitřní kontroly předvahy (obraty MD = D, předvaha = deník, vyrovnané PS,
 *      žádné rozpracované zápisy);
 *   4. doklady proti deníku: přijaté faktury × 321, vydané × 311, pokladna × 211,
 *      banka × 221 — jen převedené doklady a zápisy, na které jsou navázané.
 */
final class MoneyS3Reconciler
{
    public const STEP = 'reconciliation';

    public function __construct(
        private readonly Connection $db,
        private readonly TrialBalanceService $trialBalance,
        private readonly MoneyReportParser $reports,
        private readonly FinancialStatementService $statements,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $years = [];
        $moneyByYear = $this->moneyTrialBalances($ctx);
        foreach ($ctx->periods as $year => $period) {
            $result = ReconciliationVerdict::apply($p, self::STEP, $this->reconcileYear($ctx, $year, $period['id'], $moneyByYear[$year] ?? []));
            $years[] = $result;
            $warning = TrialBalanceReconciliation::negativeNetWarning($year, $result['negative_net_rows']);
            if ($warning !== null) {
                $p->warn(self::STEP, 'negative_net_rows', $warning, ['year' => $year, 'rows' => $result['negative_net_rows']]);
            }
        }
        $p->set('reconciliation', $years);
        $p->finish(self::STEP);
    }

    /**
     * Předvaha přímo z deníku Money: po syntetických účtech netto PS (řádky `XP`),
     * obrat (ostatní řádky kromě uzávěrky roku `XZ`, kterou převod nepřebírá) a KS.
     * Záporná částka se nepřepíná — netto účinek je znaménkem vyjádřený stejně
     * ({@see Ms3Journal::effect()}).
     *
     * @return array<int,array<string,array{0:float,1:float,2:float}>>
     */
    public function moneyTrialBalances(ImportContext $ctx): array
    {
        $out = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik', ['Zdroj', 'Castka', 'UcMD', 'UcD']) as $r) {
            $year = $ctx->yearOf($r);
            $effect = Ms3Journal::isYearEndClosing($r) ? null : Ms3Journal::effect($r);
            if ($year === null || $effect === null) {
                $slot = null;
            } else {
                $slot = Ms3Journal::isOpening($r) ? 0 : 1;
            }
            if ($slot === null) {
                continue;
            }
            foreach ([[$effect['debit'], 1], [$effect['credit'], -1]] as [$code, $sign]) {
                $syn = AccountCode::synthetic($code);
                if ($syn === null) {
                    continue;
                }
                $out[$year][$syn] ??= [0.0, 0.0, 0.0];
                $out[$year][$syn][$slot] += $sign * $effect['amount'];
            }
        }
        foreach ($out as $year => $accounts) {
            foreach ($accounts as $syn => $v) {
                $out[$year][$syn] = [round($v[0], 2), round($v[1], 2), round($v[0] + $v[1], 2)];
            }
        }
        return $out;
    }

    /**
     * @param array<string,array{0:float,1:float,2:float}> $money
     * @return array<string,mixed>
     */
    private function reconcileYear(ImportContext $ctx, int $year, int $periodId, array $money): array
    {
        $tb = $this->trialBalance->build($ctx->supplierId, $periodId, null, null, false, false);
        $mine = TrialBalanceReconciliation::synthetic($tb['rows']);

        $journalDiffs = self::compare($mine, $money);
        $checks = TrialBalanceReconciliation::checks($tb, 'money_journal', $journalDiffs, count($money));

        $report = null;
        $reportPath = $ctx->options->moneyReports[$year] ?? null;
        if ($reportPath !== null && is_file($reportPath)) {
            $parsed = $this->reports->parse((string) file_get_contents($reportPath));
            $reportDiffs = self::compare($mine, $parsed['accounts']);
            $report = [
                'accounts' => count($parsed['accounts']),
                'skipped_lines' => $parsed['skipped'],
                'diffs' => $reportDiffs,
            ];
            $checks[] = ['key' => 'money_report', 'ok' => $reportDiffs === [] && $parsed['accounts'] !== []];
        }

        $documents = $this->documentsAgainstJournal($ctx, $year, $periodId);
        foreach ($documents as $d) {
            $checks[] = ['key' => 'documents_' . $d['key'], 'ok' => $d['ok']];
        }

        $balanceSheet = TrialBalanceReconciliation::balanceSheet($this->statements->balanceSheet($ctx->supplierId, $periodId, null, 'full'));
        $unmapped = $balanceSheet['unmapped'];
        $checks[] = $balanceSheet['check'];

        $ok = TrialBalanceReconciliation::allOk($checks);
        return [
            'year' => $year,
            'period_id' => $periodId,
            'ok' => $ok,
            'checks' => $checks,
            'totals' => $tb['totals'],
            'journal_diffs' => $journalDiffs,
            'money_report' => $report,
            'documents' => $documents,
            'unmapped_accounts' => $unmapped,
            'negative_net_rows' => $balanceSheet['negative_net_rows'],
        ];
    }

    /**
     * Doklady převedené z Money proti zápisům, na které jsou navázané.
     *
     * @return list<array{key:string,documents:float,journal:float,ok:bool,other_accounts?:int,source_differences?:list<array{document_no:string,difference:float}>}>
     */
    private function documentsAgainstJournal(ImportContext $ctx, int $year, int $periodId): array
    {
        $pdo = $this->db->pdo();
        $scalar = static function (string $sql, array $params) use ($pdo): float {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return round((float) $stmt->fetchColumn(), 2);
        };
        // Porovnávají se jen zápisy, které mají účet dokladu (321, 311, 211) na JEDNÉ straně —
        // vznik závazku nebo pohledávky. Money účtuje část faktur mimo něj (kupní smlouvy
        // přes 379, daňové doklady k zálohám jen přes 314/343) a u faktury placené kartou
        // nebo v hotovosti účtuje v jednom dokladu vznik i úhradu, takže účet vyjde nulový.
        // Takový doklad by na 321 chyběl celou částkou, i když je převedený správně; jejich
        // počet se vykazuje zvlášť (`other_accounts`).
        $oneSided = static fn (string $entry, string $supplier, string $prefix): string =>
            "(SELECT COUNT(DISTINCT l2.side) FROM journal_entry_lines l2 FORCE INDEX (ix_jel_closing_by_entry)
                JOIN chart_of_accounts a2 ON a2.id = l2.account_id AND a2.supplier_id = l2.supplier_id
               WHERE l2.supplier_id = {$supplier} AND l2.entry_id = {$entry} AND a2.account_code LIKE '{$prefix}%') = 1";
        // Banka se porovnává celá: pohyb je doklad sám o sobě a převod mezi vlastními účty
        // (221 na obou stranách) se v součtu pohybů vyruší stejně jako v deníku.
        // Navázaný ruční zápis (`manual`, zápočet interním dokladem) je vyrovnání, ne předpis.
        $ledger = static fn (string $docType, string $prefix, string $sign, bool $onlyOneSided = true): string =>
            "SELECT COALESCE(SUM({$sign}), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND e.source_type <> 'manual'
                AND a.account_code LIKE '{$prefix}%'
                " . ($onlyOneSided ? 'AND ' . $oneSided('e.id', 'l.supplier_id', $prefix) : '') . "
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN money_s3_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = k.doc_id AND m.kind = '{$docType}'
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id
                              AND k.doc_type = '" . ($docType === 'bank_transaction' ? 'bank' : ($docType === 'cash_document' ? 'cash' : $docType)) . "')";
        $docs = static fn (string $table, string $expr, string $kind, string $prefix, bool $onAccount = true): string =>
            "SELECT " . ($onAccount ? "COALESCE(SUM({$expr}), 0)" : 'COUNT(*)') . "
               FROM {$table} d
              WHERE d.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                            WHERE k.supplier_id = d.supplier_id AND k.doc_id = d.id AND e.period_id = ? AND e.source_type <> 'manual'
                              AND k.doc_type = '" . ($kind === 'bank_transaction' ? 'bank' : ($kind === 'cash_document' ? 'cash' : $kind)) . "'
                              AND " . ($onAccount ? '' : 'NOT ') . $oneSided('k.entry_id', 'k.supplier_id', $prefix) . ")
                AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = '{$kind}' AND m.target_id = d.id)";

        $spec = [
            // [klíč, tabulka, částka dokladu, druh v mapě, účet, znaménko řádku]
            ['purchase_invoices', 'purchase_invoices', self::documentTotal(), 'purchase_invoice', '321', "CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END"],
            ['issued_invoices', 'invoices', self::documentTotal(), 'invoice', '311', "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END"],
            // Znaménko pokladního dokladu nese doc_type (in = příjem na MD 211, out = výdej z 211).
            ['cash', 'cash_documents', "CASE WHEN d.doc_type = 'in' THEN d.total_amount ELSE -d.total_amount END", 'cash_document', '211', "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END"],
        ];
        $rows = [];
        $other = [];
        foreach ($spec as [$key, $table, $expr, $kind, $prefix, $sign]) {
            $rows[] = [$key,
                $scalar($docs($table, $expr, $kind, $prefix), [$ctx->supplierId, $periodId]),
                $scalar($ledger($kind, $prefix, $sign), [$ctx->supplierId, $periodId])];
            $other[$key] = (int) $scalar($docs($table, $expr, $kind, $prefix, false), [$ctx->supplierId, $periodId]);
        }
        // Jen korunové výpisy: pohyby účtu v cizí měně jsou v té měně, deník v Kč.
        $bankDocs = $pdo->prepare(
            "SELECT COALESCE(SUM(t.amount), 0)
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND s.currency = 'CZK'
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                            WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id AND e.period_id = ?)
                AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)"
        );
        $bankDocs->execute([$ctx->supplierId, $periodId]);
        $rows[] = ['bank',
            round((float) $bankDocs->fetchColumn(), 2),
            $scalar($ledger('bank_transaction', '221', "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END", false)
                . " AND EXISTS (SELECT 1 FROM journal_entry_document_links kc
                                  JOIN bank_transactions tc ON tc.id = kc.doc_id
                                  JOIN bank_statements sc ON sc.id = tc.statement_id
                                 WHERE kc.supplier_id = l.supplier_id AND kc.entry_id = e.id AND kc.doc_type = 'bank' AND sc.currency = 'CZK')",
                [$ctx->supplierId, $periodId])];

        $out = [];
        foreach ($rows as [$key, $documents, $journal]) {
            $row = TrialBalanceReconciliation::documentRow($key, $documents, $journal, $other[$key] ?? 0);
            $out[] = $row['ok'] ? $row : $this->explainBySource($ctx, $year, $periodId, $row);
        }
        return $out;
    }

    /**
     * Druh dokladu v kontrole dokladů proti deníku: tabulka, částka dokladu, druh v mapě,
     * typ vazby, účet a znaménko řádku (jako ve {@see documentsAgainstJournal()}).
     */
    private static function sources(): array
    {
        $total = self::documentTotal();
        return [
            'purchase_invoices' => ['purchase_invoices', $total, 'purchase_invoice', 'purchase_invoice', '321', true],
            'issued_invoices' => ['invoices', $total, 'invoice', 'invoice', '311', false],
            'cash' => ['cash_documents', "CASE WHEN d.doc_type = 'in' THEN d.total_amount ELSE -d.total_amount END", 'cash_document', 'cash', '211', false],
        ];
    }

    /** Celkem faktury v Kč - doklad převzatý v cizí měně přepočtený kurzem dokladu. */
    private static function documentTotal(): string
    {
        return ForeignCurrencyTakeover::homeAmountSql('d.total_with_vat', 'd.exchange_rate');
    }

    /**
     * Rozdíl dokladů proti deníku, který je už v samotném Money: doklad nese jinou částku než
     * jeho zápis v deníku Money (kurzový rozdíl zaúčtovaný mimo účet dokladu, dobropis v deníku
     * přepočtený jiným kurzem). MyÚčto obojí převzalo věrně — převod sedí a rozdíl se vypíše po
     * dokladech (`source_differences`). Vysvětlený je jen zápis, jehož rozdíl v MyÚčtu je na
     * haléř stejný jako rozdíl téhož dokladu v Money; cokoli jiného zůstává chybou.
     *
     * @param array{key:string,documents:float,journal:float,ok:bool,other_accounts:int} $row
     * @return array<string,mixed>
     */
    private function explainBySource(ImportContext $ctx, int $year, int $periodId, array $row): array
    {
        $entries = $this->entryDifferences($ctx->supplierId, $periodId, $row['key']);
        if ($entries === []) {
            return $row;
        }
        $money = $this->moneyDifferences($ctx, $year, $row['key'], array_column($entries, 'document_no'));
        if (!TrialBalanceReconciliation::explainedBySource($entries, $money, $row['documents'], $row['journal'])) {
            return $row;
        }
        $row['ok'] = true;
        $row['source_differences'] = array_map(static fn (array $e): array => ['document_no' => $e['document_no'], 'difference' => round($e['difference'], 2)], $entries);
        return $row;
    }

    /**
     * Zápisy období, u kterých převedené doklady nesedí na účet dokladu v zápisu.
     *
     * @return list<array{document_no:string,difference:float}>
     */
    private function entryDifferences(int $supplierId, int $periodId, string $key): array
    {
        if ($key === 'bank') {
            $linked = "FROM journal_entry_document_links k
                         JOIN bank_transactions t ON t.id = k.doc_id
                         JOIN bank_statements s ON s.id = t.statement_id
                        WHERE k.supplier_id = e.supplier_id AND k.entry_id = e.id AND k.doc_type = 'bank' AND s.currency = 'CZK'
                          AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = e.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)";
            $sql = "SELECT e.document_no,
                           (SELECT COALESCE(SUM(t.amount), 0) {$linked}) AS docs,
                           (SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
                              FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                             WHERE l.supplier_id = e.supplier_id AND l.entry_id = e.id AND a.account_code LIKE '221%') AS journal
                      FROM journal_entries e
                     WHERE e.supplier_id = ? AND e.period_id = ? AND EXISTS (SELECT 1 {$linked})";
        } else {
            $spec = self::sources()[$key] ?? null;
            if ($spec === null) {
                return [];
            }
            [$table, $expr, $kind, $docType, $prefix, $creditPositive] = $spec;
            $sign = $creditPositive ? "CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END" : "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END";
            $linked = "FROM journal_entry_document_links k
                         JOIN {$table} d ON d.id = k.doc_id AND d.supplier_id = k.supplier_id
                        WHERE k.supplier_id = e.supplier_id AND k.entry_id = e.id AND k.doc_type = '{$docType}'
                          AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = '{$kind}' AND m.target_id = d.id)";
            $sql = "SELECT e.document_no,
                           (SELECT COALESCE(SUM({$expr}), 0) {$linked}) AS docs,
                           (SELECT COALESCE(SUM({$sign}), 0)
                              FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                             WHERE l.supplier_id = e.supplier_id AND l.entry_id = e.id AND a.account_code LIKE '{$prefix}%') AS journal
                      FROM journal_entries e
                     WHERE e.supplier_id = ? AND e.period_id = ? AND e.source_type <> 'manual' AND EXISTS (SELECT 1 {$linked})
                       AND (SELECT COUNT(DISTINCT l2.side) FROM journal_entry_lines l2 FORCE INDEX (ix_jel_closing_by_entry)
                              JOIN chart_of_accounts a2 ON a2.id = l2.account_id AND a2.supplier_id = l2.supplier_id
                             WHERE l2.supplier_id = e.supplier_id AND l2.entry_id = e.id AND a2.account_code LIKE '{$prefix}%') = 1";
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $periodId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $diff = round((float) $r['docs'] - (float) $r['journal'], 2);
            if (!ReconciliationTolerance::isZeroCent($diff)) {
                $out[] = ['document_no' => trim((string) $r['document_no']), 'difference' => $diff];
            }
        }
        return $out;
    }

    /**
     * Rozdíl dokladu proti jeho zápisu přímo v Money (částka dokladu − účet dokladu v deníku),
     * po číslech dokladů roku.
     *
     * @param list<string> $docNos
     * @return array<string,float>
     */
    private function moneyDifferences(ImportContext $ctx, int $year, string $key, array $docNos): array
    {
        $wanted = array_flip(array_filter($docNos, static fn (string $n): bool => $n !== ''));
        if ($wanted === []) {
            return [];
        }
        [$prefix, $creditPositive] = $key === 'bank' ? ['221', false] : [self::sources()[$key][4], self::sources()[$key][5]];
        // Jen adresáře tohoto roku - řádky ostatních by podmínka roku stejně vyřadila.
        $dirs = array_map('strval', array_keys($ctx->dirYears, $year, true));
        $journal = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik', ['Zdroj', 'Doklad', 'Castka', 'UcMD', 'UcD'], $dirs) as $r) {
            $doc = trim((string) ($r['Doklad'] ?? ''));
            if (!isset($wanted[$doc]) || $ctx->yearOf($r) !== $year || Ms3Journal::isYearEndClosing($r) || Ms3Journal::isOpening($r)) {
                continue;
            }
            $effect = Ms3Journal::effect($r);
            if ($effect === null) {
                continue;
            }
            $net = (str_starts_with($effect['debit'], $prefix) ? $effect['amount'] : 0.0) - (str_starts_with($effect['credit'], $prefix) ? $effect['amount'] : 0.0);
            $journal[$doc] = ($journal[$doc] ?? 0.0) + ($creditPositive ? -$net : $net);
        }
        $amounts = [];
        $add = static function (string $doc, float $amount) use (&$amounts): void {
            $amounts[$doc] = ($amounts[$doc] ?? 0.0) + $amount;
        };
        $tables = match ($key) {
            'bank' => ['BankKnih'],
            'issued_invoices' => ['VFaktury', 'KnihPohl'],
            'purchase_invoices' => ['PFaktury'],
            'cash' => ['PoklKnih'],
            default => [],
        };
        foreach ($tables as $table) {
            foreach ($ctx->backup->rowsAcrossYears($table, null, $dirs) as $r) {
                $doc = trim((string) ($r['Doklad'] ?? ''));
                if (!isset($wanted[$doc]) || $ctx->yearOf($r) !== $year || !empty($r['FlagDel'])) {
                    continue;
                }
                if ($table === 'BankKnih') {
                    $currency = strtoupper(trim((string) ($r['Mena'] ?? '')));
                    if ($currency !== '' && !in_array($currency, ['CZK', 'KČ', 'KC'], true)) {
                        continue;
                    }
                    $add($doc, CashBankImporter::transactionAmount($r, 'CZK')[0]);
                } elseif ($table === 'PoklKnih') {
                    $celkem = (float) ($r['Celkem'] ?? 0);
                    $isOut = (((int) ($r['Vydej'] ?? 0)) === 1) !== ($celkem < 0);
                    $add($doc, ($isOut ? -1 : 1) * round(abs($celkem), 2));
                } else {
                    $add($doc, round((float) ($r[$table === 'KnihPohl' ? 'Celkem' : 'CelkemSDPH'] ?? 0), 2));
                }
            }
        }
        $out = [];
        foreach ($amounts as $doc => $amount) {
            $out[$doc] = round($amount - ($journal[$doc] ?? 0.0), 2);
        }
        return $out;
    }

    /**
     * Rozdíly MyÚčto × Money po účtech ({@see TrialBalanceReconciliation::compare()}).
     *
     * @param array<string,array{0:float,1:float,2:float}> $mine
     * @param array<string,array{0:float,1:float,2:float}> $theirs
     * @return list<array{account:string,myucto:array{0:float,1:float,2:float},money:array{0:float,1:float,2:float}}>
     */
    public static function compare(array $mine, array $theirs): array
    {
        return TrialBalanceReconciliation::compare($mine, $theirs);
    }
}
