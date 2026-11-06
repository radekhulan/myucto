<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponentTreatment;
use MyInvoice\Service\Payroll\IncomeTax\TaxCorrectionTreatment;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryComponentMapper;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;
use PHPUnit\Framework\TestCase;

final class PayrollRunStatutoryComponentMapperTest extends TestCase
{
    public function testMapsIndependentFrozenClassifications(): void
    {
        $mapper = new PayrollRunStatutoryComponentMapper();
        $input = $this->input([
            'tax_treatment' => 'withholding_candidate',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'excluded',
            'health_participation_treatment' => 'excluded',
            'health_treatment' => 'included',
        ]);

        $social = $mapper->social($input);
        $health = $mapper->health($input, '2026-08-01');
        $tax = $mapper->incomeTax($input, '2026-08-01');

        self::assertCount(1, $social);
        self::assertCount(1, $health);
        self::assertCount(1, $tax);
        $social = $social[0];
        $health = $health[0];
        $tax = $tax[0];
        self::assertSame('input.42.mzda_mesicni', $social->code);
        self::assertSame(SocialComponentTreatment::Included, $social->participationTreatment);
        self::assertSame(SocialComponentTreatment::Excluded, $social->assessmentBaseTreatment);
        self::assertSame(HealthComponentTreatment::Excluded, $health->participationTreatment);
        self::assertSame(HealthComponentTreatment::Included, $health->assessmentBaseTreatment);
        self::assertSame(IncomeTaxComponentTreatment::Included, $tax->treatment);
    }

    public function testPriorSourcePeriodFailsClosedForHealthAndTax(): void
    {
        $mapper = new PayrollRunStatutoryComponentMapper();
        $input = $this->input([], '2026-07-01');

        self::assertSame(
            HealthCorrectionTreatment::Unverified,
            $mapper->health($input, '2026-08-01')[0]->correctionTreatment,
        );
        self::assertSame(
            TaxCorrectionTreatment::Unverified,
            $mapper->incomeTax($input, '2026-08-01')[0]->correctionTreatment,
        );
    }

    public function testTaxExemptionDoesNotInventVerifiedEvidence(): void
    {
        $components = (new PayrollRunStatutoryComponentMapper())->incomeTax(
            $this->input(['tax_treatment' => 'exempt']),
            '2026-08-01',
        );

        self::assertCount(1, $components);
        self::assertSame(IncomeTaxComponentTreatment::Exempt, $components[0]->treatment);
        self::assertFalse($components[0]->hasVerifiedTreatmentEvidence('2026-08-01'));
    }

    /**
     * Uvedený podklad osvobození je doklad, který výpočet daně hledal.
     *
     * Bez něj byla brána `hasVerifiedTreatmentEvidence()` nesplnitelná — pole
     * `treatment_evidence_*` nenastavoval nikdo — a osvobozený příjem tak
     * neprošel mzdovým během NIKDY.
     */
    public function testStatedExemptionBasisBecomesVerifiedEvidence(): void
    {
        $components = (new PayrollRunStatutoryComponentMapper())->incomeTax(
            $this->input([
                'tax_treatment' => 'exempt',
                'exemption_basis' => 'not_subject_to_tax',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
            ]),
            '2026-08-01',
        );

        self::assertTrue($components[0]->hasVerifiedTreatmentEvidence('2026-08-01'));
        self::assertSame(
            'payroll-component:MZDA_MESICNI@2026-01-01/not_subject_to_tax',
            $components[0]->treatmentEvidenceReference,
        );
    }

    /**
     * Doklad je sama verze klasifikace. Skončila-li její platnost před počítaným
     * měsícem, není čím osvobození podložit.
     */
    public function testExpiredClassificationVersionStopsBeingEvidence(): void
    {
        $components = (new PayrollRunStatutoryComponentMapper())->incomeTax(
            $this->input([
                'tax_treatment' => 'exempt',
                'exemption_basis' => 'statutory_exempt',
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-06-30',
            ]),
            '2026-08-01',
        );

        self::assertFalse($components[0]->hasVerifiedTreatmentEvidence('2026-08-01'));
    }

    /**
     * U ročního koše je doklad zmrazený rozpad. Bez něj není známé, kolik se do
     * úhrnu ještě vešlo — samo zařazení do koše tedy nestačí.
     */
    public function testBasketBasisWithoutAFrozenSplitStaysUnevidenced(): void
    {
        $components = (new PayrollRunStatutoryComponentMapper())->incomeTax(
            $this->input([
                'tax_treatment' => 'exempt',
                'exemption_basis' => 'benefit_basket',
                'exemption_basket' => 'non_cash_leisure',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
            ]),
            '2026-08-01',
        );

        self::assertFalse($components[0]->hasVerifiedTreatmentEvidence('2026-08-01'));
    }

