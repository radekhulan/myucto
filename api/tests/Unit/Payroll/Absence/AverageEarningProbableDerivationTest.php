<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService as Derivation;
use MyInvoice\Service\Payroll\Absence\MinimumWageFloor;
use MyInvoice\Service\Payroll\Document\AverageEarningsMonthlyMath;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * Pravděpodobný výdělek (§ 355 odst. 2 ZP) navržený z evidence u nového
 * vztahu, když ho účetní nezadala do podmínek.
 */
final class AverageEarningProbableDerivationTest extends TestCase
{
    public function testAgreedHourlyRateIsOnlyAReviewProposalAndDoesNotChangeRecordedEarning(): void
    {
        $terms = [['id' => 7, 'effective_from' => '2026-06-01', 'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 20_000, 'probable_hourly_earning_minor' => 24_000,
            'probable_earning_rationale' => 'Posouzeny obvyklé měsíční prémie.']];
        $proposal = Derivation::probableFromAgreedHourlyRate($terms, '2026-04-01');
        self::assertSame(20_000, $proposal['hourly_minor']);
        self::assertSame('agreed_hourly_rate', $proposal['source']);
        self::assertStringContainsString('ostatní obvyklé složky', $proposal['rationale']);
        self::assertSame(24_000, Derivation::probableFromTerms($terms, '2026-04-01')['hourly_minor']);
        self::assertNull(Derivation::probableFromAgreedHourlyRate([['remuneration_type' => null, 'hourly_rate_minor' => 20_000]], '2026-04-01'));
    }

    public function testNewEmploymentGetsProbableEarningFromWageAchievedSinceStart(): void
    {
        $decisive = $this->decisiveMonthsWithoutRuns();
        self::assertTrue(Derivation::derivedProbableAllowed($decisive, '2026-06-01'));

        $probable = Derivation::probableFromAchievedWage([
            $this->closedMonth('2026-06-01', 4_500_000, 9_600),
        ]);
        self::assertNotNull($probable);
        // 45 000 Kč za 160 hodin = 281,25 Kč/h.
        self::assertSame(28_125, $probable['hourly_minor']);
        self::assertSame(Derivation::PROBABLE_SOURCE_ACHIEVED_WAGE, $probable['source']);
        self::assertStringContainsString('§ 355 odst. 2', $probable['rationale']);
        self::assertStringContainsString('6/2026', $probable['rationale']);

        $suggestion = Derivation::combine($decisive, 21, false, $probable);
        self::assertTrue($suggestion['ready']);
        self::assertSame('probable', $suggestion['source_kind']);
        self::assertSame(Derivation::PROBABLE_SOURCE_ACHIEVED_WAGE, $suggestion['probable_source']);
        self::assertSame(28_125, $suggestion['probable_hourly_minor']);
    }

    public function testMissingRunWhileTheEmploymentAlreadyExistedIsNotPaperedOver(): void
    {
        // Vztah trvá od ledna, březnový běh chybí: vadná evidence, ne nováček.
        self::assertFalse(Derivation::derivedProbableAllowed(
            $this->decisiveMonthsWithoutRuns(),
            '2026-01-15',
        ));
        self::assertFalse(Derivation::derivedProbableAllowed($this->decisiveMonthsWithoutRuns(), null));
    }

    public function testBlockedMonthCancelsTheAchievedWageProposal(): void
    {
        $blocked = ['period_start' => '2026-06-01', 'blockers' => ['run_not_approved']]
            + $this->closedMonth('2026-06-01', 4_500_000, 9_600);

        self::assertNull(Derivation::probableFromAchievedWage([$blocked]));
        self::assertNull(Derivation::probableFromAchievedWage([]));
    }

