<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\SeparateTaxBaseRegime;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use PHPUnit\Framework\TestCase;

/**
 * Samostatný základ ZÁLOHY podle § 6 odst. 4 ZDP od 1. 1. 2027.
 *
 * § 6 odst. 4 ve znění zák. č. 360/2025 Sb.: příjmy DPP pod rozhodnou částkou
 * (2027: 12 500 Kč) a ostatní příjmy pod rozhodným příjmem (5 000 Kč) bez
 * prohlášení u téhož plátce „jsou samostatným základem pro výpočet zálohy …
 * sazbou zálohy podle § 38h". § 6 odst. 18 (zák. č. 180/2026 Sb.): základ
 * i záloha „se zaokrouhluje na celé koruny nahoru".
 *
 * Bez změny výpočtu se rok 2027 počítal jako srážková daň (dolů), takže každý
 * test se zaokrouhlením na haléřích a každý test na klíč režimu bez ní padá.
 */
final class SeparateAdvanceBase2027Test extends TestCase
{
    public function testDppJustBelowDecisiveAmountIsSeparateAdvanceRoundedUp(): void
    {
        // 12 499,50 Kč → základ nahoru 12 500 Kč, 15 % = 1 875 Kč.
        // Po staru (dolů): základ 12 499 Kč, daň 1 874,85 → 1 874 Kč.
        $result = $this->calculate('2027-01-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_249_950),
        ], TaxDeclarationStatus::NotSigned);

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame(TaxRegime::Withholding, $result->relationships[0]->regime);
        self::assertSame(SeparateTaxBaseRegime::SeparateAdvance, $result->separateBaseRegime);
        self::assertSame(1_250_000, $result->withholdingBaseMinorUnits);
        self::assertSame(187_500, $result->withholdingTaxMinorUnits);
        self::assertSame(1_249_950, $result->separateBaseIncomeMinorUnits);
        self::assertSame(0, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    public function testTaxIsRoundedUpToWholeCrowns(): void
    {
        // 12 499 Kč × 15 % = 1 874,85 → nahoru 1 875 Kč (dolů by bylo 1 874).
        $result = $this->calculate('2027-02-28', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_249_900),
        ], TaxDeclarationStatus::NotSigned);