    /**
     * Nadlimitní část benefitu se rozpadne na vlastní složku výpočtu.
     *
     * Bez toho by zdanitelný přebytek zmizel: složka je klasifikovaná jako
     * osvobozená a vyloučená z pojistného, takže by celé plnění prošlo bez daně
     * i bez odvodů, ať je jakkoli vysoké.
     */
    public function testOverLimitBenefitBecomesItsOwnTaxableComponent(): void
    {
        $mapper = new PayrollRunStatutoryComponentMapper();
        $input = $this->input([
            'code' => 'REKREACE_VOLNY_CAS',
            'tax_treatment' => 'exempt',
            'social_participation_treatment' => 'excluded',
            'social_treatment' => 'excluded',
            'health_participation_treatment' => 'excluded',
            'health_treatment' => 'excluded',
            'exemption_basket' => 'non_cash_leisure',
        ]);
        $input['amount_minor'] = 3_000_000;
        $input['benefit_basket'] = 'non_cash_leisure';
        $input['benefit_exempt_minor'] = 2_448_350;
        $input['benefit_taxable_minor'] = 551_650;

        $tax = $mapper->incomeTax($input, '2026-08-01');
        $social = $mapper->social($input);
        $health = $mapper->health($input, '2026-08-01');

        self::assertCount(2, $tax);
        self::assertSame(IncomeTaxComponentTreatment::Exempt, $tax[0]->treatment);
        self::assertSame(2_448_350, $tax[0]->amountMinorUnits);
        self::assertSame('input.42.rekreace_volny_cas.nadlimit', $tax[1]->code);
        self::assertSame(IncomeTaxComponentTreatment::Included, $tax[1]->treatment);
        self::assertSame(551_650, $tax[1]->amountMinorUnits);

        self::assertCount(2, $social);
        self::assertSame(SocialComponentTreatment::Included, $social[1]->participationTreatment);
        self::assertSame(SocialComponentTreatment::Included, $social[1]->assessmentBaseTreatment);
        self::assertSame(551_650, $social[1]->amountMinorUnits);

        self::assertCount(2, $health);
        self::assertSame(HealthComponentTreatment::Included, $health[1]->assessmentBaseTreatment);
        self::assertSame(551_650, $health[1]->amountMinorUnits);
    }

    /**
     * Plnění přesně na limitu se NEROZDĚLUJE. Zákon říká „osvobozena v úhrnu
     * do výše …", takže úhrn rovný limitu je ještě celý osvobozený.
     */
    public function testAmountExactlyOnTheLimitStaysOneExemptComponent(): void
    {
        $mapper = new PayrollRunStatutoryComponentMapper();
        $input = $this->input([
            'code' => 'REKREACE_VOLNY_CAS',
            'tax_treatment' => 'exempt',
            'exemption_basket' => 'non_cash_leisure',
        ]);
        $input['amount_minor'] = 2_448_350;
        $input['benefit_basket'] = 'non_cash_leisure';
        $input['benefit_exempt_minor'] = 2_448_350;
        $input['benefit_taxable_minor'] = 0;

        $tax = $mapper->incomeTax($input, '2026-08-01');

        self::assertCount(1, $tax);
        self::assertSame(IncomeTaxComponentTreatment::Exempt, $tax[0]->treatment);
        self::assertSame(2_448_350, $tax[0]->amountMinorUnits);
    }

    /**
     * Spropitné nad 7 % (§ 6 odst. 9 písm. g) ZDP od 2027): osvobozená část mimo
     * daň i vyměřovací základy (§ 5 odst. 1 z. č. 589/1992 Sb., § 3 odst. 1
     * z. č. 592/1992 Sb.), přebytek je zdanitelný a vstupuje do obou základů.
     */
    public function testTipsOverTheCapAreTaxedAndInsured(): void
    {
        $mapper = new PayrollRunStatutoryComponentMapper();
        $input = $this->input([
            'code' => 'SPROPITNE',
            'component_kind' => 'other',
            'tax_treatment' => 'exempt',
            'social_participation_treatment' => 'excluded',
            'social_treatment' => 'excluded',
            'health_participation_treatment' => 'excluded',
            'health_treatment' => 'excluded',
            'exemption_basket' => 'food_service_tips',
        ]);
        $input['amount_minor'] = 400_000;
        $input['benefit_basket'] = 'food_service_tips';
        $input['benefit_exempt_minor'] = 350_000;
        $input['benefit_taxable_minor'] = 50_000;

        $tax = $mapper->incomeTax($input, '2027-03-01');
        $social = $mapper->social($input);
        $health = $mapper->health($input, '2027-03-01');

        self::assertSame(IncomeTaxComponentTreatment::Exempt, $tax[0]->treatment);
        self::assertSame(350_000, $tax[0]->amountMinorUnits);
        self::assertSame(IncomeTaxComponentTreatment::Included, $tax[1]->treatment);
        self::assertSame(50_000, $tax[1]->amountMinorUnits);
        self::assertSame(SocialComponentTreatment::Excluded, $social[0]->assessmentBaseTreatment);
        self::assertSame(SocialComponentTreatment::Included, $social[1]->assessmentBaseTreatment);
        self::assertSame(50_000, $social[1]->amountMinorUnits);
        self::assertSame(HealthComponentTreatment::Excluded, $health[0]->assessmentBaseTreatment);
        self::assertSame(HealthComponentTreatment::Included, $health[1]->assessmentBaseTreatment);
        self::assertSame(50_000, $health[1]->amountMinorUnits);
    }

    /** Rozpad, který nedává částku vstupu, je vada dat — ne tichá oprava. */
    public function testInconsistentSplitIsRejected(): void
    {
        $mapper = new PayrollRunStatutoryComponentMapper();
        $input = $this->input(['exemption_basket' => 'non_cash_leisure']);
        $input['benefit_basket'] = 'non_cash_leisure';
        $input['benefit_exempt_minor'] = 1;
        $input['benefit_taxable_minor'] = 1;

        $this->expectException(\UnexpectedValueException::class);
        $mapper->incomeTax($input, '2026-08-01');
    }

    /**
     * @param array<string,string> $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = [], ?string $sourcePeriod = null): array
    {
        return [
            'id' => 42,
            'amount_minor' => 125_000,
            'source_period_start' => $sourcePeriod,
            'component' => [
                'code' => 'MZDA_MESICNI',
                'tax_treatment' => 'included',
                'social_participation_treatment' => 'included',
                'social_treatment' => 'included',
                'health_participation_treatment' => 'included',
                'health_treatment' => 'included',
                ...$overrides,
            ],
        ];
    }
}
