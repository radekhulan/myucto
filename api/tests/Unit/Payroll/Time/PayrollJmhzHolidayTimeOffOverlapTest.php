<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Service\Payroll\Absence\AbsenceHolidayTreatment;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollCalendarFundService;
use MyInvoice\Service\Payroll\Time\PayrollJmhzAbsenceHoursDeriver;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollJmhzHolidayTimeOffOverlapTest extends TestCase
{
    public static function minutes(): iterable
    {
        yield 'celá směna' => [480];
        yield 'část směny' => [240];
    }

    #[DataProvider('minutes')]
    public function testPublishedHolidayShiftIsReportedOnceAcrossPaidTimeOffAndHoliday(int $minutes): void
    {
        $absence = $this->absence($minutes);
        $repository = $this->createMock(PayrollAbsenceRepository::class);
        $repository->expects(self::once())->method('publishedShiftSegments')->with(self::anything(), false, AbsenceHolidayTreatment::Ignore)
            ->willReturn([['local_date' => '2026-07-06', 'eligible_minutes' => $minutes]]);
        $connection = $this->createStub(Connection::class);
        $deriver = new PayrollJmhzAbsenceHoursDeriver($connection, $repository);
        $derived = $deriver->derive(1, 2, '2026-07-01', '2026-08-01', [$absence]);
        $builder = new PayrollJmhzWorkMonthSummaryBuilder($connection, new PayrollCalendarFundService(new CzechHolidayCalendar()), $deriver, $repository);
        $holiday = (new \ReflectionMethod($builder, 'holidayMinutes'))->invoke($builder,
            [['valid_from' => '2026-01-01', 'valid_to' => null, 'week_pattern' => [1 => 480], 'days' => []]],
            new \DateTimeImmutable('2026-07-06'), new \DateTimeImmutable('2026-07-06'), [], [$absence], '2026-07-01');
        self::assertSame([480 - $minutes, 0], $holiday);
        $suggestions = PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions($derived, true);
        $hash = str_repeat('a', 64);
        $summary = $builder->confirm(['derivation_version' => 'jmhz-work-month.v11', 'source_snapshot_sha256' => $hash,
            'source_snapshot_json' => '{}', 'holiday_millihours' => intdiv($holiday[0] * 1000, 60),
            'suggestions' => $suggestions + ['evidence_days' => 31, 'worked_days' => 20, 'overtime_hours' => '0']],
            $suggestions + ['source_snapshot_sha256' => $hash, 'standard_fund_hours' => '168', 'agreed_fund_hours' => '168',
                'weekly_work_hours' => '40', 'worked_hours' => '160']);
        self::assertSame(8000, $summary['values']['unworked_total_millihours']);
        self::assertSame(8000, $summary['values']['unworked_paid_millihours']);
        self::assertSame(intdiv($minutes * 1000, 60), $summary['values']['holiday_time_off_millihours']);
    }

    public function testCalendarPathCountsOnlyDocumentedHolidayCapacityAndKeepsZeroWithoutPlan(): void
    {
        $repository = $this->createStub(PayrollAbsenceRepository::class);
        $day = new \DateTimeImmutable('2026-07-06');
        $repository->method('absenceBounds')->willReturn(['from' => $day, 'to' => $day, 'window_to' => $day]);
        $deriver = new PayrollJmhzAbsenceHoursDeriver($this->createStub(Connection::class), $repository);
        foreach ([480, 0] as $planned) {
            $result = $deriver->deriveFromCalendar(1, 2, '2026-07-01', '2026-08-01', [$this->absence(480)],
                static fn(string $date, bool $holidays): int => $holidays ? $planned : 0);
            self::assertSame($planned, $result['minutes']['holiday_time_off']);
            self::assertSame($planned, $result['total']);
            self::assertSame($planned, $result['paid']);
        }
    }

    private function absence(int $minutes): array
    {
        return ['id' => 3, 'absence_type' => 'holiday_compensatory_time_off', 'date_from' => '2026-07-06', 'date_to' => '2026-07-06',
            'status' => 'approved', 'correction_pending' => false, 'partial_first_minutes' => $minutes, 'partial_last_minutes' => $minutes];
    }
}
