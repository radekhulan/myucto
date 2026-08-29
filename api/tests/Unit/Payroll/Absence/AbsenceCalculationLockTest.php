<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AverageEarningCalculator;
use MyInvoice\Service\Payroll\Absence\LeaveEntitlementCalculator;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * Zamykací test MZ-07-W07. Zákonná čísla (60 % náhrada, 14denní okno, 21 dnů,
 * 28 dnů / 4 týdny / 52 násobků / 1200 minut) se stěhovala z literálů do
 * rulesetu — přesun, ne změna. Očekávané hodnoty jsou pořízené z chování PŘED
 * refaktoringem a nesmí se pohnout ani o haléř.
 */
final class AbsenceCalculationLockTest extends TestCase
{
    /**
     * ⚠ Zámek u DPN se VĚDOMĚ posunul (W28 / V-12). Do té doby se zaokrouhlovalo
     * matematicky (HalfUp) na haléře, a to PER SMĚNU. To odporovalo § 142 odst. 2
     * ZP ve spojení s § 144 („Mzda nebo plat se zaokrouhlují na celé koruny
     * směrem nahoru." — přes § 144 i na náhradu mzdy): vznikal systematický
     * nedoplatek a haléřová částka, kterou nelze vyplatit.
     *
     * Nové hodnoty jsou proto vždy NAHORU a vždy v celých korunách; redukovaný
     * hodinový výdělek je jen položka do stopy výpočtu (§ 192 odst. 2 ZP jeho
     * zaokrouhlení vůbec neupravuje) a aritmetika běží na přesném zlomku.
     *
     * Kontrolní srovnání starých a nových hodnot:
     * 15 432 → 15 500 · 174 869 → 174 900 · 335 637 → 335 700 ·
     * 51 222 → 51 300 · 678 345 → 678 400. Všechny rozdíly jsou pod 1 Kč
     * a ve prospěch zaměstnance.
     *
     * @return iterable<string,array{string,int,list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>,int,int}>
     */
    public static function sicknessCases(): iterable
    {
        yield 'první redukční hranice, jedna hodina' => [
            '2026-06-15',
            28_578,
            [self::segment(10, '2026-06-15', 60, 60)],
            25_721,
            15_500,
        ];
        yield 'všechna tři pásma, osmihodinová směna' => [
            '2026-06-15',
            50_000,
            [self::segment(11, '2026-06-15', 480, 480)],
            36_431,
            174_900,
        ];
        yield 'tři směny včetně částečně započitatelné' => [
            '2026-01-01',
            100_000,
            [
                self::segment(1, '2026-01-01', 480, 480),
                self::segment(2, '2026-01-02', 450, 225),
                self::segment(3, '2026-01-03', 300, 7),
            ],
            47_141,
            335_700,
        ];
        yield 'nízký průměr pod první hranicí, směna bez ID' => [
            '2026-12-31',
            12_345,
            [self::segment(null, '2026-12-31', 461, 461)],
            11_111,
            51_300,
        ];
        yield 'průměr na třetí hranici' => [
            '2026-03-09',
            85_698,
            [self::segment(7, '2026-03-09', 1_440, 1_439)],
            47_141,
            678_400,
        ];
    }

