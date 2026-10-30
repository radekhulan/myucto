<?php

declare(strict_types=1);

namespace MyInvoice\Service\Dashboard;

use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Action\Tax\TaxAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Data společných ploch; memoizace žije pouze v jednom autorizovaném požadavku. */
final class DashboardWidgetDataService
{
    public function __construct(
        private readonly Connection $db,
        private readonly DashboardWidgetRegistry $registry,
        private readonly DashboardWidgetAccess $access,
        private readonly CrmAggregationService $crm,
        private readonly SummaryAction $summary,
        private readonly DocumentRequestRepository $documents,
        private readonly DphPriznaniBuilder $dph,
        private readonly TaxAction $tax,
        private readonly TaxAdvanceScheduleService $advances,
        private readonly OverduePolicy $overduePolicy,
    ) {}

    /** @param list<array<string,mixed>> $widgets */
    public function query(Request $request, array $widgets): array
    {
        $supplier = $this->access->supplier($request);
        $sid = (int) ($supplier['id'] ?? 0);
        $now = new \DateTimeImmutable('today');
        $results = [];
        $allowed = [];
        // Nejdřív policy pro všechny instance, teprve potom mohou běžet poskytovatelé.
        foreach ($widgets as $widget) {
            $definition = $this->registry->get($widget['type']);
            if ($sid <= 0 || $definition === null || !$this->access->allows($request, $definition, $supplier)) {
                $results[$widget['id']] = ['status' => 'unavailable', 'data' => null];
            } else {
                $allowed[] = $widget;
            }
        }
        $memo = [];
        foreach ($allowed as $widget) {
            try {
                $data = $this->provide($request, $sid, $widget['type'], (array) $widget['options'], $now, $memo);
                $content = $data['items'] ?? $data['rows'] ?? $data;
                $results[$widget['id']] = ['status' => $content === [] || $data === null ? 'empty' : 'ok', 'data' => $data];
            } catch (\Throwable $failure) {
                if (\MyInvoice\Infrastructure\Database\TransactionFailure::aborted($failure)) throw $failure;
                // Chyba jedné závislosti nesmí zveřejnit SQL ani vyřadit ostatní widgety.
                $results[$widget['id']] = ['status' => 'error', 'data' => null];
            }
        }
        return ['widgets' => (object) $results, 'today' => $now->format('Y-m-d'), 'currency' => 'CZK',
            'businessActivity' => ['hasDocuments' => (bool) ($supplier['has_documents'] ?? false)]];
    }

