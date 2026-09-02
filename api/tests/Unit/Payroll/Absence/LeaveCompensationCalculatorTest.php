<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Absence\LeaveCompensationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * § 222 odst. 1 ZP — náhrada mzdy za dobu čerpání dovolené ve výši průměrného
 * výdělku. Bez redukčních hranic: ty zná jen § 192 odst. 2 pro nemoc.
 */
final class LeaveCompensationCalculatorTest extends TestCase
{
    public function testFullShiftIsPaidByTheAverageHourlyEarning(): void
    {
        $result = LeaveCompensationCalculator::calculate(75_000, [
            self::segment('2026-06-15', 480),
        ]);

        self::assertSame(['2026-06-01' => 600_000], $result->amountsByPeriod);
        self::assertSame(['2026-06-01' => 480], $result->minutesByPeriod);
        self::assertSame(600_000, $result->totalMinor());
    }

    /**
     * § 142 odst. 2 ZP ve spojení s § 144: náhrada se zaokrouhluje na celé
     * koruny NAHORU, a to až z měsíčního úhrnu. 7 h 30 min x 253,30 Kč
     * = 1 899,75 Kč → 1 900 Kč.
     */
    public function testMonthlyTotalIsRoundedUpToWholeCrowns(): void
    {
        $result = LeaveCompensationCalculator::calculate(25_330, [
            self::segment('2026-06-15', 450),
        ]);

        self::assertSame(190_000, $result->amountsByPeriod['2026-06-01']);
    }

    /** Dovolená přes přelom měsíce se dělí podle výplatních období. */
    public function testSegmentsAreSplitByCalendarMonth(): void
    {
        $result = LeaveCompensationCalculator::calculate(75_000, [
            self::segment('2026-06-30', 480),
            self::segment('2026-07-01', 480),
        ]);

        self::assertSame(
            ['2026-06-01' => 600_000, '2026-07-01' => 600_000],
            $result->amountsByPeriod,
        );
    }

    public function testTraceCarriesHoursRateAndFrozenAverage(): void
    {
        $result = LeaveCompensationCalculator::calculate(75_000, [
            self::segment('2026-06-15', 480),
        ]);

        self::assertSame(
            [
                'kind' => 'leave_compensation.v1',
                'absence_id' => 7,
                'period_start' => '2026-06-01',
                'minutes' => 480,
                'average_hourly_minor' => 75_000,
                'average_snapshot_id' => 9,
                'amount_minor' => 600_000,
                'rounding' => 'ceil-to-czk-on-period-total',
                'rounding_basis' => 'zp-142-2-via-144',
                'entitlement_basis' => 'zp-222-1',
            ],
            $result->trace('2026-06-01', 7, 9),
        );
    }

    public function testMissingAverageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LeaveCompensationCalculator::calculate(0, [self::segment('2026-06-15', 480)]);
    }

    public function testNoPublishedShiftIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LeaveCompensationCalculator::calculate(75_000, []);
    }

    /** @return array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int} */
    private static function segment(string $date, int $minutes): array
    {
        return [
            'shift_id' => 1,
            'local_date' => $date,
            'planned_minutes' => $minutes,
            'eligible_minutes' => $minutes,
        ];
    }
}
