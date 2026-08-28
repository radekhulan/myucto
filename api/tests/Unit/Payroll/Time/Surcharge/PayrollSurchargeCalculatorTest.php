<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time\Surcharge;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeBasis;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCalculator;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeSegment;
use PHPUnit\Framework\TestCase;

/**
 * Zákonné sazby § 114 až § 118 nad hodinovým průměrem 500 Kč (50 000 haléřů),
 * aby se dalo počítat zpaměti a nálezy se daly ověřit ručně.
 */
final class PayrollSurchargeCalculatorTest extends TestCase
{
    private const AVERAGE_HOURLY_MINOR = 50_000;

    /** Základní sazba minimální mzdy 2026: 134,40 Kč/h. */
    private const MINIMUM_WAGE_HOURLY_MINOR = 13_440;

    public function testNightSurchargeIsTenPercentOfAverageEarning(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-06-15', 480),
        ]);

        // 500 Kč/h × 10 % × 8 h = 400 Kč.
        self::assertSame(40_000, $result->amountFor(PayrollSurchargeKind::Night));
        $line = $result->lineFor(PayrollSurchargeKind::Night);
        self::assertNotNull($line);
        self::assertSame(PayrollSurchargeBasis::AverageEarning, $line->basis);
        self::assertSame(self::AVERAGE_HOURLY_MINOR, $line->basisHourlyMinor);
        self::assertFalse($line->rateIsAgreed);
    }

    public function testOvertimeSurchargeIsTwentyFivePercentOfAverageEarning(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Overtime, '2026-06-15', 480),
        ]);

        // 500 Kč/h × 25 % × 8 h = 1 000 Kč.
        self::assertSame(100_000, $result->amountFor(PayrollSurchargeKind::Overtime));
    }

    public function testHolidaySurchargeIsTheWholeAverageEarning(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Holiday, '2026-07-05', 480),
        ]);

        // § 115 odst. 2 — nejméně ve výši průměrného výdělku, tedy 100 %.
        self::assertSame(400_000, $result->amountFor(PayrollSurchargeKind::Holiday));
    }

    public function testWeekendSurchargeIsTenPercentOfAverageEarning(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Weekend, '2026-07-04', 480),
        ]);

        self::assertSame(40_000, $result->amountFor(PayrollSurchargeKind::Weekend));
    }

    /**
     * § 117 se NEPOČÍTÁ z průměrného výdělku. Test to hlídá tak, že výsledek
     * musí sedět na minimální mzdu — kdyby se do vzorce dostal průměrný výdělek,
     * vyjde skoro čtyřnásobek.
     */
    public function testDifficultEnvironmentSurchargeUsesMinimumWageNotAverageEarning(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(
                PayrollSurchargeKind::DifficultEnvironment,
                '2026-06-15',
                480,
            ),
        ]);

        // 134,40 Kč/h × 10 % × 8 h = 107,52 Kč.
        self::assertSame(10_752, $result->amountFor(PayrollSurchargeKind::DifficultEnvironment));
        $line = $result->lineFor(PayrollSurchargeKind::DifficultEnvironment);
        self::assertNotNull($line);
        self::assertSame(PayrollSurchargeBasis::MinimumWageHourly, $line->basis);
        self::assertSame(self::MINIMUM_WAGE_HOURLY_MINOR, $line->basisHourlyMinor);
    }

    /** § 117 přiznává příplatek za KAŽDÝ ztěžující vliv. */
    public function testDifficultEnvironmentMultipliesByEachAggravatingFactor(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(
                PayrollSurchargeKind::DifficultEnvironment,
                '2026-06-15',
                480,
                3,
            ),
        ]);

        self::assertSame(32_256, $result->amountFor(PayrollSurchargeKind::DifficultEnvironment));
        $line = $result->lineFor(PayrollSurchargeKind::DifficultEnvironment);
        self::assertNotNull($line);
        self::assertSame(480, $line->minutes);
        self::assertSame(1_440, $line->weightedMinutes);
    }

    /**
     * Přesčas v noci o víkendu — tři nároky vedle sebe, žádný nevylučuje ostatní.
     * Kategorie docházky jsou překryvné příznaky nad TÝMIŽ osmi hodinami.
     */
    public function testOvertimeAtNightOnAWeekendYieldsThreeSurchargesSideBySide(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Overtime, '2026-07-04', 480),
            new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-07-04', 480),
            new PayrollSurchargeSegment(PayrollSurchargeKind::Weekend, '2026-07-04', 480),
        ]);

        self::assertSame(100_000, $result->amountFor(PayrollSurchargeKind::Overtime));
        self::assertSame(40_000, $result->amountFor(PayrollSurchargeKind::Night));
        self::assertSame(40_000, $result->amountFor(PayrollSurchargeKind::Weekend));
        self::assertSame(180_000, $result->totalMinor);
        self::assertCount(3, $result->lines);
    }

    /** Svátek, který padne na neděli, nese § 115 i § 118 — ani jeden nevylučuje druhý. */
    public function testHolidayFallingOnASundayAlsoEarnsTheWeekendSurcharge(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Holiday, '2026-07-05', 480),
            new PayrollSurchargeSegment(PayrollSurchargeKind::Weekend, '2026-07-05', 480),
        ]);

        self::assertSame(440_000, $result->totalMinor);
    }

    /**
     * Zaokrouhluje se JEDNOU za měsíc z jednoho zlomku, ne po hodinách.
     * Dvacet zápisů po sedmi minutách musí dát totéž co jeden zápis na 140 minut;
     * kdyby se zaokrouhlovalo po dnech, chyba by se nasčítala.
     */
    public function testRoundingHappensOnceOverTheWholeMonth(): void
    {
        $segments = [];
        for ($day = 1; $day <= 20; $day++) {
            $segments[] = new PayrollSurchargeSegment(
                PayrollSurchargeKind::Night,
                sprintf('2026-06-%02d', $day),
                7,
            );
        }
        $split = $this->calculate($segments, 33_333);
        $single = $this->calculate(
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-06-01', 140)],
            33_333,
        );

        // 33 333 × 140 / 600 = 7 777,7 → 7 778 haléřů.
        self::assertSame(7_778, $split->amountFor(PayrollSurchargeKind::Night));
        self::assertSame(
            $single->amountFor(PayrollSurchargeKind::Night),
            $split->amountFor(PayrollSurchargeKind::Night),
        );
    }

    public function testTraceCarriesTheUnroundedFractionAndTheHourlySurcharge(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-06-15', 7),
        ], 33_333);

        $line = $result->lineFor(PayrollSurchargeKind::Night);
        self::assertNotNull($line);
        self::assertSame(33_333 * 7, $line->unroundedNumerator);
        self::assertSame(600, $line->unroundedDenominator);
        self::assertSame(389, $line->amountMinor);
        // Hodinová sazba příplatku: 333,33 Kč × 10 % = 33,33 Kč.
        self::assertSame(3_333, $line->hourlySurchargeStep->outputMinorUnits);
        self::assertSame(33_333, $line->hourlySurchargeStep->inputMinorUnits);
    }

    /** Kratší úvazek nesmí sazbu § 117 zvednout — základem je základní sazba. */
    public function testShorterWeeklyHoursDoNotInflateTheDifficultEnvironmentSurcharge(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(
                PayrollSurchargeKind::DifficultEnvironment,
                '2026-06-15',
                240,
            ),
        ]);

        self::assertSame(5_376, $result->amountFor(PayrollSurchargeKind::DifficultEnvironment));
    }

    public function testAgreedHigherRateIsUsedInsteadOfTheStatutoryMinimum(): void
    {
        $ruleset = PayrollSurchargeRuleset::forDate(
            CzechPayrollRulesets2026::provider(),
            '2026-06-01',
        );
        $policy = PayrollSurchargePolicy::agreed(
            \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode::Surcharge,
            \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode::Surcharge,
            null,
            [PayrollSurchargeKind::Night->value => 2_000],
            $ruleset,
        );
        $result = (new PayrollSurchargeCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            '2026-06-01',
            self::AVERAGE_HOURLY_MINOR,
            $policy,
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-06-15', 480)],
        );

        self::assertSame(80_000, $result->amountFor(PayrollSurchargeKind::Night));
        self::assertTrue($result->lineFor(PayrollSurchargeKind::Night)?->rateIsAgreed);
    }

    public function testMissingAverageEarningFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/průměrného výdělku/');
        $this->calculate(
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-06-15', 480)],
            0,
        );
    }

    /**
     * § 117 na průměrném výdělku nestojí, takže se spočítá i tehdy, když průměr
     * ještě zjištěný není. Opačné chování by blokovalo nárok, který na výdělku
     * vůbec nezávisí.
     */
    public function testDifficultEnvironmentIsCalculableWithoutAnAverageEarning(): void
    {
        $result = $this->calculate(
            [new PayrollSurchargeSegment(
                PayrollSurchargeKind::DifficultEnvironment,
                '2026-06-15',
                480,
            )],
            0,
        );

        self::assertSame(10_752, $result->amountFor(PayrollSurchargeKind::DifficultEnvironment));
    }

    public function testEmptyEvidenceProducesNoLinesAndNoAmount(): void
    {
        $result = $this->calculate([]);

        self::assertSame(0, $result->totalMinor);
        self::assertSame([], $result->lines);
        self::assertSame([], $result->componentAmounts());
    }

    public function testEachSurchargeMapsToItsOwnPayrollComponent(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Overtime, '2026-07-04', 480),
            new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-07-04', 480),
        ]);

        self::assertSame(
            ['PRIPLATEK_PRESCAS' => 100_000, 'PRIPLATEK_NOCNI' => 40_000],
            $result->componentAmounts(),
        );
    }

    public function testResultCarriesTheRulesetIdentityForTheAuditTrail(): void
    {
        $result = $this->calculate([
            new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-06-15', 60),
        ]);

        self::assertSame('cz-payroll-2026.compensation-averages.v1', $result->rulesetId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->rulesetContentHash);
        self::assertSame('manual_review', $result->supportStatus);
    }

    public function testPeriodWithoutARulesetFailsClosed(): void
    {
        $this->expectException(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException::class);
        (new PayrollSurchargeCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            '2031-06-01',
            self::AVERAGE_HOURLY_MINOR,
            PayrollSurchargePolicy::statutoryDefault(),
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2031-06-15', 480)],
        );
    }

    /** @param list<PayrollSurchargeSegment> $segments */
    private function calculate(
        array $segments,
        int $averageHourlyMinor = self::AVERAGE_HOURLY_MINOR,
    ): \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeResult {
        return (new PayrollSurchargeCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            '2026-06-01',
            $averageHourlyMinor,
            PayrollSurchargePolicy::statutoryDefault(),
            $segments,
        );
    }
}
