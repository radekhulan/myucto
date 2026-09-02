<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\MonthlyWageProration;
use PHPUnit\Framework\TestCase;

/**
 * Krácení měsíční mzdy podle NAPLÁNOVANÝCH HODIN, ne podle pracovních dnů.
 *
 * Test drží obě tvrzení rozhodnutí: poměr se počítá z fondu individuálního
 * rozvrhu a každá naplánovaná minuta je vyplacena právě jedním titulem.
 */
final class MonthlyWageProrationTest extends TestCase
{
    public function testWholeMonthWorkedKeepsAgreedAmount(): void
    {
        $result = MonthlyWageProration::calculate(2_200_000, 168 * 60, []);

        self::assertSame(2_200_000, $result->amountMinor);
        self::assertFalse($result->isProrated());
        self::assertSame(168 * 60, $result->retainedMinutes);
    }

    /**
     * Deset z jednadvaceti stejně dlouhých směn zameškáno: základní mzda
     * náleží za jedenáct směn. 22 000 × 11 / 21 = 11 523,809… → 11 524 Kč
     * (§ 142 odst. 2 ZP zaokrouhluje nahoru).
     */
    public function testEqualShiftsProrateToWholeCrownsUpwards(): void
    {
        $result = MonthlyWageProration::calculate(
            2_200_000,
            21 * 8 * 60,
            ['sickness_compensation' => 10 * 8 * 60],
        );

        self::assertSame(1_152_400, $result->amountMinor);
        self::assertTrue($result->isProrated());
        self::assertSame(11 * 8 * 60, $result->retainedMinutes);
    }

    /**
     * Jádro rozhodnutí. Deset zameškaných DNŮ dá při nerovnoměrném rozvržení
     * jinou mzdu než deset jiných dnů téhož měsíce — poměr dnů by obojí
     * ocenil stejně, poměr hodin je rozliší.
     */
    public function testUnevenScheduleIsDrivenByHoursNotByDays(): void
    {
        $fund = (10 * 12 + 11 * 4) * 60;

        $longShifts = MonthlyWageProration::calculate(
            2_200_000,
            $fund,
            ['unpaid' => 10 * 12 * 60],
        );
        $shortShifts = MonthlyWageProration::calculate(
            2_200_000,
            $fund,
            ['unpaid' => 10 * 4 * 60],
        );

        self::assertNotSame($longShifts->amountMinor, $shortShifts->amountMinor);
        self::assertLessThan($shortShifts->amountMinor, $longShifts->amountMinor);
    }

    /** Zkrácený úvazek nemění princip: dělí se JEHO fondem, ne obecným. */
    public function testPartTimeUsesItsOwnFund(): void
    {
        $result = MonthlyWageProration::calculate(
            2_200_000,
            21 * 4 * 60,
            ['vacation' => 21 * 2 * 60],
        );

        self::assertSame(1_100_000, $result->amountMinor);
    }

    public function testTitlesAreSummedAndKeptInTheTrace(): void
    {
        $result = MonthlyWageProration::calculate(
            3_000_000,
            160 * 60,
            ['vacation' => 40 * 60, 'sickness_compensation' => 16 * 60, 'unpaid' => 0],
        );

        self::assertSame(56 * 60, $result->replacedMinutes);
        self::assertSame(104 * 60, $result->retainedMinutes);
        self::assertSame(1_950_000, $result->amountMinor);

        $trace = $result->trace();
        self::assertSame('monthly_wage_proration.v1', $trace['kind']);
        self::assertSame('zp-142-2', $trace['rounding_basis']);
        self::assertSame(
            ['vacation' => 40 * 60, 'sickness_compensation' => 16 * 60, 'unpaid' => 0],
            $trace['replaced_minutes_by_title'],
        );
    }

    public function testFullMonthAbsenceLeavesNothingInTheBaseWage(): void
    {
        $result = MonthlyWageProration::calculate(
            2_200_000,
            168 * 60,
            ['sickness_compensation' => 168 * 60],
        );

        self::assertSame(0, $result->amountMinor);
        self::assertSame(0, $result->retainedMinutes);
    }

    /**
     * Hodinovou hodnotu měsíční mzdy určuje SKUTEČNÁ povinnost měsíce, ne
     * povinnost plus svátky. Zaměstnanec s 22 rozvrženými dny (176 h) a jedním
     * svátkem, na který padla nemoc, přijde přesně o cenu osmi hodin:
     * 42 000 x 168/176 = 40 090,90 → 40 091 Kč. Kdyby svátek naředil jmenovatel
     * na 184 h, vyšlo by 40 174 Kč a tatáž doba by byla zaplacená dvakrát.
     */
    public function testHolidayInsideSicknessIsPricedByTheObligationFund(): void
    {
        $result = MonthlyWageProration::calculate(
            4_200_000,
            176 * 60,
            ['sickness_compensation' => 8 * 60],
        );

        self::assertSame(4_009_100, $result->amountMinor);
    }

    /**
     * Svátek uvnitř okna DPN se proplácí náhradou (§ 192 odst. 1 ZP), ale ve
     * fondu není — mzda se za svátek jinak nekrátí (§ 115 odst. 3 ZP). Nahrazené
     * minuty proto smějí fond přesáhnout; v základní mzdě pak nezbývá nic
     * a zbytek kryje sama náhrada.
     */
    public function testHolidayPaidBySicknessMayExceedTheFundAndLeavesNothing(): void
    {
        $result = MonthlyWageProration::calculate(
            2_200_000,
            168 * 60,
            ['sickness_compensation' => 176 * 60],
        );

        self::assertSame(0, $result->retainedMinutes);
        self::assertSame(0, $result->amountMinor);
    }

    public function testEmptyFundIsRefusedInsteadOfDividingByZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MonthlyWageProration::calculate(2_200_000, 0, []);
    }

    public function testNegativeReplacedMinutesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MonthlyWageProration::calculate(2_200_000, 100, ['unpaid' => -1]);
    }
}
