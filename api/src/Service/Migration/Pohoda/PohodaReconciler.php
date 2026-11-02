<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use MyInvoice\Service\Migration\Shared\ReconciliationVerdict;
use MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation;

/**
 * Rekonciliace převodu - důkaz, že MyÚčto po převodu ukazuje totéž co Pohoda:
 *   1. obratová předvaha MyÚčta ({@see TrialBalanceService}) proti předvaze spočtené
 *      přímo z deníku Pohody - po syntetických účtech, PS / obrat / KS, na haléř;
 *   2. vnitřní kontroly předvahy (obraty MD = D, předvaha = deník, vyrovnané PS);
 *   3. doklady proti deníku: přijaté faktury × 321, vydané × 311, pokladna × 211,
 *      banka × 221 - jen převedené doklady a zápisy, na které jsou navázané; rozdíl, který
 *      je už v deníku POHODY, se vypíše po dokladech a převod neshodí;
 *   4. vyrovnaná rozvaha bez účtů, které mapa výkazů nezná;
 *   5. saldokonto proti hlavní knize po obdobích převodu - jen upozornění mimo `checks`, rozdíl
 *      může být oprávněný (přeplatek bez dokladu) a stav převodu nemění.
 */
final class PohodaReconciler
{
    public const STEP = 'reconciliation';

    private const LABELS = ['purchase_invoices' => 'Přijaté faktury × 321', 'issued_invoices' => 'Vydané faktury × 311'];

