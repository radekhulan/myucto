<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Service\Payroll\Accounting\PayrollAccountCode;
use MyInvoice\Service\Payroll\Calculation\Money;
use MyInvoice\Service\Payroll\Component\PayrollExemptIncomeSplit;
use MyInvoice\Service\Payroll\Insurance\EmployerSocialInsuranceAllocation;

final class PayslipDocumentSnapshotMapper
{
    /**
     * Mapování v2 doplňuje podklad nezdanění u jednotlivých složek mzdy.
     *
     * Verze je součástí zmrazeného výsledku osoby, takže nová páska vzniká jen
     * novým během. Už archivovaná páska se tím nemění: její snapshot zůstává
     * v1 a hydrátor ho vydá jako neevidovaný údaj, ne jako nulu.
     */
    private const SCHEMA_VERSION = 'payroll-payslip-document.v2';

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function attach(array $snapshot, array $result): array
    {
        if (($snapshot['schema_version'] ?? null) !== 'payroll-run-input.v2') {
            throw new \DomainException(
                'Snapshot výplatní pásky vyžaduje vstup payroll-run-input.v2.',
            );
        }
        $statutory = $this->object($result['statutory'] ?? null, 'zákonný výsledek');
        if (($statutory['status'] ?? null) !== 'calculated') {
            return $result;
        }

        $employer = $this->object(
            $snapshot['employer'] ?? null,
            'zmrazená identita zaměstnavatele',
        );
        $snapshotPeople = $this->indexPeople(
            $this->rows($snapshot['people'] ?? null, 'zmrazené osoby'),
            true,
        );
        $people = $this->rows($result['people'] ?? null, 'výsledky osob');
        $resultPeople = $this->indexPeople($people, false);
        if (array_keys($snapshotPeople) !== array_keys($resultPeople)) {
            throw new \DomainException(
                'Výsledek nemá právě jednu vypočtenou osobu pro každou zmrazenou osobu.',
            );
        }
        foreach ($people as $person) {
            $enforcement = $this->object(
                $person['enforcement'] ?? null,
                'výsledek exekučních srážek',
            );
            $enforcementResult = $this->object(
                $enforcement['result'] ?? null,
                'výpočet exekučních srážek',
            );
            if (($enforcementResult['status'] ?? null) !== 'supported') {
                return $result;
            }
        }

        $employerSocial = $this->nonNegativeInt(
            $statutory,
            'employer_social_minor_units',
        );
        $employerSocialBeforeDiscount = $this->nonNegativeInt(
            $statutory,
            'employer_social_before_discount_minor_units',
        );
        $employerSocialDiscount = $this->nonNegativeInt(
            $statutory,
            'employer_social_part_time_discount_minor_units',
        );
        if ($employerSocialBeforeDiscount - $employerSocialDiscount
            !== $employerSocial
        ) {
            throw new \DomainException(
                'Pojistné zaměstnavatele po slevě nemá konzistentní součet.',
            );
        }
        $socialAllocations = $this->allocateEmployerSocial(
            $resultPeople,
            $employerSocialBeforeDiscount,
            $employerSocialDiscount,
            $this->employerSocialCategoryAmounts($statutory, $employerSocialBeforeDiscount),
        );
        $attached = [];
        foreach ($people as $personResult) {
            $employeeId = $this->positiveInt($personResult, 'employee_id');
            $personResult['payslip_document'] = $this->mapPerson(
                $snapshot,
                $employer,
                $snapshotPeople[$employeeId],
                $personResult,
                $socialAllocations[$employeeId],
            );
            $attached[] = $personResult;
        }
        $result['people'] = $attached;

        return $result;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $employer
     * @param array<string,mixed> $personSnapshot
     * @param array<string,mixed> $personResult
     * @return array<string,mixed>
     */
    private function mapPerson(
        array $snapshot,
        array $employer,
        array $personSnapshot,
        array $personResult,
        int $employerSocialMinorUnits,
    ): array {
        $employee = $this->object(
            $personSnapshot['employee'] ?? null,
            'zmrazená osoba',
        );
        $employeeId = $this->positiveInt($employee, 'id');
        if ($employeeId !== $this->positiveInt($personResult, 'employee_id')) {
            throw new \DomainException('Identita osoby výplatní pásky nesouhlasí.');
        }
        $statutory = $this->object(
            $personResult['statutory'] ?? null,
            "zákonný výsledek osoby {$employeeId}",
        );
        if (($statutory['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                "Osoba {$employeeId} nemá vypočtený zákonný výsledek.",
            );
        }
        $social = $this->object(
            $statutory['social_insurance'] ?? null,
            "sociální pojištění osoby {$employeeId}",
        );
        $health = $this->object(
            $statutory['health_insurance'] ?? null,
            "zdravotní pojištění osoby {$employeeId}",
        );
        $tax = $this->object(
            $statutory['income_tax'] ?? null,
            "daň osoby {$employeeId}",
        );
        $net = $this->object(
            $statutory['net_pay'] ?? null,
            "čistá mzda osoby {$employeeId}",
        );
        foreach ([$social, $health, $tax] as $domain) {
            if (($domain['status'] ?? null) !== 'calculated') {
                throw new \DomainException(
                    "Osoba {$employeeId} nemá uzavřený zákonný výpočet.",
                );
            }
        }
        $this->assertReference($social, 'person_id', $employeeId);
        $this->assertReference($health, 'person_id', $employeeId);
        $this->assertReference($tax, 'employee_reference', $employeeId);
        $this->assertReference($net, 'person_reference', $employeeId);

        [
            'income_lines' => $incomeLines,
            'gross_minor_units' => $grossMinorUnits,
            'cash_minor_units' => $cashMinorUnits,
            'employment_label' => $employmentLabel,
            'gross_accounts' => $grossAccounts,
        ] = $this->income(
            $personSnapshot,
            $personResult,
            $this->object(
                $employer['accounting_accounts'] ?? null,
                'zmrazené účetní předkontace zaměstnavatele',
            ),
        );
        $netCash = $this->nonNegativeInt($net, 'cash_income_minor_units');
        $nonCash = $this->nonNegativeInt($net, 'non_cash_income_minor_units');
        if ($cashMinorUnits !== $netCash
            || $grossMinorUnits !== (new Money($netCash))
                ->add(new Money($nonCash))
                ->minorUnits
        ) {
            throw new \DomainException(
                "Příjmy osoby {$employeeId} nesouhlasí s výpočtem čisté mzdy.",
            );
        }

        $employeeSocial = $this->nonNegativeInt(
            $social,
            'employee_contribution_minor_units',
        );
        if ($employeeSocial !== $this->nonNegativeInt(
            $net,
            'employee_social_minor_units',
        )) {
            throw new \DomainException(
                "Sociální pojištění osoby {$employeeId} nesouhlasí s čistou mzdou.",
            );
        }
        $employeeHealthTotal = $this->nonNegativeInt(
            $health,
            'employee_contribution_minor_units',
        );
        if ($employeeHealthTotal !== $this->nonNegativeInt(
            $net,
            'employee_health_minor_units',
        )) {
            throw new \DomainException(
                "Zdravotní pojištění osoby {$employeeId} nesouhlasí s čistou mzdou.",
            );
        }
        $healthTopUp = $this->nonNegativeInt(
            $health,
            'employee_minimum_top_up_minor_units',
        );
        if ($healthTopUp > $employeeHealthTotal) {
            throw new \DomainException(
                "Doplatek zdravotního pojištění osoby {$employeeId} je rozporný.",
            );
        }

        $advanceTax = $tax['advance_tax'] ?? null;
        if ($advanceTax !== null) {
            $advanceTax = $this->object(
                $advanceTax,
                "zálohová daň osoby {$employeeId}",
            );
        }
        $taxBase = $advanceTax === null
            ? 0
            : $this->nonNegativeInt($advanceTax, 'rounded_tax_base_minor_units');
        $taxBeforeCredits = $advanceTax === null
            ? 0
            : $this->nonNegativeInt($advanceTax, 'tax_before_credits_minor_units');
        $taxNonRefundableCredits = $advanceTax === null
            ? 0
            : $this->nonNegativeInt(
                $advanceTax,
                'non_refundable_credits_minor_units',
            );
        $taxChildCredit = $advanceTax === null
            ? 0
            : $this->nonNegativeInt($advanceTax, 'child_credit_minor_units');
        $taxBonusEligible = $advanceTax === null
            ? false
            : $this->boolean($advanceTax, 'tax_bonus_eligible');
        $taxAfterCredits = $advanceTax === null
            ? 0
            : $this->nonNegativeInt($advanceTax, 'tax_after_credits_minor_units');
        $taxBonus = $advanceTax === null
            ? 0
            : $this->nonNegativeInt($advanceTax, 'tax_bonus_minor_units');
        if ($taxAfterCredits !== $this->nonNegativeInt(
            $net,
            'advance_tax_minor_units',
        ) || $taxBonus !== $this->nonNegativeInt($net, 'tax_bonus_minor_units')) {
            throw new \DomainException(
                "Daň osoby {$employeeId} nesouhlasí s čistou mzdou.",
            );
        }

        $otherDeductions = $this->otherDeductions(
            $personSnapshot,
            $personResult,
            $net,
            $nonCash,
        );
        $correction = $this->int($net, 'correction_minor_units');
        $annualSettlement = $this->nonNegativeInt(
            $net + ['annual_settlement_minor_units' => 0],
            'annual_settlement_minor_units',
        );
        $netBeforeEnforcement = $this->nonNegativeInt(
            $net,
            'net_payable_minor_units',
        );
        if ($netBeforeEnforcement !== $this->nonNegativeInt(
            $statutory,
            'net_payable_minor_units',
        )) {
            throw new \DomainException(
                "Čistá mzda osoby {$employeeId} nemá jednotný výsledek.",
            );
        }
        $netPayable = $this->nonNegativeInt(
            $personResult,
            'payable_after_enforcement_minor',
        );

        $accounts = $this->object(
            $employer['accounting_accounts'] ?? null,
            'zmrazené účetní předkontace zaměstnavatele',
        );
        $employerHealth = $this->nonNegativeInt(
            $health,
            'employer_contribution_minor_units',
        );
        $insuranceLiability = $this->insuranceLiabilityAccount(
            $accounts,
            $employerSocialMinorUnits,
            $employerHealth,
        );
        $sourceHash = $this->text($personResult, 'source_snapshot_hash', false)
            ?? $this->text($snapshot, 'source_snapshot_hash', false)
            ?? null;
        if ($sourceHash === null) {
            $sourceHash = hash('sha256', 'payslip-document-validation');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) {
            throw new \DomainException('Zdrojový otisk výplatní pásky není platný.');
        }
        $periodStart = $this->requiredText($snapshot, 'period_start');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $periodStart) !== 1) {
            throw new \DomainException('Období výplatní pásky není platné.');
        }

        $document = new PayslipDocumentData(
            revisionId: 'calculation',
            sourceSnapshotSha256: $sourceHash,
            employerName: $this->requiredText($employer, 'name'),
            employerIdentificationNumber:
                $this->requiredText($employer, 'identification_number'),
            employeeDisplayName: $this->requiredText($employee, 'full_name'),
            period: substr($periodStart, 0, 7),
            employmentLabel: $employmentLabel,
            incomeLines: $incomeLines,
            grossMinorUnits: $grossMinorUnits,
            employeeSocialMinorUnits: $employeeSocial,
            employeeHealthMinorUnits: $employeeHealthTotal - $healthTopUp,
            healthMinimumTopUpMinorUnits: $healthTopUp,
            taxBaseMinorUnits: $taxBase,
            taxBeforeCreditsMinorUnits: $taxBeforeCredits,
            taxNonRefundableCreditsMinorUnits: $taxNonRefundableCredits,
            taxChildCreditMinorUnits: $taxChildCredit,
            taxBonusEligible: $taxBonusEligible,
            taxAfterCreditsMinorUnits: $taxAfterCredits,
            taxBonusMinorUnits: $taxBonus,
            otherDeductionLines: $otherDeductions,
            roundingAdjustmentMinorUnits: $correction,
            netMinorUnits: $netPayable,
            employerSocialMinorUnits: $employerSocialMinorUnits,
            employerHealthMinorUnits: $employerHealth,
            grossExpenseAccount: $grossAccounts['debit'],
            grossLiabilityAccount: $grossAccounts['credit'],
            insuranceExpenseAccount:
                $this->account($accounts, 'employer_insurance_debit'),
            insuranceLiabilityAccount: $insuranceLiability,
            annualSettlementMinorUnits: $annualSettlement,
            incomeDetailStatus: PayslipDocumentData::INCOME_DETAIL_RECORDED,
        );

        return $this->snapshot($document);
    }