    public function testAgreedMonthlyGrossIsConvertedWithTheStatutoryCoefficient(): void
    {
        $probable = Derivation::probableFromAgreedGross([
            ['id' => 7, 'effective_from' => '2026-06-01', 'monthly_gross_minor' => '4500000', 'weekly_hours' => '40.00'],
        ], '2026-04-01');

        self::assertNotNull($probable);
        // 45 000 / (40 × 4,348) = 258,735… → 258,74 Kč/h.
        self::assertSame(25_874, $probable['hourly_minor']);
        self::assertSame(7, $probable['term_id']);
        self::assertSame(Derivation::PROBABLE_SOURCE_AGREED_MONTHLY_GROSS, $probable['source']);
        self::assertStringContainsString('4,348', $probable['rationale']);
    }

    public function testAgreedGrossWithoutWeeklyHoursIsNotUsed(): void
    {
        self::assertNull(Derivation::probableFromAgreedGross([
            ['id' => 7, 'effective_from' => '2026-06-01', 'monthly_gross_minor' => '4500000', 'weekly_hours' => null],
        ], '2026-04-01'));
        self::assertNull(Derivation::probableFromAgreedGross([
            ['id' => 7, 'effective_from' => '2026-06-01', 'monthly_gross_minor' => null, 'weekly_hours' => '40.00'],
        ], '2026-04-01'));
    }

    /**
     * Dohoda v prvním měsíci nemá dosaženou ani sjednanou měsíční mzdu.
     * 10345 je v XSD povinný; spodní mezí pravděpodobného výdělku je
     * minimální mzda (2026: 134,40 Kč/h), kterou přijatá hlášení vykazují.
     */
    public function testMinimumWageIsProposedWhenNoWageCanBeDerived(): void
    {
        $floor = MinimumWageFloor::forDate(CzechPayrollRulesets2026::provider(), '2026-07-01');
        $probable = Derivation::probableFromMinimumWage($floor);

        self::assertSame(13_440, $probable['hourly_minor']);
        self::assertSame(Derivation::PROBABLE_SOURCE_MINIMUM_WAGE, $probable['source']);
        self::assertStringContainsString('§ 357 odst. 1', $probable['rationale']);

        $suggestion = Derivation::combine($this->decisiveMonthsWithoutRuns(), 21, false, $probable);
        self::assertTrue($suggestion['ready']);
        self::assertSame('probable', $suggestion['source_kind']);
        self::assertSame(Derivation::PROBABLE_SOURCE_MINIMUM_WAGE, $suggestion['probable_source']);
        self::assertSame(13_440, $suggestion['probable_hourly_minor']);
        self::assertNotContains('probable_earning_not_recorded', $suggestion['blockers']);
    }

    public function testRecordedProbableEarningKeepsItsSourceLabel(): void
    {
        $suggestion = Derivation::combine($this->decisiveMonthsWithoutRuns(), 21, false, [
            'hourly_minor' => 30_000,
            'rationale' => 'Obvyklá mzda srovnatelných zaměstnanců.',
            'term_id' => 3,
            'effective_from' => '2026-06-01',
        ]);

        self::assertSame(Derivation::PROBABLE_SOURCE_TERMS, $suggestion['probable_source']);
    }

    public function testNeedsProbableOnlyForReasonsCoveredBySection355(): void
    {
        self::assertTrue(Derivation::needsProbable(
            Derivation::combine($this->decisiveMonthsWithoutRuns(), 21, false),
        ));
        self::assertFalse(Derivation::needsProbable(Derivation::combine([
            ['period_start' => '2026-03-01', 'blockers' => ['time_month_not_approved']],
        ], 21, false)));
    }

    public function testHourlyFromMonthlyIsTheInverseOfSection356(): void
    {
        $hourly = AverageEarningsMonthlyMath::hourlyMinorUnitsFromMonthly(4_500_000, 40_000);

        self::assertSame(25_874, $hourly);
        // Zpět na měsíc vyjde původní částka do haléřů zaokrouhlení.
        self::assertEqualsWithDelta(
            4_500_000,
            AverageEarningsMonthlyMath::grossMonthlyMinorUnits($hourly, 40_000),
            100,
        );
    }