    /**
     * § 142 odst. 2 ve spojení s § 144 ZP: náhrada mzdy se zaokrouhluje na celé
     * koruny NAHORU. Haléřová částka je nevyplatitelná, a zaokrouhlení dolů
     * (nebo matematické) by bylo krácení bez opory v zákoně.
     *
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sicknessCases')]
    public function testDpnCompensationIsWholeCrownsRoundedUp(
        string $date,
        int $averageHourlyMinor,
        array $segments,
        int $expectedReducedHourlyMinor,
        int $expectedCompensationMinor,
    ): void {
        $result = (new SicknessCompensationCalculator(
            CzechPayrollRulesets2026::provider(),
        ))->calculate($date, $averageHourlyMinor, $segments);

        self::assertSame(0, $result->compensationMinor % 100);
        self::assertSame('ceil-to-czk-on-period-total', $result->trace['compensation_rounding']);
        // Zaokrouhluje se nahoru, tedy nikdy o víc než o korunu.
        $exactUpperBound = $expectedCompensationMinor;
        $exactLowerBound = $expectedCompensationMinor - 100;
        self::assertGreaterThan($exactLowerBound, $result->compensationMinor);
        self::assertLessThanOrEqual($exactUpperBound, $result->compensationMinor);
        self::assertSame($expectedReducedHourlyMinor, $result->reducedHourlyMinor);
    }

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sicknessCases')]
    public function testDpnCompensationIsUnchanged(
        string $date,
        int $averageHourlyMinor,
        array $segments,
        int $expectedReducedHourlyMinor,
        int $expectedCompensationMinor,
    ): void {
        $result = (new SicknessCompensationCalculator(
            CzechPayrollRulesets2026::provider(),
        ))->calculate(
            $date,
            $averageHourlyMinor,
            $segments,
        );

        self::assertSame($expectedReducedHourlyMinor, $result->reducedHourlyMinor);
        self::assertSame($expectedCompensationMinor, $result->compensationMinor);
        self::assertSame(6_000, $result->trace['compensation_basis_points']);
        self::assertSame('manual_review', $result->supportStatus);
    }

    /** @return iterable<string,array{int,int,int,int,?int,?string,string,int}> */
    public static function averageCases(): iterable
    {
        yield 'skutečný průměr z celého čtvrtletí' => [
            12_000_000, 0, 9_600, 60, null, null, 'actual', 75_000,
        ];
        yield 'skutečný průměr přesně na hranici 21 dnů' => [
            7_777_777, 123_456, 10_001, 21, null, null, 'actual', 47_403,
        ];
        yield 'pravděpodobný výdělek pod hranicí 21 dnů' => [
            2_000_000, 0, 1_200, 20, 42_500, 'Srovnatelná práce.', 'probable', 42_500,
        ];
        yield 'skutečný průměr s dopočtem delšího období' => [
            5_000_000, 250_000, 8_640, 45, null, null, 'actual', 36_458,
        ];
        // Vydělený průměr je 0,60 Kč za hodinu, tedy hluboko pod minimální mzdou.
        // Zámek proto od doplnění § 357 odst. 1 ZP drží zvednutou hodnotu —
        // 60 haléřů byl výsledek dělení, ne průměrný výdělek.
        yield 'minimální nenulové vstupy zvedne minimální mzda' => [
            1, 0, 1, 21, null, null, 'actual', 13_440,
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('averageCases')]
    public function testAverageEarningIsUnchanged(
        int $grossEarningsMinor,
        int $longerPeriodAllocatedMinor,
        int $workedMinutes,
        int $workedDays,
        ?int $probableHourlyMinor,
        ?string $probableRationale,
        string $expectedSourceKind,
        int $expectedHourlyMinor,
    ): void {
        $result = (new AverageEarningCalculator(
            CzechPayrollRulesets2026::provider(),
        ))->calculate(
            '2026-04-01',
            $grossEarningsMinor,
            $longerPeriodAllocatedMinor,
            $workedMinutes,
            $workedDays,
            $probableHourlyMinor,
            $probableRationale,
        );

        self::assertSame($expectedSourceKind, $result->sourceKind);
        self::assertSame($expectedHourlyMinor, $result->averageHourlyMinor);
    }

    /** @return iterable<string,array{string,int,int,int,int,int,int,int}> */
    public static function leaveCases(): iterable
    {
        yield 'celoroční HPP se čtyřtýdenní výměrou' => [
            'employment', 2_400, 4, 365, 124_800, 2_400, 52, 9_600,
        ];
        yield 'DPP s fikcí dvacetihodinového týdne' => [
            'dpp', 2_400, 4, 365, 62_400, 1_200, 52, 4_800,
        ];
        yield 'celoroční HPP s pětitýdenní firemní výměrou' => [
            'employment', 2_400, 5, 365, 124_800, 2_400, 52, 12_000,
        ];
        yield 'kratší úvazek a nedokončený rok' => [
            'employment', 1_875, 4, 100, 30_000, 1_875, 16, 2_340,
        ];
        yield 'DPČ s fikcí dvacetihodinového týdne' => [
            'dpc', 3_000, 4, 200, 25_000, 1_200, 20, 1_860,
        ];
        yield 'odpracováno nad rámec roku, strop 52 násobků' => [
            'employment', 2_400, 4, 365, 134_400, 2_400, 52, 9_600,
        ];
        yield 'zaměstnání malého rozsahu se šestitýdenní výměrou' => [
            'small_scale_employment', 1_200, 6, 200, 33_600, 1_200, 28, 3_900,
        ];
        yield 'statutár s netriviálním zaokrouhlením na celé hodiny' => [
            'statutory_body', 2_401, 4, 365, 124_852, 2_401, 52, 9_660,
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('leaveCases')]
    public function testLeaveEntitlementIsUnchanged(
        string $relationType,
        int $weeklyMinutes,
        int $entitlementWeeks,
        int $continuousCalendarDays,
        int $workedEquivalentMinutes,
        int $expectedEffectiveWeeklyMinutes,
        int $expectedWorkedWeekMultiples,
        int $expectedEntitlementMinutes,
    ): void {
        $result = (new LeaveEntitlementCalculator(
            CzechPayrollRulesets2026::provider(),
        ))->calculate(
            '2026-01-01',
            $relationType,
            $weeklyMinutes,
            $entitlementWeeks,
            $continuousCalendarDays,
            $workedEquivalentMinutes,
            'Ruční posouzení započitatelných dob.',
        );

        self::assertSame($expectedEffectiveWeeklyMinutes, $result->weeklyMinutes);
        self::assertSame($expectedWorkedWeekMultiples, $result->workedWeekMultiples);
        self::assertSame($expectedEntitlementMinutes, $result->entitlementMinutes);
    }

    /** @return array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int} */
    private static function segment(
        ?int $shiftId,
        string $localDate,
        int $plannedMinutes,
        int $eligibleMinutes,
    ): array {
        return [
            'shift_id' => $shiftId,
            'local_date' => $localDate,
            'planned_minutes' => $plannedMinutes,
            'eligible_minutes' => $eligibleMinutes,
        ];
    }
}
