<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService as Derivation;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * Odvození vstupů průměrného výdělku z uzavřených běhů.
 *
 * Testuje se přes veřejné statické převody ({@see Derivation::monthFromRow()},
 * {@see Derivation::combine()}, {@see Derivation::workedFromEntries()}), takže
 * se nemusí sestavovat celý mzdový běh. Právě v nich sedí všechna rozhodnutí
 * „tohle se navrhnout nedá" — a z průměru se počítají peníze i údaj do hlášení
 * ČSSZ, takže každá zavřená větev musí mít test.
 */
final class AverageEarningDerivationServiceTest extends TestCase
{
    private const MINIMUM_WORKED_DAYS = 21;

    public function testDerivesGrossHoursAndDaysFromClosedRun(): void
    {
        $month = Derivation::monthFromRow($this->row(), '2026-01-01');

        self::assertSame([], $month['blockers']);
        // Započitatelná mzda = `totals.average_earning_base_minor` z výsledku
        // běhu, tedy součet složek klasifikovaných `average_earning: included`.
        self::assertSame(4_500_000, $month['gross_earnings_minor']);
        // Dvě osmihodinové směny s půlhodinovou přestávkou = 2 × 450 minut.
        self::assertSame(900, $month['worked_minutes']);
        self::assertSame(2, $month['worked_days']);
    }

    public function testSplitShiftCountsOneDayTwice(): void
    {
        $worked = Derivation::workedFromEntries([
            self::entry('2026-01-05T06:00:00Z', '2026-01-05T10:00:00Z', 0),
            self::entry('2026-01-05T12:00:00Z', '2026-01-05T15:00:00Z', 0, 'overtime'),
        ], '2026-01-01');

        // Přesčas patří do odpracované doby (§ 353 odst. 1 ZP), ale den ne
        // dvakrát: 4 + 3 hodiny v jednom dni je 420 minut a jeden den.
        self::assertSame(['minutes' => 420, 'days' => 1], $worked);
    }

    public function testNonWorkedCategoriesAreIgnored(): void
    {
        $worked = Derivation::workedFromEntries([
            self::entry('2026-01-05T06:00:00Z', '2026-01-05T14:00:00Z', 0),
            self::entry('2026-01-06T06:00:00Z', '2026-01-06T14:00:00Z', 0, 'holiday'),
        ], '2026-01-01');

        self::assertSame(['minutes' => 480, 'days' => 1], $worked);
    }

    public function testOverlappingIntervalsAreNotDerivable(): void
    {
        self::assertNull(Derivation::workedFromEntries([
            self::entry('2026-01-05T06:00:00Z', '2026-01-05T14:00:00Z', 0),
            self::entry('2026-01-05T13:00:00Z', '2026-01-05T16:00:00Z', 0, 'overtime'),
        ], '2026-01-01'));
    }

    public function testIntervalCrossingLocalMonthBoundaryIsNotDerivable(): void
    {
        self::assertNull(Derivation::workedFromEntries([
            self::entry('2026-01-31T21:00:00Z', '2026-02-01T04:00:00Z', 0),
        ], '2026-01-01'));
    }

    public function testBreakLongerThanShiftIsNotDerivable(): void
    {
        self::assertNull(Derivation::workedFromEntries([
            self::entry('2026-01-05T06:00:00Z', '2026-01-05T08:00:00Z', 200),
        ], '2026-01-01'));
    }

    public function testMissingRunBlocksInsteadOfGuessingProbableEarning(): void
    {
        $month = Derivation::monthFromRow(null, '2026-01-01');

        self::assertSame(['run_missing'], $month['blockers']);
        self::assertNull($month['gross_earnings_minor']);
        self::assertNull($month['worked_minutes']);
        self::assertNull($month['worked_days']);
    }

    public function testAmbiguousRunsBlock(): void
    {
        self::assertSame(
            ['multiple_runs_for_month'],
            Derivation::monthFromRow(['ambiguous_runs' => true], '2026-01-01')['blockers'],
        );
    }

