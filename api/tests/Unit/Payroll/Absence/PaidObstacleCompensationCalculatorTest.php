<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\PaidObstacleCompensationCalculator;
use MyInvoice\Service\Payroll\Absence\PayrollObstacleKind;
use PHPUnit\Framework\TestCase;

final class PaidObstacleCompensationCalculatorTest extends TestCase
{
    public function testDifferentDailyAveragesRoundTheMonthlyTotal(): void
    {
        $result = PaidObstacleCompensationCalculator::calculate(13440, 8000,
            [self::segment('2026-06-10', 480), self::segment('2026-06-20', 480)], [],
            \MyInvoice\Service\Payroll\Calculation\PayrollWageRounding::Ceil,
            ['2026-06-10' => 13440, '2026-06-20' => 14340]);
        self::assertSame(177800, $result['amounts']['2026-06-01']);
        self::assertSame(222300, $result['full_rate_amounts']['2026-06-01']);
    }

    /** Prostoj § 207 písm. a) ZP: 80 % průměru, na celé koruny nahoru za měsíc. */
    public function testDowntimePaysEightyPercentOfTheAverage(): void
    {
        $result = PaidObstacleCompensationCalculator::calculate(
            25_012,
            8_000,
            [self::segment('2026-07-13', 480), self::segment('2026-07-14', 480)],
            [],
        );

        // 250,12 Kč × 16 h × 0,8 = 3 201,536 Kč → 3 202 Kč.
        self::assertSame(['2026-07-01' => 960], $result['minutes']);
        self::assertSame(['2026-07-01' => 320_200], $result['amounts']);
        self::assertSame(['2026-07-01' => 400_200], $result['full_rate_amounts']);
    }

    /** Svátek se nenahrazuje: mzda se za něj nekrátí (§ 115 odst. 3 ZP). */
    public function testHolidayInsideTheObstacleIsNotCompensated(): void
    {
        $result = PaidObstacleCompensationCalculator::calculate(
            25_000,
            PayrollObstacleKind::FULL_RATE_BASIS_POINTS,
            [self::segment('2026-07-03', 480), self::segment('2026-07-06', 480)],
            ['2026-07-06' => 'Den upálení mistra Jana Husa'],
        );

        self::assertSame(['2026-07-01' => 480], $result['minutes']);
        self::assertSame(['2026-07-01' => 200_000], $result['amounts']);
    }

    public function testObstacleOverMonthBoundaryIsSplitByPeriod(): void
    {
        $result = PaidObstacleCompensationCalculator::calculate(
            30_000,
            6_000,
            [self::segment('2026-07-31', 480), self::segment('2026-08-03', 240)],
            [],
        );

        self::assertSame(['2026-07-01' => 144_000, '2026-08-01' => 72_000], $result['amounts']);
    }

    public function testRateAboveTheAverageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaidObstacleCompensationCalculator::calculate(30_000, 10_001, [self::segment('2026-07-13', 480)], []);
    }

    /** @return array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int} */
    private static function segment(string $date, int $minutes): array
    {
        return ['shift_id' => null, 'local_date' => $date, 'planned_minutes' => 480, 'eligible_minutes' => $minutes];
    }
}
