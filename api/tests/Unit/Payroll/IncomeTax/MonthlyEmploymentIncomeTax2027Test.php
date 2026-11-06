<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\StudentTaxCredit;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PHPUnit\Framework\TestCase;

/**
 * Měsíční záloha za rok 2027 nad dodanou sadou 2027 a sleva na studenta.
 *
 * Bez sady 2027 by každý výpočet níže skončil `unsupported-tax-year`
 * (a `forCalculation()` by fail-closed selhal) — to je test, který bez změny padá.
 */
final class MonthlyEmploymentIncomeTax2027Test extends TestCase
{
    public function testDppBelowDecisiveAmountIsWithheldAndAtItGoesToAdvance(): void
    {
        $below = $this->calculate('2027-01-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_249_999),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxCalculationStatus::Calculated, $below->status, implode(',', $below->issues));
        self::assertSame(TaxRegime::Withholding, $below->relationships[0]->regime);

        $at = $this->calculate('2027-01-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_250_000),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxCalculationStatus::Calculated, $at->status, implode(',', $at->issues));
        self::assertSame(TaxRegime::Advance, $at->relationships[0]->regime);
        self::assertSame(187_500, $at->advanceTax?->taxAfterCreditsMinorUnits);

        // Hranice 2026 (12 000 Kč) v roce 2027 neplatí: 12 000 Kč je pod
        // rozhodnou částkou 2027, a jde tedy srážkou.
        $old = $this->calculate('2027-01-31', [
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_200_000),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxRegime::Withholding, $old->relationships[0]->regime);
    }

    /**
     * § 6 odst. 4 ZDP 2027: samostatný základ vzniká jen, „pokud zaměstnanec
     * u tohoto plátce daně neučinil prohlášení". Kdo u plátce podepsal
     * prohlášení, má DPP pod rozhodnou částkou (12 499 Kč) v jednom základu
     * zálohy s HPP (pokyn GFŘ-D-59 k § 6 odst. 4). Prohlášení se vede pro
     * zaměstnance plátce (`payroll_person_tax_declarations` podle
     * supplier_id a employee_id), takže se nepřenáší mezi firmami.
     */
    public function testSignedDeclarationPutsDppBelowDecisiveAmountIntoTheAdvanceBase(): void
    {
        $result = $this->calculate('2027-01-31', [
            $this->relationship('hpp', EmploymentRelationshipKind::Employment, 4_000_000),
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_249_900),
        ], TaxDeclarationStatus::Signed);

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame(
            [TaxRegime::Advance, TaxRegime::Advance],
            array_map(static fn ($relationship): TaxRegime => $relationship->regime, $result->relationships),
        );
        self::assertSame(0, $result->withholdingTaxMinorUnits);
        self::assertSame(5_249_900, $result->advanceTax?->taxableIncomeMinorUnits);

        $unsigned = $this->calculate('2027-01-31', [
            $this->relationship('hpp', EmploymentRelationshipKind::Employment, 4_000_000),
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_249_900),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxRegime::Withholding, $unsigned->relationships[1]->regime);
    }

    public function testOtherIncomeUsesDecisiveIncome5000(): void
    {
        $below = $this->calculate('2027-02-28', [
            $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 499_999),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxRegime::Withholding, $below->relationships[0]->regime);

        $at = $this->calculate('2027-02-28', [
            $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 500_000),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxRegime::Advance, $at->relationships[0]->regime);

        // V roce 2026 je rozhodná částka 4 500 Kč, takže 4 800 Kč jde zálohou.
        $in2026 = $this->calculate('2026-12-31', [
            $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 480_000),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxRegime::Advance, $in2026->relationships[0]->regime);
        $in2027 = $this->calculate('2027-01-31', [
            $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 480_000),
        ], TaxDeclarationStatus::NotSigned);
        self::assertSame(TaxRegime::Withholding, $in2027->relationships[0]->regime);
    }

