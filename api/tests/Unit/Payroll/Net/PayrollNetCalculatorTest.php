<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\NetRelationshipIncome;
use MyInvoice\Service\Payroll\Net\PayrollDeductionRequest;
use MyInvoice\Service\Payroll\Net\PayrollNetCalculator;
use MyInvoice\Service\Payroll\Net\PayrollNetInput;
use MyInvoice\Service\Payroll\Net\PayrollNetInputAssembler;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthResult;
use MyInvoice\Service\Payroll\IncomeTax\AnnualTaxAccumulatorResult;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthResult;
use PHPUnit\Framework\TestCase;

final class PayrollNetCalculatorTest extends TestCase
{
    public function testAggregatesRelationshipsWithoutPayingNonCashIncomeTwice(): void
    {
        $result = (new PayrollNetCalculator())->calculate(new PayrollNetInput(
            personReference: 'person-synthetic-1',
            relationships: [
                new NetRelationshipIncome('employment-1', 4_000_000, 120_000),
                new NetRelationshipIncome('agreement-1', 500_000, 0),
            ],
            employeeSocialMinorUnits: 319_500,
            employeeHealthMinorUnits: 202_500,
            advanceTaxMinorUnits: 420_000,
            withholdingTaxMinorUnits: 75_000,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 10_000,
            voluntaryDeductionCapacityMinorUnits: 3_493_000,
            deductions: [],
        ));

        self::assertSame(4_500_000, $result->cashIncomeMinorUnits);
        self::assertSame(120_000, $result->nonCashIncomeMinorUnits);
        self::assertSame(3_493_000, $result->netBeforeDeductionsMinorUnits);
        self::assertSame(3_493_000, $result->netPayableMinorUnits);
        self::assertSame(
            ['employment-1', 'agreement-1'],
            array_map(
                static fn (NetRelationshipIncome $income): string =>
                    $income->relationshipReference,
                $result->relationships,
            ),
        );
    }

    /**
     * Ú-04/Ú-05: celý měsíc neplacené volno. Peněžní příjem nula, jediná
     * položka je doplatek zdravotního pojištění do minimálního vyměřovacího
     * základu podle § 3 odst. 10 z. č. 592/1992 Sb., který podle odst. 12
     * téhož paragrafu hradí zaměstnanec prostřednictvím zaměstnavatele.
     * Výsledkem je záporná čistá mzda — dluh zaměstnance, ne pád výpočtu.
     */
    public function testUnpaidLeaveWithHealthMinimumTopUpYieldsNegativeNet(): void
    {
        $result = (new PayrollNetCalculator())->calculate(new PayrollNetInput(
            personReference: 'person-unpaid-leave',
            relationships: [
                new NetRelationshipIncome('employment-1', 0, 0),
            ],
            employeeSocialMinorUnits: 0,
            employeeHealthMinorUnits: 297_000,
            advanceTaxMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 0,
            deductions: [],
        ));

        self::assertSame(0, $result->cashIncomeMinorUnits);
        self::assertSame(-297_000, $result->netBeforeDeductionsMinorUnits);
        self::assertSame(0, $result->deductedMinorUnits);
        self::assertSame(-297_000, $result->netPayableMinorUnits);
    }

    /**
     * NEGATIVNÍ test — povolení není plošné. Ze záporné čisté mzdy se nedá
     * srazit ani koruna, takže nenulová kapacita dobrovolných srážek je dál
     * neplatný vstup.
     */
    public function testStillRejectsDeductionCapacityOnNegativeNet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kapacita dobrovolných srážek');

