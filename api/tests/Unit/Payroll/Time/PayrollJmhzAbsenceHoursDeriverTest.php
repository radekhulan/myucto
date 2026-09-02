<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Service\Payroll\Absence\AbsenceHolidayTreatment;
use MyInvoice\Service\Payroll\Time\PayrollJmhzAbsenceHoursDeriver;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Návrh podmíněných bloků měsíčního hlášení (10275–10280, 10471/10472).
 *
 * Měsíc s dovolenou nebo nemocí se dřív schvaloval jako „žádné neodpracované
 * hodiny", protože dialog ta čísla nenavrhoval — a ELDP to o krok dál shodil
 * na `jmhz_eldp_work_summary_mismatch`. Test hlídá, že hodiny vycházejí
 * z týchž publikovaných směn, ze kterých vznikla náhrada mzdy, a že se
 * nedoložený měsíc drží prázdný, místo aby si něco domyslel.
 */
final class PayrollJmhzAbsenceHoursDeriverTest extends TestCase
{
    private const PERIOD_START = '2026-09-01';

    private const PERIOD_END = '2026-10-01';

    public function testVacationFillsTheVacationBlockAndBothSums(): void
    {
        $absences = $this->repository();
        $absences->expects(self::once())
            ->method('publishedShiftSegments')
            ->with(
                self::callback(static fn (array $absence): bool =>
                    $absence['supplier_id'] === 1
                    && $absence['employment_id'] === 32
                    && $absence['absence_type'] === 'vacation'),
                false,
                AbsenceHolidayTreatment::ExcludeFromLeave,
            )
            ->willReturn($this->segments([
                '2026-09-14' => 480,
                '2026-09-15' => 480,
                '2026-09-16' => 480,
                '2026-09-17' => 480,
                '2026-09-18' => 480,
            ]));

        $derived = $this->derive($absences, [
            $this->absence(3, 'vacation', '2026-09-14', '2026-09-18'),
        ]);

        self::assertTrue($derived['supported']);
        self::assertSame(2400, $derived['minutes']['vacation']);
        self::assertSame(2400, $derived['total']);
        self::assertSame(2400, $derived['paid']);

        $suggestions = PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions($derived);
        self::assertSame('40', $suggestions['vacation_hours']);
        self::assertSame('40', $suggestions['unworked_total_hours']);
        self::assertSame('40', $suggestions['unworked_paid_hours']);
        self::assertTrue($suggestions['unworked_hours_occurred']);
        self::assertFalse($suggestions['work_obstacles_occurred']);
        foreach ([
            'dpn_with_employer_compensation_hours',
            'dpn_without_employer_compensation_hours',
            'care_hours',
            'employee_obstacle_paid_hours',
            'employer_obstacle_hours',
        ] as $field) {
            self::assertNull($suggestions[$field], $field);
        }
    }

