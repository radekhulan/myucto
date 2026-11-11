<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthCalculator;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsRules;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputBundle;
use PHPUnit\Framework\TestCase;

/**
 * Výpočet nesmí chtít víc, než chce zákon: chybějící záznam o prohlášení je
 * „nepodepsal", rezidence u nepodepsaného výpočet nezajímá a dohoda pod
 * rozhodným příjmem nepotřebuje zdravotní pojišťovnu, protože pojistné
 * nevzniká.
 */
final class PayrollRunStatutoryLegalDefaultsTest extends TestCase
{
    public function testAgreementBelowThresholdWithoutAnyHealthCoverageIsCalculated(): void
    {
        $snapshot = $this->agreementSnapshot(800_000);
        unset($snapshot['people'][0]['statutory_evidence']['health']['coverage']);

        $bundle = $this->assemble($snapshot);
        self::assertSame([], $bundle->issues);

        $health = $this->health($bundle);
        self::assertSame(HealthCalculationStatus::Calculated, $health->status, implode(',', $health->issues));
        self::assertSame(0, $health->totalContributionMinorUnits);
        self::assertSame([], $health->insurerLiabilities);
        self::assertSame(
            HealthParticipationStatus::DoesNotParticipate,
            $health->people[0]->relationships[0]->participation->status,
        );
    }

    public function testAgreementAboveThresholdWithoutHealthCoverageStopsWithTheCoverageCode(): void
    {
        $snapshot = $this->agreementSnapshot(1_500_000);
        unset($snapshot['people'][0]['statutory_evidence']['health']['coverage']);

        $bundle = $this->assemble($snapshot);
        self::assertSame([], $bundle->issues);

        $health = $this->health($bundle);
        self::assertSame(HealthCalculationStatus::ManualReview, $health->status);
        self::assertSame(['person:employee:42:health_coverage_evidence_missing'], $health->issues);
    }

    public function testEmploymentWithoutHealthCoverageStillBlocksImmediately(): void
    {
        $snapshot = $this->agreementSnapshot(800_000, 'employment');
        unset($snapshot['people'][0]['statutory_evidence']['health']['coverage']);

        $bundle = $this->assemble($snapshot);

        self::assertSame(
            ['health_insurance|health_coverage_evidence_missing|employee:42|'],
            self::issueKeys($bundle),
        );
    }

    /**
     * Osoba zastavená jiným problémem dostane i chybějící pojištění hned,
     * ne až po opravě toho prvního.
     */
    public function testBlockedAgreementReportsMissingHealthCoverageTogether(): void
    {
        $snapshot = $this->agreementSnapshot(800_000);
        unset(
            $snapshot['people'][0]['statutory_evidence']['health']['coverage'],
            $snapshot['people'][0]['statutory_evidence']['social']['jurisdiction'],
        );

        $bundle = $this->assemble($snapshot);

        self::assertSame([
            'health_insurance|health_coverage_evidence_missing|employee:42|',
            'social_insurance|social_jurisdiction_evidence_missing|employee:42|',
        ], self::issueKeys($bundle));
    }

    public function testMissingDeclarationRecordMeansNotSignedAndWithholdsTax(): void
    {
        $snapshot = $this->agreementSnapshot(800_000);
        unset(
            $snapshot['people'][0]['statutory_evidence']['income_tax']['declaration'],
            $snapshot['people'][0]['statutory_evidence']['income_tax']['residence'],
        );

        $bundle = $this->assemble($snapshot);
        self::assertSame([], $bundle->issues);
        self::assertSame(
            TaxDeclarationStatus::NotSigned,
            $bundle->incomeTax[0]->declarations[0]->status,
        );
        self::assertSame(TaxResidence::Unverified, $bundle->incomeTax[0]->residence->residence);

        $tax = $this->tax($bundle);
        self::assertSame(TaxCalculationStatus::Calculated, $tax->status, implode(',', $tax->issues));
        self::assertSame(TaxRegime::Withholding, $tax->relationships[0]->regime);
        self::assertSame(120_000, $tax->withholdingTaxMinorUnits);
    }

