<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\Statement504Builder;
use MyInvoice\Service\Accounting\Reports\StatementMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementMetricsTest extends TestCase
{
    public function test504ReadsReportedRowsIndependentlyOfChecks(): void
    {
        $builder = new Statement504Builder();
        $lines = [['code' => '602', 'activity' => 'main', 'balance_cents' => -12000],
            ['code' => '518', 'activity' => 'economic', 'balance_cents' => 15000]];
        $income = ['accounting_framework' => 'cz_504'] + $builder->incomeStatement($lines, 'full');
        $balance = ['accounting_framework' => 'cz_504'] + $builder->balanceSheet(
            [['code' => '221', 'opening_cents' => 10000, 'closing_cents' => 7000],
                ['code' => '901', 'opening_cents' => -10000, 'closing_cents' => -10000]], $lines, 'full');
        $income['checks']['profit_current'] = 999999.0;
        $balance['checks']['profit_current'] = 999999.0;
        self::assertSame(-30.0, StatementMetrics::incomeProfit($income));
        self::assertSame(-30.0, StatementMetrics::balanceProfit($balance));
        self::assertSame(70.0, StatementMetrics::balanceTotal($balance, 'assets_net'));
        self::assertSame(70.0, StatementMetrics::balanceTotal($balance, 'liabilities_total'));
        self::assertSame(['revenue' => 120.0, 'costs' => 150.0, 'profit' => -30.0],
            StatementMetrics::accountViewTotals(['accounting_framework' => 'cz_504', 'profit_loss' => $income['rows']]));
    }

    public function test500UsesExistingRowsAndTotals(): void
    {
        self::assertSame(321.25, StatementMetrics::balanceTotal(['checks' => ['assets_net' => 321.25]], 'assets_net'));
        self::assertSame(321.25, StatementMetrics::balanceTotal(['checks' => ['liabilities_total' => 321.25]], 'liabilities_total'));
        self::assertSame(-20.5, StatementMetrics::balanceProfit(['liabilities' => [['row_code' => 'P.A.V.', 'amount' => -20.5]]]));
        self::assertSame(-20.5, StatementMetrics::incomeProfit(['rows' => [['row_code' => 'VH', 'amount' => -20.5]]]));
    }

    #[DataProvider('missingAmounts')]
    public function testMissingAmountCannotBecomeZero(string $method, array $report, array $arguments): void
    {
        $this->expectException(\RuntimeException::class);
        StatementMetrics::$method($report, ...$arguments);
    }

    public static function missingAmounts(): iterable
    {
        yield ['balanceTotal', ['accounting_framework' => 'cz_504', 'checks' => ['assets_net' => 999]], ['assets_net']];
        yield ['balanceProfit', ['accounting_framework' => 'cz_504', 'liabilities' => [['row_code' => 'US.7', 'end' => null]]], []];
        yield ['incomeProfit', ['accounting_framework' => 'cz_504', 'rows' => [['row_code' => 'UT.63', 'amount' => 999]]], []];
        yield ['incomeProfit', ['accounting_framework' => 'cz_504', 'checks' => ['profit_current' => 0]], []];
        yield ['accountViewTotals', ['accounting_framework' => 'cz_504', 'profit_loss' => []], []];
        yield ['incomeProfit', ['rows' => [['row_code' => 'VH']]], []];
        yield ['balanceProfit', ['liabilities' => [['row_code' => 'P.A.V.']]], []];
        yield ['balanceTotal', ['checks' => ['assets_net' => []]], ['assets_net']];
    }
}
