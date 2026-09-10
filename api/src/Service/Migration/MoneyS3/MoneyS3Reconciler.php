<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;

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
    ) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $years = [];
        $moneyByYear = $this->moneyTrialBalances($ctx);
        foreach ($ctx->periods as $year => $period) {
            $result = $this->reconcileYear($ctx, $year, $period['id'], $moneyByYear[$year] ?? []);
            $years[] = $result;
            if (!$result['ok']) {
                $p->error(self::STEP, 'reconciliation_failed', "Rok {$year}: převod nesedí, podrobnosti v rekonciliaci.", ['year' => $year]);
            }
        }
        $p->set('reconciliation', $years);
        $p->finish(self::STEP);
    }

    /**
     * Předvaha přímo z deníku Money: po syntetických účtech netto PS (řádky `XP`),
     * obrat (ostatní řádky) a KS. Záporná částka se nepřepíná — netto účinek je
     * znaménkem vyjádřený stejně ({@see Ms3Journal::effect()}).
     *
     * @return array<int,array<string,array{0:float,1:float,2:float}>>
     */
    public function moneyTrialBalances(ImportContext $ctx): array
    {
        $out = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik') as $r) {
            $year = $ctx->yearOf($r);
            $effect = Ms3Journal::effect($r);
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
        $mine = [];
        foreach ($tb['rows'] as $row) {
            $syn = substr((string) $row['account_code'], 0, 3);
            $mine[$syn] ??= [0.0, 0.0, 0.0];
            $mine[$syn][0] += (float) $row['ps_md'] - (float) $row['ps_d'];
            $mine[$syn][1] += (float) $row['turnover_md'] - (float) $row['turnover_d'];
            $mine[$syn][2] += (float) $row['ks_md'] - (float) $row['ks_d'];
        }
        foreach ($mine as $syn => $v) {
            $mine[$syn] = [round($v[0], 2), round($v[1], 2), round($v[2], 2)];
        }

        $journalDiffs = self::compare($mine, $money);
        $checks = [
            ['key' => 'turnover_balanced', 'ok' => (bool) $tb['checks']['turnover_balanced']],
            ['key' => 'matches_journal', 'ok' => (bool) $tb['checks']['matches_journal']],
            ['key' => 'opening_balanced', 'ok' => (bool) $tb['checks']['opening_balanced']],
            ['key' => 'no_drafts', 'ok' => (int) $tb['draft_count'] === 0],
            ['key' => 'money_journal', 'ok' => $journalDiffs === [], 'accounts' => count($money)],
        ];

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

        $documents = $this->documentsAgainstJournal($ctx, $periodId);
        foreach ($documents as $d) {
            $checks[] = ['key' => 'documents_' . $d['key'], 'ok' => $d['ok']];
        }

        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }
        return [
            'year' => $year,
            'period_id' => $periodId,
            'ok' => $ok,
            'checks' => $checks,
            'totals' => $tb['totals'],
            'journal_diffs' => $journalDiffs,
            'money_report' => $report,
            'documents' => $documents,
        ];
    }

    /**
     * Doklady převedené z Money proti zápisům, na které jsou navázané.
     *
     * @return list<array{key:string,documents:float,journal:float,ok:bool}>
     */
    private function documentsAgainstJournal(ImportContext $ctx, int $periodId): array
    {
        $pdo = $this->db->pdo();
        $scalar = static function (string $sql, array $params) use ($pdo): float {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return round((float) $stmt->fetchColumn(), 2);
        };
        $ledger = static fn (string $docType, string $prefix, string $sign): string =>
            "SELECT COALESCE(SUM({$sign}), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ?
                AND a.account_code LIKE '{$prefix}%'
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN money_s3_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = k.doc_id AND m.kind = '{$docType}'
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id
                              AND k.doc_type = '" . ($docType === 'bank_transaction' ? 'bank' : ($docType === 'cash_document' ? 'cash' : $docType)) . "')";
        $docs = static fn (string $table, string $expr, string $kind): string =>
            "SELECT COALESCE(SUM({$expr}), 0)
               FROM {$table} d
              WHERE d.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                            WHERE k.supplier_id = d.supplier_id AND k.doc_id = d.id AND e.period_id = ?
                              AND k.doc_type = '" . ($kind === 'bank_transaction' ? 'bank' : ($kind === 'cash_document' ? 'cash' : $kind)) . "')
                AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = '{$kind}' AND m.target_id = d.id)";

        $rows = [
            ['purchase_invoices',
                $scalar($docs('purchase_invoices', 'd.total_with_vat', 'purchase_invoice'), [$ctx->supplierId, $periodId]),
                $scalar($ledger('purchase_invoice', '321', "CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END"), [$ctx->supplierId, $periodId])],
            ['issued_invoices',
                $scalar($docs('invoices', 'd.total_with_vat', 'invoice'), [$ctx->supplierId, $periodId]),
                $scalar($ledger('invoice', '311', "CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END"), [$ctx->supplierId, $periodId])],
            // Znaménko pokladního dokladu nese doc_type (in = příjem na MD 211, out = výdej z 211).
            ['cash',
                $scalar($docs('cash_documents', "CASE WHEN d.doc_type = 'in' THEN d.total_amount ELSE -d.total_amount END", 'cash_document'), [$ctx->supplierId, $periodId]),
                $scalar($ledger('cash_document', '211', "CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END"), [$ctx->supplierId, $periodId])],
        ];
        $bankDocs = $pdo->prepare(
            "SELECT COALESCE(SUM(t.amount), 0)
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                            WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id AND e.period_id = ?)
                AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)"
        );
        $bankDocs->execute([$ctx->supplierId, $periodId]);
        $rows[] = ['bank',
            round((float) $bankDocs->fetchColumn(), 2),
            $scalar($ledger('bank_transaction', '221', "CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END"), [$ctx->supplierId, $periodId])];

        $out = [];
        foreach ($rows as [$key, $documents, $journal]) {
            $out[] = ['key' => $key, 'documents' => $documents, 'journal' => $journal, 'ok' => abs($documents - $journal) < 0.005];
        }
        return $out;
    }

    /**
     * Rozdíly MyÚčto × Money po účtech (PS, obrat, KS). Účet bez pohybu na obou stranách
     * se nevypisuje.
     *
     * @param array<string,array{0:float,1:float,2:float}> $mine
     * @param array<string,array{0:float,1:float,2:float}> $theirs
     * @return list<array{account:string,myucto:array{0:float,1:float,2:float},money:array{0:float,1:float,2:float}}>
     */
    public static function compare(array $mine, array $theirs): array
    {
        $diffs = [];
        $codes = array_unique(array_merge(array_map('strval', array_keys($mine)), array_map('strval', array_keys($theirs))));
        sort($codes, SORT_STRING);
        foreach ($codes as $code) {
            $a = $mine[$code] ?? [0.0, 0.0, 0.0];
            $b = $theirs[$code] ?? [0.0, 0.0, 0.0];
            foreach ([0, 1, 2] as $i) {
                if (abs((float) $a[$i] - (float) $b[$i]) >= 0.005) {
                    $diffs[] = ['account' => (string) $code, 'myucto' => $a, 'money' => $b];
                    break;
                }
            }
        }
        return $diffs;
    }
}