    public function testUnverifiedResidenceOfUnsignedDeclarationDoesNotStopTheTax(): void
    {
        $snapshot = $this->agreementSnapshot(800_000);
        $snapshot['people'][0]['statutory_evidence']['income_tax']['residence']['residence'] = 'unverified';
        $snapshot['people'][0]['statutory_evidence']['income_tax']['residence']['evidence_reference'] = null;

        $bundle = $this->assemble($snapshot);
        self::assertSame([], $bundle->issues);
        $tax = $this->tax($bundle);
        self::assertSame(TaxCalculationStatus::Calculated, $tax->status, implode(',', $tax->issues));
    }

    public function testSignedDeclarationStillNeedsTheResidence(): void
    {
        $snapshot = $this->agreementSnapshot(800_000);
        $snapshot['people'][0]['statutory_evidence']['income_tax']['declaration']['status'] = 'signed';
        $snapshot['people'][0]['statutory_evidence']['income_tax']['declaration']['evidence_reference'] = 'document:tax-declaration';
        unset($snapshot['people'][0]['statutory_evidence']['income_tax']['residence']);

        self::assertSame(
            ['income_tax|tax_residence_evidence_missing|employee:42|'],
            self::issueKeys($this->assemble($snapshot)),
        );
    }

    /** Podmínky vztahu tvrdí podpis: výchozí „nepodepsal" by ho popřel. */
    public function testMissingDeclarationRecordWithSignedTermStillBlocks(): void
    {
        $snapshot = $this->agreementSnapshot(800_000);
        unset($snapshot['people'][0]['statutory_evidence']['income_tax']['declaration']);
        $snapshot['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;

        self::assertSame(
            ['income_tax|tax_declaration_evidence_missing|employee:42|'],
            self::issueKeys($this->assemble($snapshot)),
        );
        self::assertNull(PayrollRunStatutoryInputAssembler::taxDeclarationIssue(
            $snapshot['people'][0]['statutory_evidence'],
        ));
    }

    /** @param array<string,mixed> $snapshot */
    private function assemble(array $snapshot): PayrollRunStatutoryInputBundle
    {
        return (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);
    }

    private function health(PayrollRunStatutoryInputBundle $bundle): \MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthResult
    {
        self::assertInstanceOf(HealthInsuranceMonthInput::class, $bundle->healthInsurance);

        return (new HealthInsuranceMonthCalculator(new PayrollRulesetProvider([
            CzechPayrollRulesets2026::provider()->forDate(PayrollRulesetDomain::HealthInsurance, '2026-06-30'),
        ])))->calculate($bundle->healthInsurance);
    }

    private function tax(PayrollRunStatutoryInputBundle $bundle): \MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult
    {
        return (new MonthlyEmploymentIncomeTaxCalculator(new PayrollRulesetProvider([
            CzechPayrollRulesets2026::provider()->forDate(PayrollRulesetDomain::IncomeTax, '2026-06-30'),
        ])))->calculate($bundle->incomeTax[0]);
    }

    /** @return list<string> */
    private static function issueKeys(PayrollRunStatutoryInputBundle $bundle): array
    {
        return array_map(
            static fn ($issue): string => implode('|', [
                $issue->domain,
                $issue->code,
                $issue->personReference,
                $issue->relationshipReference,
            ]),
            $bundle->issues,
        );
    }

    /** @return array<string,mixed> */
    private function agreementSnapshot(int $amountMinor, string $relationType = 'dpp'): array
    {
        $accumulator = static fn (string $kind, array $totals): array => [
            'status' => 'verified',
            'issue_code' => null,
            'state' => [
                'schema_version' => 'payroll-statutory-accumulator-state.v1',
                'supplier_id' => 7,
                'employee_id' => 42,
                'calculation_kind' => $kind,
                'year' => 2026,
                'before_period_start' => '2026-06-01',
                'totals' => $totals,
            ],
        ];

        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-15',
            'statutory_period' => [
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'payment_date' => '2026-07-15',
                'tax_calculation_date' => '2026-06-30',
                'social_calculation_date' => '2026-06-30',
                'health_calculation_date' => '2026-06-30',
            ],
            'risky_savings_ruleset' => PayrollRiskySavingsRules::fromProvider(
                CzechPayrollRulesets2026::provider(),
                '2026-06-01',
            )->toSnapshot(),
            'people' => [[
                'employee' => ['id' => 42, 'full_name' => 'Brigádník Testovací'],
                'statutory_accumulators' => [
                    'schema_version' => 'payroll-person-statutory-accumulators.v1',
                    'social_insurance' => $accumulator('social_insurance', [
                        'assessment_base_minor_units' => 0,
                    ]),
                    'income_tax' => $accumulator('income_tax', [
                        'completed_months' => 5,
                        'advance_base_minor_units' => 0,
                        'withholding_base_minor_units' => 0,
                        'advance_tax_minor_units' => 0,
                        'withholding_tax_minor_units' => 0,
                        'applied_non_refundable_credits_minor_units' => 0,
                        'applied_child_credit_minor_units' => 0,
                        'tax_bonus_minor_units' => 0,
                        'bonus_qualifying_income_minor_units' => 0,
                    ]),
                ],
                'statutory_evidence' => [
                    'schema_version' => 'payroll-person-statutory-evidence.v1',
                    'employee_id' => 42,
                    'effective_on' => '2026-06-30',
                    'health' => [
                        'coverage' => [
                            'id' => 1,
                            'effective_from' => '2026-01-01',
                            'effective_to' => null,
                            'row_version' => 1,
                            'jurisdiction' => 'czech_regime_verified',
                            'foreign_country_code' => null,
                            'jurisdiction_evidence_reference' => null,
                            'insurer_status' => 'verified',
                            'insurer_code' => '111',
                            'insurer_evidence_reference' => 'document:health-insurer',
                        ],
                        'minimum_reductions' => [],
                        'month_evidence' => null,
                        'other_employer_bases' => [],
                    ],
                    'income_tax' => [
                        'declaration' => [
                            'id' => 3,
                            'effective_from' => '2026-01-01',
                            'effective_to' => null,
                            'row_version' => 1,
                            'status' => 'not-signed',
                            'evidence_reference' => null,
                        ],
                        'residence' => [
                            'id' => 4,
                            'effective_from' => '2026-01-01',
                            'effective_to' => null,
                            'row_version' => 1,
                            'residence' => 'czech-resident',
                            'country_code' => 'CZ',
                            'evidence_reference' => 'document:tax-residence',
                        ],
                        'credit_claims' => [],
                        'child_claims' => [],
                    ],
                    'social' => [
                        'jurisdiction' => [
                            'id' => 6,
                            'effective_from' => '2026-01-01',
                            'effective_to' => null,
                            'row_version' => 1,
                            'jurisdiction' => 'czech_regime_verified',
                            'foreign_country_code' => null,
                            'jurisdiction_evidence_reference' => null,
                            'a1_status' => 'not_applicable',
                            'a1_certificate_reference' => null,
                            'a1_valid_until' => null,
                        ],
                        'working_pensioner_discount' => null,
                    ],
                ],
                'employments' => [[
                    'employment' => [
                        'id' => 84,
                        'employee_id' => 42,
                        'relation_type' => $relationType,
                        'start_date' => '2026-01-01',
                        'actual_start_date' => '2026-01-01',
                        'end_date' => null,
                        'monthly_gross_minor' => $amountMinor,
                    ],
                    'term' => [
                        'id' => 99,
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                        'social_insurance_participation' => 'automatic',
                        'health_insurance_participation' => 'automatic',
                        'tax_regime' => 'advance',
                        'tax_declaration_signed' => false,
                    ],
                    'inputs' => [[
                        'id' => 420,
                        'amount_minor' => $amountMinor,
                        'source_period_start' => null,
                        'component' => [
                            'code' => 'ODMENA_DPP',
                            'tax_treatment' => 'included',
                            'social_participation_treatment' => 'included',
                            'social_treatment' => 'included',
                            'health_participation_treatment' => 'included',
                            'health_treatment' => 'included',
                        ],
                    ]],
                ]],
            ]],
        ];
    }
}
