<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AbsenceHolidaySegments;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollWorkCalendarSchedule;
use PHPUnit\Framework\TestCase;

final class AbsenceHolidaySegmentsTest extends TestCase
{
    /**
     * § 219 odst. 1 ZP. Dovolená 23.–31. 12. 2026 má sedm pracovních směn,
     * ale 24. a 25. 12. jsou svátky — dovolená se čerpá jen za pět z nich.
     */
    public function testChristmasHolidaysDoNotConsumeLeave(): void
    {
        $segments = $this->shifts([
            '2026-12-23', '2026-12-24', '2026-12-25',
            '2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31',
        ]);

        $kept = AbsenceHolidaySegments::excludeFromLeave($segments, $this->holidays('2026-12-23', '2026-12-31'));

        self::assertSame(2_400, $this->minutes($kept));
        self::assertSame(
            ['2026-12-23', '2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31'],
            array_column($kept, 'local_date'),
        );
    }

    public function testLeaveFallingOnlyOnAHolidayConsumesNothing(): void
    {
        $kept = AbsenceHolidaySegments::excludeFromLeave(
            $this->shifts(['2026-05-01']),
            $this->holidays('2026-05-01', '2026-05-01'),
        );

        self::assertSame([], $kept);
        self::assertSame(0, $this->minutes($kept));
    }

    public function testHolidayOnAWeekendChangesNothingBecauseThereIsNoShift(): void
    {
        // 26. 12. 2026 padne na sobotu — směna publikovaná není, takže není
        // co vyloučit a pátek 25. 12. zůstane jediným vyloučeným dnem.
        $kept = AbsenceHolidaySegments::excludeFromLeave(
            $this->shifts(['2026-12-24', '2026-12-25', '2026-12-28']),
            $this->holidays('2026-12-21', '2026-12-31'),
        );

        self::assertSame(['2026-12-28'], array_column($kept, 'local_date'));
    }

    /** § 192 odst. 1 ZP — svátek v okně DPN se proplácí i bez publikované směny. */
    public function testHolidayInsideSicknessWindowIsCompensatedWithoutAShift(): void
    {
        $segments = $this->shifts(['2026-12-23', '2026-12-28']);

        $result = AbsenceHolidaySegments::compensateSickness(
            $segments,
            ['2026-12-24' => 480, '2026-12-25' => 480],
        );

        self::assertSame(
            ['2026-12-23', '2026-12-24', '2026-12-25', '2026-12-28'],
            array_column($result, 'local_date'),
        );
        self::assertSame(1_920, $this->minutes($result));
        self::assertNull($result[1]['shift_id']);
    }

    public function testPublishedHolidayShiftIsNotCompensatedTwice(): void
    {
        $result = AbsenceHolidaySegments::compensateSickness(
            $this->shifts(['2026-12-24']),
            ['2026-12-24' => 480],
        );

        self::assertCount(1, $result);
        self::assertSame(480, $this->minutes($result));
    }

    public function testHolidayThatIsNotAScheduledDayIsNotCompensated(): void
    {
        $result = AbsenceHolidaySegments::compensateSickness(
            $this->shifts(['2026-12-23']),
            ['2026-12-26' => 0],
        );

        self::assertSame(['2026-12-23'], array_column($result, 'local_date'));
    }

    public function testPartialDayLimitCapsTheCompensatedHoliday(): void
    {
        $result = AbsenceHolidaySegments::compensateSickness(
            [],
            ['2026-12-24' => 480],
            ['2026-12-24' => 180],
        );

        self::assertSame(180, $this->minutes($result));
        self::assertSame(480, $result[0]['planned_minutes']);
    }

    public function testShorterWeekHolidayUsesTheScheduledLength(): void
    {
        $result = AbsenceHolidaySegments::compensateSickness([], ['2026-05-01' => 360]);

        self::assertSame(360, $this->minutes($result));
    }

    /** @return array<string,array{code:string,name:string}> */
    private function holidays(string $from, string $to): array
    {
        return PayrollWorkCalendarSchedule::holidaysBetween(new CzechHolidayCalendar(), $from, $to);
    }

    /**
     * @param list<string> $dates
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    private function shifts(array $dates, int $minutes = 480): array
    {
        $segments = [];
        foreach ($dates as $index => $date) {
            $segments[] = [
                'shift_id' => $index + 1,
                'local_date' => $date,
                'planned_minutes' => $minutes,
                'eligible_minutes' => $minutes,
            ];
        }

        return $segments;
    }

    /** @param list<array<string,mixed>> $segments */
    private function minutes(array $segments): int
    {
        return (int) array_sum(array_column($segments, 'eligible_minutes'));
    }
}
