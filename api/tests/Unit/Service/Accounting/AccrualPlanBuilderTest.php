<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Accrual\AccrualPlanBuilder;
use MyInvoice\Service\Accounting\Accrual\AccrualScheduleCalculator;
use PHPUnit\Framework\TestCase;

/** Plán rozpouštění položky agendy Časové rozlišení podle metody. */
final class AccrualPlanBuilderTest extends TestCase
{
    public function testMonthlyDaysTelescopesToBasisAndMatchesCumulativeRounding(): void
    {
        // 301 166,93 Kč na 1 646 dní od 7. 1.: měsíce podle dnů, kumulativní zaokrouhlení.
        $rows = AccrualPlanBuilder::build('monthly_days', 301_166.93, '2091-01-07', '2095-07-10');
        self::assertSame('2091-01-31', $rows[0]['due_on']);
        self::assertSame(25, $rows[0]['days']);
        self::assertSame(AccrualScheduleCalculator::cumRecognized(301_166.93, '2091-01-07', '2095-07-10', '2091-01-31'), $rows[0]['amount_planned']);
        self::assertSame('2095-07-10', end($rows)['due_on']);
        self::assertSame(301_166.93, round(array_sum(array_column($rows, 'amount_planned')), 2));
    }

    public function testMonthlyEqualSplitsByMonthsWithRemainderInLastRow(): void
    {
        $rows = AccrualPlanBuilder::build('monthly_equal', 1_000.00, '2091-01-15', '2091-04-14');
        self::assertCount(4, $rows);
        self::assertSame([250.0, 250.0, 250.0, 250.0], array_column($rows, 'amount_planned'));
        $odd = AccrualPlanBuilder::build('monthly_equal', 100.00, '2091-01-01', '2091-03-31');
        self::assertSame([33.33, 33.34, 33.33], array_column($odd, 'amount_planned'));
    }

    public function testYearlyFollowsFiscalYearEnd(): void
    {
        $calendar = AccrualPlanBuilder::build('yearly', 3_650.00, '2091-07-01', '2093-06-30');
        self::assertSame(['2091-12-31', '2092-12-31', '2093-06-30'], array_column($calendar, 'due_on'));
        $fiscal = AccrualPlanBuilder::build('yearly', 3_650.00, '2091-07-01', '2093-06-30', null, null, '08-31');
        self::assertSame(['2091-08-31', '2092-08-31', '2093-06-30'], array_column($fiscal, 'due_on'));
        self::assertSame(3_650.00, round(array_sum(array_column($fiscal, 'amount_planned')), 2));
    }

    public function testPlanAfterCoveredPartSettlesTargetInLastRow(): void
    {
        // Převzatý zůstatek k 1. 1. 2092 se liší od rekonstrukce: poslední řádek ho dorovná.
        $rows = AccrualPlanBuilder::build('yearly', 36_500.00, '2091-01-01', '2093-12-31', '2091-12-31', 24_000.00);
        self::assertSame(['2092-12-31', '2093-12-31'], array_column($rows, 'due_on'));
        self::assertSame('2092-01-01', $rows[0]['from_on']);
        self::assertSame(24_000.00, round(array_sum(array_column($rows, 'amount_planned')), 2));
        self::assertSame([], AccrualPlanBuilder::build('manual', 100.0, '2091-01-01', '2091-12-31'));
    }
}