    /**
     * Doklady POHODY, ze kterých vznikly doklady kontroly: agenda exportu, element dokladu,
     * zdroje zápisů v deníku, účet dokladu a zda účet roste na straně Dal.
     */
    private const SOURCES = [
        'issued_invoices' => [
            'invoice' => [['issued', 'invoice'], ['issued_debit', 'invoice'], ['issued_credit', 'invoice'], ['issued_corrective', 'invoice'], ['receivable', 'invoice'], ['internal', 'intDoc']],
            'journal' => [PohodaJournal::ISSUED, PohodaJournal::RECEIVABLE, PohodaJournal::INTERNAL],
            'table' => ['invoices', 'invoice', 'invoice', '311', false],
        ],
        'purchase_invoices' => [
            'invoice' => [['received', 'invoice'], ['received_debit', 'invoice'], ['received_credit', 'invoice'], ['received_corrective', 'invoice'], ['commitment', 'invoice'], ['internal', 'intDoc']],
            'journal' => [PohodaJournal::RECEIVED, PohodaJournal::COMMITMENT, PohodaJournal::INTERNAL],
            'table' => ['purchase_invoices', 'purchase_invoice', 'purchase_invoice', '321', true],
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly TrialBalanceService $trialBalance,
        private readonly FinancialStatementService $statements,
        private readonly SaldoService $saldo,
        private readonly PohodaSaldoPairing $pairing,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->period === null) {
            $p->finish(self::STEP);
            return;
        }
        $year = $ctx->year();
        $periodId = $ctx->period['id'];
        $periodIds = $ctx->periodIds();
        $pohoda = PohodaJournal::trialBalance($ctx->export, $ctx->skipsDate(...));
        // Úhrady, které převod zaúčtoval u pohybů bez zápisu v deníku POHODY, v deníku POHODY
        // nejsou - předvaha MyÚčta je proti ní má navíc a kontrola je přičítá k POHODĚ.
        $derived = $this->derivedTurnover($ctx->supplierId);
        foreach ($derived['accounts'] as $syn => $net) {
            $pohoda[$syn] ??= [0.0, 0.0, 0.0];
            $pohoda[$syn][1] = round($pohoda[$syn][1] + $net, 2);
            $pohoda[$syn][2] = round($pohoda[$syn][2] + $net, 2);
        }
        ksort($pohoda, SORT_STRING);

        // Deník agendy sahá i do období po roce agendy (doklady následujícího roku) - předvaha
        // se proto bere za celý rozsah, který převod zapsal, ne jen za rok agendy.
        $tb = $this->trialBalance->build($ctx->supplierId, $periodId, null, $ctx->lastPeriodEnd(), false, false);
        $mine = TrialBalanceReconciliation::synthetic($tb['rows']);
        $journalDiffs = TrialBalanceReconciliation::compare($mine, $pohoda);
        $checks = TrialBalanceReconciliation::checks($tb, 'pohoda_journal', $journalDiffs, count($pohoda));
        $documents = $this->documentsAgainstJournal($ctx->supplierId, $periodIds);
        foreach ($documents as $i => $d) {
            if (!$d['ok']) {
                $documents[$i] = $d = $this->explainBySource($ctx, $periodIds, $d);
            }
            if (isset($d['source_differences'])) {
                $p->info(self::STEP, 'source_difference', self::sourceDifferenceText($d), ['check' => $d['key'], 'documents' => array_column($d['source_differences'], 'document_no')]);
            }
            $checks[] = ['key' => 'documents_' . $d['key'], 'ok' => $d['ok']];
        }
        try {
            $balanceSheet = TrialBalanceReconciliation::balanceSheet($this->statements->balanceSheet($ctx->supplierId, $periodId, null, 'full'), $ctx->export->hasNonprofitChart());
        } catch (ReportException $e) {
            if (!$ctx->export->hasNonprofitChart()) {
                throw $e;
            }
            // Výkaz 504 je přísnější než mapa rozvahy 500: účet bez řádku výkazu ho nesestaví.
            $p->warn(self::STEP, 'nonprofit_statement_failed', "Rok {$year}: výkaz podle vyhlášky 504 nejde sestavit: {$e->getMessage()}", ['year' => $year]);
            $balanceSheet = ['check' => ['key' => 'balance_sheet_balanced', 'ok' => false], 'unmapped' => [], 'negative_net_rows' => []];
        }
        $unmapped = $balanceSheet['unmapped'];
        $checks[] = $balanceSheet['check'];

        $ok = TrialBalanceReconciliation::allOk($checks);
        if ($derived['entries'] > 0) {
            $p->info(self::STEP, 'derived_payments', "Předvaha obsahuje navíc {$derived['entries']} zápisů úhrad, které převod zaúčtoval u pohybů bez zápisu v deníku POHODY (a jejich storna); kontrola proti deníku POHODY s nimi počítá.", ['entries' => $derived['entries']]);
        }
        $p->set('reconciliation', [ReconciliationVerdict::apply($p, self::STEP, [
            'year' => $year,
            'period_id' => $periodId,
            'period_ids' => $periodIds,
            'derived_payments' => $derived,
            'ok' => $ok,
            'checks' => $checks,
            'totals' => $tb['totals'],
            'journal_diffs' => $journalDiffs,
            'documents' => $documents,
            'unmapped_accounts' => $unmapped,
            'negative_net_rows' => $balanceSheet['negative_net_rows'],
            'saldo' => $this->saldoAgainstLedger($ctx),
        ])]);
        $warning = TrialBalanceReconciliation::negativeNetWarning($year, $balanceSheet['negative_net_rows']);
        if ($warning !== null) {
            $p->warn(self::STEP, 'negative_net_rows', $warning, ['year' => $year, 'rows' => $balanceSheet['negative_net_rows']]);
        }
        $p->finish(self::STEP);
    }

