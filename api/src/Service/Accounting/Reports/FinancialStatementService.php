<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use PDO;

/**
 * Rozvaha + výkaz zisku a ztráty (Epic F2, vyhl. 500/2002 Sb.):
 * zůstatky syntetik k rozvahovému dni (offbalance + closing vyloučeny, R2)
 * → mapa výkazu (StatementMapper: nejdelší prefix, balance_condition R8,
 * korekce gross − correction) → strom řádků (subtotal = Σ dětí + vlastní
 * mapované, computed = calc_key §1.2.4 + vlastní mapované). Minulé období
 * (R13) se počítá stejným během k ends_on předchozího období se STEJNOU
 * verzí mapování. Rozsah dle R12 (filtr level), scope='auto' přes
 * EntityCategoryService. Kontroly rovnosti v haléřích.
 */
final class FinancialStatementService
{
    /** VZZ v účelovém členění (vyhl. 500/2002 Sb., příloha č. 2 část II, § 39b). */
    public const TYPE_PURPOSE = 'income_statement_purpose';

    /**
     * Kódy řádků, které se ve výkazu ZOBRAZUJÍ jinak, než jsou vedené v datech.
     *
     * Ve výkazu se písmenné a římské označení tluče: v druhovém členění je „I." zároveň
     * Tržby i Úpravy hodnot ve finanční oblasti, v účelovém Tržby i Ostatní finanční
     * náklady. Kód musí být jedinečný (je klíčem mapy i vzorců), zobrazit se ale musí
     * tak, jak ho má vyhláška.
     */
    private const DISPLAY_CODE_ALIAS = [
        'I.n' => 'I.',
        'I.f' => 'I.',
    ];

    /**
     * Výnosové řádky, které si firma může podle § 35 vyhl. 500/2002 Sb. (úsudek účetní
     * jednotky o jejím obchodním modelu) přičíst k čistému obratu nad výchozí I. + II.,
     * v účelovém členění nad I. Rodič (IV.) zahrnuje své podřádky (IV.1., IV.2.), zvolené
     * podřádky zvoleného rodiče se proto nepřičítají podruhé.
     */
    public const NET_TURNOVER_EXTRA_ROW_OPTIONS = NetTurnoverPolicy::EXTRA_ROWS;

    public function __construct(
        private readonly Connection $db,
        private readonly LedgerReportRepository $ledger,
        private readonly StatementDefinitionRepository $definitions,
        private readonly AccountingPeriodRepository $periods,
        private readonly EntityCategoryService $categories,
        private readonly StatementMapper $mapper,
        private readonly StatementMapResolver $maps,
        private readonly \MyInvoice\Repository\AccountingSupplierSettingsRepository $settings,
        private readonly StatementFrameworkResolver $frameworkResolver,
        private readonly Statement504Service $statements504,
        private readonly NetTurnoverProjection $turnover,
        private readonly InterimAccrualAdjustment $interimAccruals,
    ) {}

    /**
     * Zvolené řádky výnosů obchodního modelu pro daný výkaz: jen povolené volby a bez
     * duplicit. Překryv rodičů a podřádků řeší projekce účetních zůstatků.
     *
     * @return list<string>
     */
    private function netTurnoverExtraRows(string $type, int $supplierId): array
    {
        return NetTurnoverPolicy::extraRows($type, $this->settings->getNetTurnoverExtraRows($supplierId)[$type] ?? []);
    }

