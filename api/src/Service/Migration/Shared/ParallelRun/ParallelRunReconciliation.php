<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Accounting\Reports\AssetInventoryReportService;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\StatementFrameworkResolver;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Kontrola souběhu se starým účetním programem za jeden měsíc — kritéria K1 a K5–K13.
 *
 * Strana MyÚčta se bere ze sestav, které vidí účetní v aplikaci, žádné vlastní výpočty:
 *   K1  obratová předvaha k poslednímu dni měsíce ({@see TrialBalanceService}, po syntetikách
 *       jako rekonciliace převodu),
 *   K5  počty dokladů měsíce po knihách,
 *   K6/K7 saldokonto k poslednímu dni měsíce ({@see SaldoService}),
 *   K8/K10 poslední výpis vlastního účtu a zůstatek jeho analytiky 221 z předvahy,
 *   K9  přiznání k DPH a kontrolní hlášení sestavené týmiž buildery jako podání
 *       ({@see DphPriznaniBuilder}, {@see KontrolniHlaseniBuilder} — evidence DPH jde přes
 *       VatLedgerService, nic se tu nesčítá znovu) a přečtené stejně jako XML zdroje,
 *   K11 inventurní soupis majetku ({@see AssetInventoryReportService}),
 *   K12 výsledovka po střediscích za měsíc ({@see DimensionProfitService}),
 *   K13 rozvaha a výsledovka k poslednímu dni měsíce ({@see FinancialStatementService}).
 *
 * Kritérium, ke kterému zdroj nedodal výstup, se nekontroluje (není ve výsledku).
 * Chyba jednoho kritéria (třeba firma není plátce DPH) nezastaví ostatní.
 */
