<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use PHPUnit\Framework\TestCase;

/**
 * Proč schválený průměr neodpovídá dnešním podkladům: přehled průměrů podle
 * toho účetní řekne, co se změnilo. Tři měsíce po 44 000 Kč; leden má
 * 176 h včetně 8 h svátku krytého měsíční mzdou (§ 353 odst. 2 ZP).
 */
final class AverageEarningOutdatedDiagnosisTest extends TestCase
{
    public function testAverageApprovedWithoutHolidayHoursIsOutdatedBecauseOfTheHoliday(): void
    {
        // Schváleno před opravou: 504 h (leden bez svátku), průměr 261,90 Kč.
        $diagnosis = AverageEarningBatchService::outdatedDiagnosis(self::suggestion(), self::existing(13_200_000, 504 * 60, 63));

        self::assertSame([
            'reason' => 'holiday_hours',
            'gross_minor' => 13_200_000,
            'duration_units' => PayrollDuration::fromMinutes(512 * 60),
            'worked_days' => 63,
        ], $diagnosis);
    }

    public function testMatchingAverageIsNotOutdated(): void
    {
        self::assertNull(AverageEarningBatchService::outdatedDiagnosis(self::suggestion(), self::existing(13_200_000, 512 * 60, 63)));
    }

    public function testCorrectedWagesAttendanceOrBothAreToldApart(): void
    {
        self::assertSame('earnings', AverageEarningBatchService::outdatedDiagnosis(self::suggestion(), self::existing(13_000_000, 512 * 60, 63))['reason']);
        // Jiný rozdíl hodin než právě svátek, nebo jiný počet dnů: oprava docházky.
        self::assertSame('worked_time', AverageEarningBatchService::outdatedDiagnosis(self::suggestion(), self::existing(13_200_000, 500 * 60, 63))['reason']);
        self::assertSame('worked_time', AverageEarningBatchService::outdatedDiagnosis(self::suggestion(), self::existing(13_200_000, 504 * 60, 62))['reason']);
        self::assertSame('mixed', AverageEarningBatchService::outdatedDiagnosis(self::suggestion(), self::existing(13_000_000, 504 * 60, 63))['reason']);
    }

    public function testProbableEarningAndUnverifiableMonthsAreNotJudged(): void
    {
        self::assertNull(AverageEarningBatchService::outdatedDiagnosis(
            self::suggestion(),
            ['source_kind' => 'probable'] + self::existing(13_200_000, 504 * 60, 63),
        ));
        $takeover = self::suggestion();
        $takeover['months'][1]['takeover'] = true;
        self::assertNull(AverageEarningBatchService::outdatedDiagnosis($takeover, self::existing(13_200_000, 504 * 60, 63)));
        $blocked = self::suggestion();
        $blocked['months'][2]['blockers'] = ['time_month_not_approved'];
        self::assertNull(AverageEarningBatchService::outdatedDiagnosis($blocked, self::existing(13_200_000, 504 * 60, 63)));
    }

    /** @return array<string,mixed> */
    private static function suggestion(): array
    {
        $month = static fn (int $hours, int $days, ?int $holidayHours = null): array => [
            'blockers' => [],
            'takeover' => false,
            'gross_earnings_minor' => 4_400_000,
            'worked_minutes' => $hours * 60,
            'worked_duration_units' => PayrollDuration::fromMinutes($hours * 60),
            'worked_days' => $days,
        ] + ($holidayHours === null ? [] : [
            'wage_covered_holiday_duration_units' => PayrollDuration::fromMinutes($holidayHours * 60),
        ]);

        return ['months' => [$month(176, 21, 8), $month(160, 20), $month(176, 22)]];
    }

    /** @return array<string,mixed> */
    private static function existing(int $grossMinor, int $minutes, int $days): array
    {
        return [
            'source_kind' => 'actual',
            'gross_earnings_minor' => $grossMinor,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => $minutes,
            'worked_duration_units' => null,
            'worked_days' => $days,
        ];
    }
}