    /**
     * `$accrualSpread` = průběžné časové rozlišení mimo deník ({@see InterimAccrualAdjustment}).
     * Je to jen pohled pro průběžné výkazy: zapíná ho výhradně volba v Účetnictví → Výkazy,
     * podání, závěrka, DPPO ani kontroly ho nepředávají a počítají s deníkem tak, jak je.
     *
     * @param 'full'|'small'|'micro'|'auto' $scope
     * @return array<string,mixed> struktura dle spec §2.7
     */
    public function balanceSheet(int $supplierId, int $periodId, ?string $asOf, string $scope, ?DimensionFilter $dimension = null, bool $accrualSpread = false): array
    {
        $context = $this->frameworkResolver->context($supplierId, $periodId, $asOf);
        if ($context['framework'] === 'cz_504') {
            return $this->statements504->build('balance_sheet', $supplierId, $context, $this->scope504($supplierId, $periodId, $scope), $dimension)
                + ['entity' => $this->loadEntity($supplierId)];
        }
        $ctx = $this->buildStatement('balance_sheet', $supplierId, $periodId, $asOf, $scope, $dimension, $accrualSpread);

        // D1 (audit 2026-07, H8): §3a odst. 2 písm. b) vyhl. 500/2002 Sb. — zkrácená
        // rozvaha malé ÚJ obsahuje položky písmen a římských číslic (level ≤ 2) A NAVÍC
        // položky C.II.1. a C.II.2. (level 3), které se uvádějí vždy zvlášť jako výslovná
        // výjimka z pravidla „jen do úrovně 2". Whitelist je proto vedle max_level filtru.
        $smallExtraRows = $ctx['scope'] === 'small'
            ? ['C.II.1.' => true, 'C.II.2.' => true]
            : [];

        $assets = [];
        $liabilities = [];
        foreach ($ctx['rows'] as $r) {
            if ($ctx['max_level'] !== null && (int) $r['level'] > $ctx['max_level']
                && !isset($smallExtraRows[(string) $r['row_code']])) {
                continue;
            }
            $code = (string) $r['row_code'];
            $v  = $ctx['values'][$code] ?? ['gross' => 0.0, 'correction' => 0.0];
            $pv = $ctx['values_prev'][$code] ?? ['gross' => 0.0, 'correction' => 0.0];
            $base = [
                'row_code' => $code,
                'label'    => (string) $r['label'],
                'level'    => (int) $r['level'],
                'row_type' => (string) $r['row_type'],
            ];
            if ((string) $r['section'] === 'assets') {
                $assets[] = $base + [
                    'display_code' => $code,
                    'gross'        => $v['gross'],
                    'correction'   => $v['correction'],
                    'net'          => round($v['gross'] - $v['correction'], 2),
                    'prev_net'     => round($pv['gross'] - $pv['correction'], 2),
                    'prev_gross'      => $pv['gross'],
                    'prev_correction' => $pv['correction'],
                    'accounts'     => $ctx['mapped'][$code]['accounts'] ?? [],
                ];
            } else {
                $liabilities[] = $base + [
                    'display_code' => str_starts_with($code, 'P.') ? substr($code, 2) : $code,
                    'amount'       => round($v['gross'] - $v['correction'], 2),
                    'prev_amount'  => round($pv['gross'] - $pv['correction'], 2),
                    'accounts'     => $ctx['mapped'][$code]['accounts'] ?? [],
                ];
            }
        }

        $assetsNet = $this->netOf($ctx['values'], 'AKTIVA');
        $liabilitiesTotal = $this->netOf($ctx['values'], 'PASIVA');
        $profitFromBalances = $this->profitFromBalances($ctx['balances']);
        $mapped431 = $ctx['mapped']['P.A.V.']['gross'] ?? 0.0;
        $profitInBalanceSheet = round($this->netOf($ctx['values'], 'P.A.V.') - $mapped431, 2);

        return [
            'statement_type' => 'balance_sheet',
            'version_code'   => (string) $ctx['version']['version_code'],
            'as_of'          => $ctx['as_of'],
            'scope'          => $ctx['scope'],
            'entity'         => $this->loadEntity($supplierId),
            'period'         => $this->periodOut($ctx['period']),
            'prev_period'    => $ctx['prev_period'] === null ? null : $this->periodOut($ctx['prev_period']),
            'assets'         => $assets,
            'liabilities'    => $liabilities,
            // Rozvaha za hodnotu dimenze obsahuje jen řádky s touto hodnotou (u rozpadu
            // jejich díl). Vyrovnaná je jen tehdy, když hodnotu nesou obě strany všech
            // zápisů — platba bez dimenze nechá pohledávku projektu „otevřenou".
            'dimension'      => $dimension?->toArray(),
            'checks'         => [
                'assets_net'        => $assetsNet,
                'liabilities_total' => $liabilitiesTotal,
                'balanced'          => self::cents($assetsNet) === self::cents($liabilitiesTotal),
                'profit_current'    => $profitFromBalances,
                'profit_matches'    => self::cents($profitInBalanceSheet) === self::cents($profitFromBalances),
                'unmapped_accounts' => $ctx['unmapped'],
                'negative_net_rows' => self::negativeNetRows($ctx['rows'], $ctx['values'], $ctx['values_prev']),
                // null = firma souhrnné vykázání daní vůči FÚ nepoužívá; jinak započtená částka.
                'tax_authority_offset' => $ctx['tax_offset'],
            ],
        ] + self::accrualSpreadOut($ctx);
    }

    /**
     * Řádky aktiv se záporným netto (korekce vyšší než brutto nebo brutto s kreditním
     * zůstatkem) v běžném i minulém období. Aktivum se záporným netto do rozvahy nepatří:
     * obvykle jde o opravnou položku zařazenou jinam než pohledávka, ke které patří (celá
     * 391 u obchodních pohledávek, pohledávka výjimkou v dlouhodobých). Výkaz se kvůli tomu
     * nezastaví, ale uživatel i protokol převodu to musí vidět dřív, než výkaz odevzdá.
     *
     * Hlásí se řádek, kde záporné netto vzniká: mezisoučet, jehož podřádek je sám záporný,
     * se vynechá, aby jedna příčina nebyla v seznamu několikrát.
     *
     * @param list<array<string,mixed>>                          $rows
     * @param array<string, array{gross: float, correction: float}> $values
     * @param array<string, array{gross: float, correction: float}> $valuesPrev
     * @return list<array{row_code:string,label:string,column:string,gross:float,correction:float,net:float}>
     */
    private static function negativeNetRows(array $rows, array $values, array $valuesPrev): array
    {
        $children = [];
        foreach ($rows as $r) {
            $parent = (string) ($r['parent_row_code'] ?? '');
            if ($parent !== '') {
                $children[$parent][] = (string) $r['row_code'];
            }
        }

        $out = [];
        foreach (['current' => $values, 'previous' => $valuesPrev] as $column => $set) {
            $negative = [];
            foreach ($rows as $r) {
                $code = (string) $r['row_code'];
                $v = $set[$code] ?? null;
                if ((string) $r['section'] === 'assets' && $v !== null && self::cents($v['gross'] - $v['correction']) < 0) {
                    $negative[$code] = $r;
                }
            }
            foreach ($negative as $code => $r) {
                foreach ($children[$code] ?? [] as $child) {
                    if (isset($negative[$child])) {
                        continue 2;
                    }
                }
                $v = $set[$code];
                $out[] = [
                    'row_code'   => (string) $code,
                    'label'      => (string) $r['label'],
                    'column'     => $column,
                    'gross'      => $v['gross'],
                    'correction' => $v['correction'],
                    'net'        => round($v['gross'] - $v['correction'], 2),
                ];
            }
        }

        return $out;
    }

