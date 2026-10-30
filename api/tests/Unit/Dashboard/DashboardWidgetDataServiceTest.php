<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Dashboard;

use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Action\Tax\TaxAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Dashboard\DashboardWidgetAccess;
use MyInvoice\Service\Dashboard\DashboardWidgetDataService;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DashboardWidgetDataServiceTest extends TestCase
{
    private array $queries = [];
    private array $parameters = [];
    private int $supplierId = 901;

    public function testDeniedWidgetsNeverRunTheirProviders(): void
    {
        $service = $this->service();
        $response = $service->query($this->request([]), [
            $this->widget('sales', 'sales.summary'), $this->widget('clients', 'clients.top'),
            $this->widget('internal', 'accounting.status'), $this->widget('todo', 'actions.todo'),
            $this->widget('tax', 'tax.net'),
        ]);
        foreach ((array) $response['widgets'] as $result) self::assertSame(['status' => 'unavailable', 'data' => null], $result);
        self::assertCount(1, $this->queries, 'Jediný dotaz načte kontext firmy; žádný zakázaný provider.');
    }

    public function testRepeatedKpisShareOnlyTheirSelectedAggregateAndMemoDoesNotOutliveRequest(): void
    {
        $service = $this->service();
        $widgets = [$this->widget('one', 'business.kpi', ['period' => 'ytd']), $this->widget('two', 'business.kpi', ['period' => 'ytd'])];
        $first = (array) $service->query($this->request([]), $widgets)['widgets'];
        self::assertSame('ok', $first['one']['status']);
        self::assertSame($first['one'], $first['two']);
        self::assertSame(120.0, $first['one']['data']['rows'][0]['invoiced']);
        self::assertSame(30.0, $first['one']['data']['rows'][0]['costs']);
        self::assertSame(90.0, $first['one']['data']['rows'][0]['profit']);
        self::assertCount(4, $this->queries, 'Kontext, plátcovství a pouze dva vyžádané obchodní agregáty.');
        foreach ($this->parameters as $params) self::assertSame(901, $params[0]);

        $this->supplierId = 902;
        $second = (array) $service->query($this->request([], 902), $widgets)['widgets'];
        self::assertSame(240.0, $second['one']['data']['rows'][0]['invoiced']);
        self::assertCount(8, $this->queries, 'Další firma nesmí převzít memoizaci předchozího požadavku.');
    }

    public function testQuickActionsUseTheirOwnWritePermissionsWithoutBusinessQueries(): void
    {
        $response = (array) $this->service()->query($this->request(['invoices.create' => 2, 'clients.create' => 1]), [$this->widget('quick', 'actions.quick')])['widgets'];
        self::assertSame([['id' => 'invoices', 'link' => '/invoices/new']], $response['quick']['data']['items']);
        self::assertCount(1, $this->queries);
    }

    public function testDividerDoesNotRunAnyDataProvider(): void
    {
        $result = (array) $this->service()->query($this->request([]), [$this->widget('space', 'layout.divider')])['widgets'];
        self::assertSame(['status' => 'ok', 'data' => ['layout' => true]], $result['space']);
        self::assertCount(1, $this->queries, 'Pouze kontext firmy, žádná agregace ani seznam.');
    }

    public function testWeeklyBusinessForecastNeverLoadsInternalOtherItems(): void
    {
        $result = (array) $this->service()->query($this->request([]), [$this->widget('forecast', 'cashflow.weekly')])['widgets'];
        self::assertSame('ok', $result['forecast']['status']);
        self::assertSame(150.0, $result['forecast']['data']['total_in']);
        self::assertSame(50.0, $result['forecast']['data']['total_out']);
        self::assertSame(100.0, $result['forecast']['data']['total_net']);
        self::assertCount(3, $this->queries, 'Kontext a dvě fakturační strany; jiné položky ani mzdy se nečtou.');
    }

    public function testCalendarRegistrationLinkRequiresItsActualTargetPermission(): void
    {
        $items = [['type' => 'vat_registration_deadline', 'deadline' => '2026-11-01', 'link' => '/admin/settings'],
            ['type' => 'dph_deadline', 'deadline' => '2026-11-25', 'link' => '/reports/dph']];
        $reader = $this->request(['reports' => 1]);
        $limited = DashboardWidgetDataService::calendarRows($reader, $items);
        self::assertArrayNotHasKey('link', $limited[0]);
        self::assertSame('2026-11-01', $limited[0]['deadline']);
        self::assertSame('/reports/dph', $limited[1]['link']);
        self::assertSame($items, DashboardWidgetDataService::calendarRows($this->request([])->withAttribute('auth.effective_role', new EffectiveRole(32, 'Syntetický správce nastavení', 'staff', true, ['settings.company.write' => 2])), $items));
    }

    public function testKpiWhitelistRemovesNamesAndDetailedAmounts(): void
    {
        $row = DashboardWidgetDataService::kpiRow(['period' => '2026-10', 'currency' => 'EUR', 'revenue' => 30,
            'costs' => 10, 'profit' => 20, 'revenue_czk' => 750, 'costs_czk' => 250, 'profit_czk' => 500,
            'company_name' => 'Syntetická protistrana', 'invoice_number' => 'TEST-42', 'vat_output' => 7]);
        self::assertSame(30.0, $row['invoiced']);
        self::assertSame(750.0, $row['invoiced_czk']);
        self::assertArrayNotHasKey('company_name', $row);
        self::assertArrayNotHasKey('invoice_number', $row);
        self::assertArrayNotHasKey('vat_output', $row);
    }

    public function testPeriodsKeepCalendarBoundariesAndLeapDayConventionOfPortal(): void
    {
        $now = new \DateTimeImmutable('2024-02-29');
        self::assertSame(['2024-02-01', '2024-03-01', '2024-02'], DashboardWidgetDataService::periodRange('current_month', $now));
        self::assertSame(['2024-01-01', '2024-02-01', '2024-01'], DashboardWidgetDataService::periodRange('last_month', $now));
        self::assertSame(['2023-03-01', '2024-03-01', null], DashboardWidgetDataService::periodRange('last_12m', $now));
        self::assertSame(['2023-01-01', '2023-03-01', null], DashboardWidgetDataService::periodRange('prev_year_ytd', $now));
    }

    private function widget(string $id, string $type, array $options = []): array
    {
        // Produkční validátor vrací nastavení jako objekt i pro prázdné {}.
        return ['id' => $id, 'type' => $type, 'span' => 6, 'options' => (object) $options];
    }

    private function request(array $permissions, int $sid = 901): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/dashboard-widgets/query')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 71])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute('auth.effective_role', new EffectiveRole(31, 'Syntetický čtenář', 'client', true, $permissions));
    }

    private function service(): DashboardWidgetDataService
    {
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): \PDOStatement {
            $this->queries[] = $sql;
            $stmt = $this->createStub(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (?array $parameters) { $this->parameters[] = $parameters; return true; });
            if (str_starts_with($sql, 'SELECT id, is_vat_payer')) {
                $stmt->method('fetch')->willReturn(['id' => $this->supplierId, 'is_vat_payer' => 0, 'taxpayer_type' => 'fo', 'accounting_mode' => 'double_entry']);
            } elseif (str_starts_with($sql, 'SELECT is_vat_payer')) {
                $stmt->method('fetchColumn')->willReturn(0);
            } elseif (str_contains($sql, 'GROUP BY bucket')) {
                $stmt->method('fetchAll')->willReturn([['bucket' => 0, 'amt' => str_contains($sql, 'FROM invoices i') ? 150 : 50]]);
            } elseif (str_contains($sql, 'FROM invoices i') && str_contains($sql, 'GROUP BY cur.code')) {
                $value = $this->supplierId === 901 ? 120 : 240;
                $stmt->method('fetchAll')->willReturn([['currency' => 'CZK', 'g' => $value, 'n' => $value, 'gc' => $value, 'nc' => $value, 'cnt' => 1]]);
            } elseif (str_contains($sql, 'FROM purchase_invoices pi') && str_contains($sql, 'GROUP BY cur.code')) {
                $stmt->method('fetchAll')->willReturn([['currency' => 'CZK', 'g' => 30, 'n' => 30, 'gc' => 30, 'nc' => 30, 'cnt' => 1]]);
            } else {
                throw new \LogicException('Nevyžádaný dotaz: ' . $sql);
            }
            return $stmt;
        });
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $registry = new DashboardWidgetRegistry();
        // Tyto závislosti se při testovaných widgetech nesmějí vůbec volat.
        $unreachable = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        return new DashboardWidgetDataService($db, $registry, new DashboardWidgetAccess($db, $registry, new \MyInvoice\Service\License\LicenseConsistency($db, new Config([]), \MyInvoice\Infrastructure\Cache\EntityCache::disabled(), new \Psr\Log\NullLogger())), new CrmAggregationService($db),
            $unreachable(SummaryAction::class), new DocumentRequestRepository($db), $unreachable(DphPriznaniBuilder::class),
            $unreachable(TaxAction::class), $unreachable(TaxAdvanceScheduleService::class), new OverduePolicy(new Config([])));
    }
}
