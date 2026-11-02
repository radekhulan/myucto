<?php

declare(strict_types=1);

namespace MyInvoice\Service\Dashboard;

use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Action\Tax\TaxAction;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Data společných ploch; memoizace žije pouze v jednom autorizovaném požadavku. */
final class DashboardWidgetDataService
{
    public function __construct(
        private readonly DashboardWidgetRegistry $registry,
        private readonly DashboardWidgetAccess $access,
        private readonly CrmAggregationService $crm,
        private readonly SummaryAction $summary,
        private readonly TaxAction $tax,
        private readonly TaxAdvanceScheduleService $advances,
        private readonly \MyInvoice\Service\Portal\PortalSummaryService $portal,
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
                fn () => $this->portal->aging($sid, $sales));
        };
        switch ($type) {
            case 'business.kpi':
                $period = (string) ($o['period'] ?? 'ytd');
                $rows = $this->memo($memo, 'kpi:' . $period, function () use ($sid, $period, $now): array {
                    return $this->portal->kpiPeriod($sid, $period, $now);
                });
                return ['period' => $period, 'rows' => $this->filterCurrency($rows, $currency)];
            case 'business.monthly':
                $months = (int) ($o['months'] ?? 12);
                $rows = $this->memo($memo, 'monthly:' . $months, fn () => $this->portal->monthly($sid, $months));
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
                    fn () => $this->portal->forecast($sid, $currency ?? 'CZK'));
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
                return ['items' => $rows, 'total' => $total, 'links' => ['list' => '/invoices?' . ($name === 'overdue' ? 'overdue=1' : ($name === 'drafts' ? 'status=draft' : 'unpaid=1'))]];
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
                return $this->memo($memo, 'documents', fn () => $this->portal->documentRequests($sid)) + ['links' => ['list' => $link]];
            case 'actions.quick':
                return ['items' => $this->quickActions($r)];
            case 'actions.todo':
                $todo = $this->memo($memo, 'todo', fn () => $this->crm->actionItems($sid,
                    (int) (((array) $r->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []))['id'] ?? 0), $now,
                    RequestAuthorization::allows($r, 'settings.bank_accounts', AccessLevel::WRITE),
                    RequestAuthorization::allows($r, 'bank.match', AccessLevel::WRITE)));
                $todo['items'] = array_slice($todo['items'], 0, $limit);
                return $todo;
            case 'vat.current':
                return $this->memo($memo, $type, function () use ($sid, $now, $r): ?array {
                    $data = $this->portal->vatStatus($sid, true, $now);
                    if ($data === null) return null;
                    if (RequestAuthorization::allows($r, 'reports')) {
                        $data['link'] = \MyInvoice\Service\Report\TaxReportLink::forPeriod('/reports/dph', [
                            'year' => (int) substr((string) ($data['period'] ?: $now->format('Y-m')), 0, 4),
                            'month' => (int) substr((string) ($data['period'] ?: $now->format('Y-m')), 5, 2),
                            'quarter' => ($data['quarter'] ?? null) === null ? null : (int) $data['quarter'],
                        ]) . '&remember=0';
                    }
                    return $data;
                });
            case 'tax.deadlines':
                $items = $this->memo($memo, $type, fn () => $this->portal->deadlines($sid, $now));
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

}
