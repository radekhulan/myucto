<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayslipDocumentSnapshotMapper;
use PHPUnit\Framework\TestCase;

final class PayslipDocumentSnapshotMapperTest extends TestCase
{
    public function testAttachesCompleteValidatedSnapshotForCalculatedPerson(): void
    {
        $result = (new PayslipDocumentSnapshotMapper())->attach(
            $this->inputSnapshot(),
            $this->calculatedResult(),
        );

        $document = $result['people'][0]['payslip_document'];
        self::assertSame('payroll-payslip-document.v2', $document['schema_version']);
        self::assertSame('Syntetická společnost s.r.o.', $document['employer_name']);
        self::assertSame('00000000', $document['employer_identification_number']);
        self::assertSame('Syntetická Osoba', $document['employee_display_name']);
        self::assertSame('Pracovní poměr', $document['employment_label']);
        self::assertSame([
            [
                'label' => 'Základní mzda',
                'amount_minor_units' => 4_800_000,
                'exemption_basis' => null,
                'exemption_statute' => null,
                'exempt_part_minor_units' => 0,
            ],
            [
                'label' => 'Korekce minulého období',
                'amount_minor_units' => -10_000,
                'exemption_basis' => null,
                'exemption_statute' => null,
                'exempt_part_minor_units' => 0,
            ],
        ], $document['income_lines']);
        self::assertSame('recorded', $document['income_detail_status']);
        self::assertSame(4_790_000, $document['gross_minor_units']);
        self::assertSame(340_100, $document['employee_social_minor_units']);
        self::assertSame(215_600, $document['employee_health_minor_units']);
        self::assertSame(0, $document['health_minimum_top_up_minor_units']);
        self::assertSame(1_188_000, $document['employer_social_minor_units']);
        self::assertSame(431_100, $document['employer_health_minor_units']);
        self::assertSame(4_790_100, $document['tax_base_minor_units']);
        self::assertSame([
            [
                'label' => 'Syntetická srážka',
                'amount_minor_units' => 8_000,
                'exemption_basis' => null,
                'exemption_statute' => null,
                'exempt_part_minor_units' => 0,
            ],
        ], $document['other_deduction_lines']);
        self::assertSame(4_276_300, $document['net_minor_units']);
        self::assertSame('521', $document['gross_expense_account']);
        self::assertSame('331', $document['gross_liability_account']);
        self::assertSame('524', $document['insurance_expense_account']);
        self::assertSame('336', $document['insurance_liability_account']);
    }

    /** Účtová osnova běžně používá analytiky s tečkou (např. 522.100). */
    public function testAcceptsAnalyticalAccountCodesWithDot(): void
    {
        $snapshot = $this->inputSnapshot();
        foreach ($snapshot['employer']['accounting_accounts'] as $key => $value) {
            $snapshot['employer']['accounting_accounts'][$key] = $value . '.100';
        }
        foreach ($snapshot['people'][0]['employments'][0]['inputs'] as &$input) {
            $input['component']['accounting_debit_code'] = null;
            $input['component']['accounting_credit_code'] = null;
        }
        unset($input);

        $result = $this->calculatedResult();
        foreach ($result['people'][0]['employments'][0]['inputs'] as &$inputResult) {
            $inputResult['accounting']['debit_code'] = null;
            $inputResult['accounting']['credit_code'] = null;
        }
        unset($inputResult);

        $document = (new PayslipDocumentSnapshotMapper())
            ->attach($snapshot, $result)['people'][0]['payslip_document'];

        self::assertSame('521.100', $document['gross_expense_account']);
        self::assertSame('331.100', $document['gross_liability_account']);
        self::assertSame('524.100', $document['insurance_expense_account']);
        self::assertSame('336.100', $document['insurance_liability_account']);
    }

    public function testAcceptsAnalyticalAccountsFrozenOnIndividualInputs(): void
    {
        $result = $this->calculatedResult();
        foreach ($result['people'][0]['employments'][0]['inputs'] as &$inputResult) {
            $inputResult['accounting']['debit_code'] = '521.200';
            $inputResult['accounting']['credit_code'] = '331.200';
        }
        unset($inputResult);

        $document = (new PayslipDocumentSnapshotMapper())
            ->attach($this->inputSnapshot(), $result)['people'][0]['payslip_document'];

        self::assertSame('521.200', $document['gross_expense_account']);
        self::assertSame('331.200', $document['gross_liability_account']);
    }

