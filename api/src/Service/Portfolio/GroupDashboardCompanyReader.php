<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Crm\CrmDashboardAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\StatementMetrics;
use MyInvoice\Service\Crm\CrmAggregationService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response;

final class GroupDashboardCompanyReader
{
    public function __construct(
        private readonly Connection $db,
        private readonly CrmAggregationService $crm,
        private readonly AccountingPeriodRepository $periods,
        private readonly FinancialStatementService $statements,
        private readonly CashRegisterService $cash,
        private readonly BankStatementAction $bank,
        private readonly CrmDashboardAction $crmDashboard,
        private readonly GroupDashboardForecast $forecast,
    ) {}

    public function read(Request $request, array $supplier, string $section, int $months, int $weeks, ?array $period = null, bool $includeRelated = true): array
    {
        $excludeRelated = !$includeRelated;
        $period ??= GroupDashboardService::period($months);
        $toExclusive = (new \DateTimeImmutable($period['to']))->modify('+1 day')->format('Y-m-d');
        $id = (int) $supplier['id'];
        $can = static fn (string $key): bool => RequestAuthorization::allows($request, $key);
        $documents = $can('invoices') && $can('purchase_invoices');
        $row = [
            'id' => $id, 'name' => (string) $supplier['name'],
            'accounting_mode' => (string) $supplier['accounting_mode'],
            'document_basis' => (int) $supplier['is_vat_payer'] === 1 ? 'net' : 'gross',
            'available' => [
                'documents' => $documents,
                'accounting' => $can('accounting') && $supplier['accounting_mode'] === 'double_entry',
                'bank' => $can('bank'), 'cash' => $can('cash'),
                'receivables' => $can('invoices'), 'payables' => $can('purchase_invoices'),
                'cashflow' => $documents && $can('other_items'),
                'cashflow_tax' => $can('reports'), 'cashflow_payroll' => $can('payroll.payments'),
                'forecast' => $documents && $can('other_items'),
            ],
            'issues' => [],
        ];
        if ($section === 'overview') {
            if ($documents) $this->part($row, 'financial', function () use ($id, $period, $toExclusive, $excludeRelated): array {
                $current = array_column($this->crm->documentRange($id, $period['from'], $toExclusive, null, $excludeRelated), null, 'currency');
                $previous = array_column($this->crm->documentRange($id, $period['previous_from'],
                    (new \DateTimeImmutable($period['previous_to']))->modify('+1 day')->format('Y-m-d'), null, $excludeRelated), null, 'currency');
                $currencies = array_values(array_unique([...array_keys($current), ...array_keys($previous)]));
                sort($currencies);
                return array_map(static fn (string $currency): array => [
                    'currency' => $currency,
                    'revenue' => $current[$currency]['revenue'] ?? 0.0,
                    'costs' => $current[$currency]['costs'] ?? 0.0,
                    'profit' => $current[$currency]['profit'] ?? 0.0,
                    'previous_revenue' => $previous[$currency]['revenue'] ?? 0.0,
                    'previous_costs' => $previous[$currency]['costs'] ?? 0.0,
                    'previous_profit' => $previous[$currency]['profit'] ?? 0.0,
                    'revenue_czk' => isset($current[$currency]) ? $current[$currency]['revenue_czk'] ?? null : 0.0,
                    'costs_czk' => isset($current[$currency]) ? $current[$currency]['costs_czk'] ?? null : 0.0,
                    'profit_czk' => isset($current[$currency]) ? $current[$currency]['profit_czk'] ?? null : 0.0,
                    'previous_revenue_czk' => isset($previous[$currency]) ? $previous[$currency]['revenue_czk'] ?? null : 0.0,
                    'previous_costs_czk' => isset($previous[$currency]) ? $previous[$currency]['costs_czk'] ?? null : 0.0,
                    'previous_profit_czk' => isset($previous[$currency]) ? $previous[$currency]['profit_czk'] ?? null : 0.0,
                    'conversion_missing' => ($current[$currency]['conversion_missing'] ?? 0) + ($previous[$currency]['conversion_missing'] ?? 0),
                ], $currencies);
            });
            if ($row['available']['accounting']) $this->part($row, 'accounting', function () use ($id): ?array {
                $period = $this->periods->findForDate($id, date('Y-m-d'));
                if ($period === null) return null;
                $report = $this->statements->accountView($id, (int) $period['id'], date('Y-m-d'));
                return [
                    'currency' => 'CZK', 'from' => $period['starts_on'], 'to' => $report['as_of'],
                ] + StatementMetrics::accountViewTotals($report);
            });
        } elseif ($section === 'trends' && $documents) {
            $this->part($row, 'monthly', function () use ($id, $period, $toExclusive, $excludeRelated): array {
                $rows = [];
                $months = [];
                $currencies = [];
                $cursor = new \DateTimeImmutable($period['from']);
                $end = new \DateTimeImmutable($toExclusive);
                while ($cursor < $end) {
                    $next = min($cursor->modify('first day of next month'), $end);
                    $month = $cursor->format('Y-m');
                    $months[] = $month;
                    foreach ($this->crm->documentRange($id, $cursor->format('Y-m-d'), $next->format('Y-m-d'), $cursor->format('Y-m'), $excludeRelated) as $r) {
                        $currencies[$r['currency']] = true;
                        $rows[$month][$r['currency']] = ['period' => $r['period'], 'currency' => $r['currency'],
                            'revenue' => $r['revenue'], 'costs' => $r['costs'], 'profit' => $r['profit'],
                            'revenue_czk' => $r['revenue_czk'] ?? null, 'costs_czk' => $r['costs_czk'] ?? null,
                            'profit_czk' => $r['profit_czk'] ?? null, 'conversion_missing' => $r['conversion_missing'] ?? 0];
                    }
                    $cursor = $next;
                }
                $currencies = array_keys($currencies);
                sort($currencies);
                $monthly = [];
                foreach ($months as $month) {
                    foreach ($currencies as $currency) {
                        $monthly[] = $rows[$month][$currency] ?? ['period' => $month, 'currency' => $currency,
                            'revenue' => 0.0, 'costs' => 0.0, 'profit' => 0.0,
                            'revenue_czk' => 0.0, 'costs_czk' => 0.0, 'profit_czk' => 0.0, 'conversion_missing' => 0];
                    }
                }
                return $monthly;
            });
        } elseif ($section === 'cashflow' && $row['available']['cashflow']) {
            $this->part($row, 'cashflow', function () use ($id, $weeks, $request): array {
                $find = $this->db->pdo()->prepare("SELECT code FROM currencies WHERE supplier_id = ?
                    UNION SELECT currency AS code FROM other_items WHERE supplier_id = ? AND deleted_at IS NULL
                        AND status IN ('draft', 'confirmed', 'posted') ORDER BY code");
                $find->execute([$id, $id]);
                $currencies = $find->fetchAll(\PDO::FETCH_COLUMN);
                if (RequestAuthorization::allows($request, 'reports') || RequestAuthorization::allows($request, 'payroll.payments')) {
                    $currencies[] = 'CZK';
                }
                $currencies = array_values(array_unique($currencies));
                sort($currencies);
                return array_map(function (string $currency) use ($request, $weeks): array {
                    $response = $this->crmDashboard->cashFlowForecast(
                        $request->withQueryParams(['weeks' => $weeks, 'currency' => $currency]), new Response());
                    if ($response->getStatusCode() !== 200) throw new \RuntimeException('Cash flow unavailable.');
                    return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                }, $currencies);
            });
        } elseif ($section === 'forecast' && $row['available']['forecast']) {
            $this->part($row, 'forecast', fn (): ?array => $this->forecast->read($request, $supplier));
        } elseif ($section === 'balances') {
            if ($can('bank')) $this->part($row, 'bank', function () use ($request): array {
                $response = $this->bank->accountBalances($request, new Response());
                if ($response->getStatusCode() !== 200) throw new \RuntimeException('Bank balances unavailable.');
                $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                return array_map(static fn (array $a): array => [
                    'id' => $a['id'], 'name' => $a['label'], 'currency' => $a['code'],
                    'balance' => $a['current_balance'], 'date' => $a['statement_date'],
                    'balance_czk' => $a['current_balance_czk'] ?? null,
                ], $payload['accounts']);
            });
            if ($can('cash')) $this->part($row, 'cash', fn (): array => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'name' => $r['name'], 'currency' => $r['currency_code'],
                'balance' => $r['currency_code'] === 'CZK' ? $r['balance'] : $r['balance_foreign'],
                'balance_czk' => $r['balance'],
                'date' => $r['balance_date'],
            ], $this->cash->list($id)));
        } elseif ($section === 'receivables') {
            if ($can('invoices')) $this->part($row, 'receivables', fn (): array => $this->crm->agingReceivables($id, $excludeRelated));
            if ($can('purchase_invoices')) $this->part($row, 'payables', fn (): array => $this->crm->agingPayables($id, $excludeRelated));
        } elseif ($section === 'risks' && ($can('invoices') || $can('purchase_invoices'))) {
            $this->part($row, 'risks', function () use ($id, $can, $documents, $period, $toExclusive, $excludeRelated): array {
                $risks = [];
                if ($can('invoices')) {
                    foreach ($this->crm->agingReceivables($id, $excludeRelated) as $r) {
                        if ($r['bucket'] !== 'not_due' && $r['total'] > 0) $risks[] = [
                            'kind' => 'overdue_receivables', 'currency' => $r['currency'], 'amount' => $r['total'],
                            'count' => $r['count'], 'bucket' => $r['bucket'],
                        ];
                    }
                }
                if ($can('purchase_invoices')) {
                    foreach ($this->crm->agingPayables($id, $excludeRelated) as $r) {
                        if ($r['bucket'] !== 'not_due' && $r['total'] > 0) $risks[] = [
                            'kind' => 'overdue_payables', 'currency' => $r['currency'], 'amount' => $r['total'],
                            'count' => $r['count'], 'bucket' => $r['bucket'],
                        ];
                    }
                }
                if ($documents) {
                    foreach ($this->crm->documentRange($id, $period['from'], $toExclusive, null, $excludeRelated) as $r) {
                        if ($r['profit'] < 0) $risks[] = ['kind' => 'document_loss', 'currency' => $r['currency'], 'amount' => $r['profit'],
                            'amount_czk' => $r['profit_czk'] ?? null, 'conversion_missing' => $r['conversion_missing'] ?? 0];
                    }
                    $concentration = $this->crm->clientConcentration($id, 12);
                    if ($concentration['risk_level'] !== 'low') $risks[] = [
                        'kind' => 'client_concentration', 'currency' => $concentration['currency'],
                        'percentage' => $concentration['top1_share'], 'level' => $concentration['risk_level'],
                    ];
                    $concentration = $this->crm->vendorConcentration($id, 12);
                    if ($concentration['risk_level'] !== 'low') $risks[] = [
                        'kind' => 'vendor_concentration', 'currency' => $concentration['currency'],
                        'percentage' => $concentration['top1_share'], 'level' => $concentration['risk_level'],
                    ];
                }
                return $risks;
            });
        }
        return $row;
    }

    private function part(array &$row, string $key, callable $read): void
    {
        try {
            $row[$key] = $read();
        } catch (\Throwable) {
            $row[$key] = null;
            $row['issues'][] = $key;
        }
    }
}
