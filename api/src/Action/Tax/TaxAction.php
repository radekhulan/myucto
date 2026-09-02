<?php

declare(strict_types=1);

namespace MyInvoice\Action\Tax;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Tax\TaxOptimizer;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use MyInvoice\Service\Vat\VatStatusService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Daňový optimalizátor — REST endpointy.
 *  GET /api/tax/analysis?year=YYYY  → příjmy + srovnání režimů (retrospektiva)
 *                                     nebo projekce + limity (běžící rok)
 *  PUT /api/tax/profile             → uloží daňový profil pro rok
 */
final class TaxAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxProfileRepository $profiles,
        private readonly TaxOptimizer $optimizer,
        private readonly TaxConstantsRepository $constants,
        private readonly CashJournalService $cashJournal,
        private readonly VatStatusService $vatStatus,
    ) {}

    /** GET /api/tax/analysis */
    public function analysis(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $currentYear = (int) date('Y');
        $year = (int) ($request->getQueryParams()['year'] ?? $currentYear);
        if ($year < 2018 || $year > $currentYear + 1) {
            return Json::error($response, 'tax_invalid_year', 'Neplatný rok.', 422);
        }

        $flags = $this->supplierFlags($sid);
        // Plátcovství k rozhodnému roku výpočtu, ne živá cache „dneška":
        //  - uzavřený i běžící rok → 31. 12. daného roku (retrospektiva srovnává režimy,
        //    které v TOM roce reálně platily; u běžícího roku zachytí i už zaevidovanou
        //    změnu s pozdější účinností v témže roce),
        //  - výhled na příští rok → 1. 1. toho roku (podmínka vstupu do paušálního
        //    režimu §38la: k 1. lednu nesmí být plátcem DPH — tj. odpověď na otázku
        //    „mám nárok na paušál od ledna?" včetně naplánované změny plátcovství).
        $isVat = $this->vatStatus->isVatPayerAt(
            $sid,
            $year > $currentYear ? sprintf('%04d-01-01', $year) : sprintf('%04d-12-31', $year),
        );
        $profileRow = $this->profiles->find($sid, $year);
        $publicProfile = $this->publicProfile($profileRow, $flags);
        $engineProfile = $publicProfile + ['is_vat_payer' => $isVat];

        // Epic DE (A4, §8.1 / R12): volitelně použít SKUTEČNÉ výdaje z peněžního deníku
        // (kasová báze) místo výdajového paušálu / ručně zadaných actual_expenses.
        // Jen daňová evidence + FO, aktivované explicitním requestem `use_evidence_expenses=1`.
        // BEZ write-backu do tax_profiles — deník je runtime override, ne uložená pravda
        // (tax_profiles.use_actual_expenses/actual_expenses zůstává ruční override uživatele).
        // taxpayer_type u daňové evidence je typicky FO; NULL/prázdné bereme jako FO
        // (shodně s IncomeTaxBuilder, který DPFDP5 defaultuje na type='fo'). Explicitní
        // 'po' vyloučeno. Sjednocuje gate obou wiringů (A4 review).
        $useEvidence = ((string) ($request->getQueryParams()['use_evidence_expenses'] ?? '') === '1')
            && $flags['accounting_mode'] === 'tax_evidence'
            && in_array($flags['taxpayer_type'], ['fo', '', null], true);

        $c = $this->constants->forYear($year);
        $payload = [
            'year'            => $year,
            'profile'         => $publicProfile,
            'is_vat_payer'    => $isVat,
            'supplier_band'   => $flags['flat_tax_band'],
            'constants'       => $c,
            'available_years' => $this->availableYears($sid, $currentYear),
            // Příjmy označené „osvobozeno od daně z příjmů" (§4 / přefakturace) — do
            // výpočtu daně ani pojistného NEvstupují (vyloučené jsou v annualIncome,
            // monthlyIncome i v deníkovém monthlyTaxableIncome); tady jen pro transparentní
            // zobrazení „z toho vyloučeno" v UI.
            'exempt_income'   => $this->profiles->annualExemptIncome($sid, $year, $isVat),
            'last_month'      => $this->lastMonthEstimate($sid, $flags),
        ];

        if ($year < $currentYear) {
            // Uzavřený rok → retrospektiva (srovnání režimů na skutečném příjmu)
            $income = $this->profiles->annualIncome($sid, $year, $isVat);

            if ($useEvidence) {
                // §8.1: deníkový daňový výdaj (a příjem) spočten za běhu z CashJournalService.
                $denik = $this->cashJournal->build(
                    $sid,
                    sprintf('%04d-01-01', $year),
                    sprintf('%04d-12-31', $year),
                    ['year' => $year],
                );
                $denikExpense = round((float) ($denik['totals']['vydaj_danovy'] ?? 0), 2);
                $denikIncome  = round((float) ($denik['totals']['prijem_danovy'] ?? 0), 2);
                $income = $denikIncome;
                // Skutečné výdaje z evidence → do computeRegular přes existující mechanismus
                // use_actual_expenses/actual_expenses (jen v paměti, NE do DB). Příjem i paušál
                // kandidát zůstávají na annualIncome (§8.1: „paušál kandidát beze změny").
                $engineProfile['use_actual_expenses'] = true;
                $engineProfile['actual_expenses']     = $denikExpense;
                // Deníkový příjem vystaven pro transparentnost/reconciliaci (income-side).
                $payload['evidence_expenses'] = [
                    'applied'             => true,
                    'denik_vydaj_danovy'  => $denikExpense,
                    'denik_prijem_danovy' => $denikIncome,
                ];
            }

            $payload['mode']    = 'retrospective';
            $payload['income']  = $income;
            $payload['compare'] = $this->optimizer->compare($engineProfile, $income, $c);
            // YoY: příjem + konstanty předchozího roku (frontend dopočítá meziroční srovnání).
            $prevYear   = $year - 1;
            $prevIncome = $this->profiles->annualIncome(
                $sid,
                $prevYear,
                $this->vatStatus->isVatPayerAt($sid, sprintf('%04d-12-31', $prevYear)),
            );
            $payload['prev'] = $prevIncome > 0
                ? ['year' => $prevYear, 'income' => $prevIncome, 'constants' => $this->constants->forYear($prevYear)]
                : null;
        } else {
            // Běžící rok → projekce a sledování limitů. Obě větve musí vracet TOTÉŽ:
            // zdanitelný příjem bez osvobozených (§4) a u plátce DPH bez DPH — kasově
            // z deníku, jinak z fakturace. Dřív tu deníková větev sčítala i osvobozený
            // kbelík, a to v brutto (#52).
            $monthly = $flags['accounting_mode'] === 'tax_evidence'
                ? $this->cashJournal->monthlyTaxableIncome($sid, $year)
                : $this->profiles->monthlyIncome($sid, $year, $isVat);
            [$ytd, $months] = $this->ytd($monthly, $year, $currentYear, (int) date('n'));
            $payload['mode']           = 'forecast';
            $payload['ytd_income']     = $ytd;
            $payload['months_elapsed'] = $months;
            $payload['predict']        = $this->optimizer->predict($engineProfile, $ytd, $months, $c);
        }

        return Json::ok($response, $payload);
    }

    /** PUT /api/tax/profile */
    public function updateProfile(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $body = (array) $request->getParsedBody();
        $currentYear = (int) date('Y');
        $year = (int) ($body['year'] ?? $currentYear);
        if ($year < 2018 || $year > $currentYear + 1) {
            return Json::error($response, 'tax_invalid_year', 'Neplatný rok.', 422);
        }

        $saved = $this->profiles->upsert($sid, $year, $body);
        return Json::ok($response, ['profile' => $saved]);
    }

    /**
     * Plátcovství DPH tu ZÁMĚRNĚ není — živý supplier.is_vat_payer je cache dneška;
     * rozhodné datum výpočtu obsluhuje {@see VatStatusService::isVatPayerAt()}.
     *
     * @return array{flat_tax_band: string, accounting_mode: string, taxpayer_type: string}
     */
    private function supplierFlags(int $sid): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT flat_tax_band, accounting_mode, taxpayer_type FROM supplier WHERE id = ?'
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'flat_tax_band'   => (string) ($row['flat_tax_band'] ?? 'none'),
            'accounting_mode' => (string) ($row['accounting_mode'] ?? 'double_entry'),
            'taxpayer_type'   => (string) ($row['taxpayer_type'] ?? ''),
        ];
    }

    /**
     * Profil pro frontend (form) i engine. Default z supplieru, pokud řádek neexistuje.
     * Engine si bere tentýž tvar + `is_vat_payer` (viz analysis()).
     * @param array<string,mixed>|null $row
     * @param array{flat_tax_band: string} $flags
     * @return array<string,mixed>
     */
    private function publicProfile(?array $row, array $flags): array
    {
        return [
            'activity_rate'       => (int) ($row['activity_rate'] ?? 60),
            'use_actual_expenses' => (bool) ($row['use_actual_expenses'] ?? false),
            'actual_expenses'     => (float) ($row['actual_expenses'] ?? 0),
            'flat_tax_band'     => (string) ($row['flat_tax_band'] ?? $flags['flat_tax_band']),
            'is_secondary'      => (bool) ($row['is_secondary'] ?? false),
            'spouse_credit'     => (bool) ($row['spouse_credit'] ?? false),
            'children_count'    => (int) ($row['children_count'] ?? 0),
            'mortgage_interest' => (float) ($row['mortgage_interest'] ?? 0),
            'mortgage_pre_2021' => (bool) ($row['mortgage_pre_2021'] ?? false),
            'mortgage_months'   => (int) ($row['mortgage_months'] ?? 12),
            'pension_contrib'   => (float) ($row['pension_contrib'] ?? 0),
            'life_insurance'    => (float) ($row['life_insurance'] ?? 0),
            'dip_contrib'       => (float) ($row['dip_contrib'] ?? 0),
            'long_term_care'    => (float) ($row['long_term_care'] ?? 0),
            'disability_12_months' => (int) ($row['disability_12_months'] ?? 0),
            'disability_3_months' => (int) ($row['disability_3_months'] ?? 0),
            'ztpp_months'       => (int) ($row['ztpp_months'] ?? 0),
            'donations'         => (float) ($row['donations'] ?? 0),
            'activities'        => (array) ($row['activities'] ?? []),
            'children'          => (array) ($row['children'] ?? []),
            'spouse_claim'      => $row['spouse_claim'] ?? null,
            'osvc_months'       => (array) ($row['osvc_months'] ?? []),
            'saved'             => $row !== null,
        ];
    }

    /**
     * YTD příjem a počet uplynulých celých měsíců (pro projekci).
     * @param array<int,float> $monthly
     * @return array{0: float, 1: int}
     */
    private function ytd(array $monthly, int $year, int $currentYear, int $currentMonth): array
    {
        $elapsed = $year < $currentYear ? 12 : max(1, $currentMonth - 1);
        $ytd = 0.0;
        for ($m = 1; $m <= $elapsed; $m++) {
            $ytd += $monthly[$m] ?? 0.0;
        }
        // Fallback: na začátku roku / řídká data → vezmi vše a počet měsíců s daty.
        if ($ytd <= 0) {
            $ytd = array_sum($monthly);
            $withData = count(array_filter($monthly, static fn ($v) => $v > 0));
            $elapsed = max(1, $withData);
        }
        return [round($ytd, 2), $elapsed];
    }

    /**
     * Pravděpodobný čistý příjem za minulý kalendářní měsíc — vždy nezávisle na
     * zvoleném roce v přepínači (viz {@see TaxOptimizer::estimateMonthly()}).
     * Plátcovství se bere k poslednímu dni minulého měsíce (ne k roku z přepínače).
     * @param array{flat_tax_band: string} $flags
     * @return array<string,mixed>
     */
    private function lastMonthEstimate(int $sid, array $flags): array
    {
        $lastMonth = new \DateTimeImmutable('first day of last month');
        $lmYear = (int) $lastMonth->format('Y');
        $lmYm   = $lastMonth->format('Y-m');
        $isVat  = $this->vatStatus->isVatPayerAt($sid, $lastMonth->format('Y-m-t'));

        $profileRow = $this->profiles->find($sid, $lmYear);
        $engineProfile = $this->publicProfile($profileRow, $flags) + ['is_vat_payer' => $isVat];
        $c = $this->constants->forYear($lmYear);

        $income   = $this->profiles->monthIncome($sid, $lmYm, $isVat);
        $expenses = $this->profiles->monthExpenses($sid, $lmYm, $isVat);

        return ['ym' => $lmYm] + $this->optimizer->estimateMonthly($engineProfile, $income, $expenses, $c);
    }

    /**
     * Roky pro přepínač: roky s fakturami sjednocené s aktuálním a minulým rokem.
     * @return list<int>
     */
    private function availableYears(int $sid, int $currentYear): array
    {
        $years = $this->profiles->incomeYears($sid);
        foreach ([$currentYear, $currentYear - 1] as $y) {
            if (!in_array($y, $years, true)) {
                $years[] = $y;
            }
        }
        rsort($years);
        return $years;
    }
}
