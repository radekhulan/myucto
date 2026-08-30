<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\DpfoCalculator;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Podklady §7 pro DPFO přiznání ({@see DpfoReturnCalculator}) — Epic DP (issue #18).
 *
 * §7 dílčí základ = příjmy − výdaje. Zdroj příjmů:
 *   - režim tax_evidence → daňový příjem z peněžního deníku (CashJournalService, kasová
 *     báze: hotovostní tržby bez faktury i částečné úhrady, ne jen faktury status='paid')
 *     bez ohledu na to, zda se výdaje uplatňují paušálem nebo skutečné;
 *   - jinak → zaplacené faktury (TaxProfileRepository::annualIncome).
 * Výdaje:
 *   - skutečné + režim tax_evidence → z peněžního deníku (daňové výdaje dle data úhrady);
 *   - skutečné jinak → pole actual_expenses z profilu;
 *   - jinak → výdajový paušál % se stropem (DpfoCalculator::expenses).
 *
 * Osobní odpočty §15 a slevy zůstávají v tax_profiles (jeden zdroj s optimalizátorem).
 *
 * FO s podvojným účetnictvím (§23/2 ZDP, Fáze E nález N1): §7 z VH dostává STEJNÝ §25
 * addback nedaňových nákladů jako DPPO ({@see NonDeductibleCostsService}, jeden zdroj) a
 * podporuje ruční položky §23 (`manual_increase_items`/`manual_decrease_items`, tvar jako
 * u DPPO — {@see ManualItemsSum}) předané volitelně přes $inputs.
 */
final class DpfoReturnDataProvider
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxProfileRepository $profiles,
        private readonly TaxConstantsRepository $constants,
        private readonly CashJournalService $cashJournal,
        private readonly NonDeductibleCostsService $nonDeductibleCostsService,
        private readonly AccountingModeRepository $accountingModes,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * @param array<string,mixed> $inputs ruční vstupy přiznání (jen manual_increase_items/
     *   manual_decrease_items se použijí, a jen pro accounting_mode='double_entry')
     * @return array{
     *   profile: array<string,mixed>,
     *   s7_income: float, s7_expenses: float, s7_base: float,
     *   expense_mode: string, expense_rate: int,
     *   is_vat_payer: bool, accounting_mode: string,
     *   warnings: list<string>,
     *   bank_account: array{account_number:?string,bank_code:?string,bank_name:?string,iban:?string}|null
     * }
     */
    public function gather(int $supplierId, int $year, array $inputs = []): array
    {
        $warnings = [];
        $blockingIssues = [];
        $c = $this->constants->forYear($year);
        // Plátcovství k 31. 12. zdaňovacího roku přiznání (ne živá cache „dneška") —
        // stejný princip jako accounting_mode, který má vlastní historii per rok.
        $isVatPayer = $this->vatStatus->isVatPayerAt($supplierId, sprintf('%04d-12-31', $year));
        $accountingMode = $this->accountingModes->forYear($supplierId, $year);

        $profile = $this->profiles->find($supplierId, $year) ?? $this->defaultProfile($year);
        $rate = (int) ($profile['activity_rate'] ?? 60);
        $useActual = !empty($profile['use_actual_expenses']);
        $previousProfile = $this->profiles->find($supplierId, $year - 1);
        if ($previousProfile !== null && !empty($previousProfile['use_actual_expenses']) !== $useActual) {
            $warnings[] = $this->expenseModeTransitionWarning($supplierId, $year, $useActual);
        }

        if (($profile['flat_tax_band'] ?? 'none') !== 'none') {
            $warnings[] = 'Firma je v paušálním režimu daně — pokud podmínky trvají, přiznání se nepodává (daň i pojistné jsou vypořádány paušální zálohou). Tato stránka slouží jen pro kontrolu.';
        }

        $income = $this->profiles->annualIncome($supplierId, $year, $isVatPayer);

        // FO s podvojným účetnictvím (§23 odst. 2 ZDP): §7 dílčí základ se odvozuje z VÝSLEDKU
        // HOSPODAŘENÍ deníku (výnosy 6xx − náklady 5xx mimo 59x), NE z kasové báze ani paušálu.
        $vhBased = false;
        $vhExpenses = 0.0;
        if ($accountingMode === 'double_entry') {
            $manualIncrease = ManualItemsSum::sum($inputs['manual_increase_items'] ?? []);
            $manualDecrease = ManualItemsSum::sum($inputs['manual_decrease_items'] ?? []);
            [$vhRevenues, $vhExpenses, $vhWarn] = $this->vhBase($supplierId, $year, $manualIncrease, $manualDecrease);
            $income = $vhRevenues;
            $vhBased = true;
            $warnings = array_merge($warnings, $vhWarn);
        }

        $cash = null;
        if ($accountingMode === 'tax_evidence') {
            $cash = $this->cashBase($supplierId, $year);
            if ($cash['income'] !== null) {
                $income = $cash['income'];
            }
            $warnings = array_merge($warnings, $cash['warnings']);
            $blockingIssues = array_merge($blockingIssues, $cash['blocking_issues']);
        }

        if ($vhBased) {
            // Účetnictví: výdaje = náklady z VH (skutečné), výdajový paušál se neuplatní.
            $expenseMode = 'actual';
            $expenses = $vhExpenses;
            $rate = 0;
        } elseif ($useActual) {
            $expenseMode = 'actual';
            if ($cash !== null && $cash['income'] !== null) {
                $expenses = $cash['expenses'];
            } else {
                $expenses = max(0.0, (float) ($profile['actual_expenses'] ?? 0));
            }
            $rate = 0;
        } else {
            $expenseMode = 'pausal';
            $expenses = DpfoCalculator::expenses($rate, false, 0.0, $income, $c);
        }

        $closing = $accountingMode === 'tax_evidence' ? $this->closing($supplierId, $year) : null;
        if ($accountingMode === 'tax_evidence' && ($closing === null || $closing['status'] !== 'final')) {
            $blockingIssues[] = ['key' => 'tax_evidence_closing', 'message' => 'Roční uzávěrka daňové evidence není dokončená.'];
        }
        $increase = round((float) ($closing['adjustments']['increase'] ?? 0), 2);
        $decrease = round((float) ($closing['adjustments']['decrease'] ?? 0), 2);
        $base = round($income - $expenses + $increase - $decrease, 2);

        return [
            'year' => $year,
            'profile' => $profile,
            's7_income' => round($income, 2),
            's7_expenses' => round($expenses, 2),
            's7_base' => $base,
            'expense_mode' => $expenseMode,
            'expense_rate' => $rate,
            'is_vat_payer' => $isVatPayer,
            'accounting_mode' => $accountingMode,
            'activities' => (array) ($profile['activities'] ?? []),
            'closing' => $closing,
            's7_increase' => $increase,
            's7_decrease' => $decrease,
            'source_manifest' => (array) ($cash['source_manifest'] ?? []),
            'blocking_issues' => $blockingIssues,
            'warnings' => $warnings,
            'bank_account' => $this->bankAccount($supplierId),
        ];
    }

    /**
     * Výchozí CZK bankovní účet poplatníka (`currencies`, stejný zdroj jako platební
     * příkazy — {@see \MyInvoice\Repository\PaymentOrderRepository::payerAccounts}) —
     * jediný podklad, odkud VetaN (žádost o vrácení přeplatku) může vzít bankovní
     * spojení. Stejná tabulka jako u DPPO ({@see DppoReturnDataProvider::bankAccount})
     * — u OSVČ je `suppliers`/`currencies` řádek totéž „firemní" konto, které
     * fyzická osoba v praxi vede jako svůj jediný podnikatelský účet, takže použití
     * stejného zdroje je u FO věcně správné, ne jen pohodlné.
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

    /**
     * §7 dílčí základ FO s podvojným účetnictvím z výsledku hospodaření deníku (§23/2 ZDP):
     * výnosy 6xx (Σ credit−debit) a náklady 5xx mimo 59x (Σ debit−credit) za kalendářní rok.
     * Vylučuje JEN close_books zápis (source_id < STOCK_SLOT_BASE); slotované skladové
     * zápisy §3.4 (501/504/549/648) do základu PATŘÍ a počítají se. Analogicky
     * {@see DppoReturnDataProvider::profitBeforeTax}, ale rozdělené na příjmy/výdaje pro §7.
     *
     * Fáze E nález N1: nedaňové náklady §25 (stejný zdroj jako DPPO ř. 40,
     * {@see NonDeductibleCostsService}) a ruční položky §23 (manual_increase/decrease_items,
     * stejně jako DPPO) se PŘIČTOU ZPĚT k výsledku, tj. odečtou z uznaných výdajů §7 —
     * jinak by FO s podvojným účetnictvím systematicky podhodnotila základ o každý nedaňový
     * náklad (513 reprezentace, 543 dary nad limit, 545 pokuty apod.).
     *
     * @return array{0:float,1:float,2:list<string>} [výnosy, uznané (daňové) výdaje, warnings]
     */
    private function vhBase(int $supplierId, int $year, float $manualIncrease = 0.0, float $manualDecrease = 0.0): array
    {
        $startsOn = sprintf('%04d-01-01', $year);
        $endsOn = sprintf('%04d-12-31', $year);
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_type,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0) AS bal
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND NOT (e.source_type = 'closing' AND e.source_id < ?)
                AND a.account_type IN ('revenue','expense')
                AND a.account_code NOT LIKE '59%'
              GROUP BY a.account_type"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);

        $revenues = 0.0;
        $ledgerExpenses = 0.0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // revenue: Σ(credit−debit) = kladný výnos; expense: Σ(credit−debit) je záporné → náklad = −bal.
            if ((string) $row['account_type'] === 'revenue') {
                $revenues = round((float) $row['bal'], 2);
            } else {
                $ledgerExpenses = round(-(float) $row['bal'], 2);
            }
        }

        $nonDeductible = $this->nonDeductibleCostsService->sum($supplierId, $startsOn, $endsOn);
        // Uznané (daňové) výdaje = účetní náklady MINUS nedaňové (§25) MINUS §23 manuální
        // zvýšení základu (= snížení výdajů) PLUS §23 manuální snížení základu (= zvýšení
        // výdajů) — zrcadlí DppoReturnCalculator ř. 40/62/162, jen promítnuté do výdajů §7
        // místo samostatných řádků formuláře DPFDP7 (Příloha 1 nemá ekvivalent ř. 40/62/162).
        $expenses = max(0.0, round($ledgerExpenses - $nonDeductible - $manualIncrease + $manualDecrease, 2));

        $warnings = [
            'Fyzická osoba s podvojným účetnictvím: dílčí základ §7 je odvozen z výsledku hospodaření '
            . 'deníku (výnosy − náklady, §23 odst. 2 ZDP), nikoli z kasové báze faktur.',
        ];
        if ($nonDeductible > 0) {
            $warnings[] = 'Nedaňové náklady (§25 ZDP, ' . number_format($nonDeductible, 0, ',', ' ')
                . ' Kč, dle příznaku „Daňová uznatelnost" na účtech) byly z výdajů §7 vyloučeny (přičteny zpět k základu).';
        }
        if ($manualIncrease > 0 || $manualDecrease > 0) {
            $warnings[] = 'Ruční položky §23 promítnuty do §7: zvýšení základu '
                . number_format($manualIncrease, 0, ',', ' ') . ' Kč, snížení základu '
                . number_format($manualDecrease, 0, ',', ' ') . ' Kč.';
        }
        $warnings[] = 'Rozdíl účetních a daňových odpisů se v §7 automaticky nepromítá — ověřte a případně upravte ručně.';

        return [$revenues, $expenses, $warnings];
    }

    /** @return array{income:float|null,expenses:float,warnings:list<string>,blocking_issues:list<array<string,mixed>>,source_manifest:list<array<string,mixed>>} */
    private function cashBase(int $supplierId, int $year): array
    {
        try {
            $j = $this->cashJournal->build($supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), ['year' => $year]);
            $totals = (array) ($j['totals'] ?? []);
            $income = round((float) ($totals['prijem_danovy'] ?? 0), 2);
            $expenses = round((float) ($totals['vydaj_danovy'] ?? 0), 2);
            $depreciation = $this->db->pdo()->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM depreciation_entries
                  WHERE supplier_id = ? AND fiscal_year = ? AND kind = 'tax' AND status = 'confirmed'"
            );
            $depreciation->execute([$supplierId, $year]);
            $expenses = round($expenses + (float) $depreciation->fetchColumn(), 2);
            $warn = [];
            $blocking = [];
            if (!empty($j['warnings'])) {
                $warn[] = 'Peněžní deník obsahuje nezařazené/varovné pohyby — zkontrolujte daňovou evidenci před podáním.';
                foreach ((array) $j['warnings'] as $warning) {
                    if (is_array($warning) && !empty($warning['blocking'])) {
                        $blocking[] = ['key' => (string) ($warning['type'] ?? 'cash_journal'), 'message' => (string) ($warning['message'] ?? 'Blokující chyba peněžního deníku.')];
                    }
                }
            }
            $expl = (array) (((array) ($j['checks'] ?? []))['explanations'] ?? []);
            $cashSales = round((float) ($expl['cash_sales_without_invoice'] ?? 0), 2);
            $partial = round((float) ($expl['partial_payments'] ?? 0), 2);
            $parts = [];
            if ($cashSales != 0.0) {
                $parts[] = 'hotovostní tržby bez faktury ' . number_format($cashSales, 2, ',', ' ') . ' Kč';
            }
            if ($partial != 0.0) {
                $parts[] = 'částečné úhrady faktur ' . number_format($partial, 2, ',', ' ') . ' Kč';
            }
            if ($parts !== []) {
                $warn[] = 'Do §7 příjmů byly z peněžního deníku (kasová báze) zahrnuty: ' . implode(', ', $parts) . '.';
            }
            $manifest = array_map(static fn (array $row): array => [
                'source_type' => (string) ($row['source_type'] ?? ''),
                'source_id' => (int) ($row['source_id'] ?? 0),
                'date' => (string) ($row['date'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'amount' => round((float) (($row['income'] ?? null) ?? ($row['expense'] ?? 0)), 2),
                'bucket' => (string) ($row['bucket'] ?? ''),
                'base' => round((float) ($row['base'] ?? 0), 2),
                'vat' => round((float) ($row['vat'] ?? 0), 2),
            ], (array) ($j['rows'] ?? []));
            return ['income' => $income, 'expenses' => $expenses, 'warnings' => $warn, 'blocking_issues' => $blocking, 'source_manifest' => $manifest];
        } catch (\Throwable $e) {
            return [
                'income' => null,
                'expenses' => 0.0,
                'warnings' => ['Pracovní náhled použil příjem ze zaplacených faktur, protože se peněžní deník nepodařilo načíst.'],
                'blocking_issues' => [['key' => 'cash_journal_error', 'message' => 'Peněžní deník se nepodařilo načíst: ' . $e->getMessage()]],
                'source_manifest' => [],
            ];
        }
    }

    /** @return array<string,mixed>|null */
    private function closing(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, checklist, opening_balances, closing_balances, unsupported_cases,
                    source_snapshot, source_hash, row_version, finalized_at, finalized_by
               FROM tax_evidence_closings WHERE supplier_id = ? AND year = ?'
        );
        $stmt->execute([$supplierId, $year]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $adjust = $this->db->pdo()->prepare(
            "SELECT direction, COALESCE(SUM(amount),0) total FROM tax_evidence_non_cash_adjustments
              WHERE supplier_id = ? AND closing_id = ? GROUP BY direction"
        );
        $adjust->execute([$supplierId, (int) $row['id']]);
        $sums = ['increase' => 0.0, 'decrease' => 0.0, 'neutral' => 0.0];
        foreach ($adjust->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $sum) {
            $sums[(string) $sum['direction']] = round((float) $sum['total'], 2);
        }
        foreach (['checklist', 'opening_balances', 'closing_balances', 'unsupported_cases', 'source_snapshot'] as $key) {
            $decoded = json_decode((string) ($row[$key] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }
        $row['id'] = (int) $row['id'];
        $row['row_version'] = (int) $row['row_version'];
        $row['adjustments'] = $sums;
        return $row;
    }

    private function expenseModeTransitionWarning(int $supplierId, int $year, bool $useActual): string
    {
        $asOf = sprintf('%04d-12-31', $year - 1);
        $receivables = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(GREATEST(i.amount_to_pay - COALESCE(p.paid, 0), 0) *
                    CASE WHEN c.code = 'CZK' THEN 1 ELSE COALESCE(i.exchange_rate, 1) END), 0)
               FROM invoices i
          LEFT JOIN currencies c ON c.id = i.currency_id
          LEFT JOIN (
                SELECT ip.invoice_id, SUM(ip.amount) AS paid
                  FROM invoice_payments ip
                 WHERE ip.paid_on <= ?
              GROUP BY ip.invoice_id
          ) p ON p.invoice_id = i.id
              WHERE i.supplier_id = ? AND i.issue_date <= ?
                AND i.invoice_type NOT IN ('proforma','tax_document') AND i.status <> 'cancelled'"
        );
        $receivables->execute([$asOf, $supplierId, $asOf]);
        $receivableTotal = round((float) $receivables->fetchColumn(), 2);

        $payables = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) *
                    CASE WHEN c.code = 'CZK' THEN 1 ELSE COALESCE(pi.exchange_rate, 1) END), 0)
               FROM purchase_invoices pi
          LEFT JOIN currencies c ON c.id = pi.currency_id
              WHERE pi.supplier_id = ? AND pi.issue_date <= ?
                AND pi.document_kind NOT IN ('advance', 'tax_document') AND pi.status NOT IN ('paid','cancelled')"
        );
        $payables->execute([$supplierId, $asOf]);
        $payableTotal = round((float) $payables->fetchColumn(), 2);

        $direction = $useActual ? 'z výdajového paušálu na skutečné výdaje' : 'ze skutečných výdajů na výdajový paušál';
        return 'BLOKUJÍCÍ KONTROLA §23 odst. 8 ZDP: zjistili jsme přechod ' . $direction . '. '
            . 'K ' . $asOf . ' evidujeme otevřené pohledávky ' . number_format($receivableTotal, 2, ',', ' ')
            . ' Kč a závazky ' . number_format($payableTotal, 2, ',', ' ') . ' Kč. Před finalizací ověřte dodatečné '
            . 'přiznání předchozího roku, zásoby a další povinné úpravy základu; automatický výpočet není úplný.';
    }

    /** @return array<string,mixed> */
    private function defaultProfile(int $year): array
    {
        return [
            'year' => $year,
            'activity_rate' => 60,
            'use_actual_expenses' => false,
            'actual_expenses' => 0.0,
            'flat_tax_band' => 'none',
            'is_secondary' => false,
            'spouse_credit' => false,
            'children_count' => 0,
            'mortgage_interest' => 0.0,
            'mortgage_months' => 12,
            'pension_contrib' => 0.0,
            'life_insurance' => 0.0,
            'dip_contrib' => 0.0,
            'long_term_care' => 0.0,
            'disability_12_months' => 0,
            'disability_3_months' => 0,
            'ztpp_months' => 0,
            'donations' => 0.0,
            'activities' => [],
            'children' => [],
            'spouse_claim' => null,
            'osvc_months' => [],
            'sickness_insured' => false,
            'sickness_monthly_base' => null,
        ];
    }
}