    /**
     * Nemoc se dělí oknem náhrady mzdy podle § 192 ZP: uvnitř okna je náhrada
     * zaměstnavatele (10278), za ním dávka ČSSZ (10277). Do 10276 patří jen ta
     * placená část.
     */
    public function testSicknessSplitsAlongTheEmployerCompensationWindow(): void
    {
        $absences = $this->repository();
        $absences->expects(self::once())
            ->method('publishedShiftSegments')
            ->with(self::anything(), true, AbsenceHolidayTreatment::CompensateSickness)
            ->willReturn($this->segments([
                '2026-09-08' => 240,
                '2026-09-09' => 240,
                '2026-09-10' => 240,
                '2026-09-11' => 240,
                '2026-09-14' => 240,
                '2026-09-15' => 240,
                '2026-09-16' => 240,
                '2026-09-17' => 240,
                '2026-09-18' => 240,
                '2026-09-19' => 240,
            ]));
        $absences->expects(self::once())
            ->method('publishedShiftSegmentsBeyondSicknessWindow')
            ->with(self::anything(), true)
            ->willReturn($this->segments([
                '2026-09-21' => 240,
                '2026-09-22' => 240,
            ]));

        $derived = $this->derive(
            $absences,
            [$this->absence(4, 'dpn', '2026-09-07', '2026-09-22')],
            // První den odpracován celý — posouvá okno § 192 na 8. 9.
            firstDayFullyWorked: true,
        );

        self::assertTrue($derived['supported']);
        self::assertSame(2400, $derived['minutes']['dpn_with_employer_compensation']);
        self::assertSame(480, $derived['minutes']['dpn_without_employer_compensation']);
        self::assertSame(2880, $derived['total']);
        self::assertSame(2400, $derived['paid']);

        $suggestions = PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions($derived);
        self::assertSame('40', $suggestions['dpn_with_employer_compensation_hours']);
        self::assertSame('8', $suggestions['dpn_without_employer_compensation_hours']);
        self::assertSame('48', $suggestions['unworked_total_hours']);
        self::assertSame('40', $suggestions['unworked_paid_hours']);
        self::assertNull($suggestions['vacation_hours']);
        self::assertTrue($suggestions['unworked_hours_occurred']);
        self::assertFalse($suggestions['work_obstacles_occurred']);
    }

    public function testSegmentsOutsideTheReportedMonthDoNotCount(): void
    {
        $absences = $this->repositoryStub();
        $absences->method('publishedShiftSegments')->willReturn($this->segments([
            '2026-08-31' => 480,
            '2026-09-01' => 480,
            '2026-10-01' => 480,
        ]));

        $derived = $this->derive($absences, [
            $this->absence(5, 'vacation', '2026-08-31', '2026-10-01'),
        ]);

        self::assertSame(480, $derived['minutes']['vacation']);
        self::assertSame(480, $derived['total']);
    }

    public function testObstaclesAnswerTheSecondInteraction(): void
    {
        $absences = $this->repositoryStub();
        $absences->method('publishedShiftSegments')
            ->willReturn($this->segments(['2026-09-03' => 480]));

        $derived = $this->derive($absences, [
            $this->absence(6, 'employer_obstacle', '2026-09-03', '2026-09-03'),
        ]);

        self::assertSame(480, $derived['minutes']['employer_obstacle']);
        self::assertSame(480, $derived['paid']);

        $suggestions = PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions($derived);
        self::assertSame('8', $suggestions['employer_obstacle_hours']);
        self::assertTrue($suggestions['unworked_hours_occurred']);
        self::assertTrue($suggestions['work_obstacles_occurred']);
    }

    /**
     * Neplacené volno se do žádného z atributů hlášení jednoznačně nezapíše.
     * Návrh by v součtu 10275 tiše chyběl, takže se nenavrhuje vůbec nic.
     */
    public function testUndocumentedAbsenceKindSuggestsNothing(): void
    {
        $absences = $this->repository();
        $absences->expects(self::never())->method('publishedShiftSegments');

        $derived = $this->derive($absences, [
            $this->absence(7, 'unpaid_leave', '2026-09-03', '2026-09-04'),
        ]);

        self::assertFalse($derived['supported']);

        $suggestions = PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions($derived);
        foreach ($suggestions as $field => $value) {
            self::assertNull($value, $field);
        }
    }

    public function testUndecidedAbsenceSuggestsNothing(): void
    {
        $absences = $this->repository();
        $absences->expects(self::never())->method('publishedShiftSegments');

        $derived = $this->derive($absences, [
            ['status' => 'requested'] + $this->absence(8, 'vacation', '2026-09-03', '2026-09-04'),
        ]);

        self::assertFalse($derived['supported']);
    }

    /**
     * Bez výpočtu náhrady není známé, jestli byl první den nemoci odpracován
     * celý — a bez toho by okno § 192 uteklo o den.
     */
    public function testSicknessWithoutCalculatedCompensationSuggestsNothing(): void
    {
        $absences = $this->repository();
        $absences->expects(self::never())->method('publishedShiftSegments');

        $derived = $this->derive(
            $absences,
            [$this->absence(9, 'dpn', '2026-09-07', '2026-09-18')],
            sicknessEventAbsenceId: null,
        );

        self::assertFalse($derived['supported']);
    }