    private function provide(Request $r, int $sid, string $type, array $o, \DateTimeImmutable $now, array &$memo): ?array
    {
        $currency = $this->currency($o);
        $limit = (int) ($o['rows'] ?? 10);
        $metric = function (string $name) use (&$memo, $sid, $r): mixed {
            $key = 'legacy:' . $name . (in_array($name, ['sales', 'purchases'], true) ? ':*' : '');
            return $this->memo($memo, $key, fn () => $this->summary->widgetMetric($name, $sid, RequestAuthorization::allows($r, 'projects')));
        };
        $aging = function (bool $sales) use (&$memo, $sid): array {
            return $this->memo($memo, $sales ? 'aging:sales' : 'aging:purchases',
                fn () => $sales ? $this->crm->agingReceivables($sid) : $this->crm->agingPayables($sid));
        };
        switch ($type) {
            case 'layout.divider':
                return ['layout' => true];
            case 'business.kpi':
                $period = (string) ($o['period'] ?? 'ytd');
                $rows = $this->memo($memo, 'kpi:' . $period, function () use ($sid, $period, $now): array {
                    [$from, $to, $label] = self::periodRange($period, $now);
                    return array_map(self::kpiRow(...), $this->crm->dashboardRange($sid, $from, $to, $label));
                });
                return ['period' => $period, 'rows' => $this->filterCurrency($rows, $currency)];
            case 'business.monthly':
                $months = (int) ($o['months'] ?? 12);
                $rows = $this->memo($memo, 'monthly:' . $months, fn () => array_map(self::kpiRow(...), $this->crm->monthlyHistory($sid, $months)));
                return ['rows' => $this->filterCurrency($rows, $currency)];
            case 'sales.summary':
                return $this->memo($memo, 'legacy:sales:' . ($currency ?? '*'), fn () => $this->summary->widgetMetric('sales', $sid, false, $currency));
            case 'purchases.summary':
                return $this->memo($memo, 'legacy:purchases:' . ($currency ?? '*'), fn () => $this->summary->widgetMetric('purchases', $sid, false, $currency));
            case 'receivables.aging':
            case 'payables.aging':
                return ['rows' => $this->filterCurrency($aging($type === 'receivables.aging'), $currency)];
            case 'cashflow.weekly':
                return $this->memo($memo, $type . ':' . ($currency ?? 'CZK'),
                    fn () => $this->crm->cashFlowForecast($sid, 4, $currency ?? 'CZK', false));
            case 'cashflow.collections':
                return ['rows' => $this->filterCurrency($metric('collections'), $currency)];
            case 'receivables.due':
                return ['rows' => $this->filterCurrency($metric('due'), $currency)];
            case 'invoices.overdue':
            case 'invoices.upcoming':
            case 'invoices.drafts':
                $name = substr($type, 9);
                $rows = $metric($name);
                $total = $this->memo($memo, 'count:' . $name, fn () => $this->invoiceCount($sid, $name));
                $rows = array_slice($rows, 0, $limit);
                foreach ($rows as &$row) {
                    $row['links'] = ['detail' => '/invoices/' . $row['id']];
                    if (RequestAuthorization::allows($r, 'clients') && !empty($row['client_id'])) $row['links']['client'] = '/clients/' . $row['client_id'];
                    if (RequestAuthorization::allows($r, 'projects') && !empty($row['project_id'])) $row['links']['project'] = '/projects/' . $row['project_id'];
                    if (!RequestAuthorization::allows($r, 'clients')) unset($row['client_id']);
                    if (!RequestAuthorization::allows($r, 'projects')) unset($row['project_id'], $row['project_name']);
                    // Detail znovu vyhodnotí zámky období a dokladu; widget nenabízí přímý zápis.
                }
                unset($row);
                return ['items' => $rows, 'total' => $total, 'links' => ['list' => '/invoices?' . ($name === 'overdue' ? 'overdue=1' : ($name === 'drafts' ? 'status=draft' : 'status=unpaid'))]];
            case 'clients.top':
                $months = (int) ($o['months'] ?? 12);
                $rows = $this->memo($memo, 'top:' . $months . ':' . ($currency ?? '*'), fn () => $this->crm->topClients($sid, $months, 20, $currency));
                return ['items' => array_map(static fn (array $row): array => [
                    'client_id' => (int) $row['client_id'], 'company_name' => (string) $row['company_name'],
                    // CRM topClients již řadí i sčítá napříč měnami po přepočtu kurzem dokladu do CZK.
                    'total_czk' => (float) $row['revenue'], 'revenue' => (float) $row['revenue'], 'currency' => 'CZK',
                    'currencies' => (string) $row['currencies'], 'invoice_count' => (int) $row['invoice_count'],
                    'percent_share' => (float) $row['percent_share'], 'links' => ['client' => '/clients/' . $row['client_id']],
                ], array_slice($rows, 0, $limit)), 'links' => ['list' => '/clients']];
            case 'invoices.approvals':
                return $metric('approvals') + ['links' => ['list' => '/admin/approvals']];
            case 'documents.requests':
                $link = RequestAuthorization::isClientType($r) ? '/portal/document-requests' : '/document-requests';
                return $this->memo($memo, 'documents', fn () => $this->documents->openCounts($sid)) + ['links' => ['list' => $link]];
            case 'actions.quick':
                return ['items' => $this->quickActions($r)];
            case 'actions.todo':
                $todo = $this->todo($r, $sid, $now, $memo);
                $todo['items'] = array_slice($todo['items'], 0, $limit);
                return $todo;
            case 'vat.current':
                return $this->memo($memo, $type, function () use ($sid, $now, $r): array {
                    $built = $this->dph->build($sid, (int) $now->format('Y'), (int) $now->format('n'));
                    $s = (array) ($built['summary'] ?? []);
                    $data = ['period' => $s['period'] ?? '', 'period_type' => $s['period_type'] ?? 'monthly',
                        'quarter' => $s['quarter'] ?? null, 'vat_output' => (float) ($s['total_vat_output'] ?? 0),
                        'vat_input' => (float) ($s['total_vat_input'] ?? 0), 'tax_due' => (float) ($s['tax_due'] ?? 0),
                        'is_excess_deduction' => (bool) ($s['is_excess_deduction'] ?? false),
                        'submission_deadline' => $s['submission_deadline'] ?? ''];
                    if (RequestAuthorization::allows($r, 'reports')) {
                        $data['link'] = \MyInvoice\Service\Report\TaxReportLink::forPeriod('/reports/dph', [
                            'year' => (int) substr((string) ($s['period'] ?? $now->format('Y-m')), 0, 4),
                            'month' => (int) substr((string) ($s['period'] ?? $now->format('Y-m')), 5, 2),
                            'quarter' => ($s['quarter'] ?? null) === null ? null : (int) $s['quarter'],
                        ]) . '&remember=0';
                    }
                    return $data;
                });
            case 'tax.deadlines':
                $items = $this->memo($memo, $type, fn () => $this->crm->taxDeadlineItems($sid, $now, 35));
                if (!RequestAuthorization::allows($r, 'reports')) {
                    foreach ($items as &$item) unset($item['link']);
                    unset($item);
                }
                return ['items' => $items];
            case 'tax.calendar':
                $items = array_slice($this->memo($memo, $type, fn () => $this->crm->taxCalendarItems($sid, $now, (int) ($o['days'] ?? 60))), 0, $limit);
                return ['items' => self::calendarRows($r, $items)];
            case 'tax.net':
                return $this->memo($memo, $type, function () use ($r): array {
                    $response = $this->tax->analysis($r->withQueryParams(['year' => (int) date('Y')]), new \Slim\Psr7\Response());
                    if ($response->getStatusCode() !== 200) throw new \RuntimeException('Daňový náhled není dostupný.');
                    return ['analysis' => json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)];
                });
            case 'tax.advances':
                return ['items' => array_slice($this->memo($memo, $type, fn () => $this->advances->upcoming($sid, 20)), 0, $limit)];
            case 'accounting.status':
                $data = ['links' => []];
                if (RequestAuthorization::allows($r, 'accounting.periods.manage', AccessLevel::WRITE)) {
                    $data['backfill_pending'] = $metric('backfill');
                    $data['links']['backfill'] = '/admin/accounting-activation';
                }
                if (RequestAuthorization::allows($r, 'accounting')) {
                    $data['bank_posting_pending'] = $metric('bank_posting');
                    $data['automation_auto_today'] = $metric('automation');
                    $data['links']['bank'] = '/automation?tab=pending';
                    $data['links']['automation'] = '/automation?tab=auto';
                }
                return $data;
        }
        throw new \InvalidArgumentException('Neznámý poskytovatel widgetu.');
    }

    /** Kalendář může ukázat termín registrace i bez práva měnit nastavení firmy. */
    public static function calendarRows(Request $request, array $items): array
    {
        foreach ($items as &$item) {
            if (($item['type'] ?? '') === 'vat_registration_deadline'
                && !RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) unset($item['link']);
        }
        unset($item);
        return $items;
    }

    private function memo(array &$memo, string $key, callable $load): mixed
    {
        if (!array_key_exists($key, $memo)) $memo[$key] = $load();
        return $memo[$key];
    }

    private function currency(array $options): ?string
    {
        $currency = (string) ($options['currency'] ?? 'all');
        return in_array($currency, ['all', 'auto'], true) ? null : $currency;
    }

    private function filterCurrency(array $rows, ?string $currency): array
    {
        return $currency === null ? $rows : array_values(array_filter($rows, static fn (array $row): bool => ($row['currency'] ?? '') === $currency));
    }

    /** Explicitní whitelist agregátů zachovává stejnou obchodní sémantiku portálu. */
    public static function kpiRow(array $row): array
    {
        return ['period' => $row['period'] ?? null, 'currency' => (string) ($row['currency'] ?? 'CZK'),
            'invoiced' => (float) ($row['revenue'] ?? 0), 'costs' => (float) ($row['costs'] ?? 0),
            'profit' => (float) ($row['profit'] ?? 0), 'invoiced_czk' => (float) ($row['revenue_czk'] ?? 0),
            'costs_czk' => (float) ($row['costs_czk'] ?? 0), 'profit_czk' => (float) ($row['profit_czk'] ?? 0),
            'invoice_count' => (int) ($row['invoice_count'] ?? 0), 'purchase_count' => (int) ($row['purchase_count'] ?? 0)];
    }

    /** @return array{string,string,?string} */
    public static function periodRange(string $period, \DateTimeImmutable $now): array
    {
        $month = $now->modify('first day of this month');
        [$from, $to, $label] = match ($period) {
            'current_month' => [$month, $month->modify('+1 month'), $month->format('Y-m')],
            'last_month' => [$month->modify('-1 month'), $month, $month->modify('-1 month')->format('Y-m')],
            'last_12m' => [$month->modify('-11 months'), $month->modify('+1 month'), null],
            'prev_year_ytd' => [$now->modify('-1 year')->modify('first day of January'), $now->modify('+1 day')->modify('-1 year'), null],
            'ytd' => [$now->modify('first day of January'), $now->modify('+1 day'), null],
            default => throw new \InvalidArgumentException('Neplatné období KPI.'),
        };
        return [$from->format('Y-m-d'), $to->format('Y-m-d'), $label];
    }

    private function quickActions(Request $r): array
    {
        $items = [];
        foreach (['invoices' => '/invoices/new', 'purchase_invoices' => '/purchase-invoices/new', 'clients' => '/clients/new'] as $name => $link) {
            if (RequestAuthorization::allows($r, $name . '.create', AccessLevel::WRITE)) $items[] = ['id' => $name, 'link' => $link];
        }
        return $items;
    }

    private function invoiceCount(int $sid, string $kind): int
    {
        return $this->summary->widgetInvoiceCount($kind, $sid);
    }

    /** Každá větev ověřuje cílové právo před dotazem; celý CRM feed se zde nenačítá. */
    private function todo(Request $r, int $sid, \DateTimeImmutable $now, array &$memo): array
    {
        $items = [];
        $capabilities = $this->access->capabilities($r, 'actions.todo');
        $add = static function (string $type, int $count, string $link, string $severity = 'medium') use (&$items): void {
            if ($count > 0) $items[] = ['type' => $type, 'count' => $count, 'link' => $link, 'severity' => $severity];
        };
        if ($capabilities['overdueReminders'] ?? false) {
            $add('overdue_invoices', $this->memo($memo, 'count:overdue', fn () => $this->invoiceCount($sid, 'overdue')), '/invoices?overdue=1');
        }
        if ($capabilities['invoiceApprovals'] ?? false) {
            $counts = $this->memo($memo, 'legacy:approvals', fn () => $this->summary->widgetMetric('approvals', $sid));
            $add('pending_approvals', (int) $counts['requested'], '/admin/approvals');
        }
        if ($capabilities['recurringRun'] ?? false) {
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM recurring_invoice_templates
                WHERE supplier_id=? AND status='active' AND next_run_date>=? AND next_run_date<=?");
            $stmt->execute([$sid, $now->format('Y-m-d'), $now->modify('+3 days')->format('Y-m-d')]);
            $add('recurring_due', (int) $stmt->fetchColumn(), '/recurring', 'low');
        }
        if ($capabilities['bankMatch'] ?? false) {
            // Scope firmy musí předcházet pohybům; jinak plán začíná globálním indexem match_status.
            // Předfiltr zachová historické výpisy bez vlastníka, izolaci nadále rozhoduje resolver.
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM bank_statements bs STRAIGHT_JOIN bank_transactions bt ON bt.statement_id=bs.id
                WHERE (bs.supplier_id=? OR bs.supplier_id IS NULL) AND " . \MyInvoice\Repository\BankStatementOwnershipResolver::sql() . " AND bt.match_status='unmatched'
                AND " . \MyInvoice\Service\Bank\BankNoticeLifecycle::activeSql('bt') . " AND bt.amount>0 AND bt.posted_at>=?
                AND NOT " . \MyInvoice\Service\Bank\NonInvoiceBankTransactionScope::sql($sid, 'bt.id'));
            $stmt->execute([$sid, ...\MyInvoice\Repository\BankStatementOwnershipResolver::params($sid), $now->modify('-90 days')->format('Y-m-d')]);
            $add('bank_unmatched', (int) $stmt->fetchColumn(), '/bank');
        }
        if ($capabilities['bankRematch'] ?? false) {
            array_push($items, ...$this->crm->bankProcessingActionItems($sid, true, $now));
        }
        if ($capabilities['bankConnections'] ?? false) {
            array_push($items, ...$this->crm->bankSyncActionItems($sid, true));
        }
        if ($capabilities['purchaseReview'] ?? false) {
            $counts = $this->memo($memo, 'legacy:purchases:*', fn () => $this->summary->widgetMetric('purchases', $sid));
            $add('overdue_payables', (int) $counts['purchase_overdue_count'], '/purchase-invoices?overdue=1');
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM purchase_invoices
                WHERE supplier_id=? AND status='draft' AND approval_status<>'pending'");
            $stmt->execute([$sid]);
            $add('purchase_drafts', (int) $stmt->fetchColumn(), '/purchase-invoices?status=draft', 'low');
        }
        if ($capabilities['purchaseApprove'] ?? false) {
            $user = (array) $r->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);
            $count = (new \MyInvoice\Repository\PurchaseInvoiceApprovalRepository($this->db))->pendingCountForApprover($sid, (int) ($user['id'] ?? 0));
            $add('purchase_approvals_pending', $count, '/purchase-approvals');
        }
        if ($capabilities['taxTasks'] ?? false) {
            array_push($items, ...$this->memo($memo, 'tax:todo-deadlines', fn () => $this->crm->taxDeadlineItems($sid, $now)));
            array_push($items, ...$this->memo($memo, 'tax:todo-payments', fn () => $this->crm->vatPaymentItems($sid, $now)));
        }
        if ($capabilities['accountingPeriods'] ?? false) {
            $health = (new \MyInvoice\Service\Accounting\AccountingPeriodHealthService($this->db))->diagnose($sid, $now->format('Y-m-d'));
            if ($health['applicable'] && $health['state'] !== 'ok') {
                $items[] = ['type' => 'accounting_period_missing', 'severity' => $health['severity'],
                    'count' => max(1, (int) $health['outside_count']),
                    'link' => $health['state'] === 'no_periods' ? '/admin/accounting-activation' : '/accounting/periods'];
            }
        }
        if ($capabilities['payrollSubmit'] ?? false) {
            // Zachováváme i samostatné firemní zapnutí mezd, které běžně ověřuje middleware.
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM payroll_submissions s
                JOIN payroll_obligations o ON o.id=s.obligation_id AND o.supplier_id=s.supplier_id
                JOIN supplier su ON su.id=s.supplier_id AND su.payroll_enabled=1
                WHERE s.supplier_id=? AND s.status='ready' AND s.channel='vrep_apep'
                    AND s.environment='production' AND o.agenda_code LIKE 'JMHZ%'");
            $stmt->execute([$sid]);
            $add('jmhz_unsent', (int) $stmt->fetchColumn(), '/payroll/submissions', 'high');
        }
        return ['items' => $items, 'total' => count($items)];
    }
}