    /**
     * @param array<string,mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unclosedRuns')]
    public function testUnclosedRunBlocks(array $overrides): void
    {
        self::assertSame(
            ['run_not_approved'],
            Derivation::monthFromRow($this->row($overrides), '2026-01-01')['blockers'],
        );
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function unclosedRuns(): array
    {
        return [
            'běh se opravuje' => [['run_status' => 'correction_pending']],
            'běh je zrušený' => [['run_status' => 'cancelled']],
            'běh je rozpracovaný' => [['run_status' => 'calculated']],
            'revize není schválená' => [['revision_status' => 'reviewed']],
            'revizi nahradila novější' => [['revision_status' => 'superseded']],
        ];
    }

    public function testMissingResultBlocks(): void
    {
        self::assertSame(
            ['run_result_missing'],
            Derivation::monthFromRow(
                $this->row(['result_json' => null, 'result_status' => 'blocked']),
                '2026-01-01',
            )['blockers'],
        );
    }

    public function testResultWithoutAverageEarningBaseBlocks(): void
    {
        self::assertSame(
            ['average_earning_base_missing'],
            Derivation::monthFromRow(
                $this->row(['result_json' => json_encode(['totals' => []])]),
                '2026-01-01',
            )['blockers'],
        );
    }

    public function testUnapprovedAttendanceMonthBlocks(): void
    {
        self::assertSame(
            ['time_month_not_approved'],
            Derivation::monthFromRow(
                $this->row(['input_json' => self::inputJson(['status' => 'open'])]),
                '2026-01-01',
            )['blockers'],
        );
    }

    public function testMissingAttendanceMonthBlocks(): void
    {
        self::assertSame(
            ['time_month_missing'],
            Derivation::monthFromRow(
                $this->row(['input_json' => json_encode(['time_month' => null])]),
                '2026-01-01',
            )['blockers'],
        );
    }

    public function testMissingWorkSummaryBlocks(): void
    {
        self::assertSame(
            ['work_summary_missing'],
            Derivation::monthFromRow(
                $this->row([
                    'input_json' => self::inputJson(['jmhz_work_summary' => null]),
                ]),
                '2026-01-01',
            )['blockers'],
        );
    }

    public function testOlderWorkSummaryVersionBlocks(): void
    {
        $summary = self::summary();
        $summary['derivation_version'] = 'jmhz-work-month-core.v1';

        self::assertSame(
            ['work_summary_version_unsupported'],
            Derivation::monthFromRow(
                $this->row(['input_json' => self::inputJson(['jmhz_work_summary' => $summary])]),
                '2026-01-01',
            )['blockers'],
        );
    }

    public function testTamperedWorkSummarySourceBlocks(): void
    {
        $summary = self::summary();
        $summary['source_snapshot_sha256'] = str_repeat('0', 64);

        self::assertSame(
            ['work_summary_source_corrupt'],
            Derivation::monthFromRow(
                $this->row(['input_json' => self::inputJson(['jmhz_work_summary' => $summary])]),
                '2026-01-01',
            )['blockers'],
        );
    }

    /**
     * Účetní navrženou hodinu ve mzdovém souhrnu přepsala — dny ze směn pak
     * stojí na jiné evidenci než hodiny a návrh by tvrdil souvislost, která
     * tam není.
     */
    public function testConfirmedHoursThatDoNotMatchTheShiftsBlock(): void
    {
        $summary = self::summary();
        $summary['values']['worked_millihours'] = 16_000;
        self::assertSame(
            ['work_summary_hours_mismatch'],
            Derivation::monthFromRow(
                $this->row(['input_json' => self::inputJson(['jmhz_work_summary' => $summary])]),
                '2026-01-01',
            )['blockers'],
        );
    }

    public function testUnderivableShiftsBlock(): void
    {
        $summary = self::summary([
            self::entry('2026-01-05T06:00:00Z', '2026-01-05T14:00:00Z', 0),
            self::entry('2026-01-05T13:00:00Z', '2026-01-05T16:00:00Z', 0, 'overtime'),
        ]);

        self::assertSame(
            ['worked_time_not_derivable'],
            Derivation::monthFromRow(
                $this->row(['input_json' => self::inputJson(['jmhz_work_summary' => $summary])]),
                '2026-01-01',
            )['blockers'],
        );
    }

    public function testCombineSumsAllThreeMonths(): void
    {
        $combined = Derivation::combine(
            [
                $this->readyMonth('2026-01-01', 4_500_000, 9_600, 20),
                $this->readyMonth('2026-02-01', 4_500_000, 8_400, 20),
                $this->readyMonth('2026-03-01', 5_000_000, 9_600, 21),
            ],
            self::MINIMUM_WORKED_DAYS,
            false,
        );

        self::assertTrue($combined['ready']);
        self::assertSame([], $combined['blockers']);
        self::assertSame(14_000_000, $combined['gross_earnings_minor']);
        self::assertSame(27_600, $combined['worked_minutes']);
        self::assertSame(61, $combined['worked_days']);
        // § 358 ZP se z evidence odvodit nedá; zadává ho účetní.
        self::assertNull($combined['longer_period_allocated_minor']);
    }

    public function testSingleBlockedMonthSuppressesTheWholeProposal(): void
    {
        $combined = Derivation::combine(
            [
                $this->readyMonth('2026-01-01', 4_500_000, 9_600, 20),
                $this->readyMonth('2026-02-01', 4_500_000, 8_400, 20),
                ['period_start' => '2026-03-01', 'blockers' => ['run_missing']],
            ],
            self::MINIMUM_WORKED_DAYS,
            false,
        );

        self::assertFalse($combined['ready']);
        self::assertSame(['run_missing'], $combined['blockers']);
        // Dva ze tří měsíců by dávaly číslo, které vypadá hotově — a není.
        self::assertNull($combined['gross_earnings_minor']);
        self::assertNull($combined['worked_minutes']);
        self::assertNull($combined['worked_days']);
    }