    public function testMonthWithoutAbsencesAnswersBothInteractionsWithNo(): void
    {
        $derived = $this->derive($this->repositoryStub(), []);

        self::assertTrue($derived['supported']);
        self::assertSame(0, $derived['total']);

        $suggestions = PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions($derived);
        self::assertFalse($suggestions['unworked_hours_occurred']);
        self::assertFalse($suggestions['work_obstacles_occurred']);
        self::assertNull($suggestions['unworked_total_hours']);
    }

    /**
     * Millihodina má tři desetinná místa; 10 minut na ni přesně nevyjde.
     * Zaokrouhlit by znamenalo poslat ČSSZ jiné číslo, než jaké je v evidenci.
     */
    public function testMinutesThatDoNotConvertExactlySuggestNothing(): void
    {
        $absences = $this->repositoryStub();
        $absences->method('publishedShiftSegments')
            ->willReturn($this->segments(['2026-09-03' => 10]));

        $derived = $this->derive($absences, [
            $this->absence(10, 'vacation', '2026-09-03', '2026-09-03'),
        ]);

        self::assertSame(10, $derived['minutes']['vacation']);

        $suggestions = PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions($derived);
        foreach ($suggestions as $field => $value) {
            self::assertNull($value, $field);
        }
    }

    /**
     * @param list<array<string,mixed>> $absences
     * @return array{supported:bool,minutes:array<string,int>,total:int,paid:int}
     */
    private function derive(
        PayrollAbsenceRepository $repository,
        array $absences,
        bool $firstDayFullyWorked = false,
        ?int $sicknessEventAbsenceId = -1,
    ): array {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(
            'CREATE TABLE payroll_sickness_events (
                supplier_id INTEGER NOT NULL,
                absence_id INTEGER NOT NULL,
                first_day_fully_worked INTEGER NOT NULL
            )'
        );
        if ($sicknessEventAbsenceId !== null) {
            foreach ($absences as $absence) {
                if (!in_array($absence['absence_type'], ['dpn', 'quarantine'], true)) {
                    continue;
                }
                $insert = $pdo->prepare(
                    'INSERT INTO payroll_sickness_events VALUES (1, ?, ?)'
                );
                $insert->execute([
                    $sicknessEventAbsenceId === -1 ? $absence['id'] : $sicknessEventAbsenceId,
                    $firstDayFullyWorked ? 1 : 0,
                ]);
            }
        }
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return (new PayrollJmhzAbsenceHoursDeriver($db, $repository))->derive(
            1,
            32,
            self::PERIOD_START,
            self::PERIOD_END,
            $absences,
        );
    }

    private function repository(): PayrollAbsenceRepository
    {
        return $this->createMock(PayrollAbsenceRepository::class);
    }

    private function repositoryStub(): PayrollAbsenceRepository
    {
        return $this->createStub(PayrollAbsenceRepository::class);
    }

    /** @return array<string,mixed> */
    private function absence(int $id, string $type, string $from, string $to): array
    {
        return [
            'id' => $id,
            'absence_type' => $type,
            'date_from' => $from,
            'date_to' => $to,
            'timezone_name' => 'Europe/Prague',
            'partial_first_minutes' => null,
            'partial_last_minutes' => null,
            'status' => 'approved',
            'correction_pending' => false,
        ];
    }

    /**
     * @param array<string,int> $minutesByDate
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    private function segments(array $minutesByDate): array
    {
        $segments = [];
        foreach ($minutesByDate as $date => $minutes) {
            $segments[] = [
                'shift_id' => null,
                'local_date' => (string) $date,
                'planned_minutes' => $minutes,
                'eligible_minutes' => $minutes,
            ];
        }

        return $segments;
    }
}