    /**
     * VZZ v ÚČELOVÉM členění — vyhláška 500/2002 Sb., příloha č. 2 část II (§ 39b).
     *
     * Náklady se nečlení podle druhu, ale podle FUNKCE: náklady prodeje (A.), odbytové
     * náklady (B.), správní režie (C.). To z čísla účtu odvodit nejde, přiřazení proto
     * pochází z per-firma mapy {@see StatementDefinitionRepository::functionMap()}.
     *
     * Když je přiřazení NEÚPLNÉ, výkaz se NESESTAVÍ. Nepřiřazený náklad by totiž do
     * žádného řádku nespadl, tiše vypadl z výkazu a hrubý zisk i výsledek hospodaření
     * by vyšly vyšší, než jaké jsou — a to na výkazu, který se podává. Chybová hláška
     * s výčtem účtů je jediná poctivá odpověď.
     *
     * @param 'full'|'small'|'micro'|'auto' $scope
     * @return array<string,mixed>
     */
    public function incomeStatementByFunction(int $supplierId, int $periodId, ?string $asOf, string $scope, ?DimensionFilter $dimension = null, bool $accrualSpread = false): array
    {
        $out = $this->incomeStatementFor(self::TYPE_PURPOSE, $supplierId, $periodId, $asOf, $scope, $dimension, $accrualSpread);

        $unmapped = $out['checks']['unmapped_accounts'];
        if ($unmapped !== []) {
            $codes = array_map(static fn (array $a): string => (string) ($a['account_code'] ?? '?'), $unmapped);
            throw new ReportException('function_map_incomplete', sprintf(
                'Účelové členění nelze sestavit: %d účtům s obratem chybí přiřazení funkci '
                . '(náklady prodeje / odbytové náklady / správní režie) — %s. '
                . 'Doplňte přiřazení v mapě funkcí, jinak by tyto částky ve výkazu chyběly.',
                count($codes),
                implode(', ', array_slice($codes, 0, 20)) . (count($codes) > 20 ? ' …' : ''),
            ));
        }

        return $out;
    }

    /**
     * `$accrualSpread` viz {@see balanceSheet()}.
     *
     * @param 'full'|'small'|'micro'|'auto' $scope
     * @return array<string,mixed> struktura dle spec §2.7
     */
    public function incomeStatement(int $supplierId, int $periodId, ?string $asOf, string $scope, ?DimensionFilter $dimension = null, bool $accrualSpread = false): array
    {
        return $this->incomeStatementFor('income_statement', $supplierId, $periodId, $asOf, $scope, $dimension, $accrualSpread);
    }

    /**
     * Společné jádro obou variant VZZ — liší se jen verzí mapování a vzorci computed
     * řádků, výstupní struktura je shodná.
     *
     * @param 'income_statement'|'income_statement_purpose' $type
     * @param 'full'|'small'|'micro'|'auto' $scope
     * @return array<string,mixed>
     */
    private function incomeStatementFor(string $type, int $supplierId, int $periodId, ?string $asOf, string $scope, ?DimensionFilter $dimension = null, bool $accrualSpread = false): array
    {
        $context = $this->frameworkResolver->context($supplierId, $periodId, $asOf);
        if ($context['framework'] === 'cz_504') {
            return $this->statements504->build($type, $supplierId, $context, $this->scope504($supplierId, $periodId, $scope), $dimension)
                + ['entity' => $this->loadEntity($supplierId)];
        }
        $ctx = $this->buildStatement($type, $supplierId, $periodId, $asOf, $scope, $dimension, $accrualSpread);

        $rows = [];
        foreach ($ctx['rows'] as $r) {
            if ($ctx['max_level'] !== null && (int) $r['level'] > $ctx['max_level']) {
                continue;
            }
            $code = (string) $r['row_code'];
            $v  = $ctx['values'][$code] ?? ['gross' => 0.0, 'correction' => 0.0];
            $pv = $ctx['values_prev'][$code] ?? ['gross' => 0.0, 'correction' => 0.0];
            $rows[] = [
                'row_code'     => $code,
                'display_code' => self::DISPLAY_CODE_ALIAS[$code] ?? $code,
                'label'        => (string) $r['label'],
                'level'        => (int) $r['level'],
                'row_type'     => (string) $r['row_type'],
                'amount'       => round($v['gross'] - $v['correction'], 2),
                'prev_amount'  => round($pv['gross'] - $pv['correction'], 2),
                'accounts'     => $ctx['mapped'][$code]['accounts'] ?? [],
            ];
        }

        $profitCurrent = $this->netOf($ctx['values'], 'VH');
        $profitFromBalances = $this->profitFromBalances($ctx['balances']);

        return [
            'statement_type' => $type,
            'version_code'   => (string) $ctx['version']['version_code'],
            'as_of'          => $ctx['as_of'],
            'scope'          => $ctx['scope'],
            'entity'         => $this->loadEntity($supplierId),
            'period'         => $this->periodOut($ctx['period']),
            'prev_period'    => $ctx['prev_period'] === null ? null : $this->periodOut($ctx['prev_period']),
            'rows'           => $rows,
            'dimension'      => $dimension?->toArray(),
            'checks'         => [
                'profit_current' => $profitCurrent,
                'net_turnover'   => $this->netOf($ctx['values'], 'OBRAT'),
                'profit_matches' => self::cents($profitCurrent) === self::cents($profitFromBalances),
                'unmapped_accounts' => $ctx['unmapped'],
            ],
        ] + self::accrualSpreadOut($ctx);
    }