    /**
     * @param array<string,mixed> $personSnapshot
     * @param array<string,mixed> $personResult
     * @param array<string,mixed> $configuredAccounts
     * @return array{
     *   income_lines:list<PayslipLine>,
     *   gross_minor_units:int,
     *   cash_minor_units:int,
     *   employment_label:string,
     *   gross_accounts:array{debit:string,credit:string}
     * }
     */
    private function income(
        array $personSnapshot,
        array $personResult,
        array $configuredAccounts,
    ): array {
        $snapshotEmployments = [];
        foreach ($this->rows(
            $personSnapshot['employments'] ?? null,
            'zmrazené pracovní vztahy',
        ) as $employmentSnapshot) {
            $employment = $this->object(
                $employmentSnapshot['employment'] ?? null,
                'zmrazený pracovní vztah',
            );
            $employmentId = $this->positiveInt($employment, 'id');
            if (isset($snapshotEmployments[$employmentId])) {
                throw new \DomainException('Zmrazené pracovní vztahy nejsou jednoznačné.');
            }
            $snapshotEmployments[$employmentId] = $employmentSnapshot;
        }

        $incomeLines = [];
        $gross = new Money(0);
        $cash = new Money(0);
        $labels = [];
        $accountPairs = [];
        foreach ($this->rows(
            $personResult['employments'] ?? null,
            'výsledky pracovních vztahů',
        ) as $employmentResult) {
            $employmentId = $this->positiveInt($employmentResult, 'employment_id');
            $employmentSnapshot = $snapshotEmployments[$employmentId] ?? null;
            if ($employmentSnapshot === null) {
                throw new \DomainException(
                    "Výsledek odkazuje na nezmrazený vztah {$employmentId}.",
                );
            }
            $employment = $this->object(
                $employmentSnapshot['employment'] ?? null,
                "pracovní vztah {$employmentId}",
            );
            $relationType = $this->requiredText($employment, 'relation_type');
            $labels[$relationType] = $this->relationLabel($relationType);
            $fallbackAccounts = $this->relationAccounts(
                $relationType,
                $configuredAccounts,
            );

            $inputs = [];
            foreach ($this->rows(
                $employmentSnapshot['inputs'] ?? null,
                "vstupy vztahu {$employmentId}",
            ) as $input) {
                $inputId = $this->positiveInt($input, 'id');
                if (isset($inputs[$inputId])) {
                    throw new \DomainException(
                        "Zmrazené vstupy vztahu {$employmentId} nejsou jednoznačné.",
                    );
                }
                $inputs[$inputId] = $input;
            }
            foreach ($this->rows(
                $employmentResult['inputs'] ?? null,
                "výsledky vstupů vztahu {$employmentId}",
            ) as $inputResult) {
                $inputId = $this->positiveInt($inputResult, 'input_id');
                $input = $inputs[$inputId] ?? null;
                if ($input === null) {
                    throw new \DomainException(
                        "Výsledek odkazuje na nezmrazený vstup {$inputId}.",
                    );
                }
                $component = $this->object(
                    $input['component'] ?? null,
                    "složka vstupu {$inputId}",
                );
                $totals = $this->object(
                    $inputResult['totals'] ?? null,
                    "součty vstupu {$inputId}",
                );
                $sourceAmount = $this->int($totals, 'source_amount_minor');
                $cashAmount = $this->int($totals, 'cash_payable_minor');
                // Tentýž rozpad, ze kterého mzdový list sestavuje § 38j odst. 2
                // písm. f) bod 2. Sdílený, aby se oba doklady o téže mzdě
                // nemohly rozejít.
                $split = PayrollExemptIncomeSplit::fromFrozenInput(
                    $input,
                    $sourceAmount,
                    $inputId,
                );
                $incomeLines[] = new PayslipLine(
                    $this->requiredText($component, 'name'),
                    $sourceAmount,
                    $split->basis,
                    $split->exemptMinorUnits,
                );
                $gross = $gross->add(new Money($sourceAmount));
                $cash = $cash->add(new Money($cashAmount));

                if ($sourceAmount !== 0) {
                    $accounting = $this->object(
                        $inputResult['accounting'] ?? null,
                        "účtování vstupu {$inputId}",
                    );
                    $debit = $this->nullableAccount($accounting, 'debit_code')
                        ?? $fallbackAccounts['gross_debit'];
                    $credit = $this->nullableAccount($accounting, 'credit_code')
                        ?? $fallbackAccounts['gross_credit'];
                    $accountPairs[$debit . "\0" . $credit] = [
                        'debit' => $debit,
                        'credit' => $credit,
                    ];
                }
            }
        }
        if ($incomeLines === []) {
            throw new \DomainException(
                'Vypočtená osoba nemá žádnou zmrazenou příjmovou složku.',
            );
        }
        if ($accountPairs === []) {
            $first = reset($snapshotEmployments);
            if (!is_array($first)) {
                throw new \DomainException('Vypočtená osoba nemá pracovní vztah.');
            }
            $employment = $this->object(
                $first['employment'] ?? null,
                'pracovní vztah',
            );
            $fallback = $this->relationAccounts(
                $this->requiredText($employment, 'relation_type'),
                $configuredAccounts,
            );
            $accountPairs[] = [
                'debit' => $fallback['gross_debit'],
                'credit' => $fallback['gross_credit'],
            ];
        }
        $debits = [];
        $credits = [];
        foreach ($accountPairs as $pair) {
            $debits[$pair['debit']] = true;
            $credits[$pair['credit']] = true;
        }
        ksort($debits, SORT_STRING);
        ksort($credits, SORT_STRING);
        ksort($labels, SORT_STRING);

        return [
            'income_lines' => $incomeLines,
            'gross_minor_units' => $gross->minorUnits,
            'cash_minor_units' => $cash->minorUnits,
            'employment_label' => implode(', ', array_values($labels)),
            'gross_accounts' => [
                'debit' => implode(', ', array_keys($debits)),
                'credit' => implode(', ', array_keys($credits)),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $personSnapshot
     * @param array<string,mixed> $personResult
     * @param array<string,mixed> $net
     * @return list<PayslipLine>
     */
    private function otherDeductions(
        array $personSnapshot,
        array $personResult,
        array $net,
        int $nonCashMinorUnits,
    ): array {
        $lines = [];
        if ($nonCashMinorUnits > 0) {
            $lines[] = new PayslipLine('Nepeněžní plnění', $nonCashMinorUnits);
        }
        $withholding = $this->nonNegativeInt($net, 'withholding_tax_minor_units');
        if ($withholding > 0) {
            $lines[] = new PayslipLine('Srážková daň', $withholding);
        }

        $agreements = [];
        foreach ($this->rows(
            $personSnapshot['deduction_agreements'] ?? null,
            'zmrazené dohody o srážkách',
        ) as $agreement) {
            $id = $this->positiveInt($agreement, 'id');
            if (isset($agreements[$id])) {
                throw new \DomainException('Dohody o srážkách nejsou jednoznačné.');
            }
            $agreements[$id] = $agreement;
        }
        $deducted = new Money(0);
        foreach ($this->rows($net['deductions'] ?? null, 'provedené srážky') as $deduction) {
            $reference = $this->requiredText($deduction, 'deduction_reference');
            if (preg_match('/^agreement:([1-9][0-9]*)$/D', $reference, $match) !== 1) {
                throw new \DomainException(
                    "Srážka {$reference} nemá zmrazenou dohodu.",
                );
            }
            $agreementId = (int) $match[1];
            $agreement = $agreements[$agreementId] ?? null;
            if ($agreement === null) {
                throw new \DomainException(
                    "Srážka {$reference} nemá zmrazenou dohodu.",
                );
            }
            $amount = $this->nonNegativeInt($deduction, 'applied_minor_units');
            $deducted = $deducted->add(new Money($amount));
            if ($amount > 0) {
                $lines[] = new PayslipLine(
                    $this->requiredText($agreement, 'title'),
                    $amount,
                );
            }
        }
        if ($deducted->minorUnits !== $this->nonNegativeInt(
            $net,
            'deducted_minor_units',
        )) {
            throw new \DomainException(
                'Součet zmrazených srážek nesouhlasí s čistou mzdou.',
            );
        }
        $enforcement = $this->object(
            $personResult['enforcement'] ?? null,
            'výsledek exekučních srážek',
        );
        $enforcementResult = $this->object(
            $enforcement['result'] ?? null,
            'výpočet exekučních srážek',
        );
        if (($enforcementResult['status'] ?? null) !== 'supported') {
            throw new \DomainException(
                'Výplatní pásku nelze vytvořit z neuzavřených exekučních srážek.',
            );
        }
        $enforcementWithheld = $this->nonNegativeInt(
            $enforcementResult,
            'total_withheld_minor_units',
        );
        if ($enforcementWithheld > 0) {
            $lines[] = new PayslipLine(
                'Exekuční a insolvenční srážky',
                $enforcementWithheld,
            );
        }

        return $lines;
    }

    /**
     * Pojistné zaměstnavatele po kategoriích § 5a odst. 1 ZPSZ.
     *
     * Zákonný výsledek zmrazený dřív, než rozpad existoval, žádný nenese —
     * tehdy uměl modul jen běžnou sazbu, takže prázdný seznam znamená „jedna
     * kategorie" a páska se rozdělí po staru. Neúplný rozpad (součet nesedí na
     * firemní částku) je naopak vada a nesmí se dopočítat zbytkem.
     *
     * @param array<string,mixed> $statutory
     * @return array<string,int>
     */
    private function employerSocialCategoryAmounts(array $statutory, int $beforeDiscount): array
    {
        $amounts = [];
        foreach ($this->rows(
            $statutory['employer_social_categories'] ?? [],
            'kategorie pojistného zaměstnavatele',
        ) as $row) {
            $category = $row['category'] ?? null;
            if (!is_string($category) || $category === '') {
                throw new \DomainException('Kategorie pojistného zaměstnavatele nemá název.');
            }
            $amounts[$category] = $this->nonNegativeInt($row, 'contribution_minor_units');
        }
        if ($amounts !== [] && array_sum($amounts) !== $beforeDiscount) {
            throw new \DomainException(
                'Rozpad pojistného zaměstnavatele nedává firemní částku před slevou.',
            );
        }

        return $amounts;
    }

    /**
     * @param array<int,array<string,mixed>> $people
     * @param array<string,int> $categoryAmounts
     * @return array<int,int>
     */
    private function allocateEmployerSocial(
        array $people,
        int $beforeDiscount,
        int $discount,
        array $categoryAmounts,
    ): array
    {
        $baseWeights = [];
        $discountWeights = [];
        $categoryWeights = array_fill_keys(array_keys($categoryAmounts), []);
        foreach ($people as $employeeId => $person) {
            $statutory = $this->object(
                $person['statutory'] ?? null,
                "zákonný výsledek osoby {$employeeId}",
            );
            $social = $this->object(
                $statutory['social_insurance'] ?? null,
                "sociální pojištění osoby {$employeeId}",
            );
            $baseWeights[$employeeId] = $this->nonNegativeInt(
                $social,
                'capped_assessment_base_minor_units',
            );
            $discountBase = new Money(0);
            foreach ($categoryWeights as $category => $unused) {
                $categoryWeights[$category][$employeeId] = 0;
            }
            foreach ($this->rows(
                $social['relationships'] ?? null,
                "vztahy sociálního pojištění osoby {$employeeId}",
            ) as $relationship) {
                $relationshipBase = $this->nonNegativeInt(
                    $relationship,
                    'capped_assessment_base_minor_units',
                );
                /*
                 * Doložený nárok podle § 7a odst. 1 ještě není uplatněná sleva —
                 * limity § 7a odst. 3 ji můžou vyloučit. Váhou rozdělení je jen
                 * skutečně uplatněná sleva; jinak by osoba s nulovou slevou
                 * ukrojila z rozdělení kus, který jí nepatří. Revize uložené
                 * dřív, než výsledek `..._outcome` nesl, znaly jen „doložený =
                 * uplatněný", a tak se z nich čtou dál.
                 */
                $discountOutcome = $relationship['part_time_employer_discount_outcome'] ?? null;
                if (($relationship['part_time_employer_discount'] ?? null) === 'verified'
                    && ($discountOutcome === null || $discountOutcome === 'applied')
                ) {
                    $discountBase = $discountBase->add(new Money($relationshipBase));
                }
                $category = $relationship['employer_rate_category'] ?? null;
                if (is_string($category) && array_key_exists($category, $categoryWeights)) {
                    $categoryWeights[$category][$employeeId] += $relationshipBase;
                } elseif ($categoryAmounts !== []) {
                    throw new \DomainException(
                        "Vztah osoby {$employeeId} spadá do kategorie, kterou firemní výsledek nezná.",
                    );
                }
            }
            $discountWeights[$employeeId] = $discountBase->minorUnits;
        }

        if ($categoryAmounts === []) {
            return EmployerSocialInsuranceAllocation::allocate(
                $baseWeights,
                $discountWeights,
                $beforeDiscount,
                $discount,
            );
        }

        return EmployerSocialInsuranceAllocation::allocateByCategory(
            $categoryWeights,
            $categoryAmounts,
            $discountWeights,
            $discount,
        );
    }

    /**
     * @param array<string,mixed> $accounts
     */
    private function insuranceLiabilityAccount(
        array $accounts,
        int $employerSocial,
        int $employerHealth,
    ): string {
        $social = $this->account($accounts, 'social_insurance_credit');
        $health = $this->account($accounts, 'health_insurance_credit');
        $used = [];
        if ($employerSocial > 0) {
            $used[$social] = true;
        }
        if ($employerHealth > 0) {
            $used[$health] = true;
        }
        if ($used === []) {
            $used[$social] = true;
            $used[$health] = true;
        }
        ksort($used, SORT_STRING);

        return implode(', ', array_keys($used));
    }

    /** @param array<string,mixed> $row */
    private function assertReference(array $row, string $field, int $employeeId): void
    {
        if (($row[$field] ?? null) !== "employee:{$employeeId}") {
            throw new \DomainException(
                "Zákonný výsledek osoby {$employeeId} má jinou identitu.",
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $people
     * @return array<int,array<string,mixed>>
     */
    private function indexPeople(array $people, bool $snapshot): array
    {
        $result = [];
        foreach ($people as $person) {
            $employeeId = $snapshot
                ? $this->positiveInt(
                    $this->object($person['employee'] ?? null, 'zmrazená osoba'),
                    'id',
                )
                : $this->positiveInt($person, 'employee_id');
            if (isset($result[$employeeId])) {
                throw new \DomainException('Osoby mzdového běhu nejsou jednoznačné.');
            }
            $result[$employeeId] = $person;
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /** @return array<string,mixed> */
    private function snapshot(PayslipDocumentData $document): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'employer_name' => $document->employerName,
            'employer_identification_number' =>
                $document->employerIdentificationNumber,
            'employee_display_name' => $document->employeeDisplayName,
            'employment_label' => $document->employmentLabel,
            'income_lines' => array_map(
                static fn (PayslipLine $line): array => $line->toTemplateData(),
                $document->incomeLines,
            ),
            'gross_minor_units' => $document->grossMinorUnits,
            'employee_social_minor_units' => $document->employeeSocialMinorUnits,
            'employee_health_minor_units' => $document->employeeHealthMinorUnits,
            'health_minimum_top_up_minor_units' =>
                $document->healthMinimumTopUpMinorUnits,
            'tax_base_minor_units' => $document->taxBaseMinorUnits,
            'tax_before_credits_minor_units' => $document->taxBeforeCreditsMinorUnits,
            'tax_non_refundable_credits_minor_units' =>
                $document->taxNonRefundableCreditsMinorUnits,
            'tax_child_credit_minor_units' => $document->taxChildCreditMinorUnits,
            'tax_bonus_eligible' => $document->taxBonusEligible,
            'tax_after_credits_minor_units' => $document->taxAfterCreditsMinorUnits,
            'tax_bonus_minor_units' => $document->taxBonusMinorUnits,
            'other_deduction_lines' => array_map(
                static fn (PayslipLine $line): array => $line->toTemplateData(),
                $document->otherDeductionLines,
            ),
            'rounding_adjustment_minor_units' =>
                $document->roundingAdjustmentMinorUnits,
            'annual_settlement_minor_units' =>
                $document->annualSettlementMinorUnits,
            'net_minor_units' => $document->netMinorUnits,
            'employer_social_minor_units' => $document->employerSocialMinorUnits,
            'employer_health_minor_units' => $document->employerHealthMinorUnits,
            'gross_expense_account' => $document->grossExpenseAccount,
            'gross_liability_account' => $document->grossLiabilityAccount,
            'insurance_expense_account' => $document->insuranceExpenseAccount,
            'insurance_liability_account' => $document->insuranceLiabilityAccount,
            'currency' => $document->currency,
            'income_detail_status' => $document->incomeDetailStatus,
        ];
    }

    private function relationLabel(string $relationType): string
    {
        return match ($relationType) {
            'employment' => 'Pracovní poměr',
            'small_scale_employment' => 'Zaměstnání malého rozsahu',
            'dpp' => 'Dohoda o provedení práce',
            'dpc' => 'Dohoda o pracovní činnosti',
            'partner_dependent' => 'Závislý příjem společníka',
            'statutory_body' => 'Odměna za výkon funkce',
            default => throw new \DomainException(
                "Neznámý druh pracovního vztahu {$relationType}.",
            ),
        };
    }

    /**
     * @param array<string,mixed> $accounts
     * @return array{
     *   gross_debit:string,
     *   gross_credit:string,
     *   employer_insurance_debit:string,
     *   employer_insurance_credit:string
     * }
     */
    private function relationAccounts(string $relationType, array $accounts): array
    {
        [$grossDebit, $grossCredit] = match ($relationType) {
            'employment', 'small_scale_employment', 'dpp', 'dpc' => [
                'employment_gross_debit',
                'employment_gross_credit',
            ],
            'partner_dependent' => [
                'partner_gross_debit',
                'partner_gross_credit',
            ],
            'statutory_body' => [
                'statutory_gross_debit',
                'statutory_gross_credit',
            ],
            default => throw new \DomainException(
                "Neznámý druh pracovního vztahu {$relationType}.",
            ),
        };

        return [
            'gross_debit' => $this->account($accounts, $grossDebit),
            'gross_credit' => $this->account($accounts, $grossCredit),
            'employer_insurance_debit' =>
                $this->account($accounts, 'employer_insurance_debit'),
            'employer_insurance_credit' =>
                $this->account($accounts, 'social_insurance_credit'),
        ];
    }

    /** @param array<string,mixed> $row */
    private function account(array $row, string $field): string
    {
        $value = $this->requiredText($row, $field);
        if (!PayrollAccountCode::isValid($value)) {
            throw new \DomainException("Účet {$field} není platný.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nullableAccount(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !PayrollAccountCode::isValid($value)) {
            throw new \DomainException("Účet {$field} není platný.");
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("Chybí {$field}.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException("{$field} musí mít textové klíče.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("Chybí {$field}.");
        }

        return array_map(
            fn (mixed $row): array => $this->object($row, $field),
            $value,
        );
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \DomainException("Pole {$field} musí být celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nonNegativeInt(array $row, string $field): int
    {
        $value = $this->int($row, $field);
        if ($value < 0) {
            throw new \DomainException("Pole {$field} musí být nezáporné.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $field): int
    {
        $value = $this->int($row, $field);
        if ($value <= 0) {
            throw new \DomainException("Pole {$field} musí být kladné.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function boolean(array $row, string $field): bool
    {
        $value = $row[$field] ?? null;
        if (!is_bool($value)) {
            throw new \DomainException("Pole {$field} musí být logická hodnota.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function requiredText(array $row, string $field): string
    {
        return $this->text($row, $field, true)
            ?? throw new \DomainException("Chybí pole {$field}.");
    }

    /** @param array<string,mixed> $row */
    private function text(array $row, string $field, bool $required): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("Pole {$field} musí být neprázdný text.");
        }

        return $value;
    }
}
