<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Portfolio;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Crm\CrmDashboardAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\Obligations\ExistingObligationSourceService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Portfolio\GroupDashboardCompanyReader;
use MyInvoice\Service\Portfolio\GroupDashboardForecast;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[AllowMockObjectsWithoutExpectations]
final class GroupDashboardCompanyReaderTest extends TestCase
{
    private Connection&MockObject $db;
    private CrmAggregationService&MockObject $crm;
    private AccountingPeriodRepository&MockObject $periods;
    private FinancialStatementService&MockObject $statements;
    private CashRegisterService&MockObject $cash;
    private BankStatementAction&MockObject $bank;
    private CrmDashboardAction&MockObject $forecast;
    private array $supplier = ['id' => 7, 'name' => 'Synthetic group', 'accounting_mode' => 'double_entry', 'is_vat_payer' => 1];

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->crm = $this->createMock(CrmAggregationService::class);
        $this->periods = $this->createMock(AccountingPeriodRepository::class);
        $this->statements = $this->createMock(FinancialStatementService::class);
        $this->cash = $this->createMock(CashRegisterService::class);
        $this->bank = $this->createMock(BankStatementAction::class);
        $this->forecast = $this->createMock(CrmDashboardAction::class);
    }

    public function testMissingSourceRightsDoNotInvokeSourcesOrExposeData(): void
    {
        $this->db->expects(self::never())->method('pdo');
        $this->crm->expects(self::never())->method('documentRange');
        $this->crm->expects(self::never())->method('agingPayables');
        $this->crm->expects(self::never())->method('agingReceivables');
        $this->periods->expects(self::never())->method('findForDate');
        $this->statements->expects(self::never())->method('accountView');
        $this->bank->expects(self::never())->method('accountBalances');
        $this->cash->expects(self::never())->method('list');
        $this->forecast->expects(self::never())->method('cashFlowForecast');
        foreach (['overview', 'trends', 'cashflow', 'balances', 'receivables', 'risks'] as $section) {
            $row = $this->read([], $section);
            self::assertSame([], $row['issues']);
            self::assertSame([], array_intersect(['financial', 'monthly', 'accounting', 'cashflow', 'bank', 'cash', 'receivables', 'payables', 'risks'], array_keys($row)));
        }
    }

    public function testPartialInvoiceRightsReadOnlyReceivables(): void
    {
        $aging = [['currency' => 'EUR', 'bucket' => 'overdue_30', 'count' => 1, 'total' => 45]];
        $this->crm->expects(self::once())->method('agingReceivables')->with(7)->willReturn($aging);
        $this->crm->expects(self::never())->method('agingPayables');
        $this->crm->expects(self::never())->method('overview');
        $row = $this->read(['invoices'], 'receivables');
        self::assertSame($aging, $row['receivables']);
        self::assertArrayNotHasKey('payables', $row);
        self::assertFalse($row['available']['documents']);
    }

    public function testOverviewUsesExistingKpiAndPreservesPriorYearOnlyCurrencies(): void
    {
        $this->crm->expects(self::never())->method('overview');
        $this->crm->expects(self::exactly(2))->method('documentRange')->willReturnCallback(static function ($id, $from, $to): array {
            self::assertSame(7, $id);
            if ($from === '2026-08-15') {
                self::assertSame('2026-09-01', $to);
                return [['currency' => 'CZK', 'revenue' => 100, 'costs' => 20, 'profit' => 80]];
            }
            self::assertSame('2025-08-15', $from);
            self::assertSame('2025-09-01', $to);
            return [['currency' => 'EUR', 'revenue' => 60, 'costs' => 10, 'profit' => 50]];
        });
        $row = $this->read(['invoices', 'purchase_invoices'], 'overview', '2026-08-15', '2026-08-31');
        self::assertSame(['CZK', 'EUR'], array_column($row['financial'], 'currency'));
        self::assertSame(100, $row['financial'][0]['revenue']);
        self::assertSame(0.0, $row['financial'][1]['revenue']);
        self::assertSame(60, $row['financial'][1]['previous_revenue']);
        self::assertArrayNotHasKey('other_item_result_impact', $row);
        self::assertSame('net', $row['document_basis']);
    }

    public function testAccountingUsesCurrentPeriodAndSharedStatementTotals(): void
    {
        $this->periods->expects(self::once())->method('findForDate')->with(7, date('Y-m-d'))
            ->willReturn(['id' => 8, 'starts_on' => '2026-04-01']);
        $this->statements->expects(self::once())->method('accountView')->with(7, 8, date('Y-m-d'))
            ->willReturn(['as_of' => date('Y-m-d'), 'profit_loss' => ['sections' => [
                ['revenue_total' => 100, 'expense_total' => 20], ['revenue_total' => 40, 'expense_total' => 10]], 'profit' => 110]]);
        self::assertSame(['currency' => 'CZK', 'from' => '2026-04-01', 'to' => date('Y-m-d'), 'revenue' => 140, 'costs' => 30, 'profit' => 110],
            $this->read(['accounting'], 'overview')['accounting']);
    }

    public function testAccounting504UsesManagementRowsWithoutLosingActivities(): void
    {
        $this->periods->method('findForDate')->willReturn(['id' => 8, 'starts_on' => '2026-04-01']);
        $this->statements->method('accountView')->willReturn([
            'accounting_framework' => 'cz_504', 'as_of' => date('Y-m-d'), 'profit_loss' => [
                ['row_code' => 'UT.39', 'main' => 80.0, 'economic' => 20.0, 'total' => 100.0],
                ['row_code' => 'UT.61', 'main' => 120.0, 'economic' => 50.0, 'total' => 170.0],
                ['row_code' => 'UT.63', 'main' => 40.0, 'economic' => 30.0, 'total' => 70.0],
            ],
        ]);
        $row = $this->read(['accounting'], 'overview');
        self::assertSame([], $row['issues']);
        self::assertSame(['currency' => 'CZK', 'from' => '2026-04-01', 'to' => date('Y-m-d'),
            'revenue' => 170.0, 'costs' => 100.0, 'profit' => 70.0], $row['accounting']);
    }

    public function testTrendsAndRisksUseRequestedWindowAndReturnOnlyAggregateFields(): void
    {
        $this->crm->expects(self::exactly(2))->method('documentRange')->with(7, '2026-08-15', '2026-09-01')
            ->willReturn([['period' => '2026-08', 'currency' => 'EUR', 'revenue' => 10, 'costs' => 20, 'profit' => -10, 'invoice_count' => 2]]);
        $this->crm->expects(self::never())->method('monthlyHistory');
        $monthly = $this->read(['invoices', 'purchase_invoices'], 'trends', '2026-08-15', '2026-08-31')['monthly'];
        self::assertSame([['period' => '2026-08', 'currency' => 'EUR', 'revenue' => 10, 'costs' => 20, 'profit' => -10,
            'revenue_czk' => null, 'costs_czk' => null, 'profit_czk' => null, 'conversion_missing' => 0]], $monthly);
        $this->crm->expects(self::once())->method('agingReceivables')->with(7)->willReturn([
            ['currency' => 'EUR', 'bucket' => 'not_due', 'total' => 100, 'count' => 1],
            ['currency' => 'EUR', 'bucket' => 'overdue_30', 'total' => 50, 'count' => 2]]);
        $this->crm->expects(self::once())->method('agingPayables')->with(7)->willReturn([]);
        $this->crm->expects(self::never())->method('overview');
        $this->crm->expects(self::once())->method('clientConcentration')->with(7, 12)
            ->willReturn(['risk_level' => 'high', 'currency' => 'CZK', 'top1_share' => 90, 'top_client' => 'Must not leak']);
        $this->crm->expects(self::once())->method('vendorConcentration')->with(7, 12)
            ->willReturn(['risk_level' => 'low', 'top1_share' => 5]);
        self::assertSame([
            ['kind' => 'overdue_receivables', 'currency' => 'EUR', 'amount' => 50, 'count' => 2, 'bucket' => 'overdue_30'],
            ['kind' => 'document_loss', 'currency' => 'EUR', 'amount' => -10, 'amount_czk' => null, 'conversion_missing' => 0],
            ['kind' => 'client_concentration', 'currency' => 'CZK', 'percentage' => 90, 'level' => 'high'],
        ], $this->read(['invoices', 'purchase_invoices'], 'risks', '2026-08-15', '2026-08-31')['risks']);
    }

    public function testTrendsIncludeZeroActivityMonthsForEveryObservedCurrency(): void
    {
        $this->crm->expects(self::exactly(3))->method('documentRange')->willReturnCallback(static function ($id, $from, $to, $month): array {
            self::assertSame(7, $id);
            $rows = [
                '2026-01' => ['EUR', 10, null],
                '2026-03' => ['CZK', 20, 20],
            ];
            if ($month === '2026-01') self::assertSame('2026-01-15', $from);
            if ($month === '2026-03') self::assertSame('2026-03-16', $to);
            if (!isset($rows[$month])) return [];
            [$currency, $amount, $converted] = $rows[$month];
            return [['period' => $month, 'currency' => $currency, 'revenue' => $amount, 'costs' => 0, 'profit' => $amount,
                'revenue_czk' => $converted, 'costs_czk' => 0, 'profit_czk' => $converted, 'conversion_missing' => $converted === null ? 1 : 0]];
        });
        $monthly = $this->read(['invoices', 'purchase_invoices'], 'trends', '2026-01-15', '2026-03-15')['monthly'];
        self::assertCount(6, $monthly);
        foreach (['CZK', 'EUR'] as $currency) {
            $rows = array_values(array_filter($monthly, static fn (array $row): bool => $row['currency'] === $currency));
            self::assertSame(['2026-01', '2026-02', '2026-03'], array_column($rows, 'period'));
            self::assertSame(0.0, $rows[1]['revenue']);
            self::assertSame(0.0, $rows[1]['costs']);
            self::assertSame(0.0, $rows[1]['profit']);
            self::assertSame(0.0, $rows[1]['profit_czk']);
        }
        $eur = array_values(array_filter($monthly, static fn (array $row): bool => $row['currency'] === 'EUR'));
        self::assertSame(10, $eur[0]['revenue']);
        self::assertNull($eur[0]['revenue_czk']);
        self::assertSame(1, $eur[0]['conversion_missing']);
    }

    public function testSourceFailureIsExplicitAndNoCurrentAccountingPeriodIsNotFailure(): void
    {
        $this->crm->expects(self::once())->method('documentRange')->willThrowException(new \RuntimeException('Internal confidential details'));
        $this->periods->expects(self::once())->method('findForDate')->willReturn(null);
        $this->statements->expects(self::never())->method('accountView');
        $row = $this->read(['accounting', 'invoices', 'purchase_invoices'], 'overview');
        self::assertNull($row['financial']);
        self::assertNull($row['accounting']);
        self::assertSame(['financial'], $row['issues']);
        self::assertStringNotContainsString('confidential', json_encode($row));
    }

    public function testBalancesUseScopedRequestAndNativeCurrencyWithoutCzkDoubleCounting(): void
    {
        $this->bank->expects(self::once())->method('accountBalances')->willReturnCallback(static function ($request): Response {
            self::assertSame(7, $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID));
            $response = new Response();
            $response->getBody()->write(json_encode(['accounts' => [['id' => 3, 'label' => 'Synthetic bank', 'code' => 'EUR',
                'current_balance' => null, 'current_balance_czk' => 999, 'statement_date' => null, 'account_number' => 'do not expose']]]));
            return $response;
        });
        $this->cash->expects(self::once())->method('list')->with(7)->willReturn([
            ['id' => 4, 'name' => 'Synthetic cash', 'currency_code' => 'EUR', 'balance' => 900, 'balance_foreign' => 30, 'balance_date' => '2026-09-01']]);
        $row = $this->read(['bank', 'cash'], 'balances');
        self::assertNull($row['bank'][0]['balance']);
        self::assertArrayNotHasKey('account_number', $row['bank'][0]);
        self::assertSame(30, $row['cash'][0]['balance']);
        self::assertSame('EUR', $row['cash'][0]['currency']);
    }

    public function testCashflowRequiresOtherItemsAndAddsCzkForAuthorizedObligations(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE currencies (supplier_id INTEGER, code TEXT); CREATE TABLE other_items (supplier_id INTEGER, currency TEXT, deleted_at TEXT, status TEXT);
            INSERT INTO currencies VALUES (7, 'EUR'), (99, 'USD')");
        $this->db->expects(self::once())->method('pdo')->willReturn($pdo);
        $this->forecast->expects(self::exactly(2))->method('cashFlowForecast')->willReturnCallback(static function ($request): Response {
            self::assertSame(7, $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID));
            self::assertSame(8, $request->getQueryParams()['weeks']);
            $currency = $request->getQueryParams()['currency'];
            self::assertContains($currency, ['CZK', 'EUR']);
            $response = new Response();
            $response->getBody()->write(json_encode(['currency' => $currency, 'weeks' => [], 'total_in' => 0, 'total_out' => 0, 'total_net' => 0]));
            return $response;
        });
        self::assertArrayNotHasKey('cashflow', $this->read(['invoices', 'purchase_invoices'], 'cashflow'));
        $row = $this->read(['invoices', 'purchase_invoices', 'other_items', 'reports'], 'cashflow');
        self::assertSame(['CZK', 'EUR'], array_column($row['cashflow'], 'currency'));
        self::assertTrue($row['available']['cashflow_tax']);
        self::assertFalse($row['available']['cashflow_payroll']);
    }

    public function testSharedCashflowOrchestrationHonorsScopedTaxAndPayrollRights(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE currencies (supplier_id INTEGER, code TEXT); CREATE TABLE other_items (supplier_id INTEGER, currency TEXT, deleted_at TEXT, status TEXT);
            INSERT INTO currencies VALUES (7, 'EUR')");
        $this->db->method('pdo')->willReturn($pdo);
        $obligations = $this->createMock(ExistingObligationSourceService::class);
        $obligations->expects(self::exactly(2))->method('taxAdvances')->with(7, date('Y-m-d'), date('Y-m-d', strtotime('+56 days')))
            ->willReturn([['currency' => 'CZK', 'side' => 'payable', 'remaining' => 25, 'due_on' => date('Y-m-d')]]);
        $obligations->expects(self::exactly(2))->method('taxForecasts')->willReturn([]);
        $obligations->expects(self::never())->method('payrollLiabilities');
        $obligations->expects(self::never())->method('payrollForecasts');
        $this->crm->expects(self::exactly(3))->method('cashFlowForecast')->willReturnCallback(static fn ($id, $weeks, $currency): array => [
            'currency' => $currency, 'weeks' => [['week_start' => date('Y-m-d'), 'week_end' => date('Y-m-d', strtotime('+6 days')),
                'in' => 100, 'out' => 10, 'net' => 90, 'running' => 90]], 'total_in' => 100, 'total_out' => 10, 'total_net' => 90]);
        $reader = new GroupDashboardCompanyReader($this->db, $this->crm, $this->periods, $this->statements, $this->cash, $this->bank,
            new CrmDashboardAction($this->crm, $obligations), $this->createStub(GroupDashboardForecast::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/portfolio/group-dashboard')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic reader', 'staff', true,
                ['invoices' => 1, 'purchase_invoices' => 1, 'other_items' => 1]));
        $withoutTax = $reader->read($request, $this->supplier, 'cashflow', 12, 8);
        self::assertSame(['EUR'], array_column($withoutTax['cashflow'], 'currency'));
        $withTax = $reader->read($request->withAttribute('auth.effective_role', new EffectiveRole(2, 'Synthetic tax reader', 'staff', true,
            ['invoices' => 1, 'purchase_invoices' => 1, 'other_items' => 1, 'reports' => 1])), $this->supplier, 'cashflow', 12, 8);
        self::assertSame(35, $withTax['cashflow'][0]['total_out']);
        self::assertSame(65, $withTax['cashflow'][0]['weeks'][0]['running']);
        self::assertSame(10, $withTax['cashflow'][1]['total_out']);
        self::assertSame([], $withTax['issues']);
    }

    private function read(array $keys, string $section, ?string $from = null, ?string $to = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/portfolio/group-dashboard')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic reader', 'staff', true, array_fill_keys($keys, 1)));
        return (new GroupDashboardCompanyReader($this->db, $this->crm, $this->periods, $this->statements, $this->cash, $this->bank, $this->forecast,
            $this->createStub(GroupDashboardForecast::class)))
            ->read($request, $this->supplier, $section, 12, 8, \MyInvoice\Service\Portfolio\GroupDashboardService::period(12, $from, $to));
    }
}
