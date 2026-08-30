<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;

/**
 * Podklady pro DPPO přiznání ({@see DppoReturnCalculator}) z účetního deníku firmy
 * (Epic DP, issue #18). Odděleno od čistého kalkulátoru — tato třída sahá do DB,
 * kalkulátor je testovatelný nad jejím výstupem.
 *
 * Zdroje (podvojné účetnictví, posted zápisy období):
 *   - VH před zdaněním  = Σ výnosy (6xx) − Σ náklady (5xx mimo 59x)
 *   - nedaňové náklady   = Σ nákladů na účtech tax_deductibility='non_deductible' (§25)
 *   - rozdíl odpisů      = depreciation_entries kind tax vs accounting za fiscal_year
 *   - můstek ZC          = rozdíl účetní a daňové ZC prodaného/likvidovaného majetku
 *
 * SQL konvence dle ClosingRepository / TaxBaseReportAction (posted_at IS NOT NULL,
 * reversed_by IS NULL, tenant l.supplier_id).
 */
final class DppoReturnDataProvider
{
    /** Účtové skupiny s vysokou pravděpodobností daňové neuznatelnosti (§25) — auto-návrh k ř.40. */
    private const LIKELY_NONDEDUCTIBLE = [
        '513' => 'taxReturn.suggest_513',
        '543' => 'taxReturn.suggest_543',
        '545' => 'taxReturn.suggest_545',
        '549' => 'taxReturn.suggest_549',
        '528' => 'taxReturn.suggest_528',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly NonDeductibleCostsService $nonDeductibleCostsService,
        // Volitelná (autowired v produkci) — zdroj náhledů závěrkových operací pro projekci VH.
        // V unit testech nad SQLite se nepředává (null → projekce se přeskočí, ostatní podklady jedou).
        private readonly ?ClosingService $closing = null,
        // § 23/3/a/12 — volitelná ze stejného důvodu: bez ní se návrh jen přeskočí
        // a ostatní podklady dojedou (unit testy nad SQLite ledger tabulku nemají).
        private readonly ?UnpaidLiabilityService $unpaidLiabilities = null,
        // § 23/7 — volitelná ze stejného důvodu jako předchozí dvě.
        private readonly ?\MyInvoice\Service\Tax\RelatedPartyService $relatedParties = null,
    ) {}

    /**
     * @return array{
     *   period: array{id:int,starts_on:string,ends_on:string}|null,
     *   vh: float, non_deductible_costs: float,
     *   depreciation: array{tax:float,accounting:float},
     *   depreciation_by_group: array{tangible:array<int,float>,intangible:float,unclassified:float},
     *   related_party_country_flag: 'N'|'T'|'Z'|'A',
     *   bank_account: array{account_number:?string,bank_code:?string,bank_name:?string,iban:?string}|null,
     *   disposal_nondeductible_residual: float,
     *   disposal_tax_increase: float, disposal_tax_decrease: float,
     *   disposals: list<array<string,mixed>>,
     *   closing_projection: array<string,mixed>,
     *   suggestions: array{addbacks:list<array<string,mixed>>,deductions:list<array<string,mixed>>,unpaid_liabilities:array<string,mixed>},
     *   warnings: list<string>
     * }
     */
    public function gather(int $supplierId, int $year): array
    {
        $warnings = [];
        $period = $this->periods->findByYear($supplierId, $year);
        if ($period === null) {
            return [
                'period' => null,
                'vh' => 0.0,
                'non_deductible_costs' => 0.0,
                'depreciation' => ['tax' => 0.0, 'accounting' => 0.0],
                'depreciation_by_group' => ['tangible' => [], 'intangible' => 0.0, 'unclassified' => 0.0],
                'related_party_country_flag' => 'N',
                'bank_account' => $this->bankAccount($supplierId),
                'disposal_nondeductible_residual' => 0.0,
                'disposal_tax_increase' => 0.0,
                'disposal_tax_decrease' => 0.0,
                'disposals' => [],
                'closing_projection' => (new ClosingProjectionCalculator())->project(0.0, []),
                'suggestions' => ['addbacks' => [], 'deductions' => []],
                'warnings' => ['Pro rok ' . $year . ' neexistuje účetní období — podklady z deníku nejsou k dispozici. Zkontrolujte, že je zapnuté podvojné účetnictví a účetní období založené.'],
            ];
        }

        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];

