<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use UnexpectedValueException;

final class MonthlyAdvanceTaxCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    public function calculate(string $calculationDate, MonthlyAdvanceTaxInput $input): MonthlyAdvanceTaxResult
    {
        $ruleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::IncomeTax,
            $calculationDate,
        );

        // § 38h odst. 1: základ pro výpočet zálohy se zaokrouhluje do 100 Kč na
        // celé koruny nahoru a nad 100 Kč na celé stokoruny nahoru.
        //
        // Způsob zaokrouhlení je v rulesetu (`advance.rounding.*`) a admin ho
        // smí přepsat, takže se sem MUSÍ dostat z rulesetu — dřív byl natvrdo
        // v kódu a administrátorův override tiše nic neudělal, což je horší než
        // kdyby ho katalog vůbec nenabízel. Neznámá hodnota výpočet zastaví:
        // spočítat zálohu jinak, než jak je pravidlo evidované, znamená vyrobit
        // snapshot, který nesedí na vlastní stopu.
        $roundedBase = $input->taxableIncomeMinorUnits <= 10_000
            ? $this->applyRounding(
                $ruleset,
                'advance.rounding.base_up_to_100_czk',
                $input->taxableIncomeMinorUnits,
            )
            : $this->applyRounding(
                $ruleset,
                'advance.rounding.base_above_100_czk',
                $input->taxableIncomeMinorUnits,
            );
        $highRateThreshold = $this->moneyParameter(
            $ruleset,
            'advance.high_threshold.monthly',
        );
        $lowRateBase = min($roundedBase, $highRateThreshold);
        $highRateBase = max(0, $roundedBase - $highRateThreshold);

        $lowRateStep = CalculationStep::calculate(
            'monthly-advance-tax-low-rate',
            $lowRateBase,
            $this->rateParameter($ruleset, 'advance.low_rate'),
            RoundingMode::TowardZero,
        );
        $highRateStep = CalculationStep::calculate(
            'monthly-advance-tax-high-rate',
            $highRateBase,
            $this->rateParameter($ruleset, 'advance.high_rate'),
            RoundingMode::TowardZero,
        );
        $taxBeforeCredits = PayrollRounding::ceilFractionSumToMultiple([
            [
                'numerator' => $lowRateStep->unroundedNumerator,
                'denominator' => $lowRateStep->unroundedDenominator,
            ],
            [
                'numerator' => $highRateStep->unroundedNumerator,
                'denominator' => $highRateStep->unroundedDenominator,
            ],
        ], $this->roundingMultiple($ruleset, 'advance.rounding.result'));

        $nonRefundableCredits = $input->otherNonRefundableCreditsMinorUnits;
        if ($input->signedDeclaration && $input->claimTaxpayerCredit) {
            $nonRefundableCredits += $this->moneyParameter(
                $ruleset,
                'credit.taxpayer.monthly',
            );
        }

        $taxAfterNonRefundableCredits = max(
            0,
            $taxBeforeCredits - $nonRefundableCredits,
        );
        $taxAfterCredits = max(
            0,
            $taxAfterNonRefundableCredits - $input->childCreditMinorUnits,
        );
        $bonusCandidate = max(
            0,
            $input->childCreditMinorUnits - $taxAfterNonRefundableCredits,
        );
        $bonusMinimumIncome = $this->moneyParameter(
            $ruleset,
            'bonus.minimum_income.monthly',
        );
        $bonusMinimumAmount = $this->moneyParameter(
            $ruleset,
            'bonus.minimum_amount.monthly',
        );
        $bonusIncomeThresholdMet = $input->taxableIncomeMinorUnits >= $bonusMinimumIncome;
        $bonusAmountThresholdMet = $bonusCandidate >= $bonusMinimumAmount;
        $taxBonusEligible = $input->signedDeclaration
            && $bonusIncomeThresholdMet
            && $bonusAmountThresholdMet;
        $taxBonusEligibilityReason = match (true) {
            !$input->signedDeclaration => 'declaration_not_signed',
            !$bonusIncomeThresholdMet => 'income_below_threshold',
            !$bonusAmountThresholdMet => 'amount_below_threshold',
            default => 'eligible',
        };

        return new MonthlyAdvanceTaxResult(
            taxableIncomeMinorUnits: $input->taxableIncomeMinorUnits,
            roundedTaxBaseMinorUnits: $roundedBase,
            lowRateBaseMinorUnits: $lowRateBase,
            highRateBaseMinorUnits: $highRateBase,
            rateSteps: [$lowRateStep, $highRateStep],
            taxBeforeCreditsMinorUnits: $taxBeforeCredits,
            nonRefundableCreditsMinorUnits: $nonRefundableCredits,
            childCreditMinorUnits: $input->childCreditMinorUnits,
            taxBonusEligible: $taxBonusEligible,
            taxAfterCreditsMinorUnits: $taxAfterCredits,
            taxBonusMinorUnits: $taxBonusEligible ? $bonusCandidate : 0,
            rulesetId: $ruleset->id,
            rulesetHash: $ruleset->canonicalHash,
            taxBonusCandidateMinorUnits: $bonusCandidate,
            taxBonusMinimumIncomeMinorUnits: $bonusMinimumIncome,
            taxBonusMinimumAmountMinorUnits: $bonusMinimumAmount,
            taxBonusIncomeThresholdMet: $bonusIncomeThresholdMet,
            taxBonusAmountThresholdMet: $bonusAmountThresholdMet,
            taxBonusEligibilityReason: $taxBonusEligibilityReason,
        );
    }

    /**
     * Zaokrouhlovací strategie, které umí modul spočítat.
     *
     * Klíč = hodnota parametru v rulesetu, hodnota = násobek v haléřích, na
     * který se zaokrouhluje NAHORU. Jiné strategie (dolů, na půlky) tu vědomě
     * nejsou: § 38h odst. 1 ani § 146 odst. 1 daňového řádu jiný směr u zálohy
     * nepřipouštějí a nabídnout je by znamenalo dovolit spočítat zálohu
     * protizákonně.
     */
    private const ROUNDING_MULTIPLES_MINOR_UNITS = [
        'ceil-to-1-czk' => 100,
        'ceil-to-100-czk' => 10_000,
    ];

    private function applyRounding(
        PayrollRulesetVersion $ruleset,
        string $key,
        int $minorUnits,
    ): int {
        return PayrollRounding::ceilToMultiple(
            $minorUnits,
            $this->roundingMultiple($ruleset, $key),
        );
    }

    private function roundingMultiple(PayrollRulesetVersion $ruleset, string $key): int
    {
        $value = $ruleset->parameter($key);
        if ($value->type !== 'text' || !is_string($value->value)) {
            throw new UnexpectedValueException(
                "Payroll ruleset parameter {$key} is not text.",
            );
        }
        $multiple = self::ROUNDING_MULTIPLES_MINOR_UNITS[$value->value] ?? null;
        if ($multiple === null) {
            throw new UnexpectedValueException(sprintf(
                'Payroll ruleset parameter %s has unsupported rounding strategy "%s".',
                $key,
                $value->value,
            ));
        }

        return $multiple;
    }

    private function moneyParameter(PayrollRulesetVersion $ruleset, string $key): int
    {
        $value = $ruleset->parameter($key);
        if ($value->type !== 'money_minor' || !is_int($value->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not money.");
        }

        return $value->value;
    }

    private function rateParameter(
        PayrollRulesetVersion $ruleset,
        string $key,
    ): DecimalRate {
        $value = $ruleset->parameter($key);
        if ($value->type !== 'decimal_rate' || !is_string($value->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not a rate.");
        }

        return DecimalRate::fromString($value->value);
    }
}
