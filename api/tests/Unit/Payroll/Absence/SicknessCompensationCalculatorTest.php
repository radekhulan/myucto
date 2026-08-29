<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class SicknessCompensationCalculatorTest extends TestCase
{
    public function testCompensationIsRoundedUpToWholeCrowns(): void
    {
        $result = $this->calculator()->calculate(
            '2026-06-15',
            28_578,
            [[
                'shift_id' => 10,
                'local_date' => '2026-06-15',
                'planned_minutes' => 60,
                'eligible_minutes' => 60,
            ]],
        );

        // Přesná náhrada za jednu hodinu je 154,32… Kč. § 142 odst. 2 ZP ve
        // spojení s § 144 žádá celé koruny NAHORU, tedy 155 Kč — ne 154,32 Kč,
        // což je částka, kterou nelze vyplatit.
        self::assertSame(25_721, $result->reducedHourlyMinor);
        self::assertSame(15_500, $result->compensationMinor);
        self::assertSame('ceil-to-czk-on-period-total', $result->trace['compensation_rounding']);
        self::assertSame('manual_review', $result->supportStatus);
    }

    /**
     * Jádro nálezu V-12: dřív se zaokrouhlovalo PER SMĚNA, takže se chyba
     * násobila počtem směn a výsledek byl v haléřích.
     *
     * Deset osmihodinových… přesněji deset hodinových směn při průměru
     * 285,78 Kč/h: každá směna má přesnou náhradu 154,32… Kč. Staré chování
     * dalo 10 × 15 432 haléřů = 1 543,20 Kč — nevyplatitelnou částku. Zákon
     * žádá zaokrouhlit AŽ ÚHRN, a nahoru: 1 544 Kč.
     */
    public function testRoundingHappensOnTheTotalNotPerShift(): void
    {
        $segments = [];
        for ($day = 1; $day <= 10; $day++) {
            $segments[] = [
                'shift_id' => $day,
                'local_date' => sprintf('2026-06-%02d', $day),
                'planned_minutes' => 60,
                'eligible_minutes' => 60,
            ];
        }

        $result = $this->calculator()->calculate('2026-06-15', 28_578, $segments);

        self::assertSame(154_400, $result->compensationMinor);
        self::assertSame(0, $result->compensationMinor % 100);
        // Rozpis musí dát PŘESNĚ tutéž částku: do mzdy vstupuje součet segmentů
        // (PayrollSicknessInputMaterializer), ne pole `compensation_minor`.
        self::assertSame(
            $result->compensationMinor,
            array_sum(array_column($result->segments, 'compensation_minor')),
        );
    }

    /**
     * Neschopnost přes přelom měsíce: zaokrouhluje se KAŽDÉ výplatní období
     * zvlášť (§ 142 odst. 2 ZP mluví o zúčtování mzdy za období) a součet
     * segmentů v každém měsíci musí dát měsíční částku na haléř — přesně to,
     * co pak do mzdy načte materializace seskupená po měsících.
     */
    public function testEachCalendarMonthIsRoundedOnItsOwnAndSegmentsAddUp(): void
    {
        $segments = [];
        foreach (['2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02'] as $date) {
            $segments[] = [
                'shift_id' => null,
                'local_date' => $date,
                'planned_minutes' => 450,
                'eligible_minutes' => 450,
            ];
        }

        $result = $this->calculator()->calculate('2026-06-29', 28_578, $segments);

        $byMonth = [];
        foreach ($result->segments as $segment) {
            $month = substr((string) $segment['local_date'], 0, 7);
            $byMonth[$month] = ($byMonth[$month] ?? 0) + (int) $segment['compensation_minor'];
        }

        self::assertCount(2, $byMonth);
        foreach ($byMonth as $month => $amount) {
            self::assertSame(0, $amount % 100, "Měsíc {$month} musí být v celých korunách.");
        }
        self::assertSame($result->compensationMinor, array_sum($byMonth));
    }

    public function testAllThreeReductionBandsAreAppliedPerPublishedShift(): void
    {
        $result = $this->calculator()->calculate(
            '2026-06-15',
            50_000,
            [[
                'shift_id' => 11,
                'local_date' => '2026-06-15',
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ]],
        );

        self::assertSame(36_431, $result->reducedHourlyMinor);
        self::assertSame(174_900, $result->compensationMinor);
        self::assertSame(6_000, $result->trace['compensation_basis_points']);
    }

    public function testMissingPublishedShiftFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator()->calculate('2026-06-15', 50_000, []);
    }

    public function testCompensationWindowIsTracedFromRuleset(): void
    {
        $result = $this->calculator()->calculate(
            '2026-06-15',
            50_000,
            [[
                'shift_id' => 12,
                'local_date' => '2026-06-15',
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ]],
        );

        self::assertSame(14, $result->trace['window_calendar_days']);
    }

    public function testDateWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException::class);
        $this->calculator()->calculate(
            '2027-06-15',
            50_000,
            [[
                'shift_id' => 13,
                'local_date' => '2027-06-15',
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ]],
        );
    }

    public function testCalendarShiftWorksAsSoonAsNextYearRulesetExists(): void
    {
        $calculator = new SicknessCompensationCalculator(
            ShiftedYearPayrollRulesetFixture::provider(2027),
        );

        $result = $calculator->calculate(
            '2027-06-15',
            50_000,
            [[
                'shift_id' => 14,
                'local_date' => '2027-06-15',
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ]],
        );

        self::assertSame(36_431, $result->reducedHourlyMinor);
        self::assertSame(174_900, $result->compensationMinor);
    }

    private function calculator(): SicknessCompensationCalculator
    {
        return new SicknessCompensationCalculator(CzechPayrollRulesets2026::provider());
    }
}
