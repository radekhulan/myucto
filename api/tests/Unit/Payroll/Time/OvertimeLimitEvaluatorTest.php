<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\Overtime\OvertimeConsentWindow;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitAssessment;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitEvaluator;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitFinding;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimits;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeSegment;
use PHPUnit\Framework\TestCase;

/**
 * Limity přesčasové práce podle § 93 zákoníku práce.
 *
 * Hranice se testují ze tří stran (pod / přesně na / nad), protože „přesáhl"
 * v odst. 2 i odst. 4 znamená OSTŘE VÍC — přesně vyčerpaný limit ještě
 * porušením není a hlásit ho by znamenalo posílat mzdovou účetní řešit
 * neexistující vadu.
 */
final class OvertimeLimitEvaluatorTest extends TestCase
{
    private const WEEK_LIMIT_MINUTES = 480;
    private const YEAR_LIMIT_MINUTES = 9_000;

    public function testManualHistoryPreservesKnownProhibitedMinutes(): void
    {
        $known = (new OvertimeLimitEvaluator())->assess(
            7, '2026-02-01', '2026-02-28', [new OvertimeSegment('2026-02-09', 60)], [], $this->limits(),
            profile: new \MyInvoice\Service\Payroll\Time\Overtime\OvertimeEmploymentProfile('2010-01-01'),
        );
        self::assertNotEmpty($known->prohibitedMinutes);
        $unknown = $known->withManualSummaryPeriods(['2026-01-01'], '2026-01-01', '2026-02-28');
        self::assertSame($known->prohibitedMinutes, $unknown->prohibitedMinutes);
        self::assertSame($known->toArray()['prohibited_minutes'], $unknown->toArray()['prohibited_minutes']);
    }

    public function testManualHistoryDoesNotEraseAlreadyProvenViolation(): void
    {
        $known = $this->assess('2026-02-01', '2026-02-28', [['2026-02-09', 600]]);
        $unknown = $known->withManualSummaryPeriods(['2026-01-01'], '2026-01-01', '2026-02-28');
        self::assertContains(OvertimeLimitFinding::CODE_WEEKLY, $this->codes($unknown->findings));
        self::assertContains(OvertimeLimitFinding::CODE_MANUAL_SUMMARY, $this->codes($unknown->findings));
        self::assertNull($unknown->orderedYearMinutes);
        self::assertTrue($unknown->requiresOverride());
        self::assertSame(600, $known->orderedYearMinutes);
        self::assertArrayNotHasKey('evidence_status', $known->toArray());
    }

