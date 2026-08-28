<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayslipDocumentData
{
    private const MAX_MINOR_UNITS = 1_000_000_000_000;
    private const MAX_LINES = 100;

    /** Páska nese u každé složky podklad jejího nezdanění. */
    public const INCOME_DETAIL_RECORDED = 'recorded';

    /**
     * Páska vznikla nad snapshotem, který podklad nezdanění neevidoval.
     * Není to „nic osvobozeného nebylo" — je to neevidovaný údaj a doklad ho
     * tak musí i pojmenovat, stejně jako mzdový list.
     */
    public const INCOME_DETAIL_NOT_RECORDED = 'not_recorded';

    /**
     * @param list<PayslipLine> $incomeLines
     * @param list<PayslipLine> $otherDeductionLines
     */
    public function __construct(
        public string $revisionId,
        public string $sourceSnapshotSha256,
        public string $employerName,
        public string $employerIdentificationNumber,
        public string $employeeDisplayName,
        public string $period,
        public string $employmentLabel,
        public array $incomeLines,
        public int $grossMinorUnits,
        public int $employeeSocialMinorUnits,
        public int $employeeHealthMinorUnits,
        public int $healthMinimumTopUpMinorUnits,
        public int $taxBaseMinorUnits,
        public int $taxBeforeCreditsMinorUnits,
        public int $taxNonRefundableCreditsMinorUnits,
        public int $taxChildCreditMinorUnits,
        public bool $taxBonusEligible,
        public int $taxAfterCreditsMinorUnits,
        public int $taxBonusMinorUnits,
        public array $otherDeductionLines,
        public int $roundingAdjustmentMinorUnits,
        public int $netMinorUnits,
        public int $employerSocialMinorUnits,
        public int $employerHealthMinorUnits,
        public string $grossExpenseAccount,
        public string $grossLiabilityAccount,
        public string $insuranceExpenseAccount,
        public string $insuranceLiabilityAccount,
        public string $currency = 'CZK',
        /**
         * Doplatek ze zúčtování vyplacený s touto mzdou (§ 35d odst. 8).
         * Není mzdou ani srážkou — připočítává se až k výplatě.
         */
        public int $annualSettlementMinorUnits = 0,
        public string $incomeDetailStatus = self::INCOME_DETAIL_NOT_RECORDED,
    ) {
        $this->assertText($revisionId, 'Revision ID');
        $this->assertText($employerName, 'Employer name');
        $this->assertText($employerIdentificationNumber, 'Employer identification number');
        $this->assertText($employeeDisplayName, 'Employee display name');
        $this->assertText($employmentLabel, 'Employment label');

        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $revisionId) !== 1) {
            throw new \InvalidArgumentException('Revision ID contains unsafe characters.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotSha256) !== 1) {
            throw new \InvalidArgumentException('Source snapshot SHA-256 must be a lowercase hexadecimal digest.');
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Payroll period must use YYYY-MM.');
        }

        if ($currency !== 'CZK') {
            throw new \InvalidArgumentException('The first payslip renderer version supports CZK only.');
        }

        $this->assertLineList($incomeLines, 'Income lines', false);
        $this->assertLineList($otherDeductionLines, 'Other deduction lines', true);

        if (!in_array($incomeDetailStatus, [
            self::INCOME_DETAIL_RECORDED,
            self::INCOME_DETAIL_NOT_RECORDED,
        ], true)) {
            throw new \InvalidArgumentException('Stav údajů o složkách mzdy není platný.');
        }

        if ($incomeDetailStatus === self::INCOME_DETAIL_NOT_RECORDED) {
            foreach ($incomeLines as $line) {
                if ($line->exemptionBasis !== null) {
                    throw new \InvalidArgumentException(
                        'Neevidované údaje o složkách mzdy nesmí nést podklad osvobození.',
                    );
                }
            }
        }

        foreach ($otherDeductionLines as $line) {
            if ($line->exemptionBasis !== null) {
                throw new \InvalidArgumentException(
                    'Srážka ze mzdy nemá podklad osvobození od daně.',
                );
            }
        }

        foreach ([
            $grossMinorUnits,
            $employeeSocialMinorUnits,
            $employeeHealthMinorUnits,
            $healthMinimumTopUpMinorUnits,
            $taxBaseMinorUnits,
            $taxBeforeCreditsMinorUnits,
            $taxNonRefundableCreditsMinorUnits,
            $taxChildCreditMinorUnits,
            $taxAfterCreditsMinorUnits,
            $taxBonusMinorUnits,
            $employerSocialMinorUnits,
            $employerHealthMinorUnits,
            $annualSettlementMinorUnits,
        ] as $amountMinorUnits) {
            if ($amountMinorUnits < 0 || $amountMinorUnits > self::MAX_MINOR_UNITS) {
                throw new \InvalidArgumentException('Payslip amounts other than the rounding adjustment must not be negative.');
            }
        }

        // Čistá mzda je jediná podepsaná položka pásky. Měsíc bez peněžního
        // příjmu, ve kterém zaměstnavatel odvede doplatek zdravotního
        // pojištění do minimálního vyměřovacího základu (§ 3 odst. 10
        // z. č. 592/1992 Sb.) a zaměstnanec ho podle odst. 12 téhož paragrafu
        // hradí, skončí dluhem zaměstnance. Páska ho musí ukázat — zamlčet ho
        // by znamenalo vydat doklad, který nesedí na účetnictví ani na to,
        // co se příští měsíc srazí. Rozpad si dole vynutí přesnou rovnost,
        // takže znaménko se sem nedostane omylem.
        if ($netMinorUnits < -self::MAX_MINOR_UNITS
            || $netMinorUnits > self::MAX_MINOR_UNITS
        ) {
            throw new \InvalidArgumentException('Payslip net pay is out of range.');
        }

        if ($roundingAdjustmentMinorUnits < -100 || $roundingAdjustmentMinorUnits > 100) {
            throw new \InvalidArgumentException('Rounding adjustment must not exceed one CZK.');
        }

        if ($grossMinorUnits !== $this->sumLines($incomeLines)) {
            throw new \InvalidArgumentException('Gross pay must equal the sum of income lines.');
        }

        if ($this->sumLines($otherDeductionLines) < 0) {
            throw new \InvalidArgumentException('The aggregate of other deductions must not be negative.');
        }

        if ($taxBonusMinorUnits > 0 && $taxAfterCreditsMinorUnits > 0) {
            throw new \InvalidArgumentException('Tax bonus and positive tax after credits are mutually exclusive.');
        }

        $taxAfterNonRefundableCredits = max(
            0,
            $taxBeforeCreditsMinorUnits - $taxNonRefundableCreditsMinorUnits,
        );
        $expectedTaxAfterCredits = max(0, $taxAfterNonRefundableCredits - $taxChildCreditMinorUnits);
        if ($taxAfterCreditsMinorUnits !== $expectedTaxAfterCredits) {
            throw new \InvalidArgumentException('Tax after credits does not match the tax breakdown.');
        }

        $expectedTaxBonus = $taxBonusEligible
            ? max(0, $taxChildCreditMinorUnits - $taxAfterNonRefundableCredits)
            : 0;
        if ($taxBonusMinorUnits !== $expectedTaxBonus) {
            throw new \InvalidArgumentException('Tax bonus does not match the refundable child credit breakdown.');
        }

        $expectedNet = $this->sumExactly([
            $grossMinorUnits,
            -$employeeSocialMinorUnits,
            -$employeeHealthMinorUnits,
            -$healthMinimumTopUpMinorUnits,
            -$taxAfterCreditsMinorUnits,
            -$this->sumLines($otherDeductionLines),
            $taxBonusMinorUnits,
            $roundingAdjustmentMinorUnits,
            $annualSettlementMinorUnits,
        ]);

        if ($netMinorUnits !== $expectedNet) {
            throw new \InvalidArgumentException('Net pay does not match the payslip breakdown.');
        }

        foreach ([
            $grossExpenseAccount,
            $grossLiabilityAccount,
            $insuranceExpenseAccount,
            $insuranceLiabilityAccount,
        ] as $account) {
            if (preg_match(
                '/^[0-9]{3}[.A-Z0-9]{0,13}(?:, [0-9]{3}[.A-Z0-9]{0,13})*$/D',
                $account,
            ) !== 1) {
                throw new \InvalidArgumentException(
                    'Accounting account list has an invalid format.',
                );
            }
        }
    }

    public function incomeDetailRecorded(): bool
    {
        return $this->incomeDetailStatus === self::INCOME_DETAIL_RECORDED;
    }

    /**
     * Osvobozená část hrubé mzdy — táž podmnožina, kterou mzdový list vykazuje
     * podle § 38j odst. 2 písm. f) bodu 2. Do hrubé mzdy je ZAHRNUTÁ, nepřičítá
     * se: kdyby se sečetla zvlášť, páska by tvrdila vyšší příjem než mzdový list.
     */
    public function taxExemptIncomeMinorUnits(): int
    {
        return $this->sumExactly(array_map(
            static fn (PayslipLine $line): int => $line->reportedExemptMinorUnits(),
            $this->incomeLines,
        ));
    }

    /**
     * Plnění, které podle § 6 odst. 7 ZDP není předmětem daně. Na mzdovém listě
     * mezi osvobozené částky NEPATŘÍ, na pásce být musí — je to složka, ze které
     * se nic nesrazilo, a § 142 odst. 6 zákoníku práce žádá údaj o každé složce.
     */
    public function notSubjectToTaxIncomeMinorUnits(): int
    {
        return $this->sumExactly(array_map(
            static fn (PayslipLine $line): int => $line->notSubjectToTaxMinorUnits(),
            $this->incomeLines,
        ));
    }

    public function totalOtherDeductionsMinorUnits(): int
    {
        return $this->sumLines($this->otherDeductionLines);
    }

    public function totalEmployeeDeductionsMinorUnits(): int
    {
        return $this->sumExactly([
            $this->employeeSocialMinorUnits,
            $this->employeeHealthMinorUnits,
            $this->healthMinimumTopUpMinorUnits,
            $this->taxAfterCreditsMinorUnits,
            $this->totalOtherDeductionsMinorUnits(),
        ]);
    }

    public function totalEmployerInsuranceMinorUnits(): int
    {
        return $this->sumExactly([
            $this->employerSocialMinorUnits,
            $this->employerHealthMinorUnits,
        ]);
    }

    public function totalEmployerCostMinorUnits(): int
    {
        return $this->sumExactly([
            $this->grossMinorUnits,
            $this->totalEmployerInsuranceMinorUnits(),
        ]);
    }

    /**
     * @return array{
     *   revision_id:string,
     *   source_snapshot_sha256:string,
     *   employer:array{name:string,identification_number:string},
     *   employee:array{display_name:string},
     *   period:string,
     *   employment_label:string,
     *   income_lines:list<array<string,mixed>>,
     *   gross_minor_units:int,
     *   income_detail_status:string,
     *   tax_exempt_income_minor_units:int,
     *   not_subject_to_tax_income_minor_units:int,
     *   employee_social_minor_units:int,
     *   employee_health_minor_units:int,
     *   health_minimum_top_up_minor_units:int,
     *   tax_base_minor_units:int,
     *   tax_before_credits_minor_units:int,
     *   tax_non_refundable_credits_minor_units:int,
     *   tax_child_credit_minor_units:int,
     *   tax_bonus_eligible:bool,
     *   tax_after_credits_minor_units:int,
     *   tax_bonus_minor_units:int,
     *   other_deduction_lines:list<array<string,mixed>>,
     *   total_other_deductions_minor_units:int,
     *   total_employee_deductions_minor_units:int,
     *   rounding_adjustment_minor_units:int,
     *   net_minor_units:int,
     *   employer_social_minor_units:int,
     *   employer_health_minor_units:int,
     *   total_employer_insurance_minor_units:int,
     *   total_employer_cost_minor_units:int,
     *   accounting:array{gross:string,employer_insurance:string},
     *   currency:string
     * }
     */
    public function toTemplateData(): array
    {
        return [
            'revision_id' => $this->revisionId,
            'source_snapshot_sha256' => $this->sourceSnapshotSha256,
            'employer' => [
                'name' => $this->employerName,
                'identification_number' => $this->employerIdentificationNumber,
            ],
            'employee' => [
                'display_name' => $this->employeeDisplayName,
            ],
            'period' => $this->period,
            'employment_label' => $this->employmentLabel,
            'income_lines' => array_map(
                static fn (PayslipLine $line): array => $line->toTemplateData(),
                $this->incomeLines,
            ),
            'gross_minor_units' => $this->grossMinorUnits,
            'income_detail_status' => $this->incomeDetailStatus,
            'tax_exempt_income_minor_units' => $this->taxExemptIncomeMinorUnits(),
            'not_subject_to_tax_income_minor_units' => $this->notSubjectToTaxIncomeMinorUnits(),
            'employee_social_minor_units' => $this->employeeSocialMinorUnits,
            'employee_health_minor_units' => $this->employeeHealthMinorUnits,
            'health_minimum_top_up_minor_units' => $this->healthMinimumTopUpMinorUnits,
            'tax_base_minor_units' => $this->taxBaseMinorUnits,
            'tax_before_credits_minor_units' => $this->taxBeforeCreditsMinorUnits,
            'tax_non_refundable_credits_minor_units' => $this->taxNonRefundableCreditsMinorUnits,
            'tax_child_credit_minor_units' => $this->taxChildCreditMinorUnits,
            'tax_bonus_eligible' => $this->taxBonusEligible,
            'tax_after_credits_minor_units' => $this->taxAfterCreditsMinorUnits,
            'tax_bonus_minor_units' => $this->taxBonusMinorUnits,
            'other_deduction_lines' => array_map(
                static fn (PayslipLine $line): array => $line->toTemplateData(),
                $this->otherDeductionLines,
            ),
            'total_other_deductions_minor_units' => $this->totalOtherDeductionsMinorUnits(),
            'total_employee_deductions_minor_units' => $this->totalEmployeeDeductionsMinorUnits(),
            'rounding_adjustment_minor_units' => $this->roundingAdjustmentMinorUnits,
            'annual_settlement_minor_units' => $this->annualSettlementMinorUnits,
            'net_minor_units' => $this->netMinorUnits,
            'employer_social_minor_units' => $this->employerSocialMinorUnits,
            'employer_health_minor_units' => $this->employerHealthMinorUnits,
            'total_employer_insurance_minor_units' => $this->totalEmployerInsuranceMinorUnits(),
            'total_employer_cost_minor_units' => $this->totalEmployerCostMinorUnits(),
            'accounting' => [
                'gross' => $this->grossExpenseAccount . '/' . $this->grossLiabilityAccount,
                'employer_insurance' => $this->insuranceExpenseAccount . '/' . $this->insuranceLiabilityAccount,
            ],
            'currency' => $this->currency,
        ];
    }

    private function assertText(string $value, string $label): void
    {
        if (trim($value) === '' || mb_strlen($value) > 200) {
            throw new \InvalidArgumentException($label . ' must not be empty.');
        }
    }

    /** @param array<array-key,mixed> $lines */
    private function assertLineList(array $lines, string $label, bool $allowEmpty): void
    {
        if (!array_is_list($lines) || (!$allowEmpty && $lines === []) || count($lines) > self::MAX_LINES) {
            throw new \InvalidArgumentException($label . ' has an invalid list structure.');
        }

        foreach ($lines as $line) {
            if (!$line instanceof PayslipLine) {
                throw new \InvalidArgumentException($label . ' must contain payslip lines only.');
            }
        }
    }

    /**
     * @param list<PayslipLine> $lines
     */
    private function sumLines(array $lines): int
    {
        return $this->sumExactly(array_map(
            static fn (PayslipLine $line): int => $line->amountMinorUnits,
            $lines,
        ));
    }

    /** @param list<int> $values */
    private function sumExactly(array $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            if (
                ($value > 0 && $total > PHP_INT_MAX - $value)
                || ($value < 0 && $total < PHP_INT_MIN - $value)
            ) {
                throw new \OverflowException('Payslip amount aggregation exceeds the integer range.');
            }

            $total += $value;
        }

        return $total;
    }
}