    /**
     * Souhrn průběžného časového rozlišení pro výstup výkazu. Klíč `accrual_spread` je ve
     * výstupu jen při zapnuté volbě, výkaz bez ní má beze změny tvar i čísla.
     *
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function accrualSpreadOut(array $ctx): array
    {
        return $ctx['accrual_spread'] === null ? [] : ['accrual_spread' => $ctx['accrual_spread']];
    }

    /**
     * Rozvaha a výsledovka „po účtech" s hospodářským výsledkem k libovolnému dni roku.
     *
     * Zůstatky jsou konečné stavy obratové předvahy (trialBalanceRows) se stejnými okny
     * jako výkazy: rozvahové účty od kotvy počátečního stavu, výsledkové od začátku
     * období, uzávěrkový zápis vyloučený. Pohled je proto před uzavřením knih i po něm
     * stejný a po uzávěrce ukazuje přesně to, co uzávěrka převedla na 702 a 710.
     *
     * Výsledkové účty se do provozní, finanční části, daně a převodu podílu dělí podle
     * mapy druhové VZZ (včetně výjimek firmy): řádek, kam výkaz účet zařadí, leží v
     * definici výkazu před mezisoučtem provozního VH, před finančním VH, mezi VH před
     * zdaněním a po zdanění, nebo za ním. Účet mimo mapu jde do skupiny „nezařazené"
     * a VH ho zahrnuje, výkaz VZZ ho ale nemá, proto ho kontroly vypíšou.
     *
     * S `$columns` přidá klíč `columns` ({@see StatementAccountColumns}). Je to jen pro
     * obrazovku, XLSX a PDF: chce navíc dotaz na obraty měsíce, který souhrny nad více firmami
     * neplatí. Rámec 504 má vlastní pohled a sloupce nemá.
     *
     * @param array{hide_zero:bool, analytics:bool}|null $columns
     * @return array<string,mixed>
     */
    public function accountView(int $supplierId, int $periodId, ?string $asOf, ?DimensionFilter $dimension = null, ?array $columns = null): array
    {
        $context = $this->frameworkResolver->context($supplierId, $periodId, $asOf);
        if ($context['framework'] === 'cz_504') {
            return $this->statements504->accountView($supplierId, $context, $dimension) + ['entity' => $this->loadEntity($supplierId)];
        }
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        if ($asOf === null || $asOf === '') {
            $asOf = min((string) $period['ends_on'], date('Y-m-d'));
        }
        $from = (string) $period['starts_on'];

        $version = $this->definitions->findVersion('income_statement', $asOf);
        if ($version === null) {
            throw new ReportException('statement_version_missing', 'Pro rozvahový den ' . $asOf . ' neexistuje verze mapování výkazu.');
        }
        $map = $this->maps->accountMap($version, $supplierId, (int) $period['fiscal_year']);
        $sectionByRow = self::profitLossSections($this->definitions->rows((int) $version['id']));

        $raw = $this->ledger->trialBalanceRows(
            $supplierId, $from, $asOf, $from, true,
            $dimension !== null ? ['dimension' => $dimension] : [], true,
        );

        $sectionOf = function (string $accountCode) use ($map, $sectionByRow): string {
            foreach ($this->mapper->entriesFor($map, $accountCode, null) as $m) {
                $section = $sectionByRow[(string) $m['row_code']] ?? null;
                if ($section !== null) {
                    return $section;
                }
            }
            return 'unassigned';
        };

        $balanceLeaves = [];
        $plLeaves = [];
        $technicalCents = 0;
        foreach ($raw as $r) {
            $delta = self::cents($r['ps_md']) - self::cents($r['ps_d']) + self::cents($r['to_md']) - self::cents($r['to_d']);
            if ($delta === 0) {
                continue;
            }
            $type = (string) $r['account_type'];
            $leaf = [
                'account_id'     => (int) $r['id'],
                'account_code'   => (string) $r['account_code'],
                'name'           => (string) $r['name'],
                'account_type'   => $type,
                'synthetic_id'   => $r['parent_id'] ?? (int) $r['id'],
                'synthetic_code' => (string) ($r['parent_code'] ?? $r['account_code']),
                'synthetic_name' => (string) ($r['parent_name'] ?? $r['name']),
                'delta'          => $delta,
            ];
            if (in_array($type, ['asset', 'liability', 'equity'], true)) {
                $balanceLeaves[] = $leaf;
            } elseif ($type === 'revenue' || $type === 'expense') {
                $leaf['section'] = $sectionOf($leaf['account_code']);
                $plLeaves[] = $leaf;
            } elseif ($type === 'closing') {
                $technicalCents += $delta;
            }
        }

        $classes = [];
        foreach (self::groupSynthetics($balanceLeaves) as $synthetic) {
            $class = substr((string) $synthetic['account_code'], 0, 1);
            $classes[$class] ??= ['class' => $class, 'accounts' => [], 'md' => 0, 'd' => 0];
            $classes[$class]['accounts'][] = $synthetic;
            $classes[$class]['md'] += self::cents($synthetic['md']);
            $classes[$class]['d']  += self::cents($synthetic['d']);
        }
        ksort($classes, SORT_STRING);
        $balanceMd = array_sum(array_column($classes, 'md'));
        $balanceD  = array_sum(array_column($classes, 'd'));
        $profitBalance = $balanceMd - $balanceD;

        $sections = [];
        foreach (self::ACCOUNT_VIEW_SECTIONS as $key) {
            $sections[$key] = ['key' => $key, 'expenses' => [], 'revenues' => [], 'expense_total' => 0, 'revenue_total' => 0];
        }
        foreach (self::groupSynthetics($plLeaves, 'section') as $synthetic) {
            $key = (string) $synthetic['section'];
            unset($synthetic['section']);
            $net = self::cents($synthetic['md']) - self::cents($synthetic['d']);
            if ($synthetic['account_type'] === 'expense') {
                $sections[$key]['expenses'][] = $synthetic;
                $sections[$key]['expense_total'] += $net;
            } else {
                $sections[$key]['revenues'][] = $synthetic;
                $sections[$key]['revenue_total'] -= $net;
            }
        }
        $result = [];
        foreach ($sections as $key => $s) {
            $result[$key] = $s['revenue_total'] - $s['expense_total'];
        }
        $operating   = $result['operating'];
        $financial   = $result['financial'];
        $beforeTax   = $operating + $financial + $result['unassigned'];
        $afterTax    = $beforeTax + $result['tax'];
        $profitLoss  = $afterTax + $result['transfer'];

        $money = static fn (int $c): float => $c / 100;

        return [
            'version_code' => (string) $version['version_code'],
            'as_of'   => $asOf,
            'entity'  => $this->loadEntity($supplierId),
            'period'  => $this->periodOut($period),
            'closed'  => AccountingPeriodStatus::isClosed((string) $period['status']),
            'dimension' => $dimension?->toArray(),
            'balance' => [
                'classes' => array_values(array_map(static fn (array $c): array => [
                    'class'    => $c['class'],
                    'accounts' => $c['accounts'],
                    'md'       => $money($c['md']),
                    'd'        => $money($c['d']),
                ], $classes)),
                'md'     => $money($balanceMd),
                'd'      => $money($balanceD),
                'profit' => $money($profitBalance),
            ],
            'profit_loss' => [
                'sections' => array_values(array_map(static fn (array $s): array => [
                    'key'           => $s['key'],
                    'expenses'      => $s['expenses'],
                    'revenues'      => $s['revenues'],
                    'expense_total' => $money($s['expense_total']),
                    'revenue_total' => $money($s['revenue_total']),
                    'result'        => $money($s['revenue_total'] - $s['expense_total']),
                ], $sections)),
                'operating_profit'  => $money($operating),
                'financial_profit'  => $money($financial),
                'profit_before_tax' => $money($beforeTax),
                'profit_after_tax'  => $money($afterTax),
                'profit'            => $money($profitLoss),
            ],
            'checks' => [
                'profit_balance'     => $money($profitBalance),
                'profit_loss'        => $money($profitLoss),
                'profit_matches'     => $profitBalance === $profitLoss,
                'technical_residual' => $money($technicalCents),
                'unassigned_count'   => count($sections['unassigned']['expenses']) + count($sections['unassigned']['revenues']),
            ],
        ] + ($columns === null ? [] : ['columns' => $this->accountColumns($supplierId, $raw, $sectionOf, $from, $asOf, $dimension, $columns)]);
    }

