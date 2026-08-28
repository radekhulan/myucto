<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time\Surcharge;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeBasis;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use PHPUnit\Framework\TestCase;

final class PayrollSurchargePolicyTest extends TestCase
{
    /**
     * § 114 odst. 1 dává jako výchozí PŘÍPLATEK, § 115 odst. 1 naopak NÁHRADNÍ
     * VOLNO. Prohodit to je nejsnazší chyba celého balíku a stojí buď nedoplatek,
     * nebo výplatu bez právního důvodu.
     */
    public function testStatutoryDefaultsDifferBetweenOvertimeAndHoliday(): void
    {
        $policy = PayrollSurchargePolicy::statutoryDefault();

        self::assertSame(
            PayrollSurchargeCompensationMode::Surcharge,
            $policy->mode(PayrollSurchargeKind::Overtime),
        );
        self::assertSame(
            PayrollSurchargeCompensationMode::CompensatoryTimeOff,
            $policy->mode(PayrollSurchargeKind::Holiday),
        );
        self::assertTrue($policy->isStatutoryDefault);
        self::assertNull($policy->difficultEnvironmentFactors);
    }

    /** U noční práce a víkendu se podle § 116 a § 118 smí sjednat i nižší sazba. */
    public function testNightAndWeekendMayAgreeALowerRate(): void
    {
        $policy = $this->agreed([
            PayrollSurchargeKind::Night->value => 500,
            PayrollSurchargeKind::Weekend->value => 500,
        ]);

        self::assertSame(500, $policy->agreedRateBasisPoints(PayrollSurchargeKind::Night));
        self::assertSame(
            '0.05',
            $policy->effectiveRate(PayrollSurchargeKind::Night, $this->ruleset())['rate']
                ->toCanonicalString(),
        );
    }

    /**
     * § 114, § 115 a § 117 mají kogentní „nejméně" — pod zákonné minimum se
     * sjednat nedá a odmítnout to musí aplikace, protože databáze zákonné
     * minimum nezná.
     */
    public function testOvertimeMayNotAgreeALowerRateThanTheStatutoryMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nesmí být nižší/');
        $this->agreed([PayrollSurchargeKind::Overtime->value => 2_499]);
    }

    public function testHolidayMayNotAgreeALowerRateThanTheStatutoryMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->agreed([PayrollSurchargeKind::Holiday->value => 9_999]);
    }

    public function testDifficultEnvironmentMayNotAgreeALowerRate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->agreed([PayrollSurchargeKind::DifficultEnvironment->value => 999]);
    }

    public function testExactlyTheStatutoryMinimumIsAccepted(): void
    {
        $policy = $this->agreed([
            PayrollSurchargeKind::Overtime->value => 2_500,
            PayrollSurchargeKind::Holiday->value => 10_000,
            PayrollSurchargeKind::DifficultEnvironment->value => 1_000,
        ]);

        self::assertSame(2_500, $policy->agreedRateBasisPoints(PayrollSurchargeKind::Overtime));
    }

    public function testHigherAgreedRateIsAlwaysAllowed(): void
    {
        $policy = $this->agreed([PayrollSurchargeKind::Holiday->value => 15_000]);

        self::assertSame(
            '1.5',
            $policy->effectiveRate(PayrollSurchargeKind::Holiday, $this->ruleset())['rate']
                ->toCanonicalString(),
        );
    }

    /** Mzda sjednaná s přihlédnutím k práci ve svátek neexistuje. */
    public function testHolidayCannotBeIncludedInTheAgreedWage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::IncludedInWage,
            null,
            [],
            $this->ruleset(),
        );
    }

    public function testWithoutAnAgreedRateTheStatutoryMinimumApplies(): void
    {
        $effective = PayrollSurchargePolicy::statutoryDefault()
            ->effectiveRate(PayrollSurchargeKind::Night, $this->ruleset());

        self::assertSame('0.1', $effective['rate']->toCanonicalString());
        self::assertFalse($effective['agreed']);
    }

    public function testBasisPointsConvertWithoutLosingPrecision(): void
    {
        self::assertSame(
            '0.2525',
            PayrollSurchargePolicy::rateFromBasisPoints(2_525)->toCanonicalString(),
        );
        self::assertSame(
            '1',
            PayrollSurchargePolicy::rateFromBasisPoints(10_000)->toCanonicalString(),
        );
    }

    /** § 117 je jediný, který nestojí na průměrném výdělku. */
    public function testOnlyDifficultEnvironmentUsesTheMinimumWageAsItsBasis(): void
    {
        $ruleset = $this->ruleset();
        foreach (PayrollSurchargeKind::all() as $kind) {
            $expected = $kind === PayrollSurchargeKind::DifficultEnvironment
                ? PayrollSurchargeBasis::MinimumWageHourly
                : PayrollSurchargeBasis::AverageEarning;
            self::assertSame($expected, $ruleset->basis($kind), $kind->value);
        }
    }

    /** Náhradní volno zná zákon jen u přesčasu a u svátku. */
    public function testOnlyOvertimeAndHolidayKnowCompensatoryTimeOff(): void
    {
        self::assertTrue(PayrollSurchargeKind::Overtime->allowsCompensatoryTimeOff());
        self::assertTrue(PayrollSurchargeKind::Holiday->allowsCompensatoryTimeOff());
        self::assertFalse(PayrollSurchargeKind::Night->allowsCompensatoryTimeOff());
        self::assertFalse(PayrollSurchargeKind::Weekend->allowsCompensatoryTimeOff());
        self::assertFalse(PayrollSurchargeKind::DifficultEnvironment->allowsCompensatoryTimeOff());
    }

    public function testStatutoryRatesComeFromTheRuleset(): void
    {
        $ruleset = $this->ruleset();

        self::assertSame('0.25', $ruleset->statutoryRate(PayrollSurchargeKind::Overtime)
            ->toCanonicalString());
        self::assertSame('1', $ruleset->statutoryRate(PayrollSurchargeKind::Holiday)
            ->toCanonicalString());
        self::assertSame('0.1', $ruleset->statutoryRate(PayrollSurchargeKind::Night)
            ->toCanonicalString());
        self::assertSame('0.1', $ruleset->statutoryRate(PayrollSurchargeKind::Weekend)
            ->toCanonicalString());
        self::assertSame('0.1', $ruleset->statutoryRate(PayrollSurchargeKind::DifficultEnvironment)
            ->toCanonicalString());
        self::assertSame(22, $ruleset->nightWindowStartHour());
        self::assertSame(6, $ruleset->nightWindowEndHour());
        self::assertSame(3, $ruleset->compensatoryTimeOffMonths(PayrollSurchargeKind::Overtime));
    }

    /** Každý druh příplatku má vlastní mzdovou složku ve výchozím číselníku. */
    public function testEverySurchargeHasItsOwnDefaultPayrollComponent(): void
    {
        $codes = \MyInvoice\Service\Payroll\Component\PayrollComponentDefaults::codes();
        $seen = [];
        foreach (PayrollSurchargeKind::all() as $kind) {
            self::assertContains($kind->componentCode(), $codes, $kind->value);
            self::assertNotContains($kind->componentCode(), $seen);
            $seen[] = $kind->componentCode();
        }
    }

    /** @param array<string,int|null> $rates */
    private function agreed(array $rates): PayrollSurchargePolicy
    {
        return PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            null,
            $rates,
            $this->ruleset(),
        );
    }

    private function ruleset(): PayrollSurchargeRuleset
    {
        return PayrollSurchargeRuleset::forDate(
            CzechPayrollRulesets2026::provider(),
            '2026-06-01',
        );
    }
}