    public function testAllocatesAggregateEmployerSocialDeterministically(): void
    {
        $snapshot = $this->inputSnapshot();
        $second = $snapshot['people'][0];
        $second['employee']['id'] = 12;
        $second['employee']['full_name'] = 'Druhá Syntetická';
        $second['employments'][0]['employment']['id'] = 102;
        $second['employments'][0]['employment']['employee_id'] = 12;
        $second['employments'][0]['inputs'][0]['id'] = 1003;
        $second['employments'][0]['inputs'][1]['id'] = 1004;
        $second['deduction_agreements'] = [];
        $snapshot['people'][] = $second;

        $result = $this->calculatedResult();
        $secondResult = $result['people'][0];
        $secondResult['employee_id'] = 12;
        $secondResult['employments'][0]['employment_id'] = 102;
        $secondResult['employments'][0]['inputs'][0]['input_id'] = 1003;
        $secondResult['employments'][0]['inputs'][1]['input_id'] = 1004;
        $secondResult['statutory']['person_reference'] = 'employee:12';
        $secondResult['statutory']['social_insurance']['person_id'] = 'employee:12';
        $secondResult['statutory']['health_insurance']['person_id'] = 'employee:12';
        $secondResult['statutory']['income_tax']['employee_reference'] = 'employee:12';
        $secondResult['statutory']['net_pay']['person_reference'] = 'employee:12';
        $secondResult['statutory']['net_pay']['deductions'] = [];
        $secondResult['statutory']['net_pay']['deducted_minor_units'] = 0;
        $secondResult['statutory']['net_pay']['net_payable_minor_units'] = 4_284_300;
        $secondResult['statutory']['net_payable_minor_units'] = 4_284_300;
        $secondResult['payable_after_enforcement_minor'] = 4_284_300;
        $result['people'][0]['statutory']['social_insurance']
            ['capped_assessment_base_minor_units'] = 4_000_000_000_000_000_000;
        $secondResult['statutory']['social_insurance']
            ['capped_assessment_base_minor_units'] = 4_000_000_000_000_000_000;
        $result['people'][] = $secondResult;
        $result['statutory']['employer_social_before_discount_minor_units'] =
            2_376_001;
        $result['statutory']['employer_social_part_time_discount_minor_units'] = 0;
        $result['statutory']['employer_social_minor_units'] = 2_376_001;

        $attached = (new PayslipDocumentSnapshotMapper())->attach($snapshot, $result);

        self::assertSame(
            [1_188_001, 1_188_000],
            array_column(
                array_column($attached['people'], 'payslip_document'),
                'employer_social_minor_units',
            ),
        );
    }

    public function testAllocatesEmployerDiscountToEligiblePersonOnly(): void
    {
        $snapshot = $this->inputSnapshot();
        $second = $snapshot['people'][0];
        $second['employee']['id'] = 12;
        $second['employee']['full_name'] = 'Druhá Syntetická';
        $second['employments'][0]['employment']['id'] = 102;
        $second['employments'][0]['employment']['employee_id'] = 12;
        $second['employments'][0]['inputs'][0]['id'] = 1003;
        $second['employments'][0]['inputs'][1]['id'] = 1004;
        $second['deduction_agreements'] = [];
        $snapshot['people'][] = $second;

        $result = $this->calculatedResult();
        $secondResult = $result['people'][0];
        $secondResult['employee_id'] = 12;
        $secondResult['employments'][0]['employment_id'] = 102;
        $secondResult['employments'][0]['inputs'][0]['input_id'] = 1003;
        $secondResult['employments'][0]['inputs'][1]['input_id'] = 1004;
        $secondResult['statutory']['person_reference'] = 'employee:12';
        $secondResult['statutory']['social_insurance']['person_id'] = 'employee:12';
        $secondResult['statutory']['social_insurance']['relationships'][0]
            ['relationship_id'] = 'employment:102';
        $secondResult['statutory']['social_insurance']['relationships'][0]
            ['part_time_employer_discount'] = 'verified';
        $secondResult['statutory']['health_insurance']['person_id'] = 'employee:12';
        $secondResult['statutory']['income_tax']['employee_reference'] = 'employee:12';
        $secondResult['statutory']['net_pay']['person_reference'] = 'employee:12';
        $secondResult['statutory']['net_pay']['deductions'] = [];
        $secondResult['statutory']['net_pay']['deducted_minor_units'] = 0;
        $secondResult['statutory']['net_pay']['net_payable_minor_units'] = 4_284_300;
        $secondResult['statutory']['net_payable_minor_units'] = 4_284_300;
        $secondResult['payable_after_enforcement_minor'] = 4_284_300;
        $result['people'][] = $secondResult;
        $result['statutory']['employer_social_before_discount_minor_units'] =
            2_376_000;
        $result['statutory']['employer_social_part_time_discount_minor_units'] =
            476_000;
        $result['statutory']['employer_social_minor_units'] = 1_900_000;

        $attached = (new PayslipDocumentSnapshotMapper())->attach($snapshot, $result);

        self::assertSame(
            [1_188_000, 712_000],
            array_column(
                array_column($attached['people'], 'payslip_document'),
                'employer_social_minor_units',
            ),
        );
    }

