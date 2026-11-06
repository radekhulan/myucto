<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\Statement504Builder;
use PHPUnit\Framework\TestCase;

final class Statement504BuilderTest extends TestCase
{
    private function referenceProfit(): array
    {
        return [
            ['code' => '501', 'activity' => 'main', 'balance_cents' => 10_000_000],
            ['code' => '518', 'activity' => 'main', 'balance_cents' => 10_000_000],
            ['code' => '518', 'activity' => 'economic', 'balance_cents' => 1_500_000],
            ['code' => '551', 'activity' => 'main', 'balance_cents' => 2_400_000],
            ['code' => '691', 'activity' => 'main', 'balance_cents' => -3_000_000],
            ['code' => '682', 'activity' => 'main', 'balance_cents' => -2_500_000],
            ['code' => '684', 'activity' => 'main', 'balance_cents' => -4_000_000],
            ['code' => '602', 'activity' => 'main', 'balance_cents' => -20_000_000],
            ['code' => '602', 'activity' => 'economic', 'balance_cents' => -6_000_000],
            ['code' => '649', 'activity' => 'main', 'balance_cents' => -1_440_000],
        ];
    }

    public function testReferenceAssociationProfitAndBalanceReconcile(): void
    {
        $builder = new Statement504Builder();
        $profit = $builder->incomeStatement($this->referenceProfit(), 'full');
        $rows = array_column($profit['rows'], null, 'row_code');
        self::assertSame(85_400.0, $rows['UT.63']['main']);
        self::assertSame(45_000.0, $rows['UT.63']['economic']);
        self::assertSame(130_400.0, $rows['UT.63']['total']);
        self::assertSame(239_000.0, $rows['UT.39']['total']);
        $balance = $builder->balanceSheet([
            ['code' => '221', 'opening_cents' => 30_000_000, 'closing_cents' => 39_200_000],
            ['code' => '022', 'opening_cents' => 0, 'closing_cents' => 12_000_000],
            ['code' => '082', 'opening_cents' => 0, 'closing_cents' => -2_400_000],
            ['code' => '901', 'opening_cents' => -30_000_000, 'closing_cents' => -35_760_000],
        ], $this->referenceProfit(), 'full');
        self::assertSame(488_000.0, $balance['totals']['assets_end']);
        self::assertSame(488_000.0, $balance['totals']['liabilities_end']);
        self::assertSame(300_000.0, $balance['totals']['assets_start']);
        self::assertTrue($balance['checks']['balanced']);
        $assets = array_column($balance['assets'], null, 'row_code');
        $liabilities = array_column($balance['liabilities'], null, 'row_code');
        self::assertSame(-24_000.0, $assets['UR.36']['end']);
        self::assertSame(130_400.0, $liabilities['US.7']['end']);
        self::assertNull($liabilities['US.7']['start']);
        self::assertNull($liabilities['US.8']['end']);
        self::assertArrayNotHasKey('gross', $assets['UR.36']);
        self::assertArrayNotHasKey('net', $assets['UR.36']);
    }

    public function testMissingActivityCannotHideEvenWhenRowsCancel(): void
    {
        try {
            (new Statement504Builder())->incomeStatement([
                ['code' => '518', 'activity' => null, 'balance_cents' => 100],
                ['code' => '518', 'activity' => null, 'balance_cents' => -100],
            ], 'full');
            self::fail('Missing activity must not be hidden by a zero total.');
        } catch (ReportException $e) {
            self::assertSame('statement_activity_invalid', $e->errorCode);
        }
    }

    public function testArbitraryActivityNameCannotBecomeMain(): void
    {
        $this->expectException(ReportException::class);
        (new Statement504Builder())->incomeStatement([
            ['code' => '602', 'activity' => 'Hlavní činnost', 'balance_cents' => -100],
        ], 'full');
    }

    public function testUnknownAccountWithTurnoverFailsClosed(): void
    {
        $this->expectException(ReportException::class);
        (new Statement504Builder())->incomeStatement([
            ['code' => '663', 'activity' => 'main', 'balance_cents' => -100],
        ], 'full');
    }

    public function testBothReducedScopesKeepRomanGroupsAndOfficialRowGaps(): void
    {
        $builder = new Statement504Builder();
        $small = $builder->incomeStatement($this->referenceProfit(), 'small');
        self::assertSame($small, $builder->incomeStatement($this->referenceProfit(), 'micro'));
        $codes = array_column($small['rows'], 'row_code');
        self::assertContains('UT.2', $codes);
        self::assertContains('UT.47', $codes);
        self::assertNotContains('UT.3', $codes);
        $assets = $builder->balanceSheet([], [], 'full')['assets'];
        self::assertContains(85, array_column($assets, 'adis_row'));
        self::assertNotContains(28, array_column($assets, 'adis_row'));
        self::assertNotContains(79, array_column($assets, 'adis_row'));
    }

    public function testUnresolvedPendingResultIsNotDroppedOrMovedToRetained(): void
    {
        try {
            (new Statement504Builder())->balanceSheet([
                ['code' => '931', 'opening_cents' => -10_000, 'closing_cents' => -10_000],
            ], [], 'full');
            self::fail('Unresolved pending result must be explicit.');
        } catch (ReportException $e) {
            self::assertSame('statement_result_pending_approval_unresolved', $e->errorCode);
        }
    }

    public function testRetainedLossAndRedReversalKeepTheirSigns(): void
    {
        $builder = new Statement504Builder();
        $balance = $builder->balanceSheet([
            ['code' => '931', 'opening_cents' => 100, 'closing_cents' => 0],
            ['code' => '932', 'opening_cents' => 0, 'closing_cents' => 100],
        ], [], 'full');
        $rows = array_column($balance['liabilities'], null, 'row_code');
        self::assertSame(-1.0, $rows['US.8']['start']);
        self::assertSame(-1.0, $rows['US.9']['end']);
        $profit = $builder->incomeStatement([
            ['code' => '545', 'activity' => 'economic', 'balance_cents' => -3],
        ], 'full');
        $rows = array_column($profit['rows'], null, 'row_code');
        self::assertSame(-0.03, $rows['UT.25']['economic']);
        self::assertSame(0.03, $rows['UT.63']['total']);
    }
}