    /**
     * @param list<array<string,mixed>> $raw
     * @param callable(string):string $sectionOf
     * @param array{hide_zero:bool, analytics:bool} $options
     * @return array<string,mixed>
     */
    private function accountColumns(int $supplierId, array $raw, callable $sectionOf, string $from, string $asOf, ?DimensionFilter $dimension, array $options): array
    {
        // Období nemusí začínat prvním dnem měsíce, obrat měsíce pak jde od jeho začátku.
        $monthFrom = max(substr($asOf, 0, 7) . '-01', $from);
        $monthRows = $this->ledger->monthlyTurnovers(
            $supplierId, $monthFrom, $asOf, true, $dimension !== null ? ['dimension' => $dimension] : [], true,
        );
        return StatementAccountColumns::build($raw, $monthRows, $sectionOf, self::ACCOUNT_VIEW_SECTIONS, $options + [
            'from' => $from, 'month_from' => $monthFrom, 'as_of' => $asOf,
        ]);
    }

    /** Skupiny výsledkových účtů pohledu po účtech, v pořadí výsledovky. */
    public const ACCOUNT_VIEW_SECTIONS = ['operating', 'financial', 'unassigned', 'tax', 'transfer'];

    /**
     * Skupina (provozní, finanční, daň, převod podílu) každého řádku druhové VZZ podle
     * polohy v definici výkazu vůči mezisoučtům. Stejné mezisoučty sčítá calcValue(),
     * takže účet dostane skupinu, do které ho výkaz opravdu započte.
     *
     * @param list<array<string,mixed>> $rows řádky verze seřazené podle position
     * @return array<string,string> row_code → skupina
     */
    private static function profitLossSections(array $rows): array
    {
        $after = [
            'operating_profit'  => 'financial',
            'financial_profit'  => 'unassigned',
            'profit_before_tax' => 'tax',
            'profit_after_tax'  => 'transfer',
            'profit_current'    => null,
        ];
        $section = 'operating';
        $out = [];
        foreach ($rows as $r) {
            if ((string) $r['row_type'] === 'computed') {
                $key = (string) $r['calc_key'];
                if (array_key_exists($key, $after)) {
                    $section = $after[$key];
                }
                continue;
            }
            if ($section !== null && $section !== 'unassigned') {
                $out[(string) $r['row_code']] = $section;
            }
        }
        return $out;
    }

    /**
     * Listové účty složené pod syntetiku. MD a D syntetiky jsou součty stran jejích
     * analytik bez vzájemné kompenzace, takže součty tříd i celé strany sedí na
     * řádky, jak je sestava zobrazuje.
     *
     * @param list<array<string,mixed>> $leaves
     * @return list<array<string,mixed>>
     */
    private static function groupSynthetics(array $leaves, ?string $groupKey = null): array
    {
        $out = [];
        foreach ($leaves as $leaf) {
            $key = ($groupKey !== null ? $leaf[$groupKey] . '|' . $leaf['account_type'] . '|' : '') . $leaf['synthetic_code'];
            $md = $leaf['delta'] > 0 ? $leaf['delta'] : 0;
            $d  = $leaf['delta'] < 0 ? -$leaf['delta'] : 0;
            if (!isset($out[$key])) {
                $out[$key] = [
                    'account_id'   => (int) $leaf['synthetic_id'],
                    'account_code' => $leaf['synthetic_code'],
                    'name'         => $leaf['synthetic_name'],
                    'account_type' => $leaf['account_type'],
                    'md'           => 0,
                    'd'            => 0,
                    'analytics'    => [],
                ] + ($groupKey !== null ? [$groupKey => $leaf[$groupKey]] : []);
            }
            $out[$key]['md'] += $md;
            $out[$key]['d']  += $d;
            $out[$key]['analytics'][] = [
                'account_id'   => $leaf['account_id'],
                'account_code' => $leaf['account_code'],
                'name'         => $leaf['name'],
                'md'           => $md / 100,
                'd'            => $d / 100,
            ];
        }
        ksort($out, SORT_STRING);
        return array_values(array_map(static function (array $s): array {
            if (count($s['analytics']) === 1 && $s['analytics'][0]['account_id'] === $s['account_id']) {
                $s['analytics'] = [];
            }
            $s['md'] /= 100;
            $s['d'] /= 100;
            return $s;
        }, $out));
    }

    // ── společné jádro ─────────────────────────────────────────────────────────

    /**
     * @param 'balance_sheet'|'income_statement'|'income_statement_purpose' $type
     * @return array<string,mixed>
     */
    private function buildStatement(string $type, int $supplierId, int $periodId, ?string $asOf, string $scope, ?DimensionFilter $dimension = null, bool $accrualSpread = false): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        if ($asOf === null || $asOf === '') {
            $asOf = min((string) $period['ends_on'], date('Y-m-d'));
        }

