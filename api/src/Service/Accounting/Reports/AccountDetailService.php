<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\LedgerReportRepository;

/**
 * Karta účtu — kmenová data + PS/obraty/KS za zvolený rozsah + analytiky pod
 * syntetikou s vlastními zůstatky. Slouží jako rozcestník drill-through
 * (osnova → účet → analytika → opis účtu / hlavní kniha / deník).
 *
 * Zůstatky se NEpočítají vlastním SQL — jde o tytéž metody
 * {@see LedgerReportRepository::accountOpening()} / `accountTurnovers()`, které
 * staví opis účtu. Kdyby si karta účtu okna PS (R6 / openingAnchor F4 R16)
 * naprogramovala znovu, rozjela by se s opisem i s hlavní knihou při první
 * změně pravidla — a přesně tomu má SSOT bránit (AGENTS.md §Účetní vrstva).
 * Počet analytik je řádově jednotky, N+1 je tu tedy levnější než duplicitní SQL.
 */
final class AccountDetailService
{
    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $accountId, string $from, string $to, bool $afterClosing = false): array
    {
        $account = $this->accounts->findById($supplierId, $accountId, $to);
        if ($account === null) {
            throw new ReportException('account_not_found', 'Účet #' . $accountId . ' neexistuje.', 404);
        }

        $period = $this->periods->findForDate($supplierId, $from);
        $periodStart = $period !== null ? (string) $period['starts_on'] : $from;
        $excludeClosing = !$afterClosing;

        $parent = null;
        if ($account['parent_id'] !== null) {
            $p = $this->accounts->findById($supplierId, (int) $account['parent_id'], $to);
            if ($p !== null) {
                $parent = [
                    'id'   => (int) $p['id'],
                    'code' => (string) $p['account_code'],
                    'name' => (string) $p['name'],
                ];
            }
        }

        $children = [];
        foreach ($this->accounts->childrenOf($supplierId, $accountId, $to) as $c) {
            $childId = (int) $c['id'];
            $children[] = [
                'id'        => $childId,
                'code'      => (string) $c['account_code'],
                'name'      => (string) $c['name'],
                'is_active' => (bool) $c['is_active'],
            ] + $this->balances($supplierId, $childId, $from, $to, $periodStart, $excludeClosing);
        }

        return [
            'account' => [
                'id'           => (int) $account['id'],
                'code'         => (string) $account['account_code'],
                'name'         => (string) $account['name'],
                'account_type' => (string) $account['account_type'],
                'normal_side'  => $account['normal_side'],
                'is_synthetic' => (bool) $account['is_synthetic'],
                'is_active'    => (bool) $account['is_active'],
                'parent_id'    => $account['parent_id'],
                'created_at'   => $account['created_at'],
            ],
            'parent' => $parent,
            'period' => $period === null ? null : [
                'id'          => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on'   => (string) $period['starts_on'],
                'ends_on'     => (string) $period['ends_on'],
                'status'      => (string) $period['status'],
            ],
            'from'          => $from,
            'to'            => $to,
            'after_closing' => $afterClosing,
            // Součty syntetiky zahrnují i pohyby jejích analytik — stejná definice
            // jako opis účtu a hlavní kniha (roll-up R15), aby čísla seděla napříč.
            'totals'   => $this->balances($supplierId, $accountId, $from, $to, $periodStart, $excludeClosing),
            'children' => $children,
        ];
    }

    /**
     * @return array{opening_balance:float, turnover_md:float, turnover_d:float, closing_balance:float, line_count:int}
     */
    private function balances(int $supplierId, int $accountId, string $from, string $to, string $periodStart, bool $excludeClosing): array
    {
        $opening   = $this->ledger->accountOpening($supplierId, $accountId, $from, $periodStart, $excludeClosing);
        $turnovers = $this->ledger->accountTurnovers($supplierId, $accountId, $from, $to, $excludeClosing);

        return [
            'opening_balance' => $opening,
            'turnover_md'     => $turnovers['md'],
            'turnover_d'      => $turnovers['d'],
            'closing_balance' => round($opening + $turnovers['md'] - $turnovers['d'], 2),
            'line_count'      => $this->ledger->accountLinesTotal($supplierId, $accountId, $from, $to, $excludeClosing),
        ];
    }
}