    public function testBelowMinimumWorkedDaysFallsToProbableEarningProcedure(): void
    {
        $combined = Derivation::combine(
            [$this->readyMonth('2026-03-01', 2_000_000, 4_800, 20)],
            self::MINIMUM_WORKED_DAYS,
            false,
        );

        self::assertFalse($combined['ready']);
        self::assertSame(['probable_earning_required'], $combined['blockers']);
        self::assertNull($combined['worked_days']);
    }

    public function testZeroEarningsOrTimeBlocks(): void
    {
        $combined = Derivation::combine(
            [$this->readyMonth('2026-03-01', 0, 0, 21)],
            self::MINIMUM_WORKED_DAYS,
            false,
        );

        self::assertFalse($combined['ready']);
        self::assertSame(['worked_time_missing'], $combined['blockers']);
    }

    public function testExistingSnapshotIsNotOverwritten(): void
    {
        $combined = Derivation::combine(
            [$this->readyMonth('2026-03-01', 6_000_000, 12_600, 21)],
            self::MINIMUM_WORKED_DAYS,
            true,
        );

        self::assertFalse($combined['ready']);
        self::assertSame(['average_already_exists'], $combined['blockers']);
    }

    /**
     * Verze vstupů se musí změnit, jakmile se změní kterýkoli zdrojový podklad —
     * jinak by formulář držel návrh z revize, kterou mezitím nahradila opravná.
     */
    public function testInputVersionTracksTheUnderlyingSources(): void
    {
        $first = Derivation::combine(
            [$this->readyMonth('2026-03-01', 6_000_000, 12_600, 21)],
            self::MINIMUM_WORKED_DAYS,
            false,
        );
        $changed = $this->readyMonth('2026-03-01', 6_000_000, 12_600, 21);
        $changed['revision_id'] = 999;
        $second = Derivation::combine([$changed], self::MINIMUM_WORKED_DAYS, false);

        self::assertNotSame($first['input_version'], $second['input_version']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'revision_id' => 7,
            'run_id' => 3,
            'revision_no' => 2,
            'revision_status' => 'approved',
            'run_status' => 'closed',
            'input_json' => self::inputJson(),
            'result_json' => (string) json_encode([
                'employment_id' => 11,
                'totals' => ['average_earning_base_minor' => 4_500_000],
            ]),
            'result_hash' => str_repeat('a', 64),
            'result_status' => 'calculated',
        ];
    }

    /**
     * @param array<string,mixed> $timeMonthOverrides
     */
    private static function inputJson(array $timeMonthOverrides = []): string
    {
        return (string) json_encode([
            'employment' => ['id' => 11],
            'time_month' => $timeMonthOverrides + [
                'id' => 5,
                'status' => 'approved',
                'revision_no' => 1,
                'row_version' => 2,
                'jmhz_work_summary' => self::summary(),
            ],
        ]);
    }

    /**
     * Pracovní souhrn se dvěma osmihodinovými směnami s půlhodinovou
     * přestávkou — 900 minut, tedy 15 000 milihodin.
     *
     * @param list<array<string,mixed>>|null $entries
     * @return array<string,mixed>
     */
    private static function summary(?array $entries = null): array
    {
        $entries ??= [
            self::entry('2026-01-05T06:00:00Z', '2026-01-05T14:00:00Z', 30),
            self::entry('2026-01-06T06:00:00Z', '2026-01-06T14:00:00Z', 30),
        ];
        $source = [
            'schema_version' => 'jmhz-work-month.v2',
            'period_start' => '2026-01-01',
            'time_entries' => $entries,
        ];
        $sourceJson = CanonicalJson::encode($source);

        return [
            'id' => 42,
            'derivation_version' => 'jmhz-work-month.v2',
            'source_snapshot_json' => $sourceJson,
            'source_snapshot_sha256' => hash('sha256', $sourceJson),
            'summary_sha256' => str_repeat('b', 64),
            'values' => [
                'evidence_days' => 31,
                'worked_millihours' => 15_000,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function entry(
        string $startsAtUtc,
        string $endsAtUtc,
        int $breakMinutes,
        string $category = 'regular',
    ): array {
        return [
            'id' => abs(crc32($startsAtUtc . $category)),
            'category' => $category,
            'starts_at_utc' => (new \DateTimeImmutable($startsAtUtc))->format('Y-m-d H:i:s'),
            'ends_at_utc' => (new \DateTimeImmutable($endsAtUtc))->format('Y-m-d H:i:s'),
            'timezone_name' => 'Europe/Prague',
            'break_minutes' => $breakMinutes,
        ];
    }

    /** @return array<string,mixed> */
    private function readyMonth(
        string $periodStart,
        int $grossMinor,
        int $workedMinutes,
        int $workedDays,
    ): array {
        return [
            'period_start' => $periodStart,
            'blockers' => [],
            'run_id' => 3,
            'revision_id' => 7,
            'revision_no' => 1,
            'gross_earnings_minor' => $grossMinor,
            'worked_minutes' => $workedMinutes,
            'worked_days' => $workedDays,
            'work_summary_id' => 42,
            'work_summary_sha256' => str_repeat('b', 64),
            'result_hash' => str_repeat('a', 64),
            'time_month_row_version' => 2,
        ];
    }
}