        new PayrollNetInput(
            personReference: 'person-unpaid-leave',
            relationships: [
                new NetRelationshipIncome('employment-1', 0, 0),
            ],
            employeeSocialMinorUnits: 0,
            employeeHealthMinorUnits: 297_000,
            advanceTaxMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 1,
            deductions: [],
        );
    }

    public function testAddsTaxBonusOnceAndAppliesDeductionsByPriorityAndCapacity(): void
    {
        $result = (new PayrollNetCalculator())->calculate(new PayrollNetInput(
            personReference: 'person-synthetic-2',
            relationships: [
                new NetRelationshipIncome('employment-1', 3_000_000, 0),
            ],
            employeeSocialMinorUnits: 213_000,
            employeeHealthMinorUnits: 135_000,
            advanceTaxMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 126_700,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 180_000,
            deductions: [
                new PayrollDeductionRequest('meal', 2, 100_000, null, true),
                new PayrollDeductionRequest('advance', 1, 150_000, 120_000, true),
                new PayrollDeductionRequest('paused', 0, 50_000, null, false),
            ],
        ));

        self::assertSame(2_778_700, $result->netBeforeDeductionsMinorUnits);
        self::assertSame(180_000, $result->deductedMinorUnits);
        self::assertSame(2_598_700, $result->netPayableMinorUnits);
        self::assertSame('paused', $result->deductions[0]->deductionReference);
        self::assertSame(0, $result->deductions[0]->appliedMinorUnits);
        self::assertSame('advance', $result->deductions[1]->deductionReference);
        self::assertSame(120_000, $result->deductions[1]->appliedMinorUnits);
        self::assertSame('meal', $result->deductions[2]->deductionReference);
        self::assertSame(60_000, $result->deductions[2]->appliedMinorUnits);
        self::assertSame(40_000, $result->deductions[2]->unappliedMinorUnits);
    }

    public function testRejectsCapacityAboveAvailableNetPay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PayrollNetInput(
            personReference: 'person-synthetic-3',
            relationships: [new NetRelationshipIncome('employment-1', 100_000, 0)],
            employeeSocialMinorUnits: 0,
            employeeHealthMinorUnits: 0,
            advanceTaxMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 100_001,
            deductions: [],
        );
    }

    public function testRejectsDuplicateRelationshipAndDeductionReferences(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PayrollNetInput(
            personReference: 'person-synthetic-4',
            relationships: [
                new NetRelationshipIncome('duplicate', 100_000, 0),
                new NetRelationshipIncome('duplicate', 50_000, 0),
            ],
            employeeSocialMinorUnits: 0,
            employeeHealthMinorUnits: 0,
            advanceTaxMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 0,
            deductions: [
                new PayrollDeductionRequest('same', 1, 1, null, true),
                new PayrollDeductionRequest('same', 2, 1, null, true),
            ],
        );
    }

    public function testAssemblerConnectsPersonLevelInsuranceAndTaxResultsExactlyOnce(): void
    {
        $input = (new PayrollNetInputAssembler())->assemble(
            'person-synthetic-5',
            [new NetRelationshipIncome('employment-1', 3_000_000, 100_000)],
            $this->socialResult('person-synthetic-5', 213_000),
            $this->healthResult('person-synthetic-5', 135_000),
            $this->taxResult('person-synthetic-5', 250_000, 0, 126_700),
            0,
            2_528_700,
            [],
        );
        $result = (new PayrollNetCalculator())->calculate($input);

        self::assertSame(213_000, $result->employeeSocialMinorUnits);
        self::assertSame(135_000, $result->employeeHealthMinorUnits);
        self::assertSame(250_000, $result->advanceTaxMinorUnits);
        self::assertSame(126_700, $result->taxBonusMinorUnits);
        self::assertSame(2_528_700, $result->netPayableMinorUnits);
    }

    public function testAssemblerFailsClosedOnManualReviewOrPersonMismatch(): void
    {
        $this->expectException(\DomainException::class);
        (new PayrollNetInputAssembler())->assemble(
            'person-synthetic-6',
            [new NetRelationshipIncome('employment-1', 100_000, 0)],
            $this->socialResult('different-person', null),
            $this->healthResult('person-synthetic-6', 0),
            $this->taxResult('person-synthetic-6', 0, 0, 0),
            0,
            0,
            [],
        );
    }

    private function socialResult(string $person, ?int $contribution): SocialPersonMonthResult
    {
        return new SocialPersonMonthResult(
            personId: $person,
            status: $contribution === null
                ? SocialCalculationStatus::ManualReview
                : SocialCalculationStatus::Calculated,
            jurisdiction: SocialJurisdictionEvidence::CzechRegimeVerified,
            jurisdictionEvidenceReference: 'evidence-social',
            workingPensionerDiscountEvidenceReference: null,
            yearToDateAssessmentBaseBeforeMonthMinorUnits: 0,
            participatingAssessmentBaseMinorUnits: 3_000_000,
            cappedAssessmentBaseMinorUnits: 3_000_000,
            employeeContributionBeforeDiscountMinorUnits: $contribution,
            workingPensionerDiscountMinorUnits: 0,
            employeeContributionMinorUnits: $contribution,
            contributionStep: null,
            discountStep: null,
            relationships: [],
            issues: $contribution === null ? ['manual_review'] : [],
        );
    }

    private function healthResult(string $person, int $contribution): HealthPersonMonthResult
    {
        return new HealthPersonMonthResult(
            personId: $person,
            status: HealthCalculationStatus::Calculated,
            jurisdiction: HealthJurisdictionEvidence::CzechRegimeVerified,
            jurisdictionEvidenceReference: 'evidence-health',
            insurerStatus: HealthInsurerSnapshotStatus::Verified,
            insurerCode: '111',
            insurerEvidenceReference: 'insurer-snapshot',
            assessmentBaseMinorUnits: 3_000_000,
            otherEmployerAssessmentBaseMinorUnits: 0,
            combinedAssessmentBaseMinorUnits: 3_000_000,
            employmentCalendarDays: 30,
            minimumExcludedCalendarDays: 0,
            minimumApplicableCalendarDays: 30,
            statutoryMonthlyMinimumMinorUnits: 2_240_000,
            effectiveMinimumMinorUnits: 2_240_000,
            topUpResponsibility: HealthMinimumTopUpResponsibility::Employee,
            topUpResponsibilityEvidenceReference: null,
            selectedTopUpEmployerEvidenceReference: null,
            standardContributionMinorUnits: 405_000,
            employeeStandardContributionMinorUnits: $contribution,
            employerStandardContributionMinorUnits: 270_000,
            employeeMinimumTopUpMinorUnits: 0,
            employerMinimumTopUpMinorUnits: 0,
            employeeContributionMinorUnits: $contribution,
            employerContributionMinorUnits: 270_000,
            totalContributionMinorUnits: 405_000,
            relationships: [],
            minimumReductionEvidence: [],
            otherEmployerEvidence: [],
            issues: [],
        );
    }

    private function taxResult(
        string $person,
        int $advanceTax,
        int $withholdingTax,
        int $bonus,
    ): MonthlyEmploymentIncomeTaxResult {
        $advance = new MonthlyAdvanceTaxResult(
            taxableIncomeMinorUnits: 3_000_000,
            roundedTaxBaseMinorUnits: 3_000_000,
            lowRateBaseMinorUnits: 3_000_000,
            highRateBaseMinorUnits: 0,
            rateSteps: [],
            taxBeforeCreditsMinorUnits: 450_000,
            nonRefundableCreditsMinorUnits: 200_000,
            childCreditMinorUnits: 126_700,
            taxBonusEligible: true,
            taxAfterCreditsMinorUnits: $advanceTax,
            taxBonusMinorUnits: $bonus,
            rulesetId: 'synthetic-2026',
            rulesetHash: str_repeat('a', 64),
        );
        return new MonthlyEmploymentIncomeTaxResult(
            status: TaxCalculationStatus::Calculated,
            calculationDate: '2026-06-30',
            employeeReference: $person,
            payerReference: 'payer-synthetic',
            relationships: [],
            advanceTax: $advance,
            withholdingGroups: [],
            withholdingBaseMinorUnits: 0,
            withholdingTaxMinorUnits: $withholdingTax,
            claimedNonRefundableCreditsMinorUnits: 200_000,
            appliedNonRefundableCreditsMinorUnits: 200_000,
            claimedNonRefundableCreditBreakdown: ['taxpayer' => 200_000],
            claimedChildCreditMinorUnits: 126_700,
            appliedChildCreditMinorUnits: 126_700,
            annualAccumulator: new AnnualTaxAccumulatorResult(
                2026,
                6,
                3_000_000,
                0,
                $advanceTax,
                $withholdingTax,
                200_000,
                126_700,
                $bonus,
                3_000_000,
                true,
                [],
                false,
                false,
            ),
            issues: [],
            policyId: 'synthetic-policy',
            policyHash: str_repeat('b', 64),
            rulesetId: 'synthetic-2026',
            rulesetHash: str_repeat('a', 64),
        );
    }
}