        $version = $this->definitions->findVersion($type, $asOf);
        if ($version === null) {
            throw new ReportException('statement_version_missing', 'Pro rozvahový den ' . $asOf . ' neexistuje verze mapování výkazu.');
        }
        $rows = $this->definitions->rows((int) $version['id']);
        // Sloučená mapa firmy: globální + (účelová VZZ) mapa funkcí + výjimky firmy platné
        // v roce období. Skládá ji jen StatementMapResolver, aby výjimka platila ve všech
        // výkazech stejně.
        $baseMap = $this->maps->accountMap($version, $supplierId, (int) $period['fiscal_year']);
        $map = $baseMap;
        if ($type === 'balance_sheet' && $asOf < (string) $period['ends_on']) {
            foreach ($map as &$mapping) {
                if ((string) $mapping['account_prefix'] === '431') {
                    $mapping['row_code'] = 'P.A.IV.1.';
                }
            }
            unset($mapping);
        }
        $splitCodes = $this->mapper->noCompensationPrefixes($map);
        $analyticPrefixes = $this->mapper->analyticPrefixes($map);

        // Průběžné časové rozlišení mimo deník (volba výkazu). Výkaz za hodnotu dimenze ho
        // nepoužije: úprava se počítá za celé doklady, ne za díl řádků s hodnotou dimenze.
        $spreadOn = $accrualSpread && $dimension === null;
        $spread = $spreadOn ? $this->interimAccruals->compute($supplierId, (string) $period['starts_on'], $asOf) : null;

        $balances = $this->ledger->syntheticBalances(
            $supplierId,
            $asOf,
            (string) $period['starts_on'],
            $splitCodes,
            $analyticPrefixes,
            $dimension,
            null,
            $spread['deltas'] ?? [],
        );
        $mapped   = $this->mapper->map($rows, $map, $balances);
        // § 58 odst. 2 vyhl.: souhrnné vykázání daňových pohledávek a závazků vůči FÚ, jen na
        // volbu firmy a od roku, kdy ho začala používat. Sloupec minulého období se řídí
        // stejně jako výjimky mapování: pravidly běžného roku, nebo s převzetím z uzavřeného
        // výkazu pravidly minulého roku.
        $comparativeFromPriorYear = $this->settings->getComparativeFromPriorYear($supplierId);
        $offsetIn = fn (int $year): bool => $type === 'balance_sheet' && $this->settings->taxAuthorityOffsetAppliesIn($supplierId, $year);
        $taxOffsetOn = $offsetIn((int) $period['fiscal_year']);
        $taxOffset = ['current' => 0.0, 'previous' => 0.0];
        if ($taxOffsetOn) {
            ['mapped' => $mapped, 'amount' => $taxOffset['current']] = TaxAuthorityOffset::apply($rows, $mapped);
        }
        $unmapped = $this->mapper->unmappedBalances(
            $map,
            $balances,
            $type === 'balance_sheet' ? ['asset', 'liability', 'equity'] : ['revenue', 'expense'],
        );
        $startsOn = (string) $period['starts_on'];
        $turnoverExtra = $this->netTurnoverExtraRows($type, $supplierId);
        $values   = $this->computeValues($rows, $mapped, $balances, $type, $startsOn, $turnoverExtra, $map);

        // R13: minulé období stejným během k ends_on předchozího období, stejná verze.
        // Výchozí zařazení je podle běžného roku (údaje minulého období přepočtené, aby byly
        // srovnatelné). Firma, která sloupec přebírá z uzavřeného výkazu minulého roku,
        // ho má s výjimkami platnými v minulém roce.
        $prevPeriod = $this->ledger->previousPeriod($supplierId, $startsOn);
        $valuesPrev = [];
        $spreadPrev = null;
        if ($prevPeriod !== null) {
            $prevMap = $baseMap;
            $prevSplitCodes = $splitCodes;
            if ($comparativeFromPriorYear) {
                $prevMap = $this->maps->accountMap($version, $supplierId, (int) $prevPeriod['fiscal_year']);
                $prevSplitCodes = $this->mapper->noCompensationPrefixes($prevMap);
            }
            // Sloupec minulého období stejným pravidlem k jeho konci. Rok, jehož uzávěrka
            // časové rozlišení zaúčtovala, se tím nezmění: odložené doklady služba vyřadí.
            $spreadPrev = $spreadOn
                ? $this->interimAccruals->compute($supplierId, (string) $prevPeriod['starts_on'], (string) $prevPeriod['ends_on'])
                : null;
            $balancesPrev = $this->ledger->syntheticBalances(
                $supplierId,
                (string) $prevPeriod['ends_on'],
                (string) $prevPeriod['starts_on'],
                $prevSplitCodes,
                $this->mapper->analyticPrefixes($prevMap),
                $dimension,
                null,
                $spreadPrev['deltas'] ?? [],
            );
            $mappedPrev = $this->mapper->map($rows, $prevMap, $balancesPrev);
            if ($comparativeFromPriorYear ? $offsetIn((int) $prevPeriod['fiscal_year']) : $taxOffsetOn) {
                ['mapped' => $mappedPrev, 'amount' => $taxOffset['previous']] = TaxAuthorityOffset::apply($rows, $mappedPrev);
            }
            $valuesPrev = $this->computeValues($rows, $mappedPrev, $balancesPrev, $type, (string) $prevPeriod['starts_on'], $turnoverExtra, $prevMap);
            // Čistý obrat v pojetí od 1. 1. 2024 se za minulé období spočtené po staru
            // neuvádí (stanovisko MF a Komory auditorů ČR z 24. 7. 2024): dvě různé veličiny
            // vedle sebe by nebyly srovnatelné.
            if (self::netTurnoverIsBusinessModel($startsOn)
                && !self::netTurnoverIsBusinessModel((string) $prevPeriod['starts_on'])) {
                foreach ($rows as $r) {
                    if ((string) $r['calc_key'] === 'net_turnover') {
                        $valuesPrev[(string) $r['row_code']] = ['gross' => 0.0, 'correction' => 0.0];
                    }
                }
            }
        } elseif ($type === 'balance_sheet') {
            // První účetní období (§ 4 odst. 7 vyhl. 500/2002 Sb., § 17 odst. 1 písm. a) ZoÚ):
            // sloupec minulého období nese zahajovací rozvahu, tj. stav po zápisech ke dni
            // zahájení období (typicky splacení základního kapitálu 353/411). Firma převedená
            // odjinud bez předchozího období tu má počáteční stavy, tedy konečné zůstatky
            // předchozího roku, což je pro sloupec minulého období totéž. VZZ minulé období
            // v prvním roce nemá, zůstává nulové.
            $balancesOpening = $this->ledger->syntheticBalances(
                $supplierId,
                $startsOn,
                $startsOn,
                $splitCodes,
                $this->mapper->analyticPrefixes($baseMap),
                $dimension,
            );
            $mappedOpening = $this->mapper->map($rows, $baseMap, $balancesOpening);
            if ($taxOffsetOn) {
                ['mapped' => $mappedOpening, 'amount' => $taxOffset['previous']] = TaxAuthorityOffset::apply($rows, $mappedOpening);
            }
            $valuesPrev = $this->computeValues($rows, $mappedOpening, $balancesOpening, $type, $startsOn, $turnoverExtra, $baseMap);
        }

