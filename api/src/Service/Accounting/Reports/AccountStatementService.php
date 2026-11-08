<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\Accounting\AccountBalanceSign;
use MyInvoice\Service\Accounting\JournalLineAmount;

/**
 * Opis účtu (Epic F2): stránkovaný výpis pohybů účtu (vč. analytik pod
 * syntetikou) s běžícím zůstatkem. PS k `from` dle okna R6; balance = kladné = MD,
 * `*_signed` = se znaménkem podle typu účtu ({@see AccountBalanceSign}).
 * Řazení entry_date, entry_id, line_no (deterministické) — running_delta počítá
 * window funkce nad celým rozsahem, takže stránkování ani filtr protiúčtu zůstatky nemění.
 */
final class AccountStatementService
{
    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalLineContext $context,
    ) {}

    /**
     * @param AccountStatementScope|null $scope zúžení podle řádku hlavní knihy (rozbalený
     *        měsíc ukazuje tytéž řádky, které kniha sčítá do obratu). Součty podle protiúčtu
     *        se s filtry knihy nepočítají.
     * @return array<string,mixed> struktura dle spec §2.6
     */
    public function build(int $supplierId, int $accountId, string $from, string $to, int $page, int $perPage, bool $afterClosing = false, ?int $counterAccountId = null, ?AccountStatementScope $scope = null): array
    {
        $scope ??= new AccountStatementScope();
        $filters = $scope->filters;
        if ($filters !== [] && $counterAccountId !== null) {
            // Rozdělení obratu podle protiúčtu počítá s celými řádky; díl rozpadu dimenze ani
            // zúžení na zápisy protistrany v něm nejsou.
            throw new ReportException('validation_failed', 'Filtr protiúčtu nejde kombinovat s filtrem dimenze, dodavatele, odběratele ani položky.', 422);
        }
        $account = $this->accounts->findById($supplierId, $accountId, $to);
        if ($account === null) {
            throw new ReportException('account_not_found', 'Účet #' . $accountId . ' neexistuje.', 404);
        }
        $counterAccount = null;
        if ($counterAccountId !== null) {
            $counterAccount = $this->accounts->findById($supplierId, $counterAccountId, $to);
            if ($counterAccount === null) {
                throw new ReportException('account_not_found', 'Protiúčet #' . $counterAccountId . ' neexistuje.', 404);
            }
        }
        $type = (string) $account['account_type'];
        $normalSide = $account['normal_side'] !== null ? (string) $account['normal_side'] : null;

        $period = $this->periods->findForDate($supplierId, $from);
        $periodStart = $period !== null ? (string) $period['starts_on'] : $from;

        // Stejný filtr technických zápisů musí dostat PS i pohyby, jinak se
        // otevírací zápis započítá dvakrát a KS vyjde jako dvojnásobek PS.
        $excludeClosing = !$afterClosing;

        $opening = $this->ledger->accountOpening($supplierId, $accountId, $from, $periodStart, $excludeClosing, $scope);
        $total   = $this->ledger->accountLinesTotal($supplierId, $accountId, $from, $to, $excludeClosing, $counterAccountId, $scope);

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $lines = $this->ledger->accountLines($supplierId, $accountId, $from, $to, $perPage, ($page - 1) * $perPage, $excludeClosing, $counterAccountId, $scope);

        $items = [];
        foreach ($lines as $l) {
            $balance = round($opening + (float) $l['running_delta'], 2);
            $items[] = [
                'entry_id'    => (int) $l['entry_id'],
                'entry_date'  => (string) $l['entry_date'],
                'document_no' => $l['document_no'],
                'description' => $l['description'],
                'source_type' => (string) $l['source_type'],
                'source_id'   => $l['source_id'],
                'side'        => (string) $l['side'],
                'is_red_storno' => $l['is_red_storno'],
                'amount'      => JournalLineAmount::signed($l),
                'balance'     => $balance,
                'balance_signed'  => AccountBalanceSign::signed($balance, $type),
                'balance_unusual' => AccountBalanceSign::isUnusual($balance, $normalSide),
                // Účet ŘÁDKU — u syntetiky je opis složený z analytik, bez tohohle
                // sloupce nešlo poznat, na které z nich pohyb visí.
                'account_id'   => (int) $l['line_account_id'],
                'account_code' => (string) $l['account_code'],
                'account_name' => (string) $l['line_account_name'],
                // Drill-down na prvotní doklad — shodná sada polí jako v deníku
                // (JournalEntryRepository::paginate), aby proklik vedl na tentýž doklad.
                'source_statement_id'       => $l['source_statement_id'],
                'source_bank_ref'           => $l['source_bank_ref'] ?? null,
                'source_doc_number'       => $l['source_doc_number'],
                'source_register_id'        => $l['source_register_id'],
                'source_asset_id'           => $l['source_asset_id'],
                'source_asset_name'         => $l['source_asset_name'],
                'source_settlement_doc_type' => $l['source_settlement_doc_type'],
                'source_settlement_doc_id'  => $l['source_settlement_doc_id'],
                'line_id'        => (int) $l['line_id'],
                'line_no'        => (int) $l['line_no'],
                'currency_code'  => $l['currency_code'],
                'amount_foreign' => $l['amount_foreign'] === null ? null : JournalLineAmount::signed([
                    'amount' => $l['amount_foreign'], 'is_red_storno' => $l['is_red_storno'],
                ]),
            ];
        }
        $items = $this->context->enrich($supplierId, $items);

        $turnovers = $this->ledger->accountTurnovers($supplierId, $accountId, $from, $to, $excludeClosing, $scope);
        // Součty jdou přes všechny pohyby účtu a stránkováním se nemění, stačí první stránka.
        $counterTotals = $page === 1 && $filters === []
            ? self::reconcileCounterTotals($this->ledger->accountCounterTotals($supplierId, $accountId, $from, $to, $excludeClosing, $scope), $turnovers)
            : null;
        $closing = round($opening + $turnovers['md'] - $turnovers['d'], 2);

        return [
            'account' => [
                'id'           => (int) $account['id'],
                'code'         => (string) $account['account_code'],
                'name'         => (string) $account['name'],
                'type'         => $type,
                'normal_side'  => $account['normal_side'],
                'balance_sign' => AccountBalanceSign::factor($type),
            ],
            'opening_balance' => $opening,
            'opening_balance_signed'  => AccountBalanceSign::signed($opening, $type),
            'opening_balance_unusual' => AccountBalanceSign::isUnusual($opening, $normalSide),
            'items'           => $items,
            'total'           => $total,
            'page'            => $page,
            'per_page'        => $perPage,
            'closing_balance' => $closing,
            'closing_balance_signed'  => AccountBalanceSign::signed($closing, $type),
            'closing_balance_unusual' => AccountBalanceSign::isUnusual($closing, $normalSide),
            'turnover_md'     => $turnovers['md'],
            'turnover_d'      => $turnovers['d'],
            'counter_totals'  => $counterTotals,
            'counter_filter'  => $counterAccount === null || $counterTotals === null ? null : self::counterFilter($counterAccount, $counterTotals),
            'vendor'          => $filters['vendor'] ?? null,
            'client'          => $filters['client'] ?? null,
            'item'            => $filters['item'] ?? null,
            'dimension'       => isset($filters['dimension']) ? $filters['dimension']->toArray() : null,
            'opening_date'    => $scope->openingDate,
            'include_analytics' => $scope->withAnalytics,
        ];
    }

    /**
     * Součty podle protiúčtu na haléře. Poměrné dělení složeného zápisu může po zaokrouhlení
     * řádků odskočit od obratu o haléř; zbytek se přičte k řádku s největší částkou, aby
     * Σ protiúčtů == obrat účtu.
     *
     * @param list<array{account_id:?int, account_code:?string, name:?string, parent_id:?int, md:float, d:float}> $rows
     * @param array{md:float, d:float} $turnovers
     * @return list<array{account_id:?int, account_code:?string, name:?string, parent_id:?int, md:float, d:float}>
     */
    public static function reconcileCounterTotals(array $rows, array $turnovers): array
    {
        foreach (['md', 'd'] as $side) {
            $sum = 0;
            $largest = null;
            foreach ($rows as $i => $row) {
                $rows[$i][$side] = round($row[$side], 2);
                $sum += (int) round($rows[$i][$side] * 100);
                if ($largest === null || abs($rows[$i][$side]) > abs($rows[$largest][$side])) {
                    $largest = $i;
                }
            }
            $residue = (int) round($turnovers[$side] * 100) - $sum;
            if ($residue !== 0 && $largest !== null) {
                $rows[$largest][$side] = round($rows[$largest][$side] + $residue / 100, 2);
            }
        }
        return $rows;
    }

    /**
     * Součet filtrovaného protiúčtu včetně jeho analytik. Jde o podíl na obratu, ne o součet
     * zobrazených řádků: řádek složeného zápisu se ukáže celý, do protiúčtu patří jen jeho díl.
     *
     * @param array<string,mixed> $counterAccount
     * @param list<array{account_id:?int, account_code:?string, name:?string, parent_id:?int, md:float, d:float}> $totals
     * @return array{account_id:int, account_code:string, name:string, md:float, d:float}
     */
    private static function counterFilter(array $counterAccount, array $totals): array
    {
        $id = (int) $counterAccount['id'];
        $md = 0.0;
        $d = 0.0;
        foreach ($totals as $row) {
            if ($row['account_id'] === $id || $row['parent_id'] === $id) {
                $md += $row['md'];
                $d += $row['d'];
            }
        }
        return [
            'account_id'   => $id,
            'account_code' => (string) $counterAccount['account_code'],
            'name'         => (string) $counterAccount['name'],
            'md'           => round($md, 2),
            'd'            => round($d, 2),
        ];
    }
}