    /**
     * Saldokonto proti hlavní knize za každé období převodu, k jeho konci, nejvýš však k poslednímu
     * zápisu v deníku období. Období bez zápisu se nekontroluje. Sestava jede jednou za období
     * (u velké firmy trvá sekundy) a bez cache: čte data ještě neuložené transakce. Její selhání
     * převod neshodí, jen se vypíše; rozdíl do koruny na účtu je zaokrouhlení.
     *
     * @return list<array{year:int,period_id:int,as_of:string,status:string,differences:list<array{account:string,name:string,gl_balance:float,open_items_total:float,difference:float}>,error?:string}>
     */
    private function saldoAgainstLedger(PohodaContext $ctx): array
    {
        $periods = array_filter($ctx->periods) ?: [$ctx->year() => $ctx->period];
        ksort($periods);
        $ids = implode(',', array_map(static fn (array $period): int => (int) $period['id'], $periods));
        $stmt = $this->db->pdo()->prepare(
            "SELECT period_id, MAX(entry_date) FROM journal_entries
              WHERE supplier_id = ? AND period_id IN ({$ids}) AND posted_at IS NOT NULL
              GROUP BY period_id"
        );
        $stmt->execute([$ctx->supplierId]);
        $lastEntry = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $out = [];
        foreach ($periods as $year => $period) {
            $periodId = (int) $period['id'];
            if (!isset($lastEntry[$periodId])) {
                continue;
            }
            $asOf = min((string) $period['ends_on'], substr((string) $lastEntry[$periodId], 0, 10));
            $row = ['year' => (int) $year, 'period_id' => $periodId, 'as_of' => $asOf, 'status' => 'ok', 'differences' => []];
            try {
                foreach ($this->saldo->build($ctx->supplierId, $periodId, $asOf)['accounts'] as $block) {
                    if (ReconciliationTolerance::isRounding((float) $block['difference'])) {
                        continue;
                    }
                    $difference = [
                        'account' => (string) $block['account']['code'],
                        'name' => (string) $block['account']['name'],
                        'gl_balance' => round((float) $block['gl_balance'], 2),
                        'open_items_total' => round((float) $block['open_items_total'], 2),
                        'difference' => round((float) $block['difference'], 2),
                    ];
                    $difference['by_symbol'] = $this->pairing->breakdown($ctx->supplierId, $difference['account'], $asOf);
                    $row['status'] = 'differences';
                    $row['differences'][] = $difference;
                    $ctx->protocol->warn(self::STEP, 'saldo_difference', self::saldoDifferenceText($row['year'], $asOf, $difference), ['year' => $row['year'], 'as_of' => $asOf] + $difference);
                }
            } catch (ReportException $e) {
                $row = self::saldoUnavailable($ctx, $row, $e->getMessage());
            } catch (\Throwable $e) {
                // Odvolanou transakci řeší vlastník kroku, ten po ní ve zbytku kroku nepokračuje.
                if (TransactionFailure::aborted($e)) {
                    throw $e;
                }
                error_log(sprintf('POHODA: kontrola saldokonta firmy %d k %s selhala: %s', $ctx->supplierId, $asOf, (string) $e));
                $row = self::saldoUnavailable($ctx, $row, 'Sestava skončila neočekávanou chybou, podrobnosti jsou v logu serveru.');
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array{year:int,period_id:int,as_of:string,status:string,differences:list<array<string,mixed>>} $row
     * @return array{year:int,period_id:int,as_of:string,status:string,differences:list<array<string,mixed>>,error:string}
     */
    private static function saldoUnavailable(PohodaContext $ctx, array $row, string $reason): array
    {
        $ctx->protocol->info(self::STEP, 'saldo_unavailable', sprintf(
            'Rok %d: saldokonto k %s nešlo proti hlavní knize porovnat, zkontrolujte ho v Účetnictví → Saldokonto. %s',
            $row['year'], self::saldoDay($row['as_of']), $reason,
        ), ['year' => $row['year'], 'as_of' => $row['as_of']]);
        return ['status' => 'unavailable', 'differences' => [], 'error' => $reason] + $row;
    }

    /** @param array{account:string,name:string,gl_balance:float,open_items_total:float,difference:float} $d */
    private static function saldoDifferenceText(int $year, string $asOf, array $d): string
    {
        $money = static fn (float $v): string => number_format($v, 2, ',', ' ');
        return sprintf(
            'Rok %d, saldokonto k %s, účet %s %s: zůstatek hlavní knihy %s Kč, součet položek %s Kč, rozdíl %s Kč. Může být oprávněný (například přeplatek bez dokladu), zkontrolujte ho.%s',
            $year, self::saldoDay($asOf), $d['account'], $d['name'], $money($d['gl_balance']), $money($d['open_items_total']), $money($d['difference']),
            PohodaSaldoPairing::describe($d['by_symbol'] ?? null),
        );
    }

    private static function saldoDay(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }

    /**
     * Doklady proti zápisům, na které jsou navázané. Porovnávají se jen zápisy, které mají
     * účet dokladu na JEDNÉ straně (vznik závazku nebo pohledávky); doklad účtovaný jinak
     * (zápočet, úhrada v témže zápisu) se počítá zvlášť (`other_accounts`).
     *
     * @return list<array{key:string,documents:float,journal:float,ok:bool,other_accounts:int}>
     */
    /**
     * Čistý obrat (MD − D) zápisů úhrad, které převod odvodil u pohybů bez zápisu v deníku
     * POHODY ({@see UnbookedBankPayments}), po syntetických účtech - včetně storen.
     *
     * @return array{entries:int,accounts:array<string,float>}
     */
    private function derivedTurnover(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT LEFT(a.account_code, 3) AS syn, COUNT(DISTINCT l.entry_id) AS entries,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS net
               FROM pohoda_import_map m
               JOIN journal_entry_lines l ON l.supplier_id = m.supplier_id AND l.entry_id = m.target_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE m.supplier_id = ? AND m.kind = ?
              GROUP BY LEFT(a.account_code, 3)"
        );
        $stmt->execute([$supplierId, \MyInvoice\Repository\PohodaImportRepository::KIND_DERIVED_ENTRY]);
        $accounts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $accounts[(string) $r['syn']] = round((float) $r['net'], 2);
        }
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM pohoda_import_map WHERE supplier_id = ? AND kind = ?');
        $count->execute([$supplierId, \MyInvoice\Repository\PohodaImportRepository::KIND_DERIVED_ENTRY]);
        return ['entries' => (int) $count->fetchColumn(), 'accounts' => $accounts];
    }

    /** @param list<int> $periodIds */
    private function documentsAgainstJournal(int $supplierId, array $periodIds): array
    {
        $pdo = $this->db->pdo();
        $in = implode(',', array_map('intval', $periodIds)) ?: '0';
        $scalar = static function (string $sql, array $params) use ($pdo): float {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return round((float) $stmt->fetchColumn(), 2);
        };
        // Předpis dokladu převod vždy přetypuje na typ dokladu. Navázaný ruční zápis
        // (`manual`) je vyrovnání - zápočet interním dokladem - a do předpisů nepatří.
        $oneSided = static fn (string $entry, string $prefix): string =>
            "(SELECT COUNT(DISTINCT l2.side) FROM journal_entry_lines l2 FORCE INDEX (ix_jel_closing_by_entry)
                JOIN chart_of_accounts a2 ON a2.id = l2.account_id AND a2.supplier_id = l2.supplier_id
               WHERE l2.supplier_id = l.supplier_id AND l2.entry_id = {$entry} AND a2.account_code LIKE '{$prefix}%') = 1";
        $ledger = static fn (string $kind, string $docType, string $prefix, string $sign): string =>
            "SELECT COALESCE(SUM({$sign}), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id IN ({$in}) AND a.account_code LIKE '{$prefix}%' AND e.source_type NOT IN ('opening', 'manual')
                AND " . $oneSided('e.id', $prefix) . "
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN pohoda_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = k.doc_id AND m.kind = '{$kind}'
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id AND k.doc_type = '{$docType}')";
        $docs = static fn (string $table, string $expr, string $kind, string $docType, string $prefix, bool $onAccount): string =>
            'SELECT ' . ($onAccount ? "COALESCE(SUM({$expr}), 0)" : 'COUNT(*)') . "
               FROM {$table} d
              WHERE d.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                             JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                            WHERE k.supplier_id = d.supplier_id AND k.doc_id = d.id AND e.period_id IN ({$in}) AND k.doc_type = '{$docType}'
                              AND e.source_type NOT IN ('opening', 'manual')
                              AND " . ($onAccount ? '' : 'NOT ') . $oneSided('k.entry_id', $prefix) . ")
                AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = '{$kind}' AND m.target_id = d.id)";

        // Doklad převzatý v cizí měně se s deníkem v Kč porovná přepočtený kurzem dokladu.
        $total = ForeignCurrencyTakeover::homeAmountSql('d.total_with_vat', 'd.exchange_rate');
        $spec = [
            ['purchase_invoices', 'purchase_invoices', $total, 'purchase_invoice', 'purchase_invoice', '321', "CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END"],
            ['issued_invoices', 'invoices', $total, 'invoice', 'invoice', '311', "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END"],
            ['cash', 'cash_documents', "CASE WHEN d.doc_type = 'in' THEN d.total_amount ELSE -d.total_amount END", 'cash_document', 'cash', '211', "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END"],
        ];
        $out = [];
        foreach ($spec as [$key, $table, $expr, $kind, $docType, $prefix, $sign]) {
            $documents = $scalar($docs($table, $expr, $kind, $docType, $prefix, true), [$supplierId]);
            $journal = $scalar($ledger($kind, $docType, $prefix, $sign), [$supplierId]);
            $out[] = TrialBalanceReconciliation::documentRow($key, $documents, $journal, (int) $scalar($docs($table, $expr, $kind, $docType, $prefix, false), [$supplierId]));
        }
        $bankDocs = $scalar(
            "SELECT COALESCE(SUM(t.amount), 0) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                             WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id AND e.period_id IN ({$in}))
                AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)",
            [$supplierId]
        );
        $bankJournal = $scalar(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id IN ({$in}) AND a.account_code LIKE '221%'
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN pohoda_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = k.doc_id AND m.kind = 'bank_transaction'
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id AND k.doc_type = 'bank')",
            [$supplierId]
        );
        $out[] = TrialBalanceReconciliation::documentRow('bank', $bankDocs, $bankJournal, 0);
        return $out;
    }

    /**
     * Rozdíl dokladů proti deníku, který je už v samotné POHODĚ: doklad zní na jinou částku,
     * než kolik jeho zápis v deníku POHODY dá na účet dokladu. Typicky odpočet nedaňové
     * zálohy, který POHODA zaúčtovala kladně na stranu MD 311 (311/602) místo proti ní - doklad
     * je uhrazený, ale 311 z něj v deníku drží dvojnásobek. MyÚčto obojí převzalo věrně
     * (předvaha sedí na deník POHODY), převod tedy sedí a rozdíl se vypíše po dokladech
     * (`source_differences`). Vysvětlený je jen doklad, jehož rozdíl v MyÚčtu je na haléř
     * stejný jako rozdíl téhož dokladu v POHODĚ, a jen když tyto doklady vysvětlí celý
     * rozdíl kontroly; cokoli jiného zůstává chybou převodu.
     *
     * @param array{key:string,documents:float,journal:float,ok:bool,other_accounts:int} $row
     * @return array<string,mixed>
     */
    private function explainBySource(PohodaContext $ctx, array $periodIds, array $row): array
    {
        $spec = self::SOURCES[$row['key']] ?? null;
        if ($spec === null) {
            return $row;
        }
        $mine = $this->entryDifferences($ctx->supplierId, $periodIds, $spec['table']);
        if ($mine === []) {
            return $row;
        }
        $pohoda = self::pohodaDifferences($ctx->export, $spec, array_keys($mine));
        $entries = [];
        foreach ($mine as $docNo => $difference) {
            $entries[] = ['document_no' => (string) $docNo, 'difference' => $difference];
        }
        if (!TrialBalanceReconciliation::explainedBySource($entries, array_map(static fn (array $s): float => $s['difference'], $pohoda), $row['documents'], $row['journal'])) {
            return $row;
        }
        $explained = [];
        foreach ($entries as $e) {
            $advance = $pohoda[$e['document_no']]['advance'];
            $explained[] = $e + [
                'reason' => !ReconciliationTolerance::isZeroCent($advance) && ReconciliationTolerance::sameCent($advance, $e['difference']) ? 'advance_deduction' : 'amount',
            ];
        }
        $row['ok'] = true;
        $row['source_differences'] = $explained;
        return $row;
    }

    /** @param array{key:string,documents:float,journal:float,source_differences:list<array{document_no:string,difference:float,reason:string}>} $row */
    private static function sourceDifferenceText(array $row): string
    {
        $money = static fn (float $v): string => number_format($v, 2, ',', ' ');
        $list = static function (array $rows) use ($money): string {
            $shown = array_map(static fn (array $s): string => $s['document_no'] . ' (' . $money($s['difference']) . ')', array_slice($rows, 0, 30));
            return implode(', ', $shown) . (count($rows) > 30 ? ' a další' : '');
        };
        $account = self::SOURCES[$row['key']]['table'][3] ?? '';
        $advance = array_values(array_filter($row['source_differences'], static fn (array $s): bool => $s['reason'] === 'advance_deduction'));
        $amount = array_values(array_filter($row['source_differences'], static fn (array $s): bool => $s['reason'] !== 'advance_deduction'));
        $text = sprintf('%s: rozdíl dokladů proti deníku %s Kč je už v deníku POHODY, převod ho převzal beze změny.',
            self::LABELS[$row['key']] ?? $row['key'], $money(round($row['documents'] - $row['journal'], 2)));
        if ($advance !== []) {
            $text .= sprintf(' U %d dokladů je odpočet nedaňové zálohy zaúčtovaný kladně na stranu MD účtu %s, takže účet v deníku drží navíc částku zálohy: %s.',
                count($advance), $account, $list($advance));
        }
        if ($amount !== []) {
            $text .= sprintf(' U %d dokladů se částka dokladu liší od jeho zápisu na účtu %s: %s.', count($amount), $account, $list($amount));
        }
        return $text . ' Saldo těchto dokladů na účtu ' . $account . ' zkontrolujte a případně opravte interním dokladem.';
    }

    /**
     * Rozdíl převedených dokladů proti účtu dokladu v zápisech, na které jsou navázané,
     * po číslech dokladů (jen zápisy, které mají účet dokladu na jedné straně - jako kontrola).
     *
     * @param array{0:string,1:string,2:string,3:string,4:bool} $table
     * @return array<string,float>
     */
    private function entryDifferences(int $supplierId, array $periodIds, array $table): array
    {
        [$docTable, $kind, $docType, $prefix, $creditPositive] = $table;
        $sign = $creditPositive ? "CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END" : "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END";
        $linked = "FROM journal_entry_document_links k
                     JOIN {$docTable} d ON d.id = k.doc_id AND d.supplier_id = k.supplier_id
                    WHERE k.supplier_id = e.supplier_id AND k.entry_id = e.id AND k.doc_type = '{$docType}'
                      AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = '{$kind}' AND m.target_id = d.id)";
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.document_no,
                    (SELECT COALESCE(SUM(" . ForeignCurrencyTakeover::homeAmountSql('d.total_with_vat', 'd.exchange_rate') . "), 0) {$linked}) AS docs,
                    (SELECT COALESCE(SUM({$sign}), 0)
                       FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                      WHERE l.supplier_id = e.supplier_id AND l.entry_id = e.id AND a.account_code LIKE '{$prefix}%') AS journal
               FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id IN (" . (implode(',', array_map('intval', $periodIds)) ?: '0') . ") AND e.source_type NOT IN ('opening', 'manual') AND EXISTS (SELECT 1 {$linked})
                AND (SELECT COUNT(DISTINCT l2.side) FROM journal_entry_lines l2 FORCE INDEX (ix_jel_closing_by_entry)
                       JOIN chart_of_accounts a2 ON a2.id = l2.account_id AND a2.supplier_id = l2.supplier_id
                      WHERE l2.supplier_id = e.supplier_id AND l2.entry_id = e.id AND a2.account_code LIKE '{$prefix}%') = 1"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $docNo = trim((string) $r['document_no']);
            $out[$docNo] = round(($out[$docNo] ?? 0.0) + (float) $r['docs'] - (float) $r['journal'], 2);
        }
        return array_filter($out, static fn (float $d): bool => !ReconciliationTolerance::isZeroCent($d));
    }

    /**
     * Rozdíl dokladu proti jeho zápisu přímo v POHODĚ (částka dokladu tak, jak ji převod
     * převezme, − účet dokladu v deníku POHODY) a odpočet nedaňových záloh dokladu.
     *
     * @param array{invoice:list<array{0:string,1:string}>,journal:list<string>,table:array{0:string,1:string,2:string,3:string,4:bool}} $spec
     * @param list<string|int> $docNos
     * @return array<string,array{difference:float,advance:float}>
     */
    private static function pohodaDifferences(PohodaExport $export, array $spec, array $docNos): array
    {
        $wanted = array_flip(array_map('strval', $docNos));
        [, , , $prefix, $creditPositive] = $spec['table'];
        $amounts = [];
        $advances = [];
        foreach ($spec['invoice'] as [$agenda, $tag]) {
            foreach ($export->records($agenda, $tag) as $r) {
                $docNo = PohodaXml::text(PohodaXml::get($r, $tag . 'Header') ?? [], 'number/numberRequested');
                if (!isset($wanted[$docNo])) {
                    continue;
                }
                [$total, $advance] = self::documentTotal($r, $tag);
                $amounts[$docNo] = ($amounts[$docNo] ?? 0.0) + $total;
                $advances[$docNo] = ($advances[$docNo] ?? 0.0) + $advance;
            }
        }
        $journal = [];
        foreach ($export->records('journal', 'accountingItem') as $item) {
            $docNo = PohodaJournal::number($item);
            if (!isset($amounts[$docNo]) || !in_array(PohodaJournal::source($item), $spec['journal'], true) || PohodaJournal::isYearEndClosing($item)) {
                continue;
            }
            $effect = PohodaJournal::effect($item);
            if ($effect === null) {
                continue;
            }
            $net = (str_starts_with($effect['debit'], $prefix) ? $effect['amount'] : 0.0) - (str_starts_with($effect['credit'], $prefix) ? $effect['amount'] : 0.0);
            $journal[$docNo] = ($journal[$docNo] ?? 0.0) + ($creditPositive ? -$net : $net);
        }
        $out = [];
        foreach ($amounts as $docNo => $amount) {
            $out[(string) $docNo] = ['difference' => round($amount - ($journal[$docNo] ?? 0.0), 2), 'advance' => round($advances[$docNo], 2)];
        }
        return $out;
    }

    /**
     * Celkem dokladu tak, jak ho převod převezme ({@see InvoiceImporter}): rekapitulace po
     * sazbách se zaokrouhlením a zdaněné odpočty záloh; nedaňové odpočty jsou jen úhrada.
     *
     * @return array{0:float,1:float} celkem, součet nedaňových odpočtů záloh (záporný)
     */
    private static function documentTotal(array $r, string $tag): array
    {
        $summary = PohodaXml::get($r, $tag . 'Summary/homeCurrency') ?? [];
        $total = PohodaXml::num($summary, 'priceNone') + PohodaXml::num($summary, 'round/priceRound');
        foreach (['Low', 'High', '3'] as $bucket) {
            $total += PohodaXml::num($summary, 'price' . $bucket) + PohodaXml::num($summary, 'price' . $bucket . 'VAT');
        }
        $advance = 0.0;
        foreach (PohodaXml::all($r, $tag . 'Detail/invoiceAdvancePaymentItem') as $a) {
            if (!ReconciliationTolerance::isZeroCent(round(PohodaXml::num($a, 'homeCurrency/priceVAT'), 2))) {
                $total += PohodaXml::num($a, 'homeCurrency/priceSum');
            } else {
                $advance += PohodaXml::num($a, 'homeCurrency/priceSum');
            }
        }
        return [round($total, 2), round($advance, 2)];
    }
}