    /** @return list<array<string,mixed>> */
    /**
     * Hláška „skutečný průměr odvodit nelze" neříkala proč. Důvod se čte
     * z výsledku návrhu: chybějící běhy rozhodného období, nebo méně než
     * 21 odpracovaných dnů (§ 355 odst. 1 ZP).
     */
    public function testProbableReasonNamesMissingRunsOfTheDecisivePeriod(): void
    {
        // První čtvrtletí po začátku vedení mezd: říjen a listopad bez běhu,
        // prosinec uzavřený s 21 odpracovanými dny.
        $december = ['worked_days' => 21] + $this->closedMonth('2026-12-01', 5_000_000, 10_080);
        $months = [
            ['period_start' => '2026-10-01'] + Derivation::monthFromRow(null, '2026-10-01'),
            ['period_start' => '2026-11-01'] + Derivation::monthFromRow(null, '2026-11-01'),
            $december,
        ];
        $probable = ['hourly_minor' => 29_762, 'rationale' => 'Syntetické odůvodnění.', 'term_id' => 3, 'effective_from' => '2026-12-01'];
        $suggestion = Derivation::combine($months, 21, false, $probable);
        self::assertSame('probable', $suggestion['source_kind']);

        self::assertSame(
            [
                'codes' => ['run_missing'],
                'missing_run_periods' => ['2026-10', '2026-11'],
                'worked_days' => 21,
                'minimum_worked_days' => 21,
            ],
            Derivation::probableReason($suggestion),
        );
    }

    public function testProbableReasonStatesWorkedDaysBelowTheLegalMinimum(): void
    {
        $months = [];
        foreach (['2026-10-01' => 6, '2026-11-01' => 7, '2026-12-01' => 7] as $periodStart => $days) {
            $months[] = ['worked_days' => $days] + $this->closedMonth($periodStart, 1_000_000, 2_880);
        }
        $probable = ['hourly_minor' => 20_000, 'rationale' => 'Syntetické odůvodnění.', 'term_id' => 3, 'effective_from' => '2026-10-01'];

        // 20 dnů: pod zákonným minimem 21 dnů se použije pravděpodobný výdělek.
        $below = Derivation::combine($months, 21, false, $probable);
        self::assertSame('probable', $below['source_kind']);
        self::assertSame(
            ['codes' => ['probable_earning_required'], 'missing_run_periods' => [], 'worked_days' => 20, 'minimum_worked_days' => 21],
            Derivation::probableReason($below),
        );

        // 21 dnů je už skutečný průměr: žádný důvod pro pravděpodobný výdělek.
        $months[2]['worked_days'] = 8;
        $actual = Derivation::combine($months, 21, false, $probable);
        self::assertSame('actual', $actual['source_kind']);
        self::assertNull(Derivation::probableReason($actual));
    }

    public function testProbableReasonIsNotOfferedForFaultyEvidence(): void
    {
        $months = [
            ['blockers' => ['time_month_not_approved']] + $this->closedMonth('2026-10-01', 1_000_000, 2_880),
            ['worked_days' => 20] + $this->closedMonth('2026-11-01', 1_000_000, 2_880),
            ['worked_days' => 20] + $this->closedMonth('2026-12-01', 1_000_000, 2_880),
        ];

        // Neschválená docházka je vadná evidence, ne důvod podle § 355.
        self::assertNull(Derivation::probableReason(Derivation::combine($months, 21, false, null)));
    }

    private function decisiveMonthsWithoutRuns(): array
    {
        $months = [];
        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $periodStart) {
            $months[] = ['period_start' => $periodStart] + Derivation::monthFromRow(null, $periodStart);
        }

        return $months;
    }

    /** @return array<string,mixed> */
    private function closedMonth(string $periodStart, int $grossMinor, int $workedMinutes): array
    {
        return [
            'period_start' => $periodStart,
            'blockers' => [],
            'run_id' => 2,
            'revision_id' => 7,
            'revision_no' => 6,
            'gross_earnings_minor' => $grossMinor,
            'worked_minutes' => $workedMinutes,
            'worked_days' => null,
            'work_summary_id' => 1,
            'work_summary_sha256' => str_repeat('a', 64),
            'result_hash' => str_repeat('b', 64),
            'time_month_row_version' => 1,
        ];
    }
}