    /** 23 % až nad trojnásobkem průměrné mzdy 2027 (154 989 Kč). */
    public function testHighRateStartsAboveThreeTimesAverageWage2027(): void
    {
        $result = $this->calculate('2027-03-31', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 16_000_000),
        ], TaxDeclarationStatus::Signed);

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        // 154 989 × 15 % + 5 011 × 23 % = 23 248,35 + 1 152,53 = 24 400,88 → 24 401 Kč.
        self::assertSame(2_440_100, $result->advanceTax?->taxBeforeCreditsMinorUnits);
        self::assertSame(2_183_100, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    /** Měsíční bonus od poloviny minimální mzdy 2027, tj. od 12 450 Kč. */
    public function testMonthlyBonusRequiresHalfOfMinimumWage2027(): void
    {
        $child = new TaxChildClaim('child-a', 1, false, '2027-01-01', null, TaxEvidenceStatus::Verified, true, true, 'synthetic-child-evidence');

        $below = $this->calculate('2027-01-31', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 1_244_999),
        ], TaxDeclarationStatus::Signed, childClaims: [$child]);
        self::assertSame(0, $below->advanceTax?->taxBonusMinorUnits);
        self::assertFalse($below->advanceTax?->taxBonusEligible);

        $at = $this->calculate('2027-01-31', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 1_245_000),
        ], TaxDeclarationStatus::Signed, childClaims: [$child]);
        self::assertTrue($at->advanceTax?->taxBonusEligible);
        self::assertSame(126_700, $at->advanceTax?->taxBonusMinorUnits);
    }

    public function testStudentCreditReducesTheAdvanceBy335(): void
    {
        foreach ([TaxCreditKind::Student, TaxCreditKind::StudentDoctoral] as $kind) {
            $result = $this->calculate('2027-04-30', [
                $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
            ], TaxDeclarationStatus::Signed, creditClaims: [$this->student($kind)]);

            self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
            self::assertSame(600_000, $result->advanceTax?->taxBeforeCreditsMinorUnits);
            $expected = ['taxpayer' => 257_000, $kind->value => 33_500];
            ksort($expected, SORT_STRING);
            self::assertSame($expected, $result->claimedNonRefundableCreditBreakdown);
            self::assertSame(309_500, $result->advanceTax?->taxAfterCreditsMinorUnits);
            self::assertSame(
                ['amount_minor_units' => 33_500, 'doctoral' => $kind === TaxCreditKind::StudentDoctoral],
                StudentTaxCredit::fromCreditBreakdown($result->claimedNonRefundableCreditBreakdown),
            );
        }
    }

    /**
     * Ruleset bez slevy na studenta (rok 2026) nárok nespočítá jako nulu —
     * výpočet skončí v ručním posouzení.
     */
    public function testStudentCreditOutside2027RulesetFailsClosed(): void
    {
        $claim = new TaxCreditClaim(
            TaxCreditKind::Student,
            '2026-01-01',
            '2026-12-31',
            TaxEvidenceStatus::Verified,
            'synthetic-school-confirmation',
        );
        $result = $this->calculate('2026-12-31', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
        ], TaxDeclarationStatus::Signed, creditClaims: [$claim]);

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('student-credit-not-in-ruleset', $result->issues);
    }

    public function testNonresidentHasNoStudentCredit(): void
    {
        $result = $this->calculate('2027-04-30', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
        ], TaxDeclarationStatus::Signed, creditClaims: [$this->student(TaxCreditKind::Student)], residence: TaxResidence::NonResident);

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('nonresident-student-credit-not-allowed', $result->issues);
    }

    public function testStudentAndDoctoralClaimAtOnceConflict(): void
    {
        $result = $this->calculate('2027-04-30', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
        ], TaxDeclarationStatus::Signed, creditClaims: [
            $this->student(TaxCreditKind::Student),
            $this->student(TaxCreditKind::StudentDoctoral),
        ]);

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('student-credit-conflict', $result->issues);
    }

    public function testOpenEndedStudentClaimFailsClosed(): void
    {
        $claim = new TaxCreditClaim(
            TaxCreditKind::Student,
            '2027-01-01',
            null,
            TaxEvidenceStatus::Verified,
            'synthetic-school-confirmation',
        );
        $result = $this->calculate('2027-04-30', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
        ], TaxDeclarationStatus::Signed, creditClaims: [$claim]);

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('student-credit-interval-open', $result->issues);
    }

    /** Měsíc po konci intervalu (dovršení věku) slevu nedává. */
    public function testStudentCreditEndsWithTheClaimInterval(): void
    {
        $claim = new TaxCreditClaim(
            TaxCreditKind::Student,
            '2027-01-01',
            StudentTaxCredit::latestEffectiveTo('2001-05-15', TaxCreditKind::Student),
            TaxEvidenceStatus::Verified,
            'synthetic-school-confirmation',
        );
        $may = $this->calculate('2027-05-31', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
        ], TaxDeclarationStatus::Signed, creditClaims: [$claim]);
        self::assertSame(33_500, $may->claimedNonRefundableCreditBreakdown['student'] ?? null);

        $june = $this->calculate('2027-06-30', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
        ], TaxDeclarationStatus::Signed, creditClaims: [$claim]);
        self::assertSame(TaxCalculationStatus::Calculated, $june->status);
        self::assertSame(['taxpayer' => 257_000], $june->claimedNonRefundableCreditBreakdown);
    }

    /**
     * Regrese 2026: výsledky výpočtu roku 2026 jsou se složenou sadou (2025 +
     * 2026 + 2027) bajtově tytéž jako se samotným ročníkem 2026.
     */
    public function testYear2026ResultsAreByteIdenticalWithTheComposedRuleset(): void
    {
        $only2026 = new MonthlyEmploymentIncomeTaxCalculator(new PayrollRulesetProvider([
            CzechPayrollRulesets2026::provider()->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-31'),
        ]));
        $composed = new MonthlyEmploymentIncomeTaxCalculator(CzechPayrollRulesets::provider());

        $json = file_get_contents(dirname(__DIR__, 3) . '/Fixtures/Payroll/income-tax-2026-golden.json');
        self::assertIsString($json);
        $cases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($cases);
        self::assertNotSame([], $cases);

        $inputs = [];
        foreach ($cases as $case) {
            $relationships = [];
            foreach ($case['relationships'] as $relationship) {
                $relationships[] = $this->relationship(
                    (string) $relationship['reference'],
                    EmploymentRelationshipKind::from((string) $relationship['kind']),
                    (int) $relationship['amount_minor'],
                );
            }
            $inputs[] = $this->input(
                '2026-08-31',
                $relationships,
                TaxDeclarationStatus::from((string) $case['declaration']),
                residence: TaxResidence::from((string) $case['residence']),
            );
        }
        $inputs[] = $this->input('2026-08-31', [
            $this->relationship('employment', EmploymentRelationshipKind::Employment, 3_500_000),
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 900_000),
        ], TaxDeclarationStatus::Signed, creditClaims: [
            new TaxCreditClaim(TaxCreditKind::DisabilityBasic, '2026-01-01', null, TaxEvidenceStatus::Verified, 'e'),
            new TaxCreditClaim(TaxCreditKind::ZtpP, '2026-01-01', null, TaxEvidenceStatus::Verified, 'e'),
        ], childClaims: [
            new TaxChildClaim('child-a', 1, true, '2026-01-01', null, TaxEvidenceStatus::Verified, true, true, 'e'),
        ]);

        foreach ($inputs as $index => $input) {
            self::assertSame(
                serialize($only2026->calculate($input)),
                serialize($composed->calculate($input)),
                "případ {$index}",
            );
        }
    }

    /**
     * @param list<EmploymentRelationshipTaxInput> $relationships
     * @param list<TaxCreditClaim> $creditClaims
     * @param list<TaxChildClaim> $childClaims
     */
    private function calculate(
        string $date,
        array $relationships,
        TaxDeclarationStatus $declaration,
        array $creditClaims = [],
        array $childClaims = [],
        TaxResidence $residence = TaxResidence::CzechResident,
    ): MonthlyEmploymentIncomeTaxResult {
        return (new MonthlyEmploymentIncomeTaxCalculator(CzechPayrollRulesets::provider()))->calculate(
            $this->input($date, $relationships, $declaration, $creditClaims, $childClaims, $residence),
        );
    }

    /**
     * @param list<EmploymentRelationshipTaxInput> $relationships
     * @param list<TaxCreditClaim> $creditClaims
     * @param list<TaxChildClaim> $childClaims
     */
    private function input(
        string $date,
        array $relationships,
        TaxDeclarationStatus $declaration,
        array $creditClaims = [],
        array $childClaims = [],
        TaxResidence $residence = TaxResidence::CzechResident,
    ): MonthlyEmploymentIncomeTaxInput {
        $year = substr($date, 0, 4);

        return new MonthlyEmploymentIncomeTaxInput(
            calculationDate: $date,
            employeeReference: 'synthetic-employee',
            relationships: $relationships,
            declarations: [new TaxDeclarationEvidence($declaration, "{$year}-01-01", null, 'synthetic-declaration-evidence')],
            residence: new TaxResidenceEvidence(
                $residence,
                "{$year}-01-01",
                null,
                'synthetic-residence-evidence',
            ),
            creditClaims: $creditClaims,
            childClaims: $childClaims,
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

    private function student(TaxCreditKind $kind): TaxCreditClaim
    {
        return new TaxCreditClaim(
            $kind,
            '2027-01-01',
            '2027-12-31',
            TaxEvidenceStatus::Verified,
            'synthetic-school-confirmation',
        );
    }
}
