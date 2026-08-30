<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use PHPUnit\Framework\TestCase;

final class AnnualTaxCertificateSnapshotBuilderTest extends TestCase
{
    public function testMapsAdvanceAndWithholdingRegimesWithoutMixingThem(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'monthAmounts',
        );
        $person = self::person();
        $inputPerson = self::inputPerson();

        $advance = $method->invoke(
            $builder,
            $person,
            $inputPerson,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );
        self::assertSame(10_000_000, $advance['income_minor_units']);
        self::assertSame(1_250_000, $advance['tax_minor_units']);
        self::assertSame(50_000, $advance['tax_bonus_minor_units']);
        self::assertSame(7_000_000, $advance['expected_net_minor_units']);

        $withholding = $method->invoke(
            $builder,
            $person,
            $inputPerson,
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
            2026,
        );
        self::assertSame(2_000_000, $withholding['income_minor_units']);
        self::assertSame(300_000, $withholding['tax_minor_units']);
        self::assertSame(0, $withholding['tax_bonus_minor_units']);
    }

    public function testRejectsBackpayThatCannotBeSplitIntoOfficialRows(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'monthAmounts',
        );
        $input = self::inputPerson();
        $input['employments'][0]['inputs'][0]['component']['component_kind']
            = 'backpay';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Doplatek');
        $method->invoke(
            $builder,
            self::person(),
            $input,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );
    }

    /**
     * Druh složky si účetní volí volně, takže příspěvek na doplňkové penzijní
     * spoření nebo DIP může být klidně `other`. Jediné, co ho spolehlivě
     * označí, je zákonný koš § 6 odst. 9 písm. m) zmrazený u vstupu — a přesně
     * ten patří na řádek 10 tiskopisu, který snapshot neumí naplnit.
     */
    public function testRejectsOldAgeSavingsBasketRegardlessOfComponentKind(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'monthAmounts',
        );
        $input = self::inputPerson();
        $input['employments'][0]['inputs'][0]['component']['component_kind']
            = 'other';
        $input['employments'][0]['inputs'][0]['benefit_basket']
            = 'old_age_savings';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('řádku 10');
        $method->invoke(
            $builder,
            self::person(),
            $input,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );
    }

    /**
     * Doplatek za minulý rok (řádky 4 a 7) se nemusí jmenovat `backpay` —
     * pozná se podle období původu, ne podle druhu složky.
     */
    public function testRejectsPriorYearSourcePeriodOnAnyComponentKind(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'monthAmounts',
        );
        $input = self::inputPerson();
        $input['employments'][0]['inputs'][0]['component']['component_kind']
            = 'bonus';
        $input['employments'][0]['inputs'][0]['source_period_start']
            = '2025-12-01';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Doplatek');
        $method->invoke(
            $builder,
            self::person(),
            $input,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );
    }

    /** Doplatek v rámci TÉHOŽ roku na řádek 4 nepatří a potvrzení neblokuje. */
    public function testAcceptsSameYearSourcePeriod(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'monthAmounts',
        );
        $input = self::inputPerson();
        $input['employments'][0]['inputs'][0]['source_period_start']
            = '2026-03-01';

        $amounts = $method->invoke(
            $builder,
            self::person(),
            $input,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );

        self::assertSame(10_000_000, $amounts['income_minor_units']);
    }

    public function testRejectsNonCashIncomeWithoutActualReceiptEvidence(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'monthAmounts',
        );
        $person = self::person();
        $person['statutory']['net_pay']['non_cash_income_minor_units'] = 10_000;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Nepeněžní');
        $method->invoke(
            $builder,
            $person,
            self::inputPerson(),
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );
    }

    public function testRejectsMissingDeclarationEvidenceForAdvanceCertificate(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'monthAmounts',
        );
        $input = self::inputPerson();
        unset($input['statutory_evidence']['income_tax']['declaration']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Prohlášení');
        $method->invoke(
            $builder,
            self::person(),
            $input,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );
    }

    public function testRejectsUnverifiedDeclarationEvidenceForAdvanceCertificate(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'monthAmounts',
        );
        $input = self::inputPerson();
        $input['statutory_evidence']['income_tax']['declaration']['status'] =
            'unverified';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Prohlášení');
        $method->invoke(
            $builder,
            self::person(),
            $input,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );
    }

    public function testAcceptsDocumentedDisabilityAndChildEvidence(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'supportedInputEvidence',
        );
        $input = self::inputPerson();
        $input['statutory_evidence']['income_tax']['credit_claims'][] = [
            'credit_kind' => 'disability-extended',
            'evidence_status' => 'verified',
        ];
        $input['statutory_evidence']['income_tax']['child_claims'][] = [
            'child_reference' => 'dependant-7',
            'child_order' => 1,
            'ztp_p' => true,
            'evidence_status' => 'verified',
            'shared_household_confirmed' => true,
            'other_claimant_excluded' => true,
        ];

        $evidence = $method->invoke(
            $builder,
            $input,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            2026,
        );

        self::assertSame([
            'status' => 'czech-resident',
            'country_code' => 'CZ',
        ], $evidence['residence']);
        self::assertSame(
            ['taxpayer', 'disability-extended'],
            array_column($evidence['credit_claims'], 'credit_kind'),
        );
        self::assertSame('dependant-7', $evidence['child_claims'][0]['child_reference']);
    }

    public function testAcceptsDocumentedEeaNonresidentWithoutResidentOnlyCredits(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'supportedInputEvidence',
        );
        $input = self::inputPerson();
        $input['statutory_evidence']['income_tax']['residence'] = [
            'residence' => 'non-resident',
            'country_code' => 'SK',
        ];

        $evidence = $method->invoke(
            $builder,
            $input,
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
            2026,
        );

        self::assertSame([
            'status' => 'non-resident',
            'country_code' => 'SK',
        ], $evidence['residence']);
    }

    public function testWithholdingCertificateRejectsNonresidentOutsideEuAndEea(): void
    {
        $builder = (new \ReflectionClass(
            AnnualTaxCertificateSnapshotBuilder::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'supportedInputEvidence',
        );
        $input = self::inputPerson();
        $input['statutory_evidence']['income_tax']['residence'] = [
            'residence' => 'non-resident',
            'country_code' => 'US',
        ];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('EU nebo EHP');
        $method->invoke(
            $builder,
            $input,
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
            2026,
        );
    }

    /** @return array<string,mixed> */
    private static function person(): array
    {
        return [
            'statutory' => [
                'status' => 'calculated',
                'income_tax' => [
                    'status' => 'calculated',
                    'advance_tax' => [
                        'taxable_income_minor_units' => 10_000_000,
                    ],
                    'withholding_base_minor_units' => 2_000_000,
                    'withholding_tax_minor_units' => 300_000,
                ],
                'net_pay' => [
                    'non_cash_income_minor_units' => 0,
                    'advance_tax_minor_units' => 1_250_000,
                    'withholding_tax_minor_units' => 300_000,
                    'tax_bonus_minor_units' => 50_000,
                ],
            ],
            'payable_after_enforcement_minor' => 7_000_000,
        ];
    }

    /** @return array<string,mixed> */
    private static function inputPerson(): array
    {
        return [
            'statutory_evidence' => [
                'income_tax' => [
                    'declaration' => [
                        'status' => 'signed',
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ],
                    'residence' => [
                        'residence' => 'czech-resident',
                        'country_code' => 'CZ',
                    ],
                    'credit_claims' => [[
                        'credit_kind' => 'taxpayer',
                        'evidence_status' => 'verified',
                    ]],
                    'child_claims' => [],
                ],
            ],
            'employments' => [[
                'inputs' => [[
                    'amount_minor' => 12_000_000,
                    'source_period_start' => null,
                    // Tvar, který do zmrazeného snapshotu reálně píše
                    // PayrollComponentDefinition::snapshot() — klíč
                    // `component_kind`, ne `kind`, a koš osvobození vedle
                    // složky, ne uvnitř ní.
                    'component' => ['component_kind' => 'base_wage'],
                ]],
            ]],
        ];
    }
}