    /**
     * Doložený nárok podle § 7a odst. 1 ještě není uplatněná sleva: limity
     * odst. 3 ji můžou vyloučit. Rozdělení na osoby musí vážit jen skutečně
     * uplatněnou slevu — jinak by osoba, které sleva nenáleží, ukrojila
     * z rozdělení kus, který jí nepatří, a druhé osobě by chyběl.
     */
    public function testDiscountAllocationIgnoresClaimsThatSection7a3Excluded(): void
    {
        $snapshot = $this->inputSnapshot();
        $second = $snapshot['people'][0];
        $second['employee']['id'] = 12;
        $second['employee']['full_name'] = 'Druhá Syntetická';
        $second['employments'][0]['employment']['id'] = 102;
        $second['employments'][0]['employment']['employee_id'] = 12;
        $second['employments'][0]['inputs'][0]['id'] = 1003;
        $second['employments'][0]['inputs'][1]['id'] = 1004;
        $second['deduction_agreements'] = [];
        $snapshot['people'][] = $second;

        $result = $this->calculatedResult();
        $secondResult = $result['people'][0];
        $secondResult['employee_id'] = 12;
        $secondResult['employments'][0]['employment_id'] = 102;
        $secondResult['employments'][0]['inputs'][0]['input_id'] = 1003;
        $secondResult['employments'][0]['inputs'][1]['input_id'] = 1004;
        $secondResult['statutory']['person_reference'] = 'employee:12';
        $secondResult['statutory']['social_insurance']['person_id'] = 'employee:12';
        $secondResult['statutory']['social_insurance']['relationships'][0]
            ['relationship_id'] = 'employment:102';
        $secondResult['statutory']['social_insurance']['relationships'][0]
            ['part_time_employer_discount'] = 'verified';
        $secondResult['statutory']['social_insurance']['relationships'][0]
            ['part_time_employer_discount_outcome'] = 'assessment_base_above_limit';
        $secondResult['statutory']['health_insurance']['person_id'] = 'employee:12';
        $secondResult['statutory']['income_tax']['employee_reference'] = 'employee:12';
        $secondResult['statutory']['net_pay']['person_reference'] = 'employee:12';
        $secondResult['statutory']['net_pay']['deductions'] = [];
        $secondResult['statutory']['net_pay']['deducted_minor_units'] = 0;
        $secondResult['statutory']['net_pay']['net_payable_minor_units'] = 4_284_300;
        $secondResult['statutory']['net_payable_minor_units'] = 4_284_300;
        $secondResult['payable_after_enforcement_minor'] = 4_284_300;
        $result['people'][] = $secondResult;
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['part_time_employer_discount'] = 'verified';
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['part_time_employer_discount_outcome'] = 'applied';
        $result['statutory']['employer_social_before_discount_minor_units'] = 2_376_000;
        $result['statutory']['employer_social_part_time_discount_minor_units'] = 476_000;
        $result['statutory']['employer_social_minor_units'] = 1_900_000;

        $attached = (new PayslipDocumentSnapshotMapper())->attach($snapshot, $result);

        // Celá sleva patří první osobě; rozdělit ji napůl by druhé osobě
        // přiznalo slevu, kterou § 7a odst. 3 vyloučil.
        self::assertSame(
            [712_000, 1_188_000],
            array_column(
                array_column($attached['people'], 'payslip_document'),
                'employer_social_minor_units',
            ),
        );
    }

