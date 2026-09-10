<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;

/**
 * Křížové kontroly (vrstva L4 auditního plánu) — totéž číslo spočítané DVĚMA
 * NEZÁVISLÝMI cestami a porovnané.
 *
 * Proč zrovna takhle: chyba, která je v obou cestách stejná, se křížovou kontrolou
 * neodhalí — ale chyba v jedné cestě ano, a to bez znalosti toho, kde přesně je.
 * `VatCrossCheckService` ten princip už měl pro DPH ↔ KH ↔ SH ↔ 343; tahle třída
 * ho zobecňuje na celý uzavřený rok a doplňuje účetní dvojice:
 *
 *   1. DPH: přiznání ↔ KH ↔ SH ↔ obrat 343    (delegace na VatCrossCheckService,
 *      za VŠECHNA zdaňovací období roku, ne za jedno)
 *   2. Rozvaha: aktiva = pasiva                (výkaz proti sobě)
 *   3. VH v rozvaze = VH ve VZZ                (dva různé výkazy z téhož deníku)
 *   4. VZZ = obratová předvaha                 (výkaz proti hlavní knize)
 *   5. Obratová předvaha: Σ MD = Σ D           (kniha proti sobě)
 *
 * Kontroly 2–5 se pouštějí jen nad UZAVŘENÝM obdobím: před uzávěrkou nejsou
 * výsledkové účty vynulované a rozdíly by byly legitimní.
 *
 * Služba NEDUPLIKUJE logiku výkazů — volá jejich reálné buildery, aby se kontrola
 * nikdy nerozešla s tím, co se skutečně podává a tiskne. Read-only.
 */
final class CrossCheckSuite
{
    /**
     * Tolerance shody v Kč. Výkazy zaokrouhlují po řádcích, deník je v haléřích —
     * pár korun je zaokrouhlení, ne chyba; chybějící doklad se projeví řádově výš.
     */
    private const EPS = 1.0;

    public function __construct(
        private readonly VatCrossCheckService $vat,
        private readonly FinancialStatementService $statements,
        private readonly TrialBalanceService $trialBalance,
        private readonly AccountingPeriodRepository $periods,
        private readonly Connection $db,
    ) {}

    /**
     * Spustí všechny křížové kontroly za jeden účetní rok.
     *
     * @return list<array{check:string, label:string, ok:bool, a_label:string, a:?float,
     *                    b_label:string, b:?float, difference:?float, note:?string, skipped:bool}>
     */
    public function run(int $supplierId, int $year): array
    {
        $period = $this->periods->findByYear($supplierId, $year);
        if ($period === null) {
            return [$this->skip('period', 'Účetní období ' . $year, 'období v systému neexistuje')];
        }

        $results = $this->vatChecksForYear($supplierId, $year);
        // Nezávisí na uzávěrce (porovnává evidenci majetku s doklady, ne účty), proto
        // ještě před bránou uzavřeného období.
        $results[] = $this->assetSalesVsCoefficientExclusion($supplierId, $year);

        $status = (string) ($period['status'] ?? '');
        if (!AccountingPeriodStatus::isClosed($status)) {
            $results[] = $this->skip(
                'accounting',
                'Účetní křížové kontroly ' . $year,
                'období je ve stavu "' . $status . '" — před uzávěrkou nejsou výsledkové účty vynulované',
            );
            return $results;
        }

        $periodId = (int) $period['id'];
        $scope = 'full';

        $balance = $this->statements->balanceSheet($supplierId, $periodId, null, $scope);
        $income  = $this->statements->incomeStatement($supplierId, $periodId, null, $scope);
        $tb      = $this->trialBalance->build($supplierId, $periodId, null, null);

        $results[] = $this->compare(
            'balance_sheet_sides',
            'Rozvaha: aktiva = pasiva (§ 18–20 ZoÚ)',
            'aktiva netto',
            $this->totalOf($balance, 'assets_net'),
            'pasiva netto',
            $this->totalOf($balance, 'liabilities_total'),
        );

        $results[] = $this->compare(
            'profit_balance_vs_income',
            'VH v rozvaze = VH ve výsledovce',
            'VH v rozvaze',
            $this->profitFromBalanceSheet($balance),
            'VH ve VZZ',
            $this->profitFromIncomeStatement($income),
        );

        $results[] = $this->compare(
            'income_statement_vs_ledger',
            'VZZ = obratová předvaha (výnosy − náklady)',
            'VH ve VZZ',
            $this->profitFromIncomeStatement($income),
            'z hlavní knihy',
            $this->profitFromLedger($supplierId, (string) $period['starts_on'], (string) $period['ends_on']),
        );

        $results[] = $this->compare(
            'trial_balance_sides',
            'Obratová předvaha: Σ MD = Σ D (ČÚS 001)',
            'Σ MD obratů',
            $this->trialBalanceTotal($tb, 'turnover_md'),
            'Σ D obratů',
            $this->trialBalanceTotal($tb, 'turnover_d'),
        );

        return $results;
    }