        $vh = $this->profitBeforeTax($supplierId, $startsOn, $endsOn);
        $nonDeductible = $this->nonDeductibleCosts($supplierId, $startsOn, $endsOn);
        $dep = $this->depreciation($supplierId, $year);
        $depByGroup = $this->depreciationByGroup($supplierId, $year);
        $relatedPartyFlag = $this->relatedPartyCountryFlag($supplierId, $startsOn, $endsOn);
        $bankAccount = $this->bankAccount($supplierId);
        [$disposalIncrease, $disposalDecrease, $disposals, $disposalWarnings] = $this->disposalResiduals($supplierId, $startsOn, $endsOn);
        $projection = $this->closingProjection($supplierId, (int) $period['id'], $endsOn, $vh);
        $suggestions = [
            'addbacks' => $this->addbackSuggestions($supplierId, $startsOn, $endsOn),
            'deductions' => $this->deductionSuggestions($supplierId, $startsOn, $endsOn),
            // § 23/3/a/12 — dluhy po 30 měsících. Systém je NEPŘIPOČÍTÁVÁ sám: bod 12 má
            // výjimky, které z účetních dat rozpoznat nelze (nedaňový titul, insolvence,
            // sankce, úvěry). Automatika by nadhodnotila základ — stejná chyba jako
            // dnešní podhodnocení, jen opačným směrem.
            'unpaid_liabilities' => $this->unpaidLiabilitySuggestion($supplierId, $year, $endsOn),
            // § 23 odst. 7 — rozdíl proti cenám mezi nespojenými osobami. Úpravu systém
            // NEODVOZUJE: zákon ji ukládá jen tehdy, když rozdíl NENÍ uspokojivě doložen,
            // a doložení (posudek, benchmark, obchodní důvod) leží mimo účetní data.
            // Nabízí tedy to, co eviduje účetní, plus objem transakcí se spojenými osobami
            // jako podklad k posouzení.
            'related_party' => $this->relatedPartySuggestion($supplierId, $year, $startsOn, $endsOn),
        ];