        $resolvedScope = $scope === 'auto' ? $this->categories->statementScope($supplierId, $periodId) : $scope;
        // R12: rozvaha mikro = level ≤ 1, malá = level ≤ 2; VZZ zkrácená (mikro i malá) = level ≤ 1.
        $maxLevel = match (true) {
            $resolvedScope === 'micro'                              => 1,
            $resolvedScope === 'small' && $type === 'balance_sheet' => 2,
            $resolvedScope === 'small'                              => 1,
            default                                                 => null,
        };

        return [
            'period'      => $period,
            'prev_period' => $prevPeriod,
            'version'     => $version,
            'as_of'       => $asOf,
            'scope'       => $resolvedScope,
            'max_level'   => $maxLevel,
            'rows'        => $rows,
            'mapped'      => $mapped,
            'balances'    => $balances,
            'unmapped'    => $unmapped,
            'values'      => $values,
            'values_prev' => $valuesPrev,
            'tax_offset'  => $taxOffsetOn ? $taxOffset : null,
            'accrual_spread' => !$accrualSpread ? null : [
                'applied' => $spreadOn,
                // Důvod, proč se volba neuplatnila (výkaz za hodnotu dimenze).
                'skipped_reason' => $spreadOn ? null : 'dimension',
                'prepaid_expense' => $spread['prepaid_expense'] ?? 0.0,
                'deferred_revenue' => $spread['deferred_revenue'] ?? 0.0,
                'documents' => $spread['documents'] ?? 0,
                'prev' => $spreadPrev === null ? null : [
                    'prepaid_expense' => $spreadPrev['prepaid_expense'],
                    'deferred_revenue' => $spreadPrev['deferred_revenue'],
                    'documents' => $spreadPrev['documents'],
                ],
            ],
        ];
    }

    /**
     * Hodnoty všech řádků výkazu: detail = vlastní mapované; subtotal = Σ dětí
     * + vlastní mapované (korekce 091→B.I. apod.); computed = calc_key (§1.2.4)
     * + vlastní mapované (P.A.V. = profit_current + saldo 431).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string, array{gross: float, correction: float, accounts: list<array<string,mixed>>}> $mapped
     * @param list<array<string,mixed>> $balances
     * @return array<string, array{gross: float, correction: float}>
     */
    private function computeValues(array $rows, array $mapped, array $balances, string $type, string $periodStartsOn, array $turnoverExtra = [], array $map = []): array
    {
        $byCode = [];
        $children = [];
        foreach ($rows as $r) {
            $byCode[(string) $r['row_code']] = $r;
        }
        foreach ($rows as $r) {
            $parent = $r['parent_row_code'];
            if ($parent !== null && $parent !== '') {
                $children[(string) $parent][] = (string) $r['row_code'];
            }
        }

        $turnover = $type === 'balance_sheet' ? 0.0 : $this->turnover->project(
            $rows, $map, $balances, $type, $periodStartsOn, $turnoverExtra,
        )['amount'];
        $memo = [];
        $resolve = null;
        $net = function (string $code) use (&$resolve): float {
            $v = $resolve($code);
            return round($v['gross'] - $v['correction'], 2);
        };
        $resolve = function (string $code) use (&$resolve, &$memo, $byCode, $children, $mapped, $balances, $type, $net, $periodStartsOn, $turnoverExtra, $turnover): array {
            if (isset($memo[$code])) {
                return $memo[$code];
            }
            $row = $byCode[$code] ?? null;
            if ($row === null) {
                return ['gross' => 0.0, 'correction' => 0.0];
            }
            $gross = $mapped[$code]['gross'] ?? 0.0;
            $correction = $mapped[$code]['correction'] ?? 0.0;
            if ((string) $row['row_type'] === 'subtotal') {
                foreach ($children[$code] ?? [] as $child) {
                    $cv = $resolve($child);
                    $gross += $cv['gross'];
                    $correction += $cv['correction'];
                }
            } elseif ((string) $row['row_type'] === 'computed') {
                $gross += (string) $row['calc_key'] === 'net_turnover'
                    ? $turnover
                    : $this->calcValue((string) $row['calc_key'], $type, $net, $balances);
            }
            return $memo[$code] = ['gross' => round($gross, 2), 'correction' => round($correction, 2)];
        };

        foreach ($byCode as $code => $_) {
            $resolve((string) $code);
        }
        return $memo;
    }

    /**
     * Čistý obrat jako výnosy obchodního modelu (§ 1a odst. 2 zákona o účetnictví ve znění
     * účinném od 1. 1. 2024, § 35 vyhl. 500/2002 Sb.): tržby z prodeje výrobků, zboží
     * a služeb. Pro účetní období započatá před tímto dnem platí podle přechodného
     * ustanovení dosavadní součet výnosů.
     */
    private static function netTurnoverIsBusinessModel(string $periodStartsOn): bool
    {
        return NetTurnoverPolicy::isBusinessModel($periodStartsOn);
    }

    /**
     * Vzorce computed řádků (§1.2.4). V rozvaze je jediný calc_key profit_current
     * (P.A.V.) — VH za fiskální rok přímo z výsledkových účtů (každý účet 5xx/6xx
     * je v mapě VZZ právě jednou, hodnoty jsou ekvivalentní vzorci VZZ).
     *
     * Čistý obrat počítá NetTurnoverProjection ze stejných účetních zůstatků.
     *
     * @param callable(string): float $net netto hodnota řádku dle row_code
     * @param list<array<string,mixed>> $balances
     */
    private function calcValue(string $key, string $type, callable $net, array $balances): float
    {
        if ($type === 'balance_sheet') {
            return $key === 'profit_current' ? $this->profitFromBalances($balances) : 0.0;
        }
        // Účelové členění má vlastní kódy řádků (A. je náklad prodeje, ne výkonová
        // spotřeba), takže vzorce druhového by tu sečetly něco úplně jiného.
        if ($type === self::TYPE_PURPOSE) {
            return round(match ($key) {
                'gross_profit'      => $net('I.') - $net('A.'),
                'operating_profit'  => $this->calcValue('gross_profit', $type, $net, $balances)
                                     - $net('B.') - $net('C.') + $net('II.') - $net('D.'),
                'financial_profit'  => $net('III.') - $net('E.') + $net('IV.') - $net('F.')
                                     + $net('V.') - $net('G.') - $net('H.') + $net('VI.') - $net('I.f'),
                'profit_before_tax' => $this->calcValue('operating_profit', $type, $net, $balances)
                                     + $this->calcValue('financial_profit', $type, $net, $balances),
                'profit_after_tax'  => $this->calcValue('profit_before_tax', $type, $net, $balances) - $net('J.'),
                'profit_current'    => $this->calcValue('profit_after_tax', $type, $net, $balances) - $net('K.'),
                default             => 0.0,
            }, 2);
        }

        return round(match ($key) {
            'operating_profit'  => $net('I.') + $net('II.') - $net('A.') - $net('B.') - $net('C.')
                                 - $net('D.') - $net('E.') + $net('III.') - $net('F.'),
            'financial_profit'  => $net('IV.') - $net('G.') + $net('V.') - $net('H.') + $net('VI.')
                                 - $net('I.n') - $net('J.') + $net('VII.') - $net('K.'),
            'profit_before_tax' => $this->calcValue('operating_profit', $type, $net, $balances)
                                 + $this->calcValue('financial_profit', $type, $net, $balances),
            'profit_after_tax'  => $this->calcValue('profit_before_tax', $type, $net, $balances) - $net('L.'),
            'profit_current'    => $this->calcValue('profit_after_tax', $type, $net, $balances) - $net('M.'),
            default             => 0.0,
        }, 2);
    }

    /**
     * VH za období z výsledkových účtů: výnosy − náklady = Σ(D − MD) přes
     * revenue i expense (zůstatky už jsou omezené na fiskální rok přes plFrom).
     *
     * @param list<array<string,mixed>> $balances
     */
    private function profitFromBalances(array $balances): float
    {
        $profit = 0.0;
        foreach ($balances as $b) {
            $accountType = (string) $b['account_type'];
            if ($accountType === 'revenue' || $accountType === 'expense') {
                $profit += (float) $b['d'] - (float) $b['md'];
            }
        }
        return round($profit, 2);
    }

    /**
     * @param array<string, array{gross: float, correction: float}> $values
     */
    private function netOf(array $values, string $rowCode): float
    {
        $v = $values[$rowCode] ?? ['gross' => 0.0, 'correction' => 0.0];
        return round($v['gross'] - $v['correction'], 2);
    }

    /**
     * Hlavička účetní jednotky pro výkaz — veřejný přístup k {@see loadEntity()}.
     *
     * Potřebují ji i přehledy podle § 18/2, které staví jiné služby. Přikládají se
     * k závěrce, takže bez názvu a IČ nejsou k ničemu; druhá kopie téhle metody by
     * se s touhle dřív nebo později rozešla.
     *
     * @return array{name: string, ico: ?string, address: string, legal_form: null, prepared_at: string}
     */
    public function entityHeader(int $supplierId): array
    {
        return $this->loadEntity($supplierId);
    }

    /**
     * Náležitosti výkazu dle §18 ZoÚ — stejné supplier sloupce jako DphBookBuilder.
     *
     * @return array{name: string, ico: ?string, address: string, legal_form: null, prepared_at: string}
     */
    private function loadEntity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.company_name, s.street, s.city, s.zip, s.ic
               FROM supplier s
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ReportException('supplier_not_found', 'Firma #' . $supplierId . ' neexistuje.', 404);
        }
        $address = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim(trim((string) ($row['zip'] ?? '')) . ' ' . trim((string) ($row['city'] ?? ''))),
        ], static fn (string $part): bool => $part !== '');
        return [
            'name'        => (string) $row['company_name'],
            'ico'         => $row['ic'],
            'address'     => implode(', ', $address),
            'legal_form'  => null,
            'prepared_at' => date('Y-m-d H:i'),
        ];
    }

    /**
     * @param array<string,mixed> $period
     * @return array{id: int, fiscal_year: int, starts_on: string, ends_on: string}
     */
    private function periodOut(array $period): array
    {
        return [
            'id'          => (int) $period['id'],
            'fiscal_year' => (int) $period['fiscal_year'],
            'starts_on'   => (string) $period['starts_on'],
            'ends_on'     => (string) $period['ends_on'],
        ];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }

    private function scope504(int $supplierId, int $periodId, string $scope): string
    {
        if (!in_array($scope, ['auto', 'full', 'small', 'micro'], true)) {
            throw new ReportException('invalid_statement_scope', 'Neplatný rozsah účetního výkazu.');
        }
        if ($scope === 'full') return 'full';
        $evaluation = $this->categories->evaluate($supplierId, $periodId);
        if ($evaluation['scope'] === 'full' || !in_array($evaluation['category'], ['micro', 'small'], true)) return 'full';
        return $scope === 'auto' ? $evaluation['scope'] : $scope;
    }
}
