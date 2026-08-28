<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxInput;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use PHPUnit\Framework\TestCase;

final class MonthlyAdvanceTaxCalculatorTest extends TestCase
{
    public function testMatchesOfficial2026LowRateExample(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 4_581_000,
                signedDeclaration: false,
                claimTaxpayerCredit: false,
            ),
        );

        self::assertSame(4_590_000, $result->roundedTaxBaseMinorUnits);
        self::assertSame(4_590_000, $result->lowRateBaseMinorUnits);
        self::assertSame(0, $result->highRateBaseMinorUnits);
        self::assertSame(688_500, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(688_500, $result->taxAfterCreditsMinorUnits);
    }

    public function testMatchesOfficial2026HighRateExample(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 16_561_500,
                signedDeclaration: false,
                claimTaxpayerCredit: false,
            ),
        );

        self::assertSame(16_570_000, $result->roundedTaxBaseMinorUnits);
        self::assertSame(14_690_100, $result->lowRateBaseMinorUnits);
        self::assertSame(1_879_900, $result->highRateBaseMinorUnits);
        self::assertSame(2_635_900, $result->taxBeforeCreditsMinorUnits);
    }

    public function testAppliesCreditsInOrderAndDerivesEligibleChildBonus(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 4_790_000,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 511_500,
            ),
        );

        self::assertSame(718_500, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(257_000, $result->nonRefundableCreditsMinorUnits);
        self::assertSame(0, $result->taxAfterCreditsMinorUnits);
        self::assertTrue($result->taxBonusEligible);
        self::assertSame(50_000, $result->taxBonusMinorUnits);
    }

    public function testDoesNotPayBonusBelowMinimumIncomeOrMinimumBonusAmount(): void
    {
        $belowIncome = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 1_100_000,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 300_000,
            ),
        );
        self::assertFalse($belowIncome->taxBonusEligible);
        self::assertSame(0, $belowIncome->taxBonusMinorUnits);

        $belowBonus = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 4_790_000,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 465_000,
            ),
        );
        self::assertFalse($belowBonus->taxBonusEligible);
        self::assertSame(0, $belowBonus->taxBonusMinorUnits);
    }

    public function testMonthlyBonusIncomeThresholdIsInclusiveAndAuditable(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 1_120_000,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 126_700,
            ),
        );

        self::assertTrue($result->taxBonusEligible);
        self::assertSame(126_700, $result->taxBonusMinorUnits);
        self::assertSame(1_120_000, $result->taxBonusMinimumIncomeMinorUnits);
        self::assertSame(5_000, $result->taxBonusMinimumAmountMinorUnits);
        self::assertSame(126_700, $result->taxBonusCandidateMinorUnits);
        self::assertTrue($result->taxBonusIncomeThresholdMet);
        self::assertTrue($result->taxBonusAmountThresholdMet);
        self::assertSame('eligible', $result->taxBonusEligibilityReason);
    }

    public function testMonthlyBonusExplainsIncomeAndAmountThresholdFailures(): void
    {
        $belowIncome = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 1_119_900,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 126_700,
            ),
        );
        self::assertSame('income_below_threshold', $belowIncome->taxBonusEligibilityReason);
        self::assertFalse($belowIncome->taxBonusIncomeThresholdMet);
        self::assertTrue($belowIncome->taxBonusAmountThresholdMet);

        $belowAmount = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 4_790_000,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 465_000,
            ),
        );
        self::assertSame('amount_below_threshold', $belowAmount->taxBonusEligibilityReason);
        self::assertTrue($belowAmount->taxBonusIncomeThresholdMet);
        self::assertFalse($belowAmount->taxBonusAmountThresholdMet);
    }

    /**
     * Dřív se tady tvrdilo, že dodaná sada počítat NESMÍ, dokud ji někdo neschválí.
     * To je právě to, co majitel zrušil: za dodané hodnoty ručí dodavatel a zákazník
     * je neodklikává. Fail-closed zůstává tam, kam patří — u ručního posouzení
     * a u chybějícího pokrytí obdobím.
     */
    public function testProductionFixtureCalculatesWithoutCustomerApproval(): void
    {
        $calculator = new MonthlyAdvanceTaxCalculator(CzechPayrollRulesets2026::provider());

        $result = $calculator->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(4_581_000, false, false),
        );

        self::assertSame('cz-payroll-2026.income-tax.v1', $result->rulesetId);
    }

    private function calculator(): MonthlyAdvanceTaxCalculator
    {
        return new MonthlyAdvanceTaxCalculator(new PayrollRulesetProvider([
            CzechPayrollRulesets2026::provider()->forDate(
                \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain::IncomeTax,
                '2026-08-03',
            ),
        ]));
    }
}
