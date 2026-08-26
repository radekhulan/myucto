<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Calculation\Money;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefinition;
use MyInvoice\Service\Payroll\Component\PayrollComponentFrequency;
use MyInvoice\Service\Payroll\Component\PayrollComponentInclusion;
use MyInvoice\Service\Payroll\Component\PayrollComponentKind;
use MyInvoice\Service\Payroll\Component\PayrollComponentTaxTreatment;
use MyInvoice\Service\Payroll\Component\PayrollComponentValueKind;
use MyInvoice\Service\Payroll\Component\PayrollExemptionBasis;
use PHPUnit\Framework\TestCase;

final class PayrollComponentDefinitionTest extends TestCase
{
    public function testMonetaryComponentKeepsIndependentClassifications(): void
    {
        $definition = $this->definition(
            tax: PayrollComponentTaxTreatment::EXEMPT,
            social: PayrollComponentInclusion::EXCLUDED,
            health: PayrollComponentInclusion::EXCLUDED,
            average: PayrollComponentInclusion::INCLUDED,
            enforcement: PayrollComponentInclusion::EXCLUDED,
            jmhz: PayrollComponentInclusion::INCLUDED,
        );

        $impact = $definition->impact(new Money(12_345));

        self::assertSame(12_345, $impact->cashPayable->minorUnits);
        self::assertSame(0, $impact->taxBase->minorUnits);
        self::assertSame(0, $impact->socialBase->minorUnits);
        self::assertSame(0, $impact->healthBase->minorUnits);
        self::assertSame(12_345, $impact->averageEarningBase->minorUnits);
        self::assertSame(0, $impact->enforcementBase->minorUnits);
        self::assertSame(12_345, $impact->jmhzAmount->minorUnits);
    }

    public function testNonMonetaryBenefitNeverInflatesCashPayout(): void
    {
        $definition = $this->definition(
            valueKind: PayrollComponentValueKind::NON_MONETARY,
        );

        $impact = $definition->impact(new Money(80_000));

        self::assertSame(0, $impact->cashPayable->minorUnits);
        self::assertSame(80_000, $impact->taxBase->minorUnits);
        self::assertSame(80_000, $impact->socialBase->minorUnits);
        self::assertSame(80_000, $impact->healthBase->minorUnits);
    }

    public function testNegativeCorrectionPreservesItsDirectionInEveryIncludedBase(): void
    {
        $impact = $this->definition()->impact(new Money(-500));

        self::assertSame(-500, $impact->cashPayable->minorUnits);
        self::assertSame(-500, $impact->taxBase->minorUnits);
        self::assertSame(-500, $impact->socialBase->minorUnits);
        self::assertSame(-500, $impact->healthBase->minorUnits);
    }

    public function testManualReviewCannotSilentlyEnterCalculation(): void
    {
        foreach ([
            $this->definition(tax: PayrollComponentTaxTreatment::MANUAL_REVIEW),
            $this->definition(
                socialParticipation: PayrollComponentInclusion::MANUAL_REVIEW,
            ),
            $this->definition(social: PayrollComponentInclusion::MANUAL_REVIEW),
            $this->definition(
                healthParticipation: PayrollComponentInclusion::MANUAL_REVIEW,
            ),
            $this->definition(jmhz: PayrollComponentInclusion::MANUAL_REVIEW),
        ] as $definition) {
            try {
                $definition->impact(new Money(100));
                self::fail('Neuzavřená klasifikace musí blokovat výpočet.');
            } catch (\DomainException $e) {
                self::assertStringContainsString('ruční posouzení', $e->getMessage());
            }
        }
    }

    public function testParticipationAndAssessmentBaseClassificationsStayIndependentInSnapshot(): void
    {
        $snapshot = $this->definition(
            socialParticipation: PayrollComponentInclusion::INCLUDED,
            social: PayrollComponentInclusion::EXCLUDED,
            healthParticipation: PayrollComponentInclusion::EXCLUDED,
            health: PayrollComponentInclusion::INCLUDED,
        )->snapshot();

        self::assertSame('included', $snapshot['social_participation_treatment']);
        self::assertSame('excluded', $snapshot['social_treatment']);
        self::assertSame('excluded', $snapshot['health_participation_treatment']);
        self::assertSame('included', $snapshot['health_treatment']);
    }

