<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\OtherWithholdingEligibility;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * § 36 odst. 3 ZDP — u zvláštní sazby daně se zaokrouhluje DVAKRÁT.
 *
 * Věta třetí: „Základ daně se nesnižuje o nezdanitelnou část základu daně
 * (§ 15) a zaokrouhluje se na celé koruny dolů…“
 * Věta pátá: „Daň z příjmů vybíraná zvláštní sazbou se zaokrouhluje na celé
 * koruny dolů.“
 *
 * Modul dřív zaokrouhloval jen daň. Rozdíl je do koruny, ale vykázaný základ
 * neodpovídal přepočtu finančního úřadu — a základ se vykazuje (Vyúčtování
 * daně vybírané srážkou, tiskopis 25 5466).
 */
final class WithholdingTaxBaseRoundingTest extends TestCase
{
    /**
     * Odměna z DPP 9 999,99 Kč, tedy 999 999 haléřů — pod rozhodnou částkou
     * 12 000 Kč, takže jde srážkovou daní (§ 6 odst. 4).
     *
     * Základ:  999 999 h → zaokrouhleno dolů na celé koruny → 999 900 h (9 999 Kč)
     * Daň:     15 % z 9 999 Kč = 1 499,85 Kč → dolů → 1 499 Kč = 149 900 h
     *
     * Bez zaokrouhlení základu by daň vyšla z 9 999,99 Kč jako 1 499,9985 Kč,
     * tedy po zaokrouhlení dolů také 1 499 Kč — daň se tu tedy nezmění, ale
     * VYKÁZANÝ ZÁKLAD ano: 9 999 Kč místo 9 999,99 Kč.
     */
    public function testWithholdingBaseIsFlooredToWholeCrowns(): void
    {
        $result = $this->calculate(999_999);

        self::assertCount(1, $result->withholdingGroups);
        $group = $result->withholdingGroups[0];

        self::assertSame(999_900, $group->baseMinorUnits);
        self::assertSame(999_999, $group->unroundedBaseMinorUnits);
        self::assertSame(0, $group->baseMinorUnits % 100, 'Základ musí být v celých korunách.');
        self::assertSame(149_900, $group->taxMinorUnits);
        self::assertSame(0, $group->taxMinorUnits % 100, 'Daň musí být v celých korunách.');

        // Do ročního úhrnu jde vykázaný, tedy zaokrouhlený základ.
        self::assertSame(999_900, $result->withholdingBaseMinorUnits);
    }

    /**
     * Případ, kde zaokrouhlení základu mění i DAŇ. Základ 1 000 099 haléřů
     * (10 000,99 Kč):
     *
     *   staré chování: 15 % z 10 000,99 Kč = 1 500,1485 Kč → dolů → 1 500 Kč
     *   nové chování:  základ dolů na 10 000 Kč, 15 % = 1 500,00 Kč → 1 500 Kč
     *
     * Daň sedí, základ se liší o 99 haléřů. Ať tedy ukážeme i případ, kde se
     * pohne obojí: 1 000 699 h (10 006,99 Kč).
     *
     *   staré: 15 % z 10 006,99 = 1 501,0485 → dolů → 1 501 Kč
     *   nové:  základ 10 006 Kč, 15 % = 1 500,90 → dolů → 1 500 Kč
     */
    public function testFlooringTheBaseAlsoMovesTheTax(): void
    {
        $result = $this->calculate(1_000_699);

        $group = $result->withholdingGroups[0];
        self::assertSame(1_000_600, $group->baseMinorUnits);
        self::assertSame(150_000, $group->taxMinorUnits);
    }

    /** Haléřová odměna nedá po zaokrouhlení základu žádnou daň ani žádný základ. */
    public function testSubCrownBaseYieldsNoWithholdingGroup(): void
    {
        $result = $this->calculate(99);

        self::assertSame([], $result->withholdingGroups);
        self::assertSame(0, $result->withholdingBaseMinorUnits);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
    }

    private function calculate(int $amountMinorUnits): \MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult
    {
        return (new MonthlyEmploymentIncomeTaxCalculator(
            CzechPayrollRulesets2026::provider(),
        ))->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                new EmploymentRelationshipTaxInput(
                    'dpp',
                    'synthetic-payer',
                    EmploymentRelationshipKind::Dpp,
                    [new IncomeTaxComponent('synthetic-income', $amountMinorUnits)],
                    OtherWithholdingEligibility::Automatic,
                ),
            ],
            declarations: [new TaxDeclarationEvidence(
                TaxDeclarationStatus::NotSigned,
                '2026-01-01',
                null,
                'synthetic-declaration-evidence',
            )],
            residence: new TaxResidenceEvidence(
                TaxResidence::CzechResident,
                '2026-01-01',
                null,
                'synthetic-residence-evidence',
            ),
        ));
    }
}