        self::assertSame(1_249_900, $result->withholdingBaseMinorUnits);
        self::assertSame(187_500, $result->withholdingTaxMinorUnits);
        self::assertSame('monthly-separate-advance-tax-dpp', $result->withholdingGroups[0]->rateStep->label);
    }

    public function testDppAtDecisiveAmountGoesToOrdinaryAdvance(): void
    {
        $result = $this->calculate('2027-01-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_250_000),
        ], TaxDeclarationStatus::NotSigned);

        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
        self::assertSame(0, $result->separateBaseIncomeMinorUnits);
        self::assertSame(187_500, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    public function testSignedDeclarationExcludesSeparateBase(): void
    {
        $result = $this->calculate('2027-01-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_000_000),
        ], TaxDeclarationStatus::Signed);

        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
        // 10 000 × 15 % = 1 500 − sleva na poplatníka 2 570 → 0.
        self::assertSame(0, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    public function testLetterBOtherIncomeBelowDecisiveIncomeRoundsUp(): void
    {
        // DPČ 4 999,01 Kč → základ 5 000 Kč (nahoru), 750 Kč.
        $result = $this->calculate('2027-03-31', [
            $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 499_901),
        ], TaxDeclarationStatus::NotSigned);

        self::assertSame(TaxRegime::Withholding, $result->relationships[0]->regime);
        self::assertSame(500_000, $result->withholdingBaseMinorUnits);
        self::assertSame(75_000, $result->withholdingTaxMinorUnits);
    }

    /**
     * Souběh dvou DPP u téhož plátce: rozhoduje ÚHRN. 6 000 + 6 499 = 12 499 Kč
     * jde do samostatného základu, 6 000 + 6 500 = 12 500 Kč do zálohy.
     */
    public function testTwoAgreementsAtTheSamePayerAreSummed(): void
    {
        $below = $this->calculate('2027-04-30', [
            $this->relationship('dpp-a', EmploymentRelationshipKind::Dpp, 600_000),
            $this->relationship('dpp-b', EmploymentRelationshipKind::Dpp, 649_900),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxRegime::Withholding, $below->relationships[0]->regime);
        self::assertSame(TaxRegime::Withholding, $below->relationships[1]->regime);
        self::assertCount(1, $below->withholdingGroups);
        self::assertSame(1_249_900, $below->withholdingBaseMinorUnits);
        self::assertSame(187_500, $below->withholdingTaxMinorUnits);

        $at = $this->calculate('2027-04-30', [
            $this->relationship('dpp-a', EmploymentRelationshipKind::Dpp, 600_000),
            $this->relationship('dpp-b', EmploymentRelationshipKind::Dpp, 650_000),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxRegime::Advance, $at->relationships[0]->regime);
        self::assertSame(TaxRegime::Advance, $at->relationships[1]->regime);
        self::assertSame(0, $at->withholdingTaxMinorUnits);
    }

    public function testFrozenResultCarriesRegimeAndIncomeForJmhz(): void
    {
        $result = $this->calculate('2027-01-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_249_950),
        ], TaxDeclarationStatus::NotSigned);
        $json = $result->jsonSerialize();

        self::assertSame('separate-advance', $json['separate_base_regime'] ?? null);
        self::assertSame(1_249_950, $json['separate_base_income_minor_units'] ?? null);
        self::assertSame(1_250_000, $json['withholding_base_minor_units']);
        self::assertSame(187_500, $json['withholding_tax_minor_units']);
        self::assertSame(SeparateTaxBaseRegime::SeparateAdvance, SeparateTaxBaseRegime::fromResultSnapshot($json));
    }

    /** JMHZ 10555 je údaj vztahu: dílčí příjem v samostatném základu, jinak 0. */
    public function testRelationshipCarriesItsSection6Paragraph4Income(): void
    {
        $result = $this->calculate('2027-05-31', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 3_000_000),
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 400_050),
        ], TaxDeclarationStatus::NotSigned);

        $byReference = [];
        foreach ($result->relationships as $relationship) {
            $byReference[$relationship->relationshipReference] = $relationship->jsonSerialize();
        }
        self::assertSame(0, $byReference['employment']['separate_base_income_minor_units'] ?? null);
        self::assertSame(400_050, $byReference['dpp']['separate_base_income_minor_units'] ?? null);

        $in2026 = $this->calculate('2026-05-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 400_050),
        ], TaxDeclarationStatus::NotSigned);
        self::assertArrayNotHasKey('separate_base_income_minor_units', $in2026->relationships[0]->jsonSerialize());
    }

    /**
     * Regrese 2026: srážka dolů a výsledek BEZ klíčů režimu (bajtově jako dřív).
     */
    public function testYear2026StaysFinalWithholdingRoundedDown(): void
    {
        $result = $this->calculate('2026-12-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_149_950),
        ], TaxDeclarationStatus::NotSigned);
        $json = $result->jsonSerialize();

        self::assertSame(SeparateTaxBaseRegime::FinalWithholding, $result->separateBaseRegime);
        self::assertSame(1_149_900, $result->withholdingBaseMinorUnits);
        // 11 499 × 15 % = 1 724,85 → dolů 1 724.
        self::assertSame(172_400, $result->withholdingTaxMinorUnits);
        self::assertArrayNotHasKey('separate_base_regime', $json);
        self::assertArrayNotHasKey('separate_base_income_minor_units', $json);
        self::assertSame('monthly-withholding-tax-dpp', $result->withholdingGroups[0]->rateStep->label);
        self::assertSame(SeparateTaxBaseRegime::FinalWithholding, SeparateTaxBaseRegime::fromResultSnapshot($json));
    }

    /** Prosincová mzda 2026 (datum výpočtu 31. 12. 2026) zůstává srážkou. */
    public function testRegimeFollowsTaxPeriodNotPaymentDate(): void
    {
        self::assertSame(SeparateTaxBaseRegime::FinalWithholding, SeparateTaxBaseRegime::forCalculationDate('2026-12-31'));
        self::assertSame(SeparateTaxBaseRegime::SeparateAdvance, SeparateTaxBaseRegime::forCalculationDate('2027-01-31'));
    }

    public function testResultOf2027WithoutRegimeKeyFailsClosed(): void
    {
        $this->expectException(\DomainException::class);
        SeparateTaxBaseRegime::fromResultSnapshot(['calculation_date' => '2027-01-31']);
    }

    /**
     * @param list<EmploymentRelationshipTaxInput> $relationships
     */
    private function calculate(
        string $date,
        array $relationships,
        TaxDeclarationStatus $declaration,
    ): MonthlyEmploymentIncomeTaxResult {
        $year = substr($date, 0, 4);

        return (new MonthlyEmploymentIncomeTaxCalculator(CzechPayrollRulesets::provider()))->calculate(
            new MonthlyEmploymentIncomeTaxInput(
                calculationDate: $date,
                employeeReference: 'synthetic-employee',
                relationships: $relationships,
                declarations: [new TaxDeclarationEvidence($declaration, "{$year}-01-01", null, 'synthetic-declaration-evidence')],
                residence: new TaxResidenceEvidence(
                    TaxResidence::CzechResident,
                    "{$year}-01-01",
                    null,
                    'synthetic-residence-evidence',
                ),
                creditClaims: [],
                childClaims: [],
            ),
        );
    }

    private function relationship(
        string $reference,
        EmploymentRelationshipKind $kind,
        int $amountMinorUnits,
    ): EmploymentRelationshipTaxInput {
        return new EmploymentRelationshipTaxInput(
            $reference,
            'synthetic-payer',
            $kind,
            [new IncomeTaxComponent('synthetic-income', $amountMinorUnits)],
        );
    }
}
