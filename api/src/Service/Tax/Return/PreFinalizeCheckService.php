<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Předfinalizační kontrolní checklist přiznání k dani z příjmů („závěrková kontrola
 * účetní", Fáze E audit 2026-07, návrh E10). Vzor {@see \MyInvoice\Service\Accounting\Closing\ClosingService::buildChecks}
 * (D8 Měsíční kontrola) — každá kontrola má klíč, závažnost (info|warning),
 * příznak ok a proklikatelný `value`. Kalkulátor slepě věří deníku; tyto kontroly
 * odhalí nezaúčtované odpisy, dary mimo účet 543, rozjetý VH, neuzavřené období,
 * nedaňové účty s obratem a nepodaná DPH přiznání DŘÍV, než se přiznání zmrazí.
 *
 * Kontroly (dle povahy poplatníka — účtující vs. daňová evidence):
 *   1. period_status         — účetní období roku je uzavřené (closed/approved)
 *   2. depreciation_551       — obrat 551 = Σ účetních odpisů (depreciation_entries)
 *   3. donations_543          — obrat 543 = zadané dary §20/8 (PO)
 *   4. vh_vs_statement        — VH z přiznání = VH z výsledovky (jiná cesta výpočtu)
 *   5. non_deductible_accounts — výčet nedaňových účtů s nenulovým obratem (drill-down)
 *   6. vat_returns_filed      — DPH přiznání za všechna období roku podána (tax_submissions)
 *
 * Čistě read-only; žádná perzistence — snapshot ukládá {@see TaxReturnService::finalize}.
 */
final class PreFinalizeCheckService
{
    private const EPS = 0.5; // tolerance shody v Kč (haléřové rozdíly zaokrouhlení)

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly FinancialStatementService $statements,
        private readonly TaxConstantsRepository $constants,
        // P-1 — jednotná brána na nepodporované případy pro OBĚ přiznání. Dřív
        // existovala jen u DPFO, a to jen jako ruční volný text `unsupported_cases`
        // v roční uzávěrce daňové evidence; u DPPO nebyla vůbec.
        private readonly UnsupportedCaseDetector $unsupportedCases,
    ) {}

    /**
     * @param array<string,mixed> $inputs ruční vstupy přiznání
     * @param array{result:array<string,mixed>,podklady:array<string,mixed>,warnings:list<string>} $computation
     * @return array{checks:list<array<string,mixed>>,summary:array{ok:int,warning:int,blocker:int,na:int},can_finalize:bool}
     */
    public function run(int $supplierId, int $year, string $type, array $inputs, array $computation): array
    {
        $podklady = $computation['podklady'];
        $isDoubleEntry = $type === 'po' || ($podklady['accounting_mode'] ?? '') === 'double_entry';
        $period = $this->periods->findByYear($supplierId, $year);
        $startsOn = $period !== null ? (string) $period['starts_on'] : sprintf('%04d-01-01', $year);
        $endsOn = $period !== null ? (string) $period['ends_on'] : sprintf('%04d-12-31', $year);

        $checks = [];
        $checks[] = $this->checkPeriodStatus($period, $isDoubleEntry);
        $checks[] = $this->checkDepreciation551($supplierId, $year, $startsOn, $endsOn, $isDoubleEntry);
        $checks[] = $this->checkDonations543($supplierId, $type, $inputs, $startsOn, $endsOn, $year);
        $checks[] = $this->checkVhVsStatement($supplierId, $type, $period, $podklady, $isDoubleEntry);
        $checks[] = $this->checkNonDeductibleAccounts($supplierId, $startsOn, $endsOn, $isDoubleEntry);
        $checks[] = $this->checkVatReturnsFiled($supplierId, $year);
        $checks[] = $this->checkExpenseModeTransition((array) ($computation['warnings'] ?? []));
        array_push($checks, ...$this->unsupportedCaseChecks(
            $supplierId, $type, $podklady, (array) ($computation['result'] ?? []), $inputs
        ));
        if ($type === 'fo') {
            array_push($checks, ...$this->dpfoChecks($podklady, (array) ($computation['result'] ?? []), $year));
        }

        $summary = ['ok' => 0, 'warning' => 0, 'blocker' => 0, 'na' => 0];
        foreach ($checks as $c) {
            if (($c['severity'] ?? 'info') === 'info' && ($c['na'] ?? false)) {
                $summary['na']++;
            } elseif ($c['ok']) {
                $summary['ok']++;
            } else {
                $summary['warning']++;
            }
        }

        return [
            'checks' => $checks,
            'summary' => $summary,
            'can_finalize' => true,
        ];
    }

    /**
     * P-1 — nepodporované/neúplné situace ({@see UnsupportedCaseDetector}) jako kontroly
     * checklistu. Nálezy zůstávají varováním a finalizace i export pokračují.
     * Ruční `unsupported_cases` z roční uzávěrky daňové evidence detektor slévá
     * do stejného seznamu, aby měla účetní
     * jeden soupis nálezů, ne dva.
     *
     * @param array<string,mixed> $podklady
     * @param array<string,mixed> $result
     * @param array<string,mixed> $inputs
     * @return list<array<string,mixed>>
     */
    private function unsupportedCaseChecks(int $supplierId, string $type, array $podklady, array $result, array $inputs): array
    {
        $checks = [];
        foreach ($this->unsupportedCases->detect($supplierId, $type, $podklady, $result, $inputs) as $finding) {
            $checks[] = [
                'key' => 'unsupported_' . $finding['key'],
                'severity' => $finding['severity'],
                'ok' => false,
                'overrideable' => false,
                'value' => [
                    'case' => $finding['key'],
                    'message' => $finding['message'],
                    'action' => $finding['action'],
                ],
            ];
        }

        return $checks;
    }

    /** @return list<array<string,mixed>> */
    private function dpfoChecks(array $podklady, array $result, int $year): array
    {
        $checks = [];
        foreach ((array) ($podklady['blocking_issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $checks[] = [
                'key' => (string) ($issue['key'] ?? 'dpfo_source'),
                'severity' => 'warning',
                'ok' => false,
                'overrideable' => false,
                'value' => ['message' => (string) ($issue['message'] ?? 'Neúplný podklad DPFO.')],
            ];
        }

        $profile = (array) ($podklady['profile'] ?? []);
        $children = (array) ($profile['children'] ?? []);
        $legacyChildren = (int) ($profile['children_count'] ?? 0);
        $childrenOk = $legacyChildren === 0 || $children !== [];
        foreach ($children as $child) {
            if (!is_array($child)
                || trim((string) ($child['first_name'] ?? '')) === ''
                || trim((string) ($child['last_name'] ?? '')) === ''
                || (empty($child['birth_number']) && empty($child['birth_date']))
                || empty($child['shared_household_proved'])
                || empty($child['other_parent_not_claimed_proved'])
                || (array) ($child['months'] ?? []) === []) {
                $childrenOk = false;
                break;
            }
        }
        $checks[] = ['key' => 'dpfo_children', 'severity' => 'warning', 'ok' => $childrenOk,
            'overrideable' => false, 'value' => ['count' => count($children)]];

        // Limit vlastního příjmu manžela/ky (§ 35ba odst. 1 písm. b) se bere z ročníkových
        // konstant, ne natvrdo. Admin ho v číselníku daňových konstant edituje
        // (TaxConstantsAction) a do fáze F2 to byl tichý no-op: kontrola porovnávala
        // s literálem 68000, takže úprava limitu neměla žádný efekt. Novelizace limitu by
        // se projevila až změnou kódu — a nikdo by nevěděl, že se neprojevila.
        $yearConstants = $this->constants->forYear($year);
        $spouseIncomeLimit = (float) ($yearConstants['spouse_income_limit'] ?? 68000);
        // Věk dítěte, do kterého sleva na manžela/ku náleží. Systém ho neověřuje z data
        // narození (profil nese jen potvrzení `child_under_three_proved`), ale vrací ho
        // ve výsledku kontroly, aby UI ukázalo hranici platnou PRO DANÝ ROK — jinak by
        // se v textu držela trojka i po případné novelizaci.
        $spouseChildMaxAge = (int) ($yearConstants['spouse_child_max_age'] ?? 3);

        $spouse = is_array($profile['spouse_claim'] ?? null) ? $profile['spouse_claim'] : null;
        $legacySpouse = !empty($profile['spouse_credit']);
        $spouseOk = !$legacySpouse || $spouse !== null;
        if ($spouse !== null) {
            $spouseOk = trim((string) ($spouse['first_name'] ?? '')) !== ''
                && trim((string) ($spouse['last_name'] ?? '')) !== ''
                && (!empty($spouse['birth_number']) || !empty($spouse['birth_date']))
                && !empty($spouse['income_proved'])
                && !empty($spouse['shared_household_proved'])
                && !empty($spouse['child_under_three_proved'])
                && (float) ($spouse['own_income'] ?? PHP_INT_MAX) <= $spouseIncomeLimit;
        }
        $checks[] = ['key' => 'dpfo_spouse', 'severity' => 'warning', 'ok' => $spouseOk,
            'overrideable' => false, 'value' => [
                'claimed'        => $legacySpouse || $spouse !== null,
                'income_limit'   => $spouseIncomeLimit,
                'child_max_age'  => $spouseChildMaxAge,
            ]];

        $activities = (array) ($profile['activities'] ?? []);
        $activitiesOk = true;
        foreach ($activities as $activity) {
            $nace = preg_replace('/\D/', '', (string) ($activity['nace_code'] ?? ''));
            if ($nace === '' || strlen($nace) > 6 || trim((string) ($activity['name'] ?? '')) === '') {
                $activitiesOk = false;
                break;
            }
        }
        if ($activities === [] && (float) ($podklady['s7_income'] ?? 0) > 0) {
            $activitiesOk = false;
        }
        $checks[] = ['key' => 'dpfo_activities', 'severity' => 'warning', 'ok' => $activitiesOk,
            'overrideable' => false, 'value' => ['count' => count($activities)]];

        $closing = is_array($podklady['closing'] ?? null) ? $podklady['closing'] : null;
        $closingOk = ($podklady['accounting_mode'] ?? '') !== 'tax_evidence'
            || ($closing !== null && ($closing['status'] ?? '') === 'final' && (array) ($closing['unsupported_cases'] ?? []) === []);
        $checks[] = ['key' => 'tax_evidence_closing', 'severity' => 'warning', 'ok' => $closingOk,
            'overrideable' => false, 'value' => $closing];

        $months = (array) ($profile['osvc_months'] ?? []);
        $monthsOk = (float) ($podklady['s7_income'] ?? 0) <= 0 || count($months) === 12;
        if ($monthsOk && $months !== []) {
            $seen = [];
            foreach ($months as $month) {
                if (!is_array($month)
                    || (int) ($month['month'] ?? 0) < 1
                    || (int) ($month['month'] ?? 0) > 12
                    || !in_array($month['activity_status'] ?? '', ['inactive', 'main', 'secondary'], true)
                ) {
                    $monthsOk = false;
                    break;
                }
                $seen[(int) $month['month']] = true;
            }
            $monthsOk = $monthsOk && count($seen) === 12;
        }
        $checks[] = ['key' => 'osvc_month_statuses', 'severity' => 'warning', 'ok' => $monthsOk,
            'overrideable' => false, 'value' => ['months' => count($months)]];

        return $checks;
    }

    /** @param array<string,mixed>|null $period */
    private function checkPeriodStatus(?array $period, bool $isDoubleEntry): array
    {
        if ($period === null) {
            // Účtující jednotka bez období = chyba podkladů; daňová evidence období nemá.
            return $isDoubleEntry
                ? ['key' => 'period_status', 'severity' => 'warning', 'ok' => false, 'value' => ['status' => 'missing']]
                : ['key' => 'period_status', 'severity' => 'info', 'ok' => true, 'na' => true, 'value' => ['status' => 'na']];
        }
        $status = (string) $period['status'];
        return [
            'key' => 'period_status',
            'severity' => 'warning',
            'ok' => AccountingPeriodStatus::isClosed($status),
            'value' => ['status' => $status, 'fiscal_year' => (int) $period['fiscal_year']],
        ];
    }

    private function checkDepreciation551(int $supplierId, int $year, string $startsOn, string $endsOn, bool $isDoubleEntry): array
    {
        if (!$isDoubleEntry) {
            return ['key' => 'depreciation_551', 'severity' => 'info', 'ok' => true, 'na' => true, 'value' => null];
        }
        $turnover = $this->accountTurnover($supplierId, '551', $startsOn, $endsOn);
        $entries = $this->depreciationAccounting($supplierId, $year);
        $diff = round($turnover - $entries, 2);
        return [
            'key' => 'depreciation_551',
            'severity' => 'warning',
            'ok' => abs($diff) < self::EPS,
            'value' => ['account' => '551', 'turnover' => $turnover, 'accounting_entries' => $entries, 'diff' => $diff],
        ];
    }

    /** @param array<string,mixed> $inputs */
    private function checkDonations543(int $supplierId, string $type, array $inputs, string $startsOn, string $endsOn, int $year): array
    {
        if ($type !== 'po') {
            return ['key' => 'donations_543', 'severity' => 'info', 'ok' => true, 'na' => true, 'value' => null];
        }
        $turnover = $this->accountTurnover($supplierId, '543', $startsOn, $endsOn);
        $donations = $this->donationsInput($inputs, (float) ($this->constants->forYear($year)['donation_min_po'] ?? 2000));
        $diff = round($turnover - $donations, 2);
        return [
            'key' => 'donations_543',
            'severity' => 'warning',
            'ok' => abs($diff) < self::EPS,
            'value' => ['account' => '543', 'turnover' => $turnover, 'donations_input' => $donations, 'diff' => $diff],
        ];
    }

    /**
     * VH z přiznání (Σ 6xx − Σ 5xx mimo 59x, přímý dotaz do deníku) proti VH z výsledovky
     * (FinancialStatementService — jiná cesta: mapování syntetik na řádky VZZ). Výsledovka
     * dává VH PO zdanění; přičteme zpět daň z příjmů (59x) → VH před zdaněním. Rozdíl =
     * chyba mapování osnovy nebo zápis mimo výsledkové účty → základ daně by byl špatně.
     *
     * @param array<string,mixed>|null $period
     * @param array<string,mixed> $podklady
     */
    private function checkVhVsStatement(int $supplierId, string $type, ?array $period, array $podklady, bool $isDoubleEntry): array
    {
        if ($type !== 'po' || !$isDoubleEntry || $period === null) {
            return ['key' => 'vh_vs_statement', 'severity' => 'info', 'ok' => true, 'na' => true, 'value' => null];
        }
        $returnVh = round((float) ($podklady['vh'] ?? 0), 2);
        try {
            $stmt = $this->statements->incomeStatement($supplierId, (int) $period['id'], (string) $period['ends_on'], 'auto');
            $profitAfterTax = round((float) ($stmt['checks']['profit_current'] ?? 0), 2);
            $tax59x = $this->accountTurnover($supplierId, '59', (string) $period['starts_on'], (string) $period['ends_on']);
            $statementVh = round($profitAfterTax + $tax59x, 2);
        } catch (\Throwable) {
            return ['key' => 'vh_vs_statement', 'severity' => 'info', 'ok' => true, 'na' => true, 'value' => null];
        }
        $diff = round($returnVh - $statementVh, 2);
        return [
            'key' => 'vh_vs_statement',
            'severity' => 'warning',
            'ok' => abs($diff) < self::EPS,
            'value' => ['return_vh' => $returnVh, 'statement_vh' => $statementVh, 'diff' => $diff],
        ];
    }

    private function checkNonDeductibleAccounts(int $supplierId, string $startsOn, string $endsOn, bool $isDoubleEntry): array
    {
        if (!$isDoubleEntry) {
            return ['key' => 'non_deductible_accounts', 'severity' => 'info', 'ok' => true, 'na' => true, 'value' => null];
        }
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " SELECT a.id AS account_id, a.account_code, a.name,
                    ROUND(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 2) AS turnover
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN tax_journal_origins tax_origin ON tax_origin.id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND " . JournalTaxOrigin::includedSql() . "
                AND a.account_type = 'expense'
                AND a.account_code NOT LIKE '59%'
                AND a.tax_deductibility = 'non_deductible'
              GROUP BY a.id, a.account_code, a.name
             HAVING ABS(turnover) >= 0.005
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);
        $accounts = [];
        $total = 0.0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $turnover = round((float) $r['turnover'], 2);
            $total = round($total + $turnover, 2);
            $accounts[] = [
                'account_id' => (int) $r['account_id'],
                'account_code' => (string) $r['account_code'],
                'name' => (string) $r['name'],
                'turnover' => $turnover,
            ];
        }
        // Informativní výčet (drill-down) — vždy „ok", jen upozorní účetní na částky ř. 40.
        return [
            'key' => 'non_deductible_accounts',
            'severity' => 'info',
            'ok' => true,
            'value' => ['count' => count($accounts), 'total' => $total, 'accounts' => $accounts],
        ];
    }

    private function checkVatReturnsFiled(int $supplierId, int $year): array
    {
        // Plátcovství KDYKOLI BĚHEM finalizovaného roku, ne živý flag ani jen 31. 12. —
        // firma odregistrovaná v průběhu roku musí mít přiznání za období do zrušení
        // registrace a check nesmí celý rok tiše přeskočit (nález review VH follow-up).
        $payerDuringYear = \MyInvoice\Service\Vat\VatStatusService::payerDuring(
            $this->db->pdo(), $supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)
        );
        [, $vatPeriod] = $this->vatSettings($supplierId, sprintf('%04d-12-31', $year));
        if (!$payerDuringYear || $vatPeriod === null) {
            return ['key' => 'vat_returns_filed', 'severity' => 'info', 'ok' => true, 'na' => true, 'value' => ['status' => 'not_payer']];
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT period_month, period_quarter
               FROM tax_submissions
              WHERE supplier_id = ? AND form_code = 'dphdp3' AND period_year = ?"
        );
        $stmt->execute([$supplierId, $year]);
        $submittedMonths = [];
        $submittedQuarters = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['period_month'] !== null) {
                $submittedMonths[(int) $r['period_month']] = true;
            }
            if ($r['period_quarter'] !== null) {
                $submittedQuarters[(int) $r['period_quarter']] = true;
            }
        }

        // Očekávaná období jen ta, ve kterých firma byla plátcem aspoň jeden den —
        // registrace v průběhu roku nesmí vyžadovat přiznání za měsíce před ní.
        $pdo = $this->db->pdo();
        if ($vatPeriod === 'monthly') {
            $expected = [];
            foreach (range(1, 12) as $m) {
                $start = sprintf('%04d-%02d-01', $year, $m);
                $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
                if (\MyInvoice\Service\Vat\VatStatusService::payerDuring($pdo, $supplierId, $start, $end)) {
                    $expected[] = $m;
                }
            }
            $submitted = array_keys($submittedMonths);
        } else {
            $expected = [];
            foreach (range(1, 4) as $q) {
                $start = sprintf('%04d-%02d-01', $year, $q * 3 - 2);
                $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $q * 3)))
                    ->modify('last day of this month')->format('Y-m-d');
                if (\MyInvoice\Service\Vat\VatStatusService::payerDuring($pdo, $supplierId, $start, $end)) {
                    $expected[] = $q;
                }
            }
            $submitted = array_keys($submittedQuarters);
        }
        $missing = array_values(array_diff($expected, $submitted));
        sort($submitted);

        return [
            'key' => 'vat_returns_filed',
            'severity' => 'warning',
            'ok' => $missing === [],
            'value' => [
                'frequency' => $vatPeriod,
                'expected' => count($expected),
                'submitted' => $submitted,
                'missing' => $missing,
            ],
        ];
    }

    /** @param list<string> $warnings */
    private function checkExpenseModeTransition(array $warnings): array
    {
        foreach ($warnings as $warning) {
            if (str_starts_with($warning, 'BLOKUJÍCÍ KONTROLA §23 odst. 8 ZDP:')
                || str_starts_with($warning, 'VAROVÁNÍ §23 odst. 8 ZDP:')) {
                return [
                    'key' => 'expense_mode_transition_23_8',
                    'severity' => 'warning',
                    'ok' => false,
                    'value' => ['message' => str_replace('BLOKUJÍCÍ KONTROLA §23 odst. 8 ZDP:', 'VAROVÁNÍ §23 odst. 8 ZDP:', $warning)],
                ];
            }
        }
        return [
            'key' => 'expense_mode_transition_23_8',
            'severity' => 'info',
            'ok' => true,
            'na' => true,
            'value' => null,
        ];
    }

    // ── Pomocné dotazy ───────────────────────────────────────────────────────

    /** Obrat výdajového účtu (debit−credit) za období; prefix dle account_code LIKE. Vylučuje jen close_books zápis (skladové sloty §3.4 se počítají). */
    private function accountTurnover(int $supplierId, string $codePrefix, string $startsOn, string $endsOn): float
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN tax_journal_origins tax_origin ON tax_origin.id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND " . JournalTaxOrigin::includedSql() . "
                AND a.account_code LIKE ?"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE, $codePrefix . '%']);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function depreciationAccounting(int $supplierId, int $fiscalYear): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(amount), 0)
               FROM depreciation_entries
              WHERE supplier_id = ? AND fiscal_year = ? AND kind = 'accounting'"
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Úhrn darů zadaných do přiznání (§20/8): preferuje položkový vstup (donation_items,
     * jen položky ≥ 2 000 Kč — pod limitem odčitatelné nejsou), jinak agregát `donations`.
     *
     * @param array<string,mixed> $inputs
     */
    private function donationsInput(array $inputs, float $minimum): float
    {
        $items = $inputs['donation_items'] ?? null;
        if (is_array($items) && $items !== []) {
            $sum = 0.0;
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $amount = round((float) ($it['amount'] ?? 0), 2);
                if ($amount >= $minimum) {
                    $sum = round($sum + $amount, 2);
                }
            }
            return $sum;
        }
        return max(0.0, round((float) ($inputs['donations'] ?? 0), 2));
    }

    /**
     * @param ?string $statusDate rozhodné datum plátcovství (EPIC VH-04) — s datem se
     *        is_vat_payer čte z historie přes VatStatusService, bez něj živá cache dneška.
     * @return array{0:bool,1:?string} [is_vat_payer, vat_period(monthly|quarterly|null)]
     */
    private function vatSettings(int $supplierId, ?string $statusDate = null): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer, vat_period FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return [false, null];
        }
        $period = $row['vat_period'] !== null ? (string) $row['vat_period'] : null;
        $isPayer = $statusDate !== null
            ? \MyInvoice\Service\Vat\VatStatusService::payerAt($this->db->pdo(), $supplierId, $statusDate)
            : (bool) $row['is_vat_payer'];
        return [$isPayer, $period];
    }
}