    public function testAnnualLimitIsAllowedOnlyForBenefit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->definition(
            kind: PayrollComponentKind::BONUS,
            annualLimitMinor: 10_000,
        );
    }

    /**
     * Podklad osvobození u složky, která se stejně zdaní, by tvrdil doklad
     * k něčemu, co osvobozené není.
     */
    public function testExemptionBasisRequiresAnExemptClassification(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->definition(
            tax: PayrollComponentTaxTreatment::INCLUDED,
            exemptionBasis: PayrollExemptionBasis::StatutoryExempt,
        );
    }

    /** Bez zařazení do koše není co rozpadnout, takže ani co doložit. */
    public function testBasketBasisRequiresABasket(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->definition(
            tax: PayrollComponentTaxTreatment::EXEMPT,
            exemptionBasis: PayrollExemptionBasis::BenefitBasket,
        );
    }

    public function testExemptionBasisReachesTheFrozenSnapshot(): void
    {
        $snapshot = $this->definition(
            tax: PayrollComponentTaxTreatment::EXEMPT,
            exemptionBasis: PayrollExemptionBasis::NotSubjectToTax,
        )->snapshot();

        self::assertSame('not_subject_to_tax', $snapshot['exemption_basis']);
    }

    public function testDottedAnalyticalAccountsAreAccepted(): void
    {
        $definition = new PayrollComponentDefinition(
            code: 'SYNTHETIC',
            name: 'Syntetická mzdová složka',
            kind: PayrollComponentKind::BONUS,
            valueKind: PayrollComponentValueKind::MONETARY,
            frequency: PayrollComponentFrequency::ONE_OFF,
            taxTreatment: PayrollComponentTaxTreatment::INCLUDED,
            socialParticipationTreatment: PayrollComponentInclusion::INCLUDED,
            socialTreatment: PayrollComponentInclusion::INCLUDED,
            healthParticipationTreatment: PayrollComponentInclusion::INCLUDED,
            healthTreatment: PayrollComponentInclusion::INCLUDED,
            averageEarningTreatment: PayrollComponentInclusion::INCLUDED,
            enforcementTreatment: PayrollComponentInclusion::INCLUDED,
            jmhzTreatment: PayrollComponentInclusion::INCLUDED,
            statisticsTreatment: PayrollComponentInclusion::INCLUDED,
            accountingDebitCode: '521.100',
            accountingCreditCode: '331.100',
        );

        self::assertSame('521.100', $definition->accountingDebitCode);
        self::assertSame('331.100', $definition->accountingCreditCode);
    }

    private function definition(
        PayrollComponentKind $kind = PayrollComponentKind::BENEFIT_MEAL,
        PayrollComponentValueKind $valueKind = PayrollComponentValueKind::MONETARY,
        PayrollComponentTaxTreatment $tax = PayrollComponentTaxTreatment::INCLUDED,
        PayrollComponentInclusion $socialParticipation = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $social = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $healthParticipation = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $health = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $average = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $enforcement = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $jmhz = PayrollComponentInclusion::INCLUDED,
        ?int $annualLimitMinor = null,
        ?PayrollExemptionBasis $exemptionBasis = null,
    ): PayrollComponentDefinition {
        return new PayrollComponentDefinition(
            code: 'SYNTHETIC',
            name: 'Syntetická mzdová složka',
            kind: $kind,
            valueKind: $valueKind,
            frequency: PayrollComponentFrequency::ONE_OFF,
            taxTreatment: $tax,
            socialParticipationTreatment: $socialParticipation,
            socialTreatment: $social,
            healthParticipationTreatment: $healthParticipation,
            healthTreatment: $health,
            averageEarningTreatment: $average,
            enforcementTreatment: $enforcement,
            jmhzTreatment: $jmhz,
            statisticsTreatment: PayrollComponentInclusion::INCLUDED,
            annualLimitMinor: $annualLimitMinor,
            exemptionBasis: $exemptionBasis,
        );
    }
}