    /** Roky, které mají uzavřené účetní období — vhodné jako golden korpus. */
    public function closedYears(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT fiscal_year FROM accounting_periods
              WHERE supplier_id = ? AND status IN (" . AccountingPeriodStatus::closedSqlList() . ")
           ORDER BY fiscal_year"
        );
        $stmt->execute([$supplierId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * DPH smír za VŠECHNA zdaňovací období roku. Původní služba počítá jedno období;
     * kontrola nad jedním měsícem přitom nic neříká o roce — a rok je to, co se
     * rekonciliuje proti podaným přiznáním.
     *
     * @return list<array<string,mixed>>
     */
    private function vatChecksForYear(int $supplierId, int $year): array
    {
        $vatPeriod = (string) ($this->db->pdo()->query(
            'SELECT vat_period FROM supplier WHERE id = ' . (int) $supplierId
        )->fetchColumn() ?: 'monthly');

        $months = $vatPeriod === 'quarterly' ? [3, 6, 9, 12] : range(1, 12);
        $out = [];

        foreach ($months as $month) {
            $findings = $this->vat->check($supplierId, $year, $month, $vatPeriod);
            $blocking = array_values(array_filter($findings, static fn (array $f): bool => (bool) ($f['blocking'] ?? false)));

            $label = sprintf('DPH smír %d/%d (%s)', $month, $year, $vatPeriod === 'quarterly' ? 'čtvrtletně' : 'měsíčně');
            if ($blocking === []) {
                $out[] = [
                    'check' => 'vat_reconciliation',
                    'label' => $label,
                    'ok' => true,
                    'a_label' => 'přiznání',
                    'a' => null,
                    'b_label' => 'KH / SH / 343',
                    'b' => null,
                    'difference' => null,
                    'note' => $findings === [] ? null : sprintf('%d informativních poznámek', count($findings)),
                    'skipped' => false,
                ];
                continue;
            }

            foreach ($blocking as $f) {
                $out[] = [
                    'check' => 'vat_reconciliation',
                    'label' => $label . ' — ' . (string) ($f['label'] ?? $f['check'] ?? ''),
                    'ok' => false,
                    'a_label' => 'přiznání',
                    'a' => isset($f['declared']) ? (float) $f['declared'] : null,
                    'b_label' => 'protistrana',
                    'b' => isset($f['counter']) ? (float) $f['counter'] : null,
                    'difference' => isset($f['difference']) ? (float) $f['difference'] : null,
                    'note' => $f['note'] ?? null,
                    'skipped' => false,
                ];
            }
        }

        return $out;
    }

    /**
     * Prodej dlouhodobého majetku ↔ vyloučení z koeficientu § 76 odst. 4
     * (audit VAT klasifikací 2026-08, nález M-2).
     *
     * Kódy `1m`/`2m` (ř. 1/2 + doplňující ř. 51) se na vydané faktuře dosadí JEN tehdy,
     * má-li řádek vazbu na kartu majetku (`invoice_items.asset_id`). Faktura „prodej
     * vozidla" bez té vazby dostane běžný kód `1` a plnění zůstane v čitateli i jmenovateli
     * vypořádacího koeficientu — u plátce s kráceným nárokem tím koeficient nadhodnotí
     * a odpočet vyjde vyšší, než na jaký je nárok.
     *
     * Audit výslovně odmítá heuristiku nad popisem řádku („prodej vozu" najde i nájem
     * vozu) — jediná spolehlivá cesta je porovnat DVĚ NEZÁVISLÉ EVIDENCE: karty majetku
     * vyřazené prodejem v daném roce proti dokladům, které kód `1m`/`2m` skutečně nesou.
     * Kontrola proto NIC NEDOSAZUJE, jen ukáže rozdíl; posoudit ho musí účetní (prodej
     * osvobozený od daně, doklad vystavený v jiném roce nebo vyřazení bez fakturace jsou
     * legitimní důvody, proč se čísla nepotkají).
     *
     * @return array{check:string, label:string, ok:bool, a_label:string, a:?float,
     *               b_label:string, b:?float, difference:?float, note:?string, skipped:bool}
     */
    public function assetSalesVsCoefficientExclusion(int $supplierId, int $year): array
    {
        $label = 'Prodej majetku ↔ vyloučení z koeficientu § 76/4 (kód 1m/2m) ' . $year;
        if (!$this->db->hasColumn('assets', 'disposal_type')
            || !$this->db->hasColumn('assets', 'sale_invoice_id')
        ) {
            return $this->skip('asset_sale_coefficient', $label, 'evidence majetku není na téhle instalaci k dispozici');
        }

        $sold = $this->db->pdo()->prepare(
            "SELECT a.id, a.inventory_number, a.name, a.sale_invoice_id
               FROM assets a
              WHERE a.supplier_id = ?
                AND a.disposal_type = 'sold'
                AND a.disposal_date BETWEEN ? AND ?"
        );
        $sold->execute([$supplierId, $year . '-01-01', $year . '-12-31']);
        $soldAssets = $sold->fetchAll(\PDO::FETCH_ASSOC);

        // Druhá, nezávislá cesta: doklady, které kód pro vyloučení z koeficientu NESOU.
        // Kód se hledá na řádku i na hlavičce — výkazy čtou COALESCE(položka, hlavička).
        $marked = $this->db->pdo()->prepare(
            "SELECT DISTINCT i.id
               FROM invoices i
          LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.supplier_id = ?
                AND i.status <> 'draft'
                AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
                AND (ii.vat_classification_code IN ('1m', '2m')
                     OR i.vat_classification_code IN ('1m', '2m'))"
        );
        $marked->execute([$supplierId, $year . '-01-01', $year . '-12-31']);
        $markedInvoices = array_fill_keys(
            array_map('intval', $marked->fetchAll(\PDO::FETCH_COLUMN)),
            true,
        );

        // POZOR na past, do které se dá spadnout: porovnávat POČET karet proti POČTU
        // dokladů nejde. Jedna faktura běžně prodá víc karet, takže tři správně
        // označené karty na jednom dokladu daly „3 − 1 = 2" a kontrola hlásila nesoulad
        // nad bezvadnými daty. Porovnává se proto karta po kartě: každá vyřazená prodejem
        // musí mít doklad a ten doklad musí kód nést.
        $unlinked = [];
        $unmarked = [];
        foreach ($soldAssets as $asset) {
            $invoiceId = (int) ($asset['sale_invoice_id'] ?? 0);
            if ($invoiceId === 0) {
                $unlinked[] = $asset;
            } elseif (!isset($markedInvoices[$invoiceId])) {
                $unmarked[] = $asset;
            }
        }
        $offenders = array_merge($unlinked, $unmarked);

        $note = null;
        if ($offenders !== []) {
            $names = array_map(
                static fn (array $a): string => trim((string) $a['inventory_number'] . ' ' . (string) $a['name']),
                array_slice($offenders, 0, 10),
            );
            $note = 'Karty vyřazené prodejem bez dokladu s klasifikací 1m/2m: '
                . implode(', ', $names)
                . (count($offenders) > 10 ? ' …' : '')
                . '. Řádek faktury s vazbou na kartu majetku kód dostane sám; '
                . ($unlinked !== []
                    ? count($unlinked) . ' z nich nemá vazbu na vydanou fakturu vůbec. '
                    : '')
                . 'Osvobozený prodej, doklad v jiném roce nebo vyřazení bez fakturace jsou '
                . 'legitimní důvody rozdílu.';
        }

        return [
            'check' => 'asset_sale_coefficient',
            'label' => $label,
            // Rozdíl je PODEZŘENÍ, ne chyba — kontrola nic nedosazuje, jen ukáže karty,
            // u kterých se druhá evidence nepotvrdila.
            'ok' => $offenders === [],
            'a_label' => 'karet vyřazených prodejem',
            'a' => (float) count($soldAssets),
            'b_label' => 'z toho s dokladem nesoucím kód 1m/2m',
            'b' => (float) (count($soldAssets) - count($offenders)),
            'difference' => (float) count($offenders),
            'note' => $note,
            'skipped' => false,
        ];
    }

    /**
     * @return array{check:string, label:string, ok:bool, a_label:string, a:?float,
     *               b_label:string, b:?float, difference:?float, note:?string, skipped:bool}
     */
    private function compare(string $check, string $label, string $aLabel, float $a, string $bLabel, float $b): array
    {
        $diff = round($a - $b, 2);

        return [
            'check' => $check,
            'label' => $label,
            'ok' => abs($diff) <= self::EPS,
            'a_label' => $aLabel,
            'a' => round($a, 2),
            'b_label' => $bLabel,
            'b' => round($b, 2),
            'difference' => $diff,
            'note' => null,
            'skipped' => false,
        ];
    }

    /**
     * @return array{check:string, label:string, ok:bool, a_label:string, a:?float,
     *               b_label:string, b:?float, difference:?float, note:?string, skipped:bool}
     */
    private function skip(string $check, string $label, string $reason): array
    {
        return [
            'check' => $check, 'label' => $label, 'ok' => true,
            'a_label' => '', 'a' => null, 'b_label' => '', 'b' => null,
            'difference' => null, 'note' => $reason, 'skipped' => true,
        ];
    }

    /**
     * Součet ze sestavy — a když klíč NEEXISTUJE, výjimka místo nuly.
     *
     * Tichá nula tu byla nejhorší možná varianta: `assets_total` ani `liabilities_total`
     * na nejvyšší úrovni rozvahy nikdy nebyly (leží v `checks` jako `assets_net`
     * a `liabilities_total`), takže kontrola „aktiva = pasiva" porovnávala 0,00 s 0,00
     * a hlásila OK i nad rozvahou v řádu milionů — kontrola by neselhala, ani kdyby
     * se rozvaha rozešla o desítky milionů.
     *
     * Stejnou pojistku má {@see profitFromIncomeStatement()}; právě tady chyběla.
     *
     * @param array<string,mixed> $statement
     */
    private function totalOf(array $statement, string $key): float
    {
        $value = $statement[$key] ?? $statement['checks'][$key] ?? null;
        if ($value === null) {
            throw new \RuntimeException(sprintf(
                'Rozvaha neobsahuje „%s" — křížová kontrola by jinak porovnávala nulu.',
                $key,
            ));
        }
        if (is_array($value)) {
            return (float) ($value['net'] ?? $value['amount'] ?? 0.0);
        }

        return (float) $value;
    }

    /**
     * VH z rozvahy (P.A.V. Výsledek hospodaření běžného účetního období).
     *
     * @param array<string,mixed> $balance
     */
    private function profitFromBalanceSheet(array $balance): float
    {
        foreach ((array) ($balance['liabilities'] ?? []) as $row) {
            if (str_starts_with((string) ($row['row_code'] ?? ''), 'P.A.V.')) {
                return (float) ($row['net'] ?? $row['amount'] ?? 0.0);
            }
        }
        throw new \RuntimeException(
            'Rozvaha nemá řádek P.A.V. (VH běžného období) — křížová kontrola by jinak '
                . 'porovnávala nulu a tvářila se, že hlídá.',
        );
    }

    /**
     * VH z výsledovky — řádek `VH` (computed, „Výsledek hospodaření za účetní období").
     *
     * Chybějící řádek je tvrdá chyba, ne nula: první verze téhle metody hledala kódy
     * `***`/`**` (konvence jiných systémů), nenašla nic a vracela 0,00. Kontrola pak
     * hlásila rozdíl v plné výši VH a vypadala jako účetní nález, přestože šlo o vadu
     * kontroly. Tichá nula je u křížové kontroly nejhorší možná odpověď.
     *
     * @param array<string,mixed> $income
     */
    private function profitFromIncomeStatement(array $income): float
    {
        foreach ((array) ($income['rows'] ?? []) as $row) {
            if ((string) ($row['row_code'] ?? '') === 'VH') {
                return (float) ($row['amount'] ?? 0.0);
            }
        }
        throw new \RuntimeException(
            'Výsledovka nemá řádek VH — křížová kontrola by jinak porovnávala nulu.',
        );
    }

    /**
     * VH nezávisle na výkazech: Σ výnosů (tř. 6) − Σ nákladů (tř. 5) přímo z deníku,
     * BEZ uzávěrkových zápisů (ty výsledkovky vynulují převodem na 710).
     */
    private function profitFromLedger(int $supplierId, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ROUND(
                      SUM(CASE WHEN a.account_code LIKE '6%'
                               THEN (CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END)
                               ELSE 0 END)
                    - SUM(CASE WHEN a.account_code LIKE '5%'
                               THEN (CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
                               ELSE 0 END), 2)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ?
                AND e.entry_date BETWEEN ? AND ?
                AND e.posted_at IS NOT NULL
                AND e.source_type <> 'closing'"
        );
        $stmt->execute([$supplierId, $from, $to]);

        return (float) ($stmt->fetchColumn() ?: 0.0);
    }

    /** @param array<string,mixed> $trialBalance */
    private function trialBalanceTotal(array $trialBalance, string $key): float
    {
        $totals = (array) ($trialBalance['totals'] ?? []);
        return (float) ($totals[$key] ?? 0.0);
    }
}