    public function testDisplaysMultipleGrossAndInsuranceAccountsWithoutBlocking(): void
    {
        $snapshot = $this->inputSnapshot();
        $snapshot['employer']['accounting_accounts']
            ['health_insurance_credit'] = '337';
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][0]['inputs'][1]['accounting'] = [
            'debit_code' => '523',
            'credit_code' => '366',
        ];

        $document = (new PayslipDocumentSnapshotMapper())
            ->attach($snapshot, $result)['people'][0]['payslip_document'];

        self::assertSame('521, 523', $document['gross_expense_account']);
        self::assertSame('331, 366', $document['gross_liability_account']);
        self::assertSame('336, 337', $document['insurance_liability_account']);
    }

    public function testCalculatedResultWithoutFrozenEmployerIdentityFailsClosed(): void
    {
        $snapshot = $this->inputSnapshot();
        unset($snapshot['employer']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zaměstnavatele');

        (new PayslipDocumentSnapshotMapper())->attach(
            $snapshot,
            $this->calculatedResult(),
        );
    }

    public function testManualReviewDoesNotCreatePayslipPayload(): void
    {
        $result = $this->calculatedResult();
        $result['statutory']['status'] = 'manual_review';

        $result = (new PayslipDocumentSnapshotMapper())->attach(
            $this->inputSnapshot(),
            $result,
        );

        self::assertArrayNotHasKey('payslip_document', $result['people'][0]);
    }

    public function testIncludesSupportedEnforcementInDeductionsAndNetPay(): void
    {
        $result = $this->calculatedResult();
        $result['people'][0]['enforcement']['result']['total_withheld_minor_units'] =
            100_000;
        $result['people'][0]['payable_after_enforcement_minor'] = 4_176_300;

        $attached = (new PayslipDocumentSnapshotMapper())->attach(
            $this->inputSnapshot(),
            $result,
        );
        $document = $attached['people'][0]['payslip_document'];

        self::assertSame([
            [
                'label' => 'Syntetická srážka',
                'amount_minor_units' => 8_000,
                'exemption_basis' => null,
                'exemption_statute' => null,
                'exempt_part_minor_units' => 0,
            ],
            [
                'label' => 'Exekuční a insolvenční srážky',
                'amount_minor_units' => 100_000,
                'exemption_basis' => null,
                'exemption_statute' => null,
                'exempt_part_minor_units' => 0,
            ],
        ], $document['other_deduction_lines']);
        self::assertSame(4_176_300, $document['net_minor_units']);
    }

    public function testUnsupportedEnforcementDoesNotCreatePartialPayslipPayload(): void
    {
        $result = $this->calculatedResult();
        $result['people'][0]['enforcement']['result']['status'] = 'manual_review';

        $attached = (new PayslipDocumentSnapshotMapper())->attach(
            $this->inputSnapshot(),
            $result,
        );

        self::assertArrayNotHasKey('payslip_document', $attached['people'][0]);
    }

    /** @return array<string,mixed> */
    private function inputSnapshot(): array
    {
        $component = static fn (
            string $code,
            string $name,
        ): array => [
            'code' => $code,
            'name' => $name,
            'component_kind' => 'income',
            'value_kind' => 'monetary',
            'frequency_kind' => 'monthly',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
        ];

        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'payment_date' => '2026-07-31',
            'employer' => [
                'name' => 'Syntetická společnost s.r.o.',
                'identification_number' => '00000000',
                'accounting_accounts' => [
                    'employment_gross_debit' => '521',
                    'employment_gross_credit' => '331',
                    'partner_gross_debit' => '522',
                    'partner_gross_credit' => '366',
                    'statutory_gross_debit' => '523',
                    'statutory_gross_credit' => '366',
                    'employer_insurance_debit' => '524',
                    'social_insurance_credit' => '336',
                    'health_insurance_credit' => '336',
                    'income_tax_credit' => '342',
                    'other_deductions_credit' => '379',
                ],
            ],
            'people' => [[
                'employee' => [
                    'id' => 11,
                    'full_name' => 'Syntetická Osoba',
                ],
                'deduction_agreements' => [[
                    'id' => 501,
                    'title' => 'Syntetická srážka',
                    'deduction_kind' => 'other',
                ]],
                'employments' => [[
                    'employment' => [
                        'id' => 101,
                        'employee_id' => 11,
                        'code' => 'SYN-101',
                        'relation_type' => 'employment',
                    ],
                    'inputs' => [
                        [
                            'id' => 1001,
                            'amount_minor' => 4_800_000,
                            'component' => $component('MZDA_MESICNI', 'Základní mzda'),
                        ],
                        [
                            'id' => 1002,
                            'amount_minor' => -10_000,
                            'component' => $component('KOREKCE', 'Korekce minulého období'),
                        ],
                    ],
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function calculatedResult(): array
    {
        return [
            'schema_version' => 'payroll-run-result.v1',
            'source_snapshot_hash' => hash('sha256', 'synthetic-input'),
            'people' => [[
                'employee_id' => 11,
                'employments' => [[
                    'employment_id' => 101,
                    'inputs' => [
                        [
                            'input_id' => 1001,
                            'component_code' => 'MZDA_MESICNI',
                            'totals' => [
                                'source_amount_minor' => 4_800_000,
                                'cash_payable_minor' => 4_800_000,
                            ],
                            'accounting' => [
                                'debit_code' => '521',
                                'credit_code' => '331',
                            ],
                        ],
                        [
                            'input_id' => 1002,
                            'component_code' => 'KOREKCE',
                            'totals' => [
                                'source_amount_minor' => -10_000,
                                'cash_payable_minor' => -10_000,
                            ],
                            'accounting' => [
                                'debit_code' => '521',
                                'credit_code' => '331',
                            ],
                        ],
                    ],
                    'totals' => [
                        'source_amount_minor' => 4_790_000,
                        'cash_payable_minor' => 4_790_000,
                    ],
                ]],
                'totals' => [
                    'source_amount_minor' => 4_790_000,
                    'cash_payable_minor' => 4_790_000,
                ],
                'enforcement' => [
                    'result' => [
                        'status' => 'supported',
                        'total_withheld_minor_units' => 0,
                    ],
                ],
                'payable_after_enforcement_minor' => 4_276_300,
                'statutory' => [
                    'person_reference' => 'employee:11',
                    'status' => 'calculated',
                    'social_insurance' => [
                        'person_id' => 'employee:11',
                        'status' => 'calculated',
                        'capped_assessment_base_minor_units' => 4_790_000,
                        'employee_contribution_minor_units' => 340_100,
                        'relationships' => [[
                            'relationship_id' => 'employment:101',
                            'capped_assessment_base_minor_units' => 4_790_000,
                            'part_time_employer_discount' => 'not_requested',
                        ]],
                    ],
                    'health_insurance' => [
                        'person_id' => 'employee:11',
                        'status' => 'calculated',
                        'employee_minimum_top_up_minor_units' => 0,
                        'employee_contribution_minor_units' => 215_600,
                        'employer_contribution_minor_units' => 431_100,
                    ],
                    'income_tax' => [
                        'employee_reference' => 'employee:11',
                        'status' => 'calculated',
                        'advance_tax' => [
                            'taxable_income_minor_units' => 4_790_000,
                            'rounded_tax_base_minor_units' => 4_790_100,
                            'tax_before_credits_minor_units' => 718_500,
                            'non_refundable_credits_minor_units' => 257_000,
                            'child_credit_minor_units' => 511_500,
                            'tax_bonus_eligible' => true,
                            'tax_after_credits_minor_units' => 0,
                            'tax_bonus_minor_units' => 50_000,
                        ],
                        'withholding_tax_minor_units' => 0,
                    ],
                    'net_pay' => [
                        'person_reference' => 'employee:11',
                        'cash_income_minor_units' => 4_790_000,
                        'non_cash_income_minor_units' => 0,
                        'employee_social_minor_units' => 340_100,
                        'employee_health_minor_units' => 215_600,
                        'advance_tax_minor_units' => 0,
                        'withholding_tax_minor_units' => 0,
                        'tax_bonus_minor_units' => 50_000,
                        'correction_minor_units' => 0,
                        'deducted_minor_units' => 8_000,
                        'net_payable_minor_units' => 4_276_300,
                        'deductions' => [[
                            'deduction_reference' => 'agreement:501',
                            'applied_minor_units' => 8_000,
                        ]],
                    ],
                    'net_payable_minor_units' => 4_276_300,
                ],
            ]],
            'statutory' => [
                'schema_version' => 'payroll-run-statutory-result.v1',
                'status' => 'calculated',
                'employer_social_before_discount_minor_units' => 1_188_000,
                'employer_social_part_time_discount_minor_units' => 0,
                'employer_social_minor_units' => 1_188_000,
                'people' => [],
            ],
        ];
    }
}