    public function testWeekBelowOrderedLimitIsSilent(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-09', 470],
        ]);

        self::assertSame([], $this->codes($assessment->findings));
    }

    public function testWeekExactlyAtOrderedLimitIsSilent(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-09', 300],
            ['2026-02-11', 180],
        ]);

        self::assertSame([], $this->codes($assessment->findings));
    }

    public function testWeekAboveOrderedLimitWarns(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-09', 300],
            ['2026-02-11', 181],
        ]);

        $findings = $this->of($assessment->findings, OvertimeLimitFinding::CODE_WEEKLY);
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]->severity);
        self::assertSame(481, $findings[0]->actualMinutes);
        self::assertSame(self::WEEK_LIMIT_MINUTES, $findings[0]->limitMinutes);
        self::assertSame('2026-02-09', $findings[0]->scopeFrom);
        self::assertSame('2026-02-15', $findings[0]->scopeTo);
        self::assertStringContainsString('§ 93 odst. 2', $findings[0]->message);
    }

    /**
     * Týden pondělí–neděle nerespektuje hranici měsíce. Přesčas rozdělený mezi
     * 30. a 31. březen a 1. duben je JEDEN týden, a jako jeden se musí posoudit —
     * jinak by stačilo směny rozložit přes přelom a limit by nikdy nepadl.
     */
    public function testWeekIsEvaluatedAcrossMonthBoundary(): void
    {
        $assessment = $this->assess('2026-04-01', '2026-04-30', [
            ['2026-03-30', 300],
            ['2026-03-31', 120],
            ['2026-04-01', 120],
        ]);

        $findings = $this->of($assessment->findings, OvertimeLimitFinding::CODE_WEEKLY);
        self::assertCount(1, $findings);
        self::assertSame(540, $findings[0]->actualMinutes);
        self::assertSame('2026-03-30', $findings[0]->scopeFrom);
    }

    public function testAnnualLimitBelowExactlyAtAndAbove(): void
    {
        $below = $this->assess('2026-12-01', '2026-12-31', $this->spread(2026, 8_940));
        self::assertNotContains(
            OvertimeLimitFinding::CODE_YEARLY,
            $this->codes($below->findings),
        );

        $exact = $this->assess('2026-12-01', '2026-12-31', $this->spread(2026, 9_000));
        self::assertNotContains(
            OvertimeLimitFinding::CODE_YEARLY,
            $this->codes($exact->findings),
        );
        self::assertSame(self::YEAR_LIMIT_MINUTES, $exact->orderedYearMinutes);

        $above = $this->assess('2026-12-01', '2026-12-31', $this->spread(2026, 9_060));
        $findings = $this->of($above->findings, OvertimeLimitFinding::CODE_YEARLY);
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]->severity);
        self::assertSame(9_060, $findings[0]->actualMinutes);
        self::assertStringContainsString('§ 93 odst. 2', $findings[0]->message);
    }

    /**
     * Roční limit je vázaný na KALENDÁŘNÍ rok (§ 93 odst. 2), takže se k 1. lednu
     * nuluje. Loňský přesčas se do letošního počítadla nesmí přenést.
     */
    public function testAnnualCounterResetsAcrossYearBoundary(): void
    {
        $segments = array_merge(
            $this->spread(2025, 9_600),
            [['2026-01-07', 240]],
        );
        $assessment = $this->assess('2026-01-01', '2026-01-31', $segments);

        self::assertSame(240, $assessment->orderedYearMinutes);
        self::assertNotContains(
            OvertimeLimitFinding::CODE_YEARLY,
            $this->codes($assessment->findings),
        );
    }

    public function testAnnualLimitAccumulatesAcrossMonths(): void
    {
        $segments = [];
        for ($month = 1; $month <= 11; ++$month) {
            $segments[] = [sprintf('2026-%02d-07', $month), 840];
        }
        $assessment = $this->assess('2026-11-01', '2026-11-30', $segments);

        self::assertSame(9_240, $assessment->orderedYearMinutes);
        self::assertContains(
            OvertimeLimitFinding::CODE_YEARLY,
            $this->codes($assessment->findings),
        );
    }

    public function testApproachingAnnualLimitIsInfoNotWarning(): void
    {
        $assessment = $this->assess('2026-10-01', '2026-10-31', $this->spread(2026, 7_400));

        $findings = $this->of(
            $assessment->findings,
            OvertimeLimitFinding::CODE_YEARLY_APPROACHING,
        );
        self::assertCount(1, $findings);
        self::assertSame('info', $findings[0]->severity);
    }

    /**
     * § 93 odst. 4 — průměr 8 h týdně ve vyrovnávacím období. 26 celých týdnů
     * dává strop 26 × 480 = 12 480 minut a poměřuje se s CELKOVÝM přesčasem,
     * tedy i s tím dohodnutým podle odst. 3.
     */
    public function testAveragingPeriodBelowExactlyAtAndAbove(): void
    {
        $consent = [new OvertimeConsentWindow('2020-01-01')];

        $below = $this->assess(
            '2026-06-01',
            '2026-06-30',
            $this->weekly('2025-12-29', 26, 470),
            $consent,
        );
        self::assertSame(26, $below->averagingWeeks);
        self::assertSame(12_480, $below->averagingLimitMinutes);
        self::assertNotContains(
            OvertimeLimitFinding::CODE_AVERAGING,
            $this->codes($below->findings),
        );

        $exact = $this->assess(
            '2026-06-01',
            '2026-06-30',
            $this->weekly('2025-12-29', 26, 480),
            $consent,
        );
        self::assertSame(12_480, $exact->averagingMinutes);
        self::assertNotContains(
            OvertimeLimitFinding::CODE_AVERAGING,
            $this->codes($exact->findings),
        );

        $above = $this->assess(
            '2026-06-01',
            '2026-06-30',
            array_merge($this->weekly('2025-12-29', 26, 480), [['2026-06-23', 1]]),
            $consent,
        );
        $findings = $this->of($above->findings, OvertimeLimitFinding::CODE_AVERAGING);
        self::assertCount(1, $findings);
        self::assertSame(12_481, $findings[0]->actualMinutes);
        self::assertStringContainsString('§ 93 odst. 4', $findings[0]->message);
    }

    /**
     * Na začátku pracovního poměru je okno kratší — strop musí klesnout s ním,
     * jinak by se zaměstnanec po dvou týdnech poměřoval s půlročním limitem
     * a kontrola by mlčela i u zjevného překročení.
     */
    public function testAveragingWindowIsShorterAtTheStartOfEmployment(): void
    {
        $assessment = $this->assess(
            '2026-02-01',
            '2026-02-28',
            [['2026-02-10', 700], ['2026-02-17', 700]],
            [new OvertimeConsentWindow('2020-01-01')],
            '2026-02-09',
        );

        self::assertSame('2026-02-09', $assessment->averagingFrom);
        self::assertSame('2026-02-22', $assessment->averagingTo);
        self::assertSame(2, $assessment->averagingWeeks);
        self::assertSame(960, $assessment->averagingLimitMinutes);
        self::assertContains(
            OvertimeLimitFinding::CODE_AVERAGING,
            $this->codes($assessment->findings),
        );
    }

    public function testAveragingIsSkippedBeforeTheFirstCompleteWeek(): void
    {
        $assessment = $this->assess(
            '2026-02-01',
            '2026-02-28',
            [['2026-02-24', 600]],
            [],
            '2026-02-23',
        );

        self::assertNull($assessment->averagingFrom);
        self::assertSame(0, $assessment->averagingWeeks);
        self::assertNotContains(
            OvertimeLimitFinding::CODE_AVERAGING,
            $this->codes($assessment->findings),
        );
    }

    /**
     * § 93 odst. 3 — s dohodou zaměstnance přestává být přesčas NAŘÍZENÝ, takže
     * se limity odst. 2 na něj nevztahují. Tentýž měsíc bez dohody vadu hlásí.
     */
    public function testConsentMovesOvertimeOutOfTheOrderedLimits(): void
    {
        $segments = array_merge($this->spread(2026, 9_600), [['2026-11-10', 600]]);

        $without = $this->assess('2026-11-01', '2026-11-30', $segments);
        self::assertContains(
            OvertimeLimitFinding::CODE_YEARLY,
            $this->codes($without->findings),
        );
        self::assertContains(
            OvertimeLimitFinding::CODE_WEEKLY,
            $this->codes($without->findings),
        );
        self::assertFalse($without->consentEvidenced);
        self::assertStringContainsString(
            'Souhlas zaměstnance',
            $this->of($without->findings, OvertimeLimitFinding::CODE_YEARLY)[0]->message,
        );

        $with = $this->assess(
            '2026-11-01',
            '2026-11-30',
            $segments,
            [new OvertimeConsentWindow('2026-01-01')],
        );
        self::assertSame([], $this->codes($with->findings));
        self::assertTrue($with->consentEvidenced);
        self::assertSame(0, $with->orderedYearMinutes);
        self::assertSame(10_200, $with->agreedYearMinutes);
    }

    /** Souhlas platí jen ve své době — po jeho skončení je přesčas zase nařízený. */
    public function testExpiredConsentNoLongerCoversOvertime(): void
    {
        $assessment = $this->assess(
            '2026-11-01',
            '2026-11-30',
            [['2026-11-10', 600]],
            [new OvertimeConsentWindow('2026-01-01', '2026-10-31')],
        );

        self::assertContains(
            OvertimeLimitFinding::CODE_WEEKLY,
            $this->codes($assessment->findings),
        );
        self::assertFalse($assessment->consentEvidenced);
    }

    /**
     * Klíčové rozhodnutí celé kontroly: nález § 93 NIKDY nesmí být `blocker` ani
     * varování vyžadující override, protože obojí zastaví příkaz `approve`, tedy
     * výplatu. Odpracovaný přesčas se podle § 114 platí i tehdy, když ho
     * zaměstnavatel nařídil nad zákonný rozsah.
     */
    public function testFindingsNeverBlockThePayout(): void
    {
        $assessment = $this->assess(
            '2026-11-01',
            '2026-11-30',
            array_merge($this->spread(2026, 12_000), [['2026-11-10', 900]]),
        );

        self::assertNotSame([], $assessment->findings);
        foreach ($assessment->findings as $finding) {
            self::assertContains($finding->severity, ['warning', 'info']);
            self::assertNotSame('blocker', $finding->severity);
        }
    }

    /**
     * @param list<array{0:string,1:int}> $segments
     * @param list<OvertimeConsentWindow> $consents
     */
    private function assess(
        string $periodStart,
        string $periodEnd,
        array $segments,
        array $consents = [],
        ?string $employmentStart = null,
    ): OvertimeLimitAssessment {
        return (new OvertimeLimitEvaluator())->assess(
            7,
            $periodStart,
            $periodEnd,
            array_map(
                static fn (array $row): OvertimeSegment => new OvertimeSegment($row[0], $row[1]),
                $segments,
            ),
            $consents,
            $this->limits(),
            $employmentStart,
        );
    }

    private function limits(): OvertimeLimits
    {
        return new OvertimeLimits(
            self::WEEK_LIMIT_MINUTES,
            self::YEAR_LIMIT_MINUTES,
            480,
            26,
            8_000,
            false,
        );
    }

    /**
     * Rozprostře minuty po jedné úterní dávce týdně tak, aby žádný jednotlivý
     * týden nepřekročil 8 h — testy ročního limitu tak nechytají týdenní nález.
     *
     * @return list<array{0:string,1:int}>
     */
    private function spread(int $year, int $minutes): array
    {
        $segments = [];
        $cursor = new \DateTimeImmutable(sprintf('%d-01-06', $year));
        while ($minutes > 0) {
            $chunk = min(240, $minutes);
            $segments[] = [$cursor->format('Y-m-d'), $chunk];
            $minutes -= $chunk;
            $cursor = $cursor->modify('+7 days');
        }

        return $segments;
    }

    /** @return list<array{0:string,1:int}> */
    private function weekly(string $firstMonday, int $weeks, int $minutes): array
    {
        $segments = [];
        $cursor = new \DateTimeImmutable($firstMonday);
        for ($index = 0; $index < $weeks; ++$index) {
            $segments[] = [$cursor->format('Y-m-d'), $minutes];
            $cursor = $cursor->modify('+7 days');
        }

        return $segments;
    }

    /**
     * @param list<OvertimeLimitFinding> $findings
     * @return list<string>
     */
    private function codes(array $findings): array
    {
        return array_map(
            static fn (OvertimeLimitFinding $finding): string => $finding->code,
            $findings,
        );
    }

    /**
     * @param list<OvertimeLimitFinding> $findings
     * @return list<OvertimeLimitFinding>
     */
    private function of(array $findings, string $code): array
    {
        return array_values(array_filter(
            $findings,
            static fn (OvertimeLimitFinding $finding): bool => $finding->code === $code,
        ));
    }
}
