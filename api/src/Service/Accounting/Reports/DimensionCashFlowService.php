<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Card\CardClearingAccounts;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use PDO;

/**
 * Peněžní tok po dimenzi (projekt, středisko, zakázka) NEPŘÍMOU metodou — manažerská
 * sestava, ne přehled o peněžních tocích do závěrky ({@see CashFlowStatementService}).
 *
 * Přímá metoda třídí pohyby na peněžních účtech podle protiúčtu. Po dimenzi to nejde:
 * úhrada faktury projektu v bance obvykle hodnotu projektu nenese, nese ji faktura.
 * Nepřímá metoda proto vychází z řádků, které hodnotu nesou (u rozpadu jejich díl,
 * {@see DimensionFilter::lineSource()}):
 *
 *     peněžní tok = výsledek hospodaření − Σ změn nepeněžních rozvahových účtů
 *
 * Každý řádek na nepeněžním rozvahovém účtu přispívá částkou −(MD − D): nárůst
 * pohledávky tok snižuje, nárůst závazku zvyšuje. Příspěvky se třídí podle účtu:
 *   PROVOZNÍ  výsledek; nepeněžní operace (oprávky 07x–09x, opravné položky 19x/29x/39x,
 *             rezervy 45x); změna pracovního kapitálu (zbytek tříd 1–3)
 *   INVESTIČNÍ dlouhodobý majetek 0xx, krátkodobý finanční majetek 25x (kromě 252)
 *   FINANČNÍ  třída 4 kromě rezerv, úvěry 231/232, vlastní podíly 252
 *
 * Kontrola: vypočtený tok se porovná se skutečným pohybem na peněžních účtech ve
 * stejném výběru řádků. Bez filtru (celá firma) se musí shodovat na haléř — zápisy
 * jsou vyvážené. S filtrem rozdíl ukazuje peníze, které hodnotu dimenze nenesou
 * (typicky úhrady bez projektu); sestava ho nevydává za tok, jen ho vyčísluje.
 *
 * Otevírací zápisy (převod počátečních stavů) a uzávěrkový převod se vyřazují, slotované
 * skladové zápisy uzávěrky zůstávají (jsou to skutečné změny zásob), stejně jako ve výkazech.
 */
final class DimensionCashFlowService
{
    /** Peněžní prostředky — shodně s {@see CashFlowStatementService}. */
    private const CASH_PREFIXES = ['211', '213', '221', '261'];

    private const NON_CASH_PREFIXES = ['07', '08', '09', '19', '29', '39', '45'];

    public function __construct(
        private readonly Connection $db,
        private readonly CardClearingAccounts $cardAccounts,
    ) {}

    /**
     * @param array<int,?DimensionFilter> $filters firma => filtr (null = celá firma); první je aktuální firma
     * @return array<string,mixed>
     */
    public function build(string $from, string $to, array $filters): array
    {
        $profit = 0;
        $cash = 0;
        /** @var array<string,array<string,array{code:string,name:string,amount:int}>> $groups */
        $groups = ['non_cash' => [], 'working_capital' => [], 'investing' => [], 'financing' => []];
        foreach ($filters as $supplierId => $filter) {
            $cardCodes = array_flip($this->cardAccounts->allClearingCodes((int) $supplierId));
            foreach ($this->balances((int) $supplierId, $from, $to, $filter) as $row) {
                $delta = (int) round(((float) $row['delta']) * 100);
                if ($delta === 0) {
                    continue;
                }
                $type = (string) $row['account_type'];
                if ($type === 'revenue' || $type === 'expense') {
                    $profit -= $delta;
                    continue;
                }
                if ($type !== 'asset' && $type !== 'liability' && $type !== 'equity') {
                    continue;
                }
                $leaf = (string) $row['leaf_code'];
                if (self::isCash($leaf) && !isset($cardCodes[$leaf])) {
                    $cash += $delta;
                    continue;
                }
                $code = (string) $row['code'];
                $group = self::classify($code);
                $groups[$group][$code]['code'] = $code;
                $groups[$group][$code]['name'] ??= (string) $row['name'];
                $groups[$group][$code]['amount'] = ($groups[$group][$code]['amount'] ?? 0) - $delta;
            }
        }

        $lists = [];
        $totals = [];
        foreach ($groups as $group => $accounts) {
            ksort($accounts, SORT_STRING);
            $lists[$group] = array_values(array_map(
                static fn (array $a): array => ['account_code' => $a['code'], 'name' => $a['name'], 'amount' => $a['amount'] / 100],
                array_filter($accounts, static fn (array $a): bool => $a['amount'] !== 0),
            ));
            $totals[$group] = array_sum(array_column($accounts, 'amount'));
        }
        $operating = $profit + $totals['non_cash'] + $totals['working_capital'];
        $implied = $operating + $totals['investing'] + $totals['financing'];

        return [
            'from' => $from,
            'to' => $to,
            'supplier_ids' => array_map('intval', array_keys($filters)),
            'profit' => $profit / 100,
            'non_cash' => ['total' => $totals['non_cash'] / 100, 'accounts' => $lists['non_cash']],
            'working_capital' => ['total' => $totals['working_capital'] / 100, 'accounts' => $lists['working_capital']],
            'operating' => $operating / 100,
            'investing' => ['total' => $totals['investing'] / 100, 'accounts' => $lists['investing']],
            'financing' => ['total' => $totals['financing'] / 100, 'accounts' => $lists['financing']],
            'net_cash_flow' => $implied / 100,
            'cash_movement' => $cash / 100,
            'untagged_cash' => ($implied - $cash) / 100,
            'reconciles' => $implied === $cash,
        ];
    }

    /**
     * Obraty MD − D v období po listovém účtu (se syntetikou) nad řádky výběru.
     *
     * @return list<array<string,mixed>>
     */
    private function balances(int $supplierId, string $from, string $to, ?DimensionFilter $filter): array
    {
        [$linesSql, $linesParams] = $filter !== null ? $filter->lineSource($supplierId, 'l', $from, $to) : ['journal_entry_lines l', []];
        $stmt = $this->db->pdo()->prepare(
            'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . "
            SELECT a.account_code AS leaf_code,
                   COALESCE(p.account_code, a.account_code) AS code,
                   COALESCE(p.name, a.name) AS name,
                   a.account_type,
                   SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS delta
              FROM {$linesSql}
              JOIN journal_entries e ON e.id = l.entry_id
              " . JournalTaxOrigin::join() . "
              JOIN chart_of_accounts a ON a.id = l.account_id
         LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
             WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
               AND e.entry_date BETWEEN ? AND ?
               AND e.source_type <> 'opening'
               AND " . JournalTaxOrigin::includedSql() . '
             GROUP BY a.account_code, COALESCE(p.account_code, a.account_code), COALESCE(p.name, a.name), a.account_type'
        );
        $stmt->execute([...$linesParams, $supplierId, $from, $to, ClosingSourceId::STOCK_SLOT_BASE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function isCash(string $code): bool
    {
        foreach (self::CASH_PREFIXES as $p) {
            if (str_starts_with($code, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @return 'non_cash'|'working_capital'|'investing'|'financing' */
    private static function classify(string $code): string
    {
        foreach (self::NON_CASH_PREFIXES as $p) {
            if (str_starts_with($code, $p)) {
                return 'non_cash';
            }
        }
        if (str_starts_with($code, '252') || str_starts_with($code, '231') || str_starts_with($code, '232') || str_starts_with($code, '4')) {
            return 'financing';
        }
        if (str_starts_with($code, '0') || str_starts_with($code, '25')) {
            return 'investing';
        }
        return 'working_capital';
    }
}
