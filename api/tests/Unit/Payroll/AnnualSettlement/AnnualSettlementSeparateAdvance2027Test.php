<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementCreditMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementInput;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementCalculator;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use PHPUnit\Framework\TestCase;

/**
 * Roční zúčtování a příjmy podle § 6 odst. 4 ZDP.
 *
 * Od zdaňovacího období 2027 počítá plátce daň „z úhrnu mezd, vyjma příjmů
 * podle § 6 odst. 4 plynoucích od jiného plátce daně" (§ 38ch odst. 4 ve znění
 * zák. č. 360/2025 Sb.) — vlastní příjmy podle § 6 odst. 4 do úhrnu patří
 * a sražená záloha z nich se započte. Za rok 2026 do zúčtování nevstupují.
 *
 * Bez změny kalkulátoru zůstala záloha 1 875 Kč ze samostatného základu mimo
 * zúčtování a přeplatek vyšel nulový — test pak padá.
 */
final class AnnualSettlementSeparateAdvance2027Test extends TestCase
{
    public function testOwnSeparateAdvanceIsIncludedAndRefunded2027(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(2027),
            $this->rates(2027),
        );

        self::assertTrue($result->performed);
        // 100 000 + 12 499 = 112 499 → dolů na sta 112 400 Kč.
        self::assertSame(11_249_900, $result->taxBaseBeforeDeductionsMinorUnits);
        self::assertSame(11_240_000, $result->roundedTaxBaseMinorUnits);
        // 15 % = 16 860 Kč, sleva na poplatníka 30 840 Kč → daň 0.
        self::assertSame(0, $result->taxAfterAllCreditsMinorUnits);
        // Sražená záloha ze samostatného základu se vrací jako přeplatek.
        self::assertSame(187_500, $result->taxDifferenceMinorUnits);
        self::assertSame(187_500, $result->payableMinorUnits);
        self::assertSame(AnnualSettlementOutcome::Overpayment, $result->outcome);
        self::assertSame([
            'regime' => 'separate-advance',
            'base_minor_units' => 1_249_900,
            'advance_tax_minor_units' => 187_500,
        ], $result->trace['separate_base_included'] ?? null);
    }

    /** Regrese 2026: srážková daň do zúčtování nevstupuje, stopa beze změny. */
    public function testWithholdingTax2026StaysOutsideTheSettlement(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(2026),
            $this->rates(2026),
        );

        self::assertTrue($result->performed);
        self::assertSame(10_000_000, $result->taxBaseBeforeDeductionsMinorUnits);
        self::assertSame(0, $result->taxDifferenceMinorUnits);
        self::assertSame(AnnualSettlementOutcome::NoDifference, $result->outcome);
        self::assertArrayNotHasKey('separate_base_included', $result->trace);
    }

    private function input(int $year): AnnualSettlementInput
    {
        return new AnnualSettlementInput(
            $year,
            12,
            10_000_000,
            0,
            0,
            0,
            0,
            10_000_000,
            1_249_900,
            187_500,
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)],
            [],
            [],
        );
    }

    private function rates(int $year): AnnualTaxRates
    {
        return AnnualTaxRates::forRuleset(
            CzechPayrollRulesets::provider()->forDate(
                PayrollRulesetDomain::IncomeTax,
                sprintf('%04d-12-01', $year),
            ),
        );
    }
}
