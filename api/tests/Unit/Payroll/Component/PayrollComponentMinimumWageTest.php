<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollComponentDefinitionFactory;
use MyInvoice\Service\Payroll\Component\PayrollComponentMinimumWage;
use PHPUnit\Framework\TestCase;

final class PayrollComponentMinimumWageTest extends TestCase
{
    public function testStatutoryExclusionsDoNotFollowTaxOrInsuranceClassification(): void
    {
        foreach (['MZDA_DOSAZENA_PRESCAS', 'PRIPLATEK_PRESCAS', 'PRIPLATEK_SVATEK',
            'PRIPLATEK_NOCNI', 'PRIPLATEK_VIKEND', 'PRIPLATEK_ZTIZENE_PROSTREDI',
            'ODMENA_POHOTOVOST'] as $code) {
            self::assertSame(['treatment' => 'excluded', 'editable' => false],
                PayrollComponentMinimumWage::resolve($this->component($code, 'premium')));
        }
        self::assertSame('included', PayrollComponentMinimumWage::resolve(
            $this->component('SYN_ODMENA', 'bonus') + ['tax_treatment' => 'excluded'],
        )['treatment']);
        foreach (['compensation', 'benefit_meal', 'severance', 'travel_reimbursement'] as $kind) {
            self::assertSame('excluded', PayrollComponentMinimumWage::resolve(
                $this->component('SYN_PLNENI', $kind),
            )['treatment']);
        }
    }

    public function testAmbiguousTitlesRequireExplicitReasonedDecision(): void
    {
        foreach (['hourly_wage', 'premium', 'allowance', 'backpay', 'other'] as $kind) {
            $component = $this->component('SYN_TITUL', $kind);
            self::assertSame(['treatment' => 'manual_review', 'editable' => true],
                PayrollComponentMinimumWage::resolve($component));
            self::assertSame('manual_review', PayrollComponentMinimumWage::resolve(
                $component + ['minimum_wage_treatment' => 'included'],
            )['treatment']);
            self::assertSame('included', PayrollComponentMinimumWage::resolve($component + [
                'minimum_wage_treatment' => 'included',
                'minimum_wage_reason' => 'Výkonnostní odměna za běžnou práci v měsíci.',
            ])['treatment']);
        }
    }

    public function testNaturalWageCannotSatisfyMonetaryMinimumEvenWithExplicitOverride(): void
    {
        $component = [...$this->component('SYN_NATURALNI', 'bonus'),
            'value_kind' => 'non_monetary', 'minimum_wage_treatment' => 'included',
            'minimum_wage_reason' => 'Souhlas zaměstnance.'];
        self::assertSame('excluded', PayrollComponentMinimumWage::resolve($component)['treatment']);
        $this->expectException(\InvalidArgumentException::class);
        PayrollComponentMinimumWage::assertValid($component);
    }

    public function testStatutoryPremiumCannotBeReclassifiedAsOrdinaryWage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollComponentMinimumWage::assertValid($this->component('PRIPLATEK_NOCNI', 'premium') + [
            'minimum_wage_treatment' => 'included', 'minimum_wage_reason' => 'Vlastní rozhodnutí.',
        ]);
    }

    public function testAmbiguousDecisionWithoutReasonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollComponentMinimumWage::assertValid($this->component('SYN_PREMIE', 'premium') + [
            'minimum_wage_treatment' => 'excluded', 'minimum_wage_reason' => ' ',
        ]);
    }

    public function testClassificationDoesNotChangeLegacyComponentSnapshot(): void
    {
        $component = $this->component('SYN_BONUS', 'bonus') + [
            'name' => 'Syntetická odměna', 'frequency_kind' => 'one_off',
            'tax_treatment' => 'included', 'social_participation_treatment' => 'included',
            'social_treatment' => 'included', 'health_participation_treatment' => 'included',
            'health_treatment' => 'included', 'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included', 'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
        ];
        $factory = new PayrollComponentDefinitionFactory();
        self::assertSame($factory->fromArray($component)->snapshot(),
            $factory->fromArray($component + [
                'minimum_wage_treatment' => 'included', 'minimum_wage_reason' => 'Výkon práce.',
                'effective_minimum_wage_treatment' => 'included', 'minimum_wage_treatment_editable' => false,
            ])->snapshot());
    }

    /** @return array<string,mixed> */
    private function component(string $code, string $kind): array
    {
        return ['code' => $code, 'component_kind' => $kind, 'value_kind' => 'monetary'];
    }
}