final class ParallelRunReconciliation
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly TrialBalanceService $trialBalance,
        private readonly SaldoService $saldo,
        private readonly DphPriznaniBuilder $vatReturns,
        private readonly KontrolniHlaseniBuilder $controlStatements,
        private readonly AssetInventoryReportService $assets,
        private readonly DimensionProfitService $dimensionProfit,
        private readonly FinancialStatementService $statements,
        private readonly StatementFrameworkResolver $frameworks,
        private readonly LoggerInterface $log,
        private readonly EpoVatFilingReader $epo = new EpoVatFilingReader(),
    ) {}

    /**
     * @return array{month:string,period:array{id:int,fiscal_year:int,starts_on:string,ends_on:string},as_of:string,source:string,status:string,criteria:list<array<string,mixed>>,warnings:list<string>,inputs:list<array<string,mixed>>}
     */
    public function run(int $supplierId, int $year, int $month, SourceSnapshot $source): array
    {
        if ($month < 1 || $month > 12) {
            throw new ParallelRunException('month_invalid', 'Měsíc musí být 1 až 12.');
        }
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', (int) strtotime($monthStart));
        $period = $this->periods->findForDate($supplierId, $monthEnd);
        if ($period === null) {
            throw new ParallelRunException('period_missing', "Pro {$month}/{$year} není založené účetní období.", ['month' => $month, 'year' => $year]);
        }
        $periodId = (int) $period['id'];

        $criteria = [];
        $run = function (string $key, callable $fn) use (&$criteria): void {
            try {
                foreach ($fn() as $result) {
                    $criteria[] = $result;
                }
            } catch (\Throwable $e) {
                if (!$e instanceof ParallelRunException && !$e instanceof \MyInvoice\Service\Accounting\PostingException && !$e instanceof \MyInvoice\Service\Accounting\Reports\ReportException) {
                    $this->log->error('Kontrola souběhu ' . $key . ': ' . $e->getMessage(), ['exception' => $e]);
                }
                $criteria[] = ParallelRunComparator::error($key, $e->getMessage());
            }
        };

        if ($source->trialBalance !== null) {
            $run('K1', fn (): array => [ParallelRunComparator::trialBalance(
                TrialBalanceReconciliation::synthetic($this->trialBalance->build($supplierId, $periodId, null, $monthEnd)['rows']),
                $source->trialBalance,
            )]);
        }
        if ($source->documentCounts !== null) {
            $run('K5', fn (): array => [ParallelRunComparator::documentCounts($this->documentCounts($supplierId, $monthStart, $monthEnd), $source->documentCounts)]);
        }
        if ($source->saldo !== null) {
            $saldo = $source->saldo;
            $run('K6', fn (): array => ParallelRunComparator::saldo($this->openItems($supplierId, $periodId, $monthEnd), $saldo));
        }
        if ($source->bankBalances !== null) {
            $banks = $source->bankBalances;
            $run('K8', fn (): array => ParallelRunComparator::bank($this->bankAccounts($supplierId, $periodId, $monthEnd), $banks));
        }
        if ($source->vatReturn !== null || $source->controlStatement !== null) {
            $run('K9', fn (): array => [$this->vat($supplierId, $year, $month, $source)]);
        }
        if ($source->assets !== null) {
            $assets = $source->assets;
            $run('K11', fn (): array => [ParallelRunComparator::assets($this->assets->build($supplierId, $periodId)['rows'], $assets)]);
        }
        if ($source->costCenters !== null) {
            $centers = $source->costCenters;
            $run('K12', fn (): array => [ParallelRunComparator::costCenters($this->costCenters($supplierId, $monthStart, $monthEnd), $centers)]);
        }
        if ($source->balanceSheet !== null || $source->incomeStatement !== null) {
            $run('K13', fn (): array => [$this->statementsCriterion($supplierId, $periodId, $monthEnd, $source)]);
        }

        usort($criteria, static fn (array $a, array $b): int => (int) substr((string) $a['key'], 1) <=> (int) substr((string) $b['key'], 1));

        return [
            'month' => sprintf('%04d-%02d', $year, $month),
            'period' => [
                'id' => $periodId,
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on' => (string) $period['starts_on'],
                'ends_on' => (string) $period['ends_on'],
            ],
            'as_of' => $monthEnd,
            'source' => $source->source,
            'status' => self::status($criteria),
            'criteria' => $criteria,
            'warnings' => $source->warnings,
            'inputs' => $source->inputs,
        ];
    }

    /** @param list<array<string,mixed>> $criteria */
    public static function status(array $criteria): string
    {
        if ($criteria === []) {
            return 'incomplete';
        }
        $statuses = array_column($criteria, 'status');
        if (in_array('differences', $statuses, true)) {
            return 'differences';
        }
        return in_array('error', $statuses, true) ? 'incomplete' : 'ok';
    }

    /**
     * Počty dokladů měsíce po knihách. Faktury a pokladna podle data vystavení (bez konceptů),
     * banka podle data pohybu, interní doklady = ruční zápisy deníku podle data zápisu.
     *
     * @return array<string,int>
     */
    private function documentCounts(int $supplierId, string $from, string $to): array
    {
        $pdo = $this->db->pdo();
        $count = static function (string $sql) use ($pdo, $supplierId, $from, $to): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId, $from, $to]);
            return (int) $stmt->fetchColumn();
        };
        return [
            'issued_invoices' => $count("SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND status <> 'draft' AND issue_date BETWEEN ? AND ?"),
            'purchase_invoices' => $count("SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ? AND status <> 'draft' AND issue_date BETWEEN ? AND ?"),
            'cash' => $count("SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ? AND status <> 'draft' AND issue_date BETWEEN ? AND ?"),
            'bank' => $count('SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                               WHERE s.supplier_id = ? AND DATE(t.posted_at) BETWEEN ? AND ?'),
            'internal' => $count("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual' AND posted_at IS NOT NULL AND entry_date BETWEEN ? AND ?"),
        ];
    }

    /**
     * Otevřené položky saldokonta k poslednímu dni měsíce, kladně na normální straně účtu.
     *
     * @return list<array{account:string,doc_no:string,alt_doc_no:?string,doc_type:string,doc_id:int,partner:string,remaining:float}>
     */
    private function openItems(int $supplierId, int $periodId, string $asOf): array
    {
        $out = [];
        $purchaseIds = [];
        foreach ($this->saldo->build($supplierId, $periodId, $asOf)['accounts'] as $block) {
            $code = (string) $block['account']['code'];
            foreach ($block['partners'] as $partner) {
                foreach ($partner['items'] as $item) {
                    $out[] = [
                        'account' => substr($code, 0, 3),
                        'doc_no' => (string) $item['doc_no'],
                        'alt_doc_no' => null,
                        'doc_type' => (string) $item['doc_type'],
                        'doc_id' => (int) $item['doc_id'],
                        'partner' => (string) $partner['partner_name'],
                        'remaining' => round((float) $item['remaining_czk'], 2),
                    ];
                    if ($item['doc_type'] === 'purchase_invoice') {
                        $purchaseIds[(int) $item['doc_id']] = true;
                    }
                }
            }
        }
        if ($purchaseIds !== []) {
            $ids = array_keys($purchaseIds);
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, varsymbol FROM purchase_invoices WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $stmt->execute(array_merge([$supplierId], $ids));
            $own = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $own[(int) $r['id']] = (string) ($r['varsymbol'] ?? '');
            }
            foreach ($out as $i => $item) {
                if ($item['doc_type'] === 'purchase_invoice' && ($own[$item['doc_id']] ?? '') !== '') {
                    $out[$i]['alt_doc_no'] = $own[$item['doc_id']];
                }
            }
        }
        return $out;
    }

    /**
     * Vlastní bankovní účty: analytika 221 a její zůstatek z předvahy, poslední výpis do konce
     * měsíce (zůstatek v měně účtu). Úvěrový účet kreditní karty se sem nepočítá: jeho dluh
     * leží na 231 a s 221 by se srovnával chybně (a jako „jediný účet" by shodil srovnání
     * běžného účtu se syntetickým 221).
     *
     * @return list<array{key:string,numbers:list<string>,label:string,currency:string,ledger_code:?string,ledger_balance:?float,statement_balance:?float,statement_date:?string}>
     */
    private function bankAccounts(int $supplierId, int $periodId, string $asOf): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, label, account_number, bank_code, iban, currency, analytic_suffix
               FROM supplier_bank_accounts
              WHERE supplier_id = ? AND is_active = 1 AND kind <> \'credit_card\'
              ORDER BY id'
        );
        $stmt->execute([$supplierId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($accounts === []) {
            return [];
        }

        $ledger = [];
        foreach ($this->trialBalance->build($supplierId, $periodId, null, $asOf, true)['rows'] as $row) {
            $ledger[(string) $row['account_code']] = round((float) $row['ks_md'] - (float) $row['ks_d'], 2);
        }

        $last = $pdo->prepare(
            'SELECT account_number, bank_code, curr_balance, statement_date
               FROM (SELECT account_number, bank_code, curr_balance, statement_date,
                            ROW_NUMBER() OVER (PARTITION BY account_number, bank_code ORDER BY statement_date DESC, id DESC) AS rn
                       FROM bank_statements
                      WHERE supplier_id = ? AND statement_date <= ?) s
              WHERE rn = 1'
        );
        $last->execute([$supplierId, $asOf]);
        $statements = [];
        foreach ($last->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $statements[ParallelRunComparator::normalizeAccount((string) $s['account_number'] . '/' . (string) $s['bank_code'])] = $s;
        }

        $single = count($accounts) === 1;
        $out = [];
        foreach ($accounts as $a) {
            $number = trim((string) $a['account_number']);
            $bank = trim((string) ($a['bank_code'] ?? ''));
            $full = $number !== '' && $bank !== '' ? $number . '/' . $bank : $number;
            $suffix = $a['analytic_suffix'] ?? null;
            $code = BankAnalyticAssigner::isValidSuffix($suffix) ? BankAnalyticAssigner::codeFor((string) $suffix) : ($single ? BankAnalyticAssigner::BANK_SYNTHETIC : null);
            $balance = null;
            if ($code !== null) {
                $balance = $ledger[$code] ?? ($code === BankAnalyticAssigner::BANK_SYNTHETIC ? self::syntheticBalance($ledger, $code) : 0.0);
            }
            $statement = $statements[ParallelRunComparator::normalizeAccount($full)] ?? null;
            $out[] = [
                'key' => (string) $a['id'],
                'numbers' => array_values(array_filter([$full, $number, (string) ($a['iban'] ?? '')], static fn (string $n): bool => $n !== '')),
                'label' => trim((string) ($a['label'] ?? '')) !== '' ? (string) $a['label'] : $full,
                'currency' => strtoupper((string) ($a['currency'] ?? 'CZK')) ?: 'CZK',
                'ledger_code' => $code,
                'ledger_balance' => $balance,
                'statement_balance' => $statement === null ? null : round((float) $statement['curr_balance'], 2),
                'statement_date' => $statement === null ? null : (string) $statement['statement_date'],
            ];
        }
        return $out;
    }

    /** @param array<string,float> $ledger */
    private static function syntheticBalance(array $ledger, string $code): float
    {
        $sum = 0.0;
        foreach ($ledger as $account => $balance) {
            if (str_starts_with((string) $account, $code)) {
                $sum += $balance;
            }
        }
        return round($sum, 2);
    }

    /** K9 — přiznání k DPH a kontrolní hlášení v jednom kritériu. */
    private function vat(int $supplierId, int $year, int $month, SourceSnapshot $source): array
    {
        $diffs = [];
        $summary = [];
        if ($source->vatReturn !== null) {
            $built = $this->vatReturns->build($supplierId, $year, $month);
            $mine = $this->epo->read($built['xml'], 'dphdp3')['values'];
            $vat = ParallelRunComparator::vatReturn($mine, $source->vatReturn);
            $diffs = array_merge($diffs, $vat['differences']);
            $summary['vat_return'] = $vat['summary'] + ['differences' => $vat['difference_count'], 'period' => $built['summary']['period'] ?? null];
        }
        if ($source->controlStatement !== null) {
            $built = $this->controlStatements->build($supplierId, $year, $month);
            $mine = ExportInputParser::controlStatement($this->epo->read($built['xml'], 'dphkh1'));
            $kh = ParallelRunComparator::controlStatement($mine, $source->controlStatement, fn (string $section, string $document): ?array => $this->resolveDocument($supplierId, $section, $document));
            $diffs = array_merge($diffs, $kh);
            $summary['control_statement'] = ['rows_myucto' => count($mine['rows']), 'rows_source' => count($source->controlStatement['rows']), 'differences' => count($kh)];
        }
        return ParallelRunComparator::result('K9', ParallelRunComparator::TOLERANCE_CROWN, $diffs, $summary);
    }

    /** @return array{type:string,id:int}|null */
    private function resolveDocument(int $supplierId, string $section, string $document): ?array
    {
        [$table, $column, $type] = str_starts_with($section, 'A')
            ? ['invoices', 'varsymbol', 'invoice']
            : ['purchase_invoices', 'vendor_invoice_number', 'purchase_invoice'];
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND {$column} = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$supplierId, $document]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : ['type' => $type, 'id' => (int) $id];
    }

    /**
     * Obraty středisek za měsíc: první aktivní typ dimenze „středisko" firmy.
     *
     * @return array<string,array{name:string,revenue:float,cost:float}>
     */
    private function costCenters(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM dimension_types WHERE supplier_id = ? AND kind = 'cost_center' AND is_active = 1 ORDER BY sort_order, id LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $typeId = (int) ($stmt->fetchColumn() ?: 0);
        if ($typeId === 0) {
            throw new ParallelRunException('cost_center_type_missing', 'Firma nemá typ dimenze Středisko, obraty po střediscích nejde sestavit.');
        }
        $report = $this->dimensionProfit->build($supplierId, $typeId, $from, $to, [$supplierId]);
        $out = [];
        foreach ($report['rows'] as $row) {
            $own = $row['own'];
            if (abs((float) $own['revenue']) < 0.005 && abs((float) $own['cost']) < 0.005) {
                continue;
            }
            $out[(string) $row['code']] = ['name' => (string) $row['name'], 'revenue' => round((float) $own['revenue'], 2), 'cost' => round((float) $own['cost'], 2)];
        }
        $un = $report['unassigned'];
        if (abs((float) $un['revenue']) >= 0.005 || abs((float) $un['cost']) >= 0.005) {
            $out[''] = ['name' => '', 'revenue' => round((float) $un['revenue'], 2), 'cost' => round((float) $un['cost'], 2)];
        }
        return $out;
    }

    /** K13 — rozvaha a výsledovka k poslednímu dni měsíce v plném rozsahu. */
    private function statementsCriterion(int $supplierId, int $periodId, string $asOf, SourceSnapshot $source): array
    {
        $context = $this->frameworks->context($supplierId, $periodId, $asOf);
        TrialBalanceReconciliation::assertStatementFrameworkSupported($context['framework']);
        $diffs = [];
        $summary = [];
        if ($source->balanceSheet !== null) {
            $bs = $this->statements->balanceSheet($supplierId, $periodId, $asOf, 'full');
            $mine = [];
            foreach ($bs['assets'] as $r) {
                $mine['A:' . ExportInputParser::rowCode((string) $r['row_code'])] = ['label' => (string) $r['label'], 'amount' => (float) $r['net']];
            }
            foreach ($bs['liabilities'] as $r) {
                $mine['P:' . ExportInputParser::rowCode((string) $r['display_code'])] = ['label' => (string) $r['label'], 'amount' => (float) $r['amount']];
            }
            $d = ParallelRunComparator::statement('balance_sheet', $mine, $source->balanceSheet['rows'], $source->balanceSheet['unit']);
            $diffs = array_merge($diffs, $d);
            $summary['balance_sheet'] = ['rows_source' => count($source->balanceSheet['rows']), 'differences' => count($d), 'unit' => $source->balanceSheet['unit']];
        }
        if ($source->incomeStatement !== null) {
            $is = $this->statements->incomeStatement($supplierId, $periodId, $asOf, 'full');
            $mine = [];
            foreach ($is['rows'] as $r) {
                $row = ['label' => (string) $r['label'], 'amount' => (float) $r['amount']];
                $mine[ExportInputParser::rowCode((string) $r['row_code'])] = $row;
                $mine[ExportInputParser::rowCode((string) ($r['display_code'] ?? $r['row_code']))] ??= $row;
            }
            $d = ParallelRunComparator::statement('income_statement', $mine, $source->incomeStatement['rows'], $source->incomeStatement['unit']);
            $diffs = array_merge($diffs, $d);
            $summary['income_statement'] = ['rows_source' => count($source->incomeStatement['rows']), 'differences' => count($d), 'unit' => $source->incomeStatement['unit']];
        }
        $unit = max($source->balanceSheet['unit'] ?? 1.0, $source->incomeStatement['unit'] ?? 1.0);
        return ParallelRunComparator::result('K13', $unit > 1.0 ? 'thousand' : ParallelRunComparator::TOLERANCE_CROWN, $diffs, $summary);
    }
}