        return [
            'period' => ['id' => (int) $period['id'], 'starts_on' => $startsOn, 'ends_on' => $endsOn],
            'vh' => $vh,
            'non_deductible_costs' => $nonDeductible,
            'depreciation' => $dep,
            'depreciation_by_group' => $depByGroup,
            'related_party_country_flag' => $relatedPartyFlag,
            'bank_account' => $bankAccount,
            'disposal_nondeductible_residual' => 0.0,
            'disposal_tax_increase' => $disposalIncrease,
            'disposal_tax_decrease' => $disposalDecrease,
            'disposals' => $disposals,
            'closing_projection' => $projection,
            'suggestions' => $suggestions,
            'warnings' => array_merge($warnings, $disposalWarnings),
        ];
    }

    /**
     * FEATURE 1 — projekce dosud NEZAÚČTOVANÝCH závěrkových operací do VH. Zdrojem částek jsou
     * read-only náhledy z {@see ClosingService}; skládá je čistý {@see ClosingProjectionCalculator}.
     * Každý krok se do projekce zahrne JEN pokud ještě není zaúčtovaný (jinak už je ve vh_posted):
     * u 381 podle preview['existing'], u fx podle posted zápisu k rozvahovému dni, u rozpuštění 381
     * z minulého období podle stavu open_next. Bez ClosingService (unit testy) → prázdná projekce.
     *
     * @return array<string,mixed>
     */
    private function closingProjection(int $supplierId, int $periodId, string $endsOn, float $vhPosted): array
    {
        $calc = new ClosingProjectionCalculator();
        if ($this->closing === null) {
            return $calc->project($vhPosted, []);
        }
        $sources = [];
        // Každý náhled izolovaně — chyba jednoho kroku (např. chybí kontace) nesmí shodit náhled.
        try {
            $sources['small_asset'] = $this->closing->smallAssetAccrualPreview($supplierId, $periodId);
        } catch (\Throwable) {
        }
        try {
            $sources['prepaid'] = $this->closing->prepaidExpenseAccrualPreview($supplierId, $periodId);
        } catch (\Throwable) {
        }
        try {
            // fx jen když ještě NENÍ zaúčtováno k rozvahovému dni (jinak už je ve vh_posted).
            if (!$this->hasPostedFxRevaluation($supplierId, $endsOn)) {
                $sources['fx'] = $this->closing->fxPreview($supplierId, $periodId);
            }
        } catch (\Throwable) {
        }
        try {
            $sources['provisions'] = $this->closing->provisionsPreview($supplierId, $periodId);
        } catch (\Throwable) {
        }
        try {
            $sources['estimates'] = $this->closing->estimatesSuggest($supplierId, $periodId);
        } catch (\Throwable) {
        }
        try {
            $rel = $this->closing->priorDeferralReleaseProjection($supplierId, $periodId);
            if (($rel['applicable'] ?? false) === true) {
                $sources['prior_release'] = $rel;
            }
        } catch (\Throwable) {
        }
        return $calc->project($vhPosted, $sources);
    }

    /** Je k rozvahovému dni zaúčtované přecenění kurzových rozdílů (aby se fx neprojektovalo dvakrát)? */
    private function hasPostedFxRevaluation(int $supplierId, string $endsOn): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'fx_revaluation'
                AND posted_at IS NOT NULL AND reversed_by IS NULL
                AND entry_date = ? LIMIT 1"
        );
        $stmt->execute([$supplierId, $endsOn]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * FEATURE 2a — auto-návrh PŘIPOČITATELNÝCH položek (§25) k ř.40: obraty účtů, které bývají
     * daňově neuznatelné (513 reprezentace, 543 dary, 545 pokuty/penále, 549 manka a škody, 528),
     * i když nemají tax_deductibility='non_deductible'. Účetní je jen ZVÁŽÍ a potvrdí — systém je
     * NEpřičítá automaticky. `already_non_deductible` = účet už je v auto-součtu ř.40 (nedaňový).
     *
     * @return list<array{account_code:string,name:string,amount:float,hint_key:string,already_non_deductible:bool}>
     */
    private function addbackSuggestions(int $supplierId, string $startsOn, string $endsOn): array
    {
        $prefixes = array_keys(self::LIKELY_NONDEDUCTIBLE);
        $likeSql = implode(' OR ', array_fill(0, count($prefixes), 'a.account_code LIKE ?'));
        $params = array_merge([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE], array_map(static fn (string $p): string => $p . '%', $prefixes));
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, a.name, a.tax_deductibility,
                    COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0) AS turnover
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND NOT (e.source_type = 'closing' AND e.source_id < ?)
                AND a.account_type = 'expense'
                AND (" . $likeSql . ")
              GROUP BY a.account_code, a.name, a.tax_deductibility
              ORDER BY turnover DESC"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $amount = round((float) $row['turnover'], 2);
            if ((int) round($amount * 100) === 0) {
                continue;
            }
            $group = substr((string) $row['account_code'], 0, 3);
            $out[] = [
                'account_code' => (string) $row['account_code'],
                'name' => (string) $row['name'],
                'amount' => $amount,
                'hint_key' => self::LIKELY_NONDEDUCTIBLE[$group] ?? 'taxReturn.suggest_other',
                'already_non_deductible' => (string) $row['tax_deductibility'] === 'non_deductible',
            ];
        }
        return $out;
    }

    /**
     * FEATURE 2b — auto-návrh ODEČITATELNÝCH položek: obrat účtu 543 (dary) jako možný odečet
     * §20/8 ZDP. Daňovou ztrátu minulých let (§34) nabízí FE z karty tax_losses. Návrh, ne
     * automatický odečet.
     *
     * @return list<array{key:string,account_code:string,amount:float,hint_key:string}>
     */
    /**
     * FEATURE — návrh připočtení neuhrazených dluhů po 30 měsících (§ 23/3/a/12) a jejich
     * snížení po úhradě (§ 23/3/c/6). Bez služby (unit testy bez ledger tabulky) vrací
     * prázdný návrh, aby ostatní podklady dojely.
     *
     * @return array<string,mixed>
     */
    private function unpaidLiabilitySuggestion(int $supplierId, int $year, string $endsOn): array
    {
        $empty = ['rows' => [], 'total_increase' => 0.0, 'total_decrease' => 0.0, 'net_delta' => 0.0, 'warnings' => []];
        if ($this->unpaidLiabilities === null) {
            return $empty;
        }
        try {
            $p = $this->unpaidLiabilities->preview($supplierId, $year, $endsOn);
        } catch (\Throwable) {
            // Chybějící migrace nesmí shodit celý náhled přiznání.
            return $empty;
        }

        return [
            'rows'           => $p['rows'],
            'total_increase' => $p['total_increase'],
            'total_decrease' => $p['total_decrease'],
            'net_delta'      => $p['net_delta'],
            'warnings'       => $p['warnings'],
        ];
    }

    /**
     * § 23 odst. 7 ZDP — úpravy základu daně o rozdíl proti cenám mezi nespojenými osobami
     * a podklad k jejich posouzení (objem transakcí, měřitelné cenové odchylky).
     *
     * Bez služby (unit testy bez ledger tabulky) vrací prázdný návrh, aby ostatní podklady
     * dojely — shodně s {@see unpaidLiabilitySuggestion()}.
     *
     * @return array<string,mixed>
     */
    private function relatedPartySuggestion(int $supplierId, int $year, string $startsOn, string $endsOn): array
    {
        $empty = [
            'rows' => [], 'total_increase' => 0.0, 'total_decrease' => 0.0, 'net_delta' => 0.0,
            'transactions_total' => 0.0, 'transactions_count' => 0, 'deviations' => [],
        ];
        if ($this->relatedParties === null) {
            return $empty;
        }
        try {
            $adjustments = $this->relatedParties->adjustments($supplierId, $year);
            $transactions = $this->relatedParties->transactions($supplierId, $startsOn, $endsOn);
            $deviations = $this->relatedParties->priceDeviations($supplierId, $startsOn, $endsOn);
        } catch (\Throwable) {
            // Chybějící migrace nesmí shodit celý náhled přiznání.
            return $empty;
        }

        return $adjustments + [
            'transactions_total' => round(array_sum(array_column($transactions, 'amount')), 2),
            'transactions_count' => count($transactions),
            'deviations'         => $deviations,
        ];
    }

    private function deductionSuggestions(int $supplierId, string $startsOn, string $endsOn): array
    {
        $out = [];
        $donations = $this->accountGroupExpense($supplierId, $startsOn, $endsOn, '543');
        if ((int) round($donations * 100) !== 0) {
            $out[] = [
                'key' => 'donation_543',
                'account_code' => '543',
                'amount' => round($donations, 2),
                'hint_key' => 'taxReturn.suggest_deduct_543',
            ];
        }
        return $out;
    }

    /** Čistý nákladový obrat (debet − kredit) účtové skupiny za období, mimo close_books zápis (skladové sloty §3.4 se počítají). */
    private function accountGroupExpense(int $supplierId, string $startsOn, string $endsOn, string $groupPrefix): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0) AS c
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND NOT (e.source_type = 'closing' AND e.source_id < ?)
                AND a.account_type = 'expense'
                AND a.account_code LIKE ?"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE, $groupPrefix . '%']);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * VH před zdaněním = Σ (6xx credit−debit) − Σ (5xx mimo 59x debit−credit) = Σ (credit−debit)
     * přes výnosy+náklady mimo 59x.
     *
     * KRITICKÉ: vyloučit JEN close_books zápis (source_type='closing', source_id =
     * period_id < STOCK_SLOT_BASE) — po uzávěrce převádí MD 6xx/D 710 a MD 710/D 5xx
     * výsledkové účty na nulu a jeho řádky na 6xx/5xx (protistrana 710 je typu 'closing',
     * tj. nefiltruje se account_type) by jinak vynulovaly operativní VH. Slotované
     * skladové zápisy §3.4 (source_id >= STOCK_SLOT_BASE, snížení 501/504, manka 549,
     * přebytky 648) do VH PATŘÍ a POČÍTAJÍ SE. Zrcadlí ClosingRepository::plBalances.
     */
    private function profitBeforeTax(int $supplierId, string $startsOn, string $endsOn): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0) AS vh
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND NOT (e.source_type = 'closing' AND e.source_id < ?)
                AND a.account_type IN ('revenue','expense')
                AND a.account_code NOT LIKE '59%'"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Σ nákladů na účtech s tax_deductibility='non_deductible' (§25 ZDP). Vylučuje
     * uzávěrkové zápisy (jinak by převod nákladů na 710 sumu vynuloval) i 59x (daň
     * z příjmů je z VH vyloučena, přidávat ji do ř.40 by ji do základu započetlo dvakrát).
     */
    private function nonDeductibleCosts(int $supplierId, string $startsOn, string $endsOn): float
    {
        return $this->nonDeductibleCostsService->sum($supplierId, $startsOn, $endsOn);
    }

    /**
     * Uplatněné DAŇOVÉ odpisy za rok rozpadlé podle přílohy č. 1 II. oddílu, tabulka B:
     * hmotný majetek po odpisové skupině (assets.tax_group 1-6, karty §26-33) a nehmotný
     * majetek zvlášť (assets.kind='intangible', §32a). `unclassified` = daňové odpisy
     * hmotného majetku bez vyplněné odpisové skupiny na kartě — do tabulky B se promítnout
     * nedají, builder z toho udělá varování místo tichého vynechání.
     *
     * @return array{tangible: array<int,float>, intangible: float, unclassified: float}
     */
    private function depreciationByGroup(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.kind, a.tax_group, COALESCE(SUM(de.amount), 0) AS total
               FROM depreciation_entries de
               JOIN assets a ON a.id = de.asset_id
              WHERE de.supplier_id = ? AND de.kind = \'tax\' AND de.fiscal_year = ?
              GROUP BY a.kind, a.tax_group'
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $tangible = [];
        $intangible = 0.0;
        $unclassified = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $amount = round((float) $row['total'], 2);
            if ($amount === 0.0) {
                continue;
            }
            if ((string) $row['kind'] === 'intangible') {
                $intangible = round($intangible + $amount, 2);
                continue;
            }
            $group = $row['tax_group'] !== null ? (int) $row['tax_group'] : null;
            if ($group !== null && $group >= 1 && $group <= 6) {
                $tangible[$group] = round(($tangible[$group] ?? 0.0) + $amount, 2);
            } else {
                $unclassified = round($unclassified + $amount, 2);
            }
        }
        return ['tangible' => $tangible, 'intangible' => $intangible, 'unclassified' => $unclassified];
    }

    /**
     * § 23 odst. 7 ZDP (VetaD/spoj_zahr) — uskutečnil poplatník transakce se spojenou
     * osobou (clients.related_party=1) a byla tato osoba tuzemská, nebo zahraniční, nebo
     * obojí? Vlastní dotaz místo volání {@see \MyInvoice\Service\Tax\RelatedPartyService}
     * — ta vrací transakce k lidskému náhledu, ne zemi protistrany; přidávat tam sloupec
     * navíc pro jednoho volajícího by rozšiřovalo cizí rozhraní kvůli DPPO detailu.
     *
     * @return 'N'|'T'|'Z'|'A'
     */
    private function relatedPartyCountryFlag(int $supplierId, string $startsOn, string $endsOn): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT co.iso2 AS iso2
               FROM invoices i
               JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
               JOIN countries co ON co.id = c.country_id
              WHERE i.supplier_id = ? AND c.related_party = 1
                AND i.status NOT IN ('draft','cancelled')
                AND i.invoice_type IN ('invoice','credit_note','tax_document')
                AND i.effective_tax_date BETWEEN ? AND ?
              UNION ALL
             SELECT co.iso2
               FROM purchase_invoices p
               JOIN clients v ON v.id = p.vendor_id AND v.supplier_id = p.supplier_id
               JOIN countries co ON co.id = v.country_id
              WHERE p.supplier_id = ? AND v.related_party = 1
                AND p.status NOT IN ('draft','cancelled')
                AND p.document_kind IN ('invoice','receipt','credit_note','tax_document')
                AND p.effective_cost_date BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, $supplierId, $startsOn, $endsOn]);
        $hasDomestic = false;
        $hasForeign = false;
        foreach ($stmt->fetchAll() as $row) {
            if (strtoupper((string) $row['iso2']) === 'CZ') {
                $hasDomestic = true;
            } else {
                $hasForeign = true;
            }
        }
        if ($hasDomestic && $hasForeign) {
            return 'A';
        }
        if ($hasForeign) {
            return 'Z';
        }
        if ($hasDomestic) {
            return 'T';
        }
        return 'N';
    }

    /**
     * Výchozí CZK bankovní účet poplatníka (`currencies`, stejný zdroj jako platební
     * příkazy — {@see \MyInvoice\Repository\PaymentOrderRepository::payerAccounts}) —
     * jediný podklad, odkud VetaNP (žádost o vrácení přeplatku) může vzít bankovní spojení.
     *
     * @return array{account_number:?string,bank_code:?string,bank_name:?string,iban:?string}|null
     */
    private function bankAccount(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_number, bank_code, bank_name, iban
               FROM currencies
              WHERE supplier_id = ? AND code = 'CZK' AND is_active = 1
           ORDER BY is_default DESC, id
              LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array{tax:float,accounting:float} */
    private function depreciation(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT kind, COALESCE(SUM(amount), 0) AS total
               FROM depreciation_entries
              WHERE supplier_id = ? AND fiscal_year = ?
              GROUP BY kind'
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $sums = ['tax' => 0.0, 'accounting' => 0.0];
        foreach ($stmt->fetchAll() as $row) {
            $kind = (string) $row['kind'];
            if (isset($sums[$kind])) {
                $sums[$kind] = round((float) $row['total'], 2);
            }
        }
        return $sums;
    }

    /**
     * Můstek účetní a daňové ZC vyřazeného majetku. U daru/škody je účetní ZC
     * už přičtena přes nedaňový účet 543/549, proto se daňová ZC znovu nepřičítá.
     * U prodeje/likvidace se rozdíl promítne do ř. 62 nebo 162.
     *
     * @return array{0:float,1:float,2:list<array<string,mixed>>,3:list<string>}
     */
    private function disposalResiduals(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.inventory_number, a.name, a.disposal_date, a.disposal_type,
                    a.input_price, a.opening_tax_amount,
                    (SELECT COALESCE(SUM(ai.amount), 0) FROM asset_improvements ai
                      WHERE ai.supplier_id = a.supplier_id AND ai.asset_id = a.id) AS improvements_total,
                    (SELECT de.residual_value_end FROM depreciation_entries de
                      WHERE de.supplier_id = a.supplier_id AND de.asset_id = a.id AND de.kind = \'tax\'
                      ORDER BY de.fiscal_year DESC LIMIT 1) AS tax_residual,
                    (SELECT SUM(jl.amount)
                       FROM journal_entries je
                       JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id
                       JOIN chart_of_accounts ca ON ca.id = jl.account_id
                      WHERE je.supplier_id = a.supplier_id AND je.source_type = \'asset_disposal\'
                        AND je.source_id = a.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                        AND jl.side = \'debit\' AND ca.account_type = \'expense\') AS book_residual,
                    (SELECT je.id FROM journal_entries je
                      WHERE je.supplier_id = a.supplier_id AND je.source_type = \'asset_disposal\'
                        AND je.source_id = a.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                      ORDER BY je.id DESC LIMIT 1) AS disposal_entry_id
               FROM assets a
              WHERE a.supplier_id = ? AND a.status = \'disposed\'
                AND a.disposal_date BETWEEN ? AND ?
              ORDER BY a.disposal_date, a.inventory_number'
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);

        $increase = 0.0;
        $decrease = 0.0;
        $disposals = [];
        $warnings = [];
        $hasLimited = false;
        foreach ($stmt->fetchAll() as $row) {
            $taxResidual = $row['tax_residual'] !== null
                ? (float) $row['tax_residual']
                : max(0.0, (float) $row['input_price'] + (float) $row['improvements_total'] - (float) $row['opening_tax_amount']);
            $deductibility = $this->classifyDisposal((string) $row['disposal_type']);

            $bookResidual = $row['disposal_entry_id'] !== null
                ? round((float) ($row['book_residual'] ?? 0), 2)
                : null;
            $taxIncrease = 0.0;
            $taxDecrease = 0.0;
            if ($deductibility === 'full' && $bookResidual !== null) {
                $taxIncrease = max(0.0, round($bookResidual - $taxResidual, 2));
                $taxDecrease = max(0.0, round($taxResidual - $bookResidual, 2));
                $increase += $taxIncrease;
                $decrease += $taxDecrease;
            } elseif ($deductibility === 'full') {
                $warnings[] = 'U majetku ' . (string) $row['inventory_number']
                    . ' chybí aktivní zápis vyřazení; rozdíl účetní a daňové ZC nelze automaticky promítnout.';
            } elseif ($deductibility === 'limited') {
                $hasLimited = true;
            }

            $disposals[] = [
                'asset_id' => (int) $row['id'],
                'inventory_number' => (string) $row['inventory_number'],
                'name' => (string) $row['name'],
                'disposal_date' => (string) $row['disposal_date'],
                'disposal_type' => (string) $row['disposal_type'],
                'tax_residual_value' => round($taxResidual, 2),
                'book_residual_value' => $bookResidual,
                'deductibility' => $deductibility,
                'non_deductible_part' => 0.0,
                'tax_increase' => $taxIncrease,
                'tax_decrease' => $taxDecrease,
            ];
        }

        if ($hasLimited) {
            $warnings[] = 'U vyřazení typu „škoda" je účetní ZC vedena jako nedaňová. Uznatelnou část do výše náhrad '
                . '(nebo při živelní pohromě dle §24/2/l ZDP) uplatněte ruční snižující položkou §23.';
        }

        return [round($increase, 2), round($decrease, 2), $disposals, $warnings];
    }

    private function classifyDisposal(string $type): string
    {
        return match ($type) {
            'sold', 'liquidated' => 'full',
            'donated' => 'none',
            default => 'limited', // damaged + neznámé
        };
    }
}
