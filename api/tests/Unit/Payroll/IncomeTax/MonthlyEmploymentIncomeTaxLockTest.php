<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\OtherWithholdingEligibility;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use PHPUnit\Framework\TestCase;

/**
 * MZ-02-W08 — zamykací test.
 *
 * Daňové parametry se stěhovaly z {@see \MyInvoice\Service\Payroll\IncomeTax\EmploymentIncomeTaxPolicy2026}
 * (druhá kopie hodnot v kódu) do registry rulesetů. Byl to přesun, ne změna:
 * tenhle test drží vypočtenou zálohu, srážkovou daň, slevy, daňové zvýhodnění
 * i bonus **na haléř** takové, jaké byly před refaktoringem.
 *
 * Hodnoty jsou pořízené z běhu PŘED přesunem — když se kterákoli změní,
 * neproběhl přesun, ale zásah do peněz.
 */
final class MonthlyEmploymentIncomeTaxLockTest extends TestCase
{
    public function testCalculationResultsAreUnchangedByTheRulesetMigration(): void
    {
        $calculator = new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([self::activeIncomeTaxRuleset()]),
        );

        $actual = [];
        foreach (self::scenarios() as $name => $input) {
            $actual[$name] = self::snapshot($calculator->calculate($input));
        }

        self::assertSame(
            self::EXPECTED,
            $actual,
            'Přesun daňových parametrů do rulesetu změnil vypočtenou daň.',
        );
    }

    /**
     * Pořízeno z běhu před MZ-02-W08 (policy třída jako zdroj hodnot).
     *
     * @var array<string, array<string, mixed>>
     */
    private const EXPECTED = [
        'signed-credits-children-bonus' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 4_790_000,
            'rounded_base' => 4_790_000,
            'low_rate_base' => 4_790_000,
            'high_rate_base' => 0,
            'tax_before_credits' => 718_500,
            'non_refundable_credits' => 412_500,
            'tax_after_credits' => 0,
            'tax_bonus' => 133_400,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 412_500,
            'applied_non_refundable' => 412_500,
            'claimed_child' => 439_400,
            'applied_child' => 306_000,
            'regimes' => ['advance', 'advance', 'advance'],
            'annual_bonus_threshold_met' => false,
        ],
        'disability-extended-credit' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 4_000_000,
            'rounded_base' => 4_000_000,
            'low_rate_base' => 4_000_000,
            'high_rate_base' => 0,
            'tax_before_credits' => 600_000,
            'non_refundable_credits' => 299_000,
            'tax_after_credits' => 301_000,
            'tax_bonus' => 0,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 299_000,
            'applied_non_refundable' => 299_000,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => false,
        ],
        'paragraph-38h-threshold-exact' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 14_690_100,
            'rounded_base' => 14_700_000,
            'low_rate_base' => 14_690_100,
            'high_rate_base' => 9_900,
            'tax_before_credits' => 2_205_800,
            'non_refundable_credits' => 257_000,
            'tax_after_credits' => 1_948_800,
            'tax_bonus' => 0,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 257_000,
            'applied_non_refundable' => 257_000,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => true,
        ],
        'paragraph-38h-threshold-above' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 15_690_100,
            'rounded_base' => 15_700_000,
            'low_rate_base' => 14_690_100,
            'high_rate_base' => 1_009_900,
            'tax_before_credits' => 2_435_800,
            'non_refundable_credits' => 257_000,
            'tax_after_credits' => 2_178_800,
            'tax_bonus' => 0,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 257_000,
            'applied_non_refundable' => 257_000,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => true,
        ],
        'dpp-withholding-at-maximum' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 0,
            'rounded_base' => 0,
            'low_rate_base' => 0,
            'high_rate_base' => 0,
            'tax_before_credits' => 0,
            'non_refundable_credits' => 0,
            'tax_after_credits' => 0,
            'tax_bonus' => 0,
            'withholding_base' => 1_199_900,
            'withholding_tax' => 179_900,
            'claimed_non_refundable' => 0,
            'applied_non_refundable' => 0,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['withholding'],
            'annual_bonus_threshold_met' => false,
        ],
        'dpp-withholding-above-maximum' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 1_200_000,
            'rounded_base' => 1_200_000,
            'low_rate_base' => 1_200_000,
            'high_rate_base' => 0,
            'tax_before_credits' => 180_000,
            'non_refundable_credits' => 0,
            'tax_after_credits' => 180_000,
            'tax_bonus' => 0,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 0,
            'applied_non_refundable' => 0,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => false,
        ],
        'other-withholding-at-maximum' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 0,
            'rounded_base' => 0,
            'low_rate_base' => 0,
            'high_rate_base' => 0,
            'tax_before_credits' => 0,
            'non_refundable_credits' => 0,
            'tax_after_credits' => 0,
            'tax_bonus' => 0,
            'withholding_base' => 449_900,
            'withholding_tax' => 67_400,
            'claimed_non_refundable' => 0,
            'applied_non_refundable' => 0,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['withholding'],
            'annual_bonus_threshold_met' => false,
        ],
        'other-withholding-above-maximum' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 450_000,
            'rounded_base' => 450_000,
            'low_rate_base' => 450_000,
            'high_rate_base' => 0,
            'tax_before_credits' => 67_500,
            'non_refundable_credits' => 0,
            'tax_after_credits' => 67_500,
            'tax_bonus' => 0,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 0,
            'applied_non_refundable' => 0,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => false,
        ],
        /*
         * ZMĚNĚNO PROTI PŮVODNÍMU ZÁMKU, a to vědomě — není to posun peněz
         * refaktoringem, ale oprava zařazení.
         *
         * Scénář je člen orgánu — nerezident, 4 000 Kč, bez prohlášení
         * poplatníka a BEZ prohlášení plátce o zařazení podle § 6 odst. 4
         * (`OtherWithholdingEligibility::Automatic`). Výpočet ho tiše zdaňoval
         * zálohou jen proto, že šlo o nerezidenta; u rezidenta ve stejné situaci
         * vrací ruční posouzení, protože sjednanou odměnu proti rozhodné částce
         * nezná. Od 1. 1. 2026 (zák. č. 360/2025 Sb., čl. VI body 24 a 25) není
         * důvod obě situace rozlišovat — odměna člena orgánu — fyzické osoby
         * vypadla z § 22 odst. 1 písm. g) bodu 6 do bodu 15, který § 36 odst. 1
         * nevyjmenovává. Podrobné odůvodnění se zdroji je v `candidateGroup()`.
         */
        'nonresident-statutory-body' => [
            'status' => 'manual-review',
            'issues' => ['other-withholding-eligibility-unverified'],
            'advance_base' => null,
            'rounded_base' => null,
            'low_rate_base' => null,
            'high_rate_base' => null,
            'tax_before_credits' => null,
            'non_refundable_credits' => null,
            'tax_after_credits' => null,
            'tax_bonus' => null,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 0,
            'applied_non_refundable' => 0,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['manual-review'],
            'annual_bonus_threshold_met' => false,
        ],
        /*
         * NOVÝ scénář — týž člověk, ale s doloženým zařazením podle § 6 odst. 4
         * písm. b). Srážka 15 % ze 4 000 Kč = 600 Kč, sazba podle § 36 odst. 2
         * písm. m). Zámek tím nepřichází o pokrytou peněžní cestu, kterou měl
         * záznam výš.
         */
        'nonresident-statutory-body-withholding' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 0,
            'rounded_base' => 0,
            'low_rate_base' => 0,
            'high_rate_base' => 0,
            'tax_before_credits' => 0,
            'non_refundable_credits' => 0,
            'tax_after_credits' => 0,
            'tax_bonus' => 0,
            'withholding_base' => 400_000,
            'withholding_tax' => 60_000,
            'claimed_non_refundable' => 0,
            'applied_non_refundable' => 0,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['withholding'],
            'annual_bonus_threshold_met' => false,
        ],
        'nonresident-taxpayer-credit-only' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 5_000_000,
            'rounded_base' => 5_000_000,
            'low_rate_base' => 5_000_000,
            'high_rate_base' => 0,
            'tax_before_credits' => 750_000,
            'non_refundable_credits' => 257_000,
            'tax_after_credits' => 493_000,
            'tax_bonus' => 0,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 257_000,
            'applied_non_refundable' => 257_000,
            'claimed_child' => 0,
            'applied_child' => 0,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => false,
        ],
        'bonus-below-minimum-income' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 1_119_900,
            'rounded_base' => 1_120_000,
            'low_rate_base' => 1_120_000,
            'high_rate_base' => 0,
            'tax_before_credits' => 168_000,
            'non_refundable_credits' => 257_000,
            'tax_after_credits' => 0,
            'tax_bonus' => 0,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 257_000,
            'applied_non_refundable' => 168_000,
            'claimed_child' => 126_700,
            'applied_child' => 0,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => false,
        ],
        'bonus-at-minimum-income' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 1_120_000,
            'rounded_base' => 1_120_000,
            'low_rate_base' => 1_120_000,
            'high_rate_base' => 0,
            'tax_before_credits' => 168_000,
            'non_refundable_credits' => 257_000,
            'tax_after_credits' => 0,
            'tax_bonus' => 126_700,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 257_000,
            'applied_non_refundable' => 168_000,
            'claimed_child' => 126_700,
            'applied_child' => 0,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => false,
        ],
        'three-children-orders' => [
            'status' => 'calculated',
            'issues' => [],
            'advance_base' => 6_000_000,
            'rounded_base' => 6_000_000,
            'low_rate_base' => 6_000_000,
            'high_rate_base' => 0,
            'tax_before_credits' => 900_000,
            'non_refundable_credits' => 257_000,
            'tax_after_credits' => 98_300,
            'tax_bonus' => 0,
            'withholding_base' => 0,
            'withholding_tax' => 0,
            'claimed_non_refundable' => 257_000,
            'applied_non_refundable' => 257_000,
            'claimed_child' => 544_700,
            'applied_child' => 544_700,
            'regimes' => ['advance'],
            'annual_bonus_threshold_met' => false,
        ],
    ];

    /** @return array<string, mixed> */
    private static function snapshot(MonthlyEmploymentIncomeTaxResult $result): array
    {
        return [
            'status' => $result->status->value,
            'issues' => $result->issues,
            'advance_base' => $result->advanceTax?->taxableIncomeMinorUnits,
            'rounded_base' => $result->advanceTax?->roundedTaxBaseMinorUnits,
            'low_rate_base' => $result->advanceTax?->lowRateBaseMinorUnits,
            'high_rate_base' => $result->advanceTax?->highRateBaseMinorUnits,
            'tax_before_credits' => $result->advanceTax?->taxBeforeCreditsMinorUnits,
            'non_refundable_credits' => $result->advanceTax?->nonRefundableCreditsMinorUnits,
            'tax_after_credits' => $result->advanceTax?->taxAfterCreditsMinorUnits,
            'tax_bonus' => $result->advanceTax?->taxBonusMinorUnits,
            'withholding_base' => $result->withholdingBaseMinorUnits,
            'withholding_tax' => $result->withholdingTaxMinorUnits,
            'claimed_non_refundable' => $result->claimedNonRefundableCreditsMinorUnits,
            'applied_non_refundable' => $result->appliedNonRefundableCreditsMinorUnits,
            'claimed_child' => $result->claimedChildCreditMinorUnits,
            'applied_child' => $result->appliedChildCreditMinorUnits,
            'regimes' => array_map(
                static fn ($relationship): string => $relationship->regime->value,
                $result->relationships,
            ),
            'annual_bonus_threshold_met' =>
                $result->annualAccumulator->annualBonusIncomeThresholdMet,
        ];
    }

    /** @return array<string, MonthlyEmploymentIncomeTaxInput> */
    private static function scenarios(): array
    {
        return [
            'signed-credits-children-bonus' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('employment', EmploymentRelationshipKind::Employment, 3_500_000),
                    self::relationship('dpp', EmploymentRelationshipKind::Dpp, 900_000),
                    self::relationship('director', EmploymentRelationshipKind::StatutoryBody, 390_000),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::Signed)],
                residence: self::residence(TaxResidence::CzechResident),
                creditClaims: [
                    self::credit(TaxCreditKind::Taxpayer),
                    self::credit(TaxCreditKind::DisabilityBasic),
                    self::credit(TaxCreditKind::ZtpP),
                ],
                childClaims: [self::child('child-a', 1, true), self::child('child-b', 2, false)],
            ),
            'disability-extended-credit' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::Signed)],
                residence: self::residence(TaxResidence::CzechResident),
                creditClaims: [
                    self::credit(TaxCreditKind::Taxpayer),
                    self::credit(TaxCreditKind::DisabilityExtended),
                ],
            ),
            'paragraph-38h-threshold-exact' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('employment', EmploymentRelationshipKind::Employment, 14_690_100),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::Signed)],
                residence: self::residence(TaxResidence::CzechResident),
                creditClaims: [self::credit(TaxCreditKind::Taxpayer)],
            ),
            'paragraph-38h-threshold-above' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('employment', EmploymentRelationshipKind::Employment, 15_690_100),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::Signed)],
                residence: self::residence(TaxResidence::CzechResident),
                creditClaims: [self::credit(TaxCreditKind::Taxpayer)],
            ),
            'dpp-withholding-at-maximum' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('dpp', EmploymentRelationshipKind::Dpp, 1_199_900),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::NotSigned)],
                residence: self::residence(TaxResidence::CzechResident),
            ),
            'dpp-withholding-above-maximum' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('dpp', EmploymentRelationshipKind::Dpp, 1_200_000),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::NotSigned)],
                residence: self::residence(TaxResidence::CzechResident),
            ),
            'other-withholding-at-maximum' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship(
                        'small-scale',
                        EmploymentRelationshipKind::SmallScaleEmployment,
                        449_900,
                    ),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::NotSigned)],
                residence: self::residence(TaxResidence::CzechResident),
            ),
            'other-withholding-above-maximum' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship(
                        'small-scale',
                        EmploymentRelationshipKind::SmallScaleEmployment,
                        450_000,
                    ),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::NotSigned)],
                residence: self::residence(TaxResidence::CzechResident),
            ),
            'nonresident-statutory-body' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('director', EmploymentRelationshipKind::StatutoryBody, 400_000),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::NotSigned)],
                residence: self::residence(TaxResidence::NonResident),
            ),
            'nonresident-statutory-body-withholding' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship(
                        'director',
                        EmploymentRelationshipKind::StatutoryBody,
                        400_000,
                        OtherWithholdingEligibility::EligibleVerified,
                    ),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::NotSigned)],
                residence: self::residence(TaxResidence::NonResident),
            ),
            'nonresident-taxpayer-credit-only' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('employment', EmploymentRelationshipKind::Employment, 5_000_000),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::Signed)],
                residence: self::residence(TaxResidence::NonResident),
                creditClaims: [self::credit(TaxCreditKind::Taxpayer)],
            ),
            'bonus-below-minimum-income' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('employment', EmploymentRelationshipKind::Employment, 1_119_900),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::Signed)],
                residence: self::residence(TaxResidence::CzechResident),
                creditClaims: [self::credit(TaxCreditKind::Taxpayer)],
                childClaims: [self::child('child-a', 1, false)],
            ),
            'bonus-at-minimum-income' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('employment', EmploymentRelationshipKind::Employment, 1_120_000),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::Signed)],
                residence: self::residence(TaxResidence::CzechResident),
                creditClaims: [self::credit(TaxCreditKind::Taxpayer)],
                childClaims: [self::child('child-a', 1, false)],
            ),
            'three-children-orders' => new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [
                    self::relationship('employment', EmploymentRelationshipKind::Employment, 6_000_000),
                ],
                declarations: [self::declaration(TaxDeclarationStatus::Signed)],
                residence: self::residence(TaxResidence::CzechResident),
                creditClaims: [self::credit(TaxCreditKind::Taxpayer)],
                childClaims: [
                    self::child('child-a', 1, false),
                    self::child('child-b', 2, false),
                    self::child('child-c', 3, false),
                ],
            ),
        ];
    }

    /** Dodaná sada je účinná rovnou — není co aktivovat ani co schvalovat. */
    public static function activeIncomeTaxRuleset(): PayrollRulesetVersion
    {
        return CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-31');
    }

    private static function relationship(
        string $reference,
        EmploymentRelationshipKind $kind,
        int $amountMinorUnits,
        OtherWithholdingEligibility $eligibility = OtherWithholdingEligibility::Automatic,
    ): EmploymentRelationshipTaxInput {
        return new EmploymentRelationshipTaxInput(
            $reference,
            'synthetic-payer',
            $kind,
            [new IncomeTaxComponent('synthetic-income', $amountMinorUnits)],
            $eligibility,
            $eligibility === OtherWithholdingEligibility::Automatic
                ? null
                : 'synthetic-classification-evidence',
        );
    }

    private static function declaration(TaxDeclarationStatus $status): TaxDeclarationEvidence
    {
        return new TaxDeclarationEvidence(
            $status,
            '2026-01-01',
            null,
            'synthetic-declaration-evidence',
        );
    }

    private static function residence(TaxResidence $residence): TaxResidenceEvidence
    {
        return new TaxResidenceEvidence(
            $residence,
            '2026-01-01',
            null,
            'synthetic-residence-evidence',
        );
    }

    private static function credit(TaxCreditKind $kind): TaxCreditClaim
    {
        return new TaxCreditClaim(
            $kind,
            '2026-01-01',
            null,
            TaxEvidenceStatus::Verified,
            'synthetic-credit-evidence',
        );
    }

    private static function child(string $reference, int $order, bool $ztpP): TaxChildClaim
    {
        return new TaxChildClaim(
            $reference,
            $order,
            $ztpP,
            '2026-01-01',
            null,
            TaxEvidenceStatus::Verified,
            true,
            true,
            'synthetic-child-evidence',
        );
    }
}
