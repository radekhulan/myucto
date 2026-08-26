<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Service\Payroll\Accounting\PayrollAccountCode;
use MyInvoice\Service\Payroll\Net\PayoutAllocationRequest;
use MyInvoice\Service\Payroll\Net\PayoutAllocationService;
use MyInvoice\Service\Payroll\Net\PayrollPartnerSettlement;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\PayrollEmploymentAccountingClassifier;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollPostingLineBuilder
{
    public function __construct(
        private readonly PayrollEmploymentAccountingClassifier $classifier =
            new PayrollEmploymentAccountingClassifier(),
        private readonly PayoutAllocationService $payoutAllocations =
            new PayoutAllocationService(),
        private readonly PayrollDimensionCostAccountResolver $dimensionAccounts =
            new PayrollDimensionCostAccountResolver(),
    ) {}

    /**
     * Debit is positive and credit is negative in target allocations.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     * @param array<string,mixed> $statutorySets
     * @param array<string,string> $accounts
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $previousTarget
     */
    public function build(
        array $snapshot,
        array $result,
        array $statutorySets,
        array $accounts,
        array $previousTarget = [],
    ): PayrollPostingPreview {
        $this->assertSource($snapshot, $result);
        $accounts = $this->accounts($accounts);
        $snapshotPeople = $this->snapshotPeople($snapshot);
        $resultPeople = $this->resultPeople($result);
        if (array_keys($snapshotPeople) !== array_keys($resultPeople)) {
            throw new \DomainException(
                'Účetní výsledek nepokrývá přesně osoby zmrazené revize.',
            );
        }

        $allocations = [];
        /** @var array<int,list<array{key:string,account_code:string,weight:int}>> $buckets */
        $buckets = [];
        /** @var array<int,int> $cashByEmployee */
        $cashByEmployee = [];
        /** @var array<int,list<string>> $relationTypesByEmployee */
        $relationTypesByEmployee = [];
        /** @var array<int,?string> $costCenterByEmployment */
        $costCenterByEmployment = [];
        /** @var array<int,list<int>> $employmentsByEmployee */
        $employmentsByEmployee = [];
        foreach ($resultPeople as $employeeId => $personResult) {
            $personSnapshot = $snapshotPeople[$employeeId];
            $employmentResults = $this->resultEmployments($personResult);
            if (array_keys($personSnapshot['employments'])
                !== array_keys($employmentResults)
            ) {
                throw new \DomainException(
                    "Výsledek employee:{$employeeId} nepokrývá přesně pracovní vztahy.",
                );
            }
            $buckets[$employeeId] = [];
            $cashByEmployee[$employeeId] = 0;
            $relationTypesByEmployee[$employeeId] = [];
            foreach ($employmentResults as $employmentId => $employmentResult) {
                $employmentSnapshot =
                    $personSnapshot['employments'][$employmentId];
                $relationType = $this->requiredString(
                    $employmentSnapshot['employment'],
                    'relation_type',
                );
                $relationTypesByEmployee[$employeeId][] = $relationType;
                $relationAccounts = ($this->classifier)(
                    $relationType,
                    $accounts,
                );
                $dimensionDebit = $this->dimensionAccounts->resolve($employmentSnapshot);
                $costCenter = $this->dimensionCostCenter($employmentSnapshot);
                $costCenterByEmployment[$employmentId] = $costCenter;
                $employmentsByEmployee[$employeeId][] = $employmentId;
                $inputResults = $this->resultInputs($employmentResult);
                if (array_keys($employmentSnapshot['inputs'])
                    !== array_keys($inputResults)
                ) {
                    throw new \DomainException(
                        "Výsledek employment:{$employmentId} nepokrývá přesně mzdové vstupy.",
                    );
                }
                $employmentCash = 0;
                foreach ($inputResults as $inputId => $inputResult) {
                    $inputSnapshot = $employmentSnapshot['inputs'][$inputId];
                    $totals = $this->object(
                        $inputResult['totals'] ?? null,
                        'input.totals',
                    );
                    $sourceMinor = $this->integer(
                        $totals,
                        'source_amount_minor',
                    );
                    $cashMinor = $this->integer(
                        $totals,
                        'cash_payable_minor',
                    );
                    $accounting = $this->object(
                        $inputResult['accounting'] ?? null,
                        'input.accounting',
                    );
                    if ($this->integer($accounting, 'amount_minor') !== $sourceMinor) {
                        throw new \DomainException(
                            "Účetní částka input:{$inputId} nesouhlasí s výpočtem.",
                        );
                    }
                    $component = $this->object(
                        $inputSnapshot['component'] ?? null,
                        'input.component',
                    );
                    $snapshotDebit = $this->nullableAccount(
                        $component['accounting_debit_code'] ?? null,
                        'component.accounting_debit_code',
                    );
                    $snapshotCredit = $this->nullableAccount(
                        $component['accounting_credit_code'] ?? null,
                        'component.accounting_credit_code',
                    );
                    if ($snapshotDebit !== null) {
                        PayrollPostingAccountPolicy::assertGrossCostAccountIsUnambiguous(
                            $snapshotDebit,
                        );
                    }
                    $debit = $this->nullableAccount(
                        $accounting['debit_code'] ?? null,
                        'accounting.debit_code',
                    );
                    $credit = $this->nullableAccount(
                        $accounting['credit_code'] ?? null,
                        'accounting.credit_code',
                    );
                    if ($debit !== $snapshotDebit || $credit !== $snapshotCredit) {
                        throw new \DomainException(
                            "Účetní mapování input:{$inputId} neodpovídá zmrazené složce.",
                        );
                    }
                    if (($debit === null) !== ($credit === null)) {
                        throw new \DomainException(
                            "Mzdová složka input:{$inputId} musí mít oba účty nebo žádný.",
                        );
                    }

                    $baseKey = "gross:employment:{$employmentId}:input:{$inputId}";
                    $description = $this->grossDescription($relationType);
                    if ($debit !== null && $credit !== null) {
                        $this->addPair(
                            $allocations,
                            $baseKey,
                            $debit,
                            $credit,
                            $sourceMinor,
                            $description,
                            $costCenter,
                        );
                    } else {
                        if ($sourceMinor !== $cashMinor) {
                            throw new \DomainException(
                                "Mzdová složka input:{$inputId} má nepeněžní část "
                                . 'bez explicitní účetní předkontace.',
                            );
                        }
                        // Výchozí účet dimenze přebíjí VÝCHOZÍ předkontaci
                        // zaměstnavatele, ne explicitní předkontaci složky —
                        // ta je řešená větví výš a sem se nedostane.
                        $debit = $dimensionDebit ?? $relationAccounts['gross_debit'];
                        $credit = $relationAccounts['gross_credit'];
                        $this->addPair(
                            $allocations,
                            $baseKey,
                            $debit,
                            $credit,
                            $cashMinor,
                            $description,
                            $costCenter,
                        );
                    }
                    if ($cashMinor > 0) {
                        $buckets[$employeeId][] = [
                            'key' => "employment:{$employmentId}:input:{$inputId}",
                            'account_code' => $credit,
                            'weight' => $cashMinor,
                        ];
                    }
                    $employmentCash = $this->add(
                        $employmentCash,
                        $cashMinor,
                    );
                }
                $employmentTotals = $this->object(
                    $employmentResult['totals'] ?? null,
                    'employment.totals',
                );
                if ($employmentCash
                    !== $this->integer(
                        $employmentTotals,
                        'cash_payable_minor',
                    )
                ) {
                    throw new \DomainException(
                        "Peněžní příjem employment:{$employmentId} nesouhlasí se vstupy.",
                    );
                }
                $cashByEmployee[$employeeId] = $this->add(
                    $cashByEmployee[$employeeId],
                    $employmentCash,
                );
            }
            $personTotals = $this->object(
                $personResult['totals'] ?? null,
                'person.totals',
            );
            if ($cashByEmployee[$employeeId]
                !== $this->integer($personTotals, 'cash_payable_minor')
            ) {
                throw new \DomainException(
                    "Peněžní příjem employee:{$employeeId} nesouhlasí se vztahy.",
                );
            }
        }

        $sets = $this->sets($statutorySets, array_keys($snapshotPeople));
        $socialEmployer = $this->nonNegativeInt(
            $sets['social_insurance']['root'],
            'employer_contribution_minor_units',
        );
        $healthEmployer = $this->nonNegativeInt(
            $sets['health_insurance']['root'],
            'employer_contribution_minor_units',
        );
        $splitEmployerInsurance = array_filter(
            $costCenterByEmployment,
            static fn (?string $code): bool => $code !== null,
        ) !== [];
        $this->addEmployerInsurance(
            $allocations,
            'employer-insurance:social',
            $accounts['employer_insurance_debit'],
            $accounts['social_insurance_credit'],
            $socialEmployer,
            'Sociální pojištění hrazené zaměstnavatelem',
            $splitEmployerInsurance
                ? PayrollEmployerInsuranceCostAllocation::social(
                    $sets['social_insurance']['root'],
                    $this->flattenRelationships(
                        $sets['social_insurance']['relationships'],
                    ),
                    $socialEmployer,
                )
                : null,
            $costCenterByEmployment,
        );
        $this->addEmployerInsurance(
            $allocations,
            'employer-insurance:health',
            $accounts['employer_insurance_debit'],
            $accounts['health_insurance_credit'],
            $healthEmployer,
            'Zdravotní pojištění hrazené zaměstnavatelem',
            $splitEmployerInsurance
                ? PayrollEmployerInsuranceCostAllocation::health(
                    $this->healthEmployerPersonTotals($sets['health_insurance']),
                    $this->healthEmployerWeights($sets['health_insurance']),
                    $healthEmployer,
                )
                : null,
            $costCenterByEmployment,
        );

        foreach ($resultPeople as $employeeId => $personResult) {
            $social = $sets['social_insurance']['people'][$employeeId];
            $health = $sets['health_insurance']['people'][$employeeId];
            $tax = $sets['income_tax']['people'][$employeeId];
            $net = $sets['net_pay']['people'][$employeeId];
            $employeeSocial = $this->nonNegativeInt(
                $social,
                'employee_contribution_minor_units',
            );
            $employeeHealth = $this->nonNegativeInt(
                $health,
                'employee_contribution_minor_units',
            );
            $advance = $this->object(
                $tax['advance_tax'] ?? null,
                'income_tax.advance_tax',
            );
            $advanceTax = $this->nonNegativeInt(
                $advance,
                'tax_after_credits_minor_units',
            );
            $taxBonus = $this->nonNegativeInt(
                $advance,
                'tax_bonus_minor_units',
            );
            $withholdingTax = $this->nonNegativeInt(
                $tax,
                'withholding_tax_minor_units',
            );
            $deducted = $this->nonNegativeInt(
                $net,
                'deducted_minor_units',
            );
            $annualSettlement = $this->nonNegativeInt(
                $net + ['annual_settlement_minor_units' => 0],
                'annual_settlement_minor_units',
            );
            $netPayable = $this->nonNegativeInt(
                $net,
                'net_payable_minor_units',
            );
            $deductions = $this->rows(
                $net['deductions'] ?? null,
                'net_pay.deductions',
            );
            $deductionSum = 0;
            foreach ($deductions as $deduction) {
                $applied = $this->nonNegativeInt(
                    $deduction,
                    'applied_minor_units',
                );
                $deductionSum = $this->add($deductionSum, $applied);
                $reference = $this->requiredString(
                    $deduction,
                    'deduction_reference',
                );
                $this->addEmployeeCharge(
                    $allocations,
                    $buckets[$employeeId],
                    $applied,
                    $accounts['other_deductions_credit'],
                    "employee:{$employeeId}:deduction:"
                        . hash('sha256', $reference),
                    'Ostatní srážky ze mzdy',
                );
            }
            if ($deductionSum !== $deducted) {
                throw new \DomainException(
                    "Srážky employee:{$employeeId} nesouhlasí s čistou mzdou.",
                );
            }
            $enforcement = $this->object(
                $personResult['enforcement'] ?? null,
                'person.enforcement',
            );
            $enforcementResult = $this->object(
                $enforcement['result'] ?? null,
                'person.enforcement.result',
            );
            if (($enforcementResult['status'] ?? null) !== 'supported') {
                throw new \DomainException(
                    "Exekuční výsledek employee:{$employeeId} není schválený.",
                );
            }
            $enforcementWithheld = $this->nonNegativeInt(
                $enforcementResult,
                'total_withheld_minor_units',
            );

            $this->addEmployeeCharge(
                $allocations,
                $buckets[$employeeId],
                $employeeSocial,
                $accounts['social_insurance_credit'],
                "employee:{$employeeId}:social-insurance",
                'Sociální pojištění zaměstnance',
            );
            $this->addEmployeeCharge(
                $allocations,
                $buckets[$employeeId],
                $employeeHealth,
                $accounts['health_insurance_credit'],
                "employee:{$employeeId}:health-insurance",
                'Zdravotní pojištění zaměstnance',
            );
            $this->addEmployeeCharge(
                $allocations,
                $buckets[$employeeId],
                $advanceTax,
                $accounts['income_tax_credit'],
                "employee:{$employeeId}:advance-tax",
                'Zálohová daň ze závislé činnosti',
            );
            $this->addEmployeeCharge(
                $allocations,
                $buckets[$employeeId],
                $withholdingTax,
                $accounts['income_tax_credit'],
                "employee:{$employeeId}:withholding-tax",
                'Srážková daň ze závislé činnosti',
            );
            $this->addEmployeeBonus(
                $allocations,
                $buckets[$employeeId],
                $taxBonus,
                $accounts['income_tax_credit'],
                "employee:{$employeeId}:tax-bonus",
            );
            // § 38ch odst. 5 a § 35d odst. 9: vyplacený doplatek ze zúčtování
            // je vrácená záloha na daň, takže snižuje závazek vůči správci
            // daně stejně jako měsíční bonus. Nákladem není.
            $this->addEmployeeBonus(
                $allocations,
                $buckets[$employeeId],
                $annualSettlement,
                $accounts['income_tax_credit'],
                "employee:{$employeeId}:annual-settlement",
                'Doplatek z ročního zúčtování záloh',
            );
            $this->addEmployeeCharge(
                $allocations,
                $buckets[$employeeId],
                $enforcementWithheld,
                $accounts['other_deductions_credit'],
                "employee:{$employeeId}:enforcement",
                'Exekuční a insolvenční srážky',
            );

            $expectedNet = $cashByEmployee[$employeeId];
            foreach ([
                $employeeSocial,
                $employeeHealth,
                $advanceTax,
                $withholdingTax,
                $deducted,
            ] as $charge) {
                $expectedNet = $this->subtract($expectedNet, $charge);
            }
            $expectedNet = $this->add($expectedNet, $taxBonus);
            $expectedNet = $this->add($expectedNet, $annualSettlement);
            if ($expectedNet !== $netPayable) {
                throw new \DomainException(
                    "Účetní předpis employee:{$employeeId} nesouhlasí s čistou mzdou.",
                );
            }
            $expectedAfterEnforcement = $this->subtract(
                $netPayable,
                $enforcementWithheld,
            );
            $payableAfterEnforcement = $this->nonNegativeInt(
                $personResult,
                'payable_after_enforcement_minor',
            );
            if ($expectedAfterEnforcement !== $payableAfterEnforcement) {
                throw new \DomainException(
                    "Účetní předpis employee:{$employeeId} nesouhlasí s čistou výplatou po srážkách.",
                );
            }

            foreach ($this->partnerSettlements(
                $employeeId,
                $snapshotPeople[$employeeId]['payout_rules'],
                $relationTypesByEmployee[$employeeId],
                $payableAfterEnforcement,
            ) as $settlement) {
                $this->addEmployeeCharge(
                    $allocations,
                    $buckets[$employeeId],
                    $settlement['amount_minor'],
                    $settlement['account_code'],
                    "employee:{$employeeId}:partner-settlement:"
                        . hash('sha256', $settlement['allocation_reference']),
                    'Zápočet čisté mzdy na účet společníka',
                );
            }
        }

        $this->assertBalancedAllocations($allocations, 'cílový účetní stav');
        $lines = $this->deltaLines($allocations, $previousTarget);
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            if ($line['side'] === 'debit') {
                $debit = $this->add($debit, $line['amount_minor']);
            } else {
                $credit = $this->add($credit, $line['amount_minor']);
            }
        }
        if ($debit !== $credit) {
            throw new \LogicException('Rozdílový účetní zápis není vyrovnaný.');
        }

        return new PayrollPostingPreview(
            $allocations,
            $lines,
            hash('sha256', CanonicalJson::encode([
                'allocations' => $allocations,
            ])),
            hash('sha256', CanonicalJson::encode(['lines' => $lines])),
            $debit,
            $credit,
        );
    }

    /**
     * Zápočet čisté mzdy na účet společníka: relační závazkový účet mzdy
     * (*_gross_credit, tedy 331 u zaměstnance nebo 366 u společníka) MD proti
     * účtu zápočtu (365.x) D.
     *
     * Proč je tenhle způsob výplaty jiný než hotovost a banka: nevyplácí se.
     * Nevzniká platba, platební příkaz ani pokladní doklad — je to čistě účetní
     * překlasifikace závazku. Účetní zápis proto vzniká TADY, kdežto závazek
     * čisté mzdy a řádek platební dávky vzniknout NESMÍ (viz
     * PayrollNetWageLiabilityMaterializer). Kdyby vznikly, firma by vyplatila
     * peníze, které jsou už vypořádané.
     *
     * Rozdělení na jednotlivé závazkové účty vezme stejný poměrový mechanismus
     * jako srážky (addEmployeeCharge → allocate), takže při více pracovních
     * vztazích se zápočet rozpustí podle jejich peněžního podílu a zápis zůstává
     * vyrovnaný — kontroluje assertBalancedAllocations.
     *
     * @param list<string> $relationTypes
     * @return list<array{
     *   allocation_reference:string,
     *   account_code:string,
     *   amount_minor:int
     * }>
     */
    private function partnerSettlements(
        int $employeeId,
        mixed $payoutRules,
        array $relationTypes,
        int $payableAfterEnforcement,
    ): array {
        if (!is_array($payoutRules) || !array_is_list($payoutRules)) {
            return [];
        }
        $hasSettlement = false;
        foreach ($payoutRules as $rule) {
            if (is_array($rule)
                && ($rule['destination_kind'] ?? null) === PayrollPartnerSettlement::KIND
            ) {
                $hasSettlement = true;
                break;
            }
        }
        if (!$hasSettlement) {
            // Bez zápočtu se výplatní pravidla vůbec nerozpočítávají. Účetní
            // zápis tak zůstává pro všechny dosavadní i budoucí revize bez
            // zápočtu byte-identický — zmrazené snapshoty bez klíče payout_rules
            // nevyjímaje.
            return [];
        }
        PayrollPartnerSettlement::assertEligible($relationTypes, $employeeId);

        $result = [];
        foreach ($this->payoutAllocations->allocate(
            $payableAfterEnforcement,
            $this->payoutAllocationRequests($payoutRules),
        )->allocations as $allocation) {
            if ($allocation->destinationKind !== PayrollPartnerSettlement::KIND
                || $allocation->amountMinorUnits === 0
            ) {
                continue;
            }
            $result[] = [
                'allocation_reference' => $allocation->allocationReference,
                'account_code' => $this->account(
                    $allocation->destinationReference,
                    'cíl zápočtu na účet společníka',
                ),
                'amount_minor' => $allocation->amountMinorUnits,
            ];
        }

        return $result;
    }

    /**
     * @param list<mixed> $payoutRules
     * @return list<PayoutAllocationRequest>
     */
    private function payoutAllocationRequests(array $payoutRules): array
    {
        $requests = [];
        foreach ($payoutRules as $rule) {
            $rule = $this->object($rule, 'snapshot.payout_rule');
            $reference = $this->requiredString($rule, 'allocation_reference');
            $destinationKind = $this->requiredString($rule, 'destination_kind');
            $destinationReference = $rule['destination_reference'] ?? null;
            if ($destinationReference !== null && !is_string($destinationReference)) {
                throw new \DomainException(
                    'Reference platebního cíle není text.',
                );
            }
            $priority = $this->nonNegativeInt($rule, 'priority_no');
            $requests[] = match ($this->requiredString($rule, 'allocation_kind')) {
                'fixed' => PayoutAllocationRequest::fixed(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    $this->nonNegativeInt($rule, 'amount_minor'),
                    $priority,
                ),
                'percentage' => PayoutAllocationRequest::percentage(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    $this->nonNegativeInt($rule, 'basis_points'),
                    $priority,
                ),
                'remainder' => PayoutAllocationRequest::remainder(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    $priority,
                ),
                default => throw new \DomainException(
                    'Zmrazené výplatní pravidlo má nepodporovaný typ alokace.',
                ),
            };
        }

        return $requests;
    }

    /**
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $allocations
     */
    private function addPair(
        array &$allocations,
        string $baseKey,
        string $debit,
        string $credit,
        int $amount,
        string $description,
        ?string $costCenter = null,
    ): void {
        if ($amount === 0) {
            return;
        }
        $this->addAllocation(
            $allocations,
            "{$baseKey}:debit",
            $debit,
            $amount,
            $description,
            $costCenter,
        );
        $this->addAllocation(
            $allocations,
            "{$baseKey}:credit",
            $credit,
            -$amount,
            $description,
        );
    }

    /**
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $allocations
     */
    private function addAllocation(
        array &$allocations,
        string $key,
        string $account,
        int $signedMinor,
        string $description,
        ?string $costCenter = null,
    ): void {
        if ($signedMinor === 0) {
            return;
        }
        $allocation = [
            'allocation_key' => $key,
            'account_code' => $this->account($account, $key),
            'signed_minor' => $signedMinor,
            'description' => $description,
        ];
        // Klíč se přidává jen tam, kde středisko opravdu je. Alokace revizí bez
        // dimenzí tak zůstávají BYTE-IDENTICKÉ včetně `target_hash`, takže se
        // zaúčtované revize nezačnou hlásit jiným cílovým otiskem.
        if ($costCenter !== null) {
            $allocation['cost_center'] = $costCenter;
        }
        $allocations[] = $allocation;
    }

    /**
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $allocations
     * @param list<array{key:string,account_code:string,weight:int}> $buckets
     */
    private function addEmployeeCharge(
        array &$allocations,
        array $buckets,
        int $amount,
        string $liabilityAccount,
        string $baseKey,
        string $description,
    ): void {
        if ($amount === 0) {
            return;
        }
        foreach ($this->allocate($amount, $buckets) as $allocation) {
            $this->addAllocation(
                $allocations,
                "{$baseKey}:settlement:{$allocation['key']}",
                $allocation['account_code'],
                $allocation['amount'],
                $description,
            );
        }
        $this->addAllocation(
            $allocations,
            "{$baseKey}:liability",
            $liabilityAccount,
            -$amount,
            $description,
        );
    }

    /**
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $allocations
     * @param list<array{key:string,account_code:string,weight:int}> $buckets
     */
    private function addEmployeeBonus(
        array &$allocations,
        array $buckets,
        int $amount,
        string $taxAccount,
        string $baseKey,
        string $description = 'Daňový bonus zaměstnance',
    ): void {
        if ($amount === 0) {
            return;
        }
        $this->addAllocation(
            $allocations,
            "{$baseKey}:tax",
            $taxAccount,
            $amount,
            $description,
        );
        foreach ($this->allocate($amount, $buckets) as $allocation) {
            $this->addAllocation(
                $allocations,
                "{$baseKey}:settlement:{$allocation['key']}",
                $allocation['account_code'],
                -$allocation['amount'],
                $description,
            );
        }
    }

    /**
     * @param list<array{key:string,account_code:string,weight:int}> $buckets
     * @return list<array{key:string,account_code:string,amount:int}>
     */
    private function allocate(int $amount, array $buckets): array
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Rozdělovaná částka nesmí být záporná.');
        }
        if ($amount === 0) {
            return [];
        }
        usort(
            $buckets,
            static fn (array $left, array $right): int =>
                $left['key'] <=> $right['key'],
        );
        $totalWeight = 0;
        foreach ($buckets as $bucket) {
            if ($bucket['weight'] <= 0) {
                throw new \DomainException(
                    'Závazkový účet nemá kladnou peněžní váhu.',
                );
            }
            $totalWeight = $this->add($totalWeight, $bucket['weight']);
        }
        if ($totalWeight === 0) {
            throw new \DomainException(
                'Srážku nelze přiřadit bez peněžního závazku.',
            );
        }

        $allocated = 0;
        $shares = [];
        foreach ($buckets as $index => $bucket) {
            $product = $this->multiply($amount, $bucket['weight']);
            $share = intdiv($product, $totalWeight);
            $shares[$index] = [
                ...$bucket,
                'amount' => $share,
                'remainder' => $product % $totalWeight,
            ];
            $allocated = $this->add($allocated, $share);
        }
        $remainder = $amount - $allocated;
        $order = array_keys($shares);
        usort($order, static function (int $left, int $right) use ($shares): int {
            $byRemainder = $shares[$right]['remainder']
                <=> $shares[$left]['remainder'];
            return $byRemainder !== 0
                ? $byRemainder
                : $shares[$left]['key'] <=> $shares[$right]['key'];
        });
        for ($i = 0; $i < $remainder; $i++) {
            $index = $order[$i];
            $share = $shares[$index];
            $shares[$index] = [
                'key' => $share['key'],
                'account_code' => $share['account_code'],
                'weight' => $share['weight'],
                'amount' => $this->add($share['amount'], 1),
                'remainder' => $share['remainder'],
            ];
        }
        ksort($shares, SORT_NUMERIC);

        return array_values(array_map(
            static fn (array $share): array => [
                'key' => $share['key'],
                'account_code' => $share['account_code'],
                'amount' => $share['amount'],
            ],
            $shares,
        ));
    }

    /**
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $target
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $previous
     * @return list<array{
     *   account_code:string,
     *   side:'debit'|'credit',
     *   amount_minor:int,
     *   description:string,
     *   cost_center?:string
     * }>
     */
    private function deltaLines(array $target, array $previous): array
    {
        $current = $this->allocationVector($target, 'cílové alokace');
        $before = $this->allocationVector($previous, 'předchozí alokace');
        $keys = array_values(array_unique([
            ...array_keys($current),
            ...array_keys($before),
        ]));
        sort($keys, SORT_STRING);
        /** @var array<string,array{account_code:string,side:'debit'|'credit',amount_minor:int,description:string,cost_center?:string}> $grouped */
        $grouped = [];
        foreach ($keys as $key) {
            $new = $current[$key]['signed_minor'] ?? 0;
            $old = $before[$key]['signed_minor'] ?? 0;
            $delta = $this->subtract($new, $old);
            if ($delta === 0) {
                continue;
            }
            $source = $current[$key] ?? $before[$key];
            $side = $delta > 0 ? 'debit' : 'credit';
            $costCenter = $source['cost_center']
                ?? $this->deductionDimension($source['allocation_key']);
            $group = $source['account_code']
                . "\0" . $side
                . "\0" . ($costCenter ?? '');
            if (!isset($grouped[$group])) {
                $grouped[$group] = [
                    'account_code' => $source['account_code'],
                    'side' => $side,
                    'amount_minor' => 0,
                    'description' => 'Mzdový předpis',
                ];
                if ($costCenter !== null) {
                    $grouped[$group]['cost_center'] = $costCenter;
                }
            }
            $grouped[$group]['amount_minor'] = $this->add(
                $grouped[$group]['amount_minor'],
                $this->absolute($delta),
            );
        }
        ksort($grouped, SORT_STRING);

        return array_values($grouped);
    }

    /**
     * @param array<array-key,mixed> $allocations
     * @return array<string,array{
     *   account_code:string,
     *   signed_minor:int,
     *   description:string,
     *   allocation_key:string,
     *   cost_center:?string
     * }>
     */
    private function allocationVector(array $allocations, string $context): array
    {
        $result = [];
        $seenKeys = [];
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                throw new \InvalidArgumentException(
                    "{$context} nemají platný formát.",
                );
            }
            $key = $allocation['allocation_key'] ?? null;
            $account = $allocation['account_code'] ?? null;
            $signed = $allocation['signed_minor'] ?? null;
            $description = $allocation['description'] ?? null;
            if (!is_string($key) || $key === ''
                || !is_string($account)
                || !is_int($signed)
                || !is_string($description)
            ) {
                throw new \InvalidArgumentException(
                    "{$context} nemají platný formát.",
                );
            }
            if (isset($seenKeys[$key])) {
                throw new \InvalidArgumentException(
                    "{$context} obsahují duplicitní klíč {$key}.",
                );
            }
            $costCenter = $allocation['cost_center'] ?? null;
            if ($costCenter !== null
                && (!is_string($costCenter) || $costCenter === '')
            ) {
                throw new \InvalidArgumentException(
                    "{$context} nemají platný formát.",
                );
            }
            $seenKeys[$key] = true;
            $vectorKey = $key . "\0" . $account;
            $result[$vectorKey] = [
                'account_code' => $this->account($account, $key),
                'signed_minor' => $signed,
                'description' => $description,
                'allocation_key' => $key,
                'cost_center' => $costCenter,
            ];
        }

        return $result;
    }

    private function deductionDimension(string $allocationKey): ?string
    {
        $prefix = str_contains($allocationKey, ':deduction:')
            ? 'MZ-SR-'
            : (str_contains($allocationKey, ':enforcement:')
                ? 'MZ-EX-'
                : null);
        if ($prefix === null) {
            return null;
        }

        return $prefix . strtoupper(substr(
            hash('sha256', $allocationKey),
            0,
            16,
        ));
    }

    /**
     * @param list<array{signed_minor:int}> $allocations
     */
    private function assertBalancedAllocations(
        array $allocations,
        string $context,
    ): void {
        $balance = 0;
        foreach ($allocations as $allocation) {
            $balance = $this->add($balance, $allocation['signed_minor']);
        }
        if ($balance !== 0) {
            throw new \LogicException("{$context} není vyrovnaný.");
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     */
    private function assertSource(array $snapshot, array $result): void
    {
        if (($snapshot['schema_version'] ?? null) !== 'payroll-run-input.v2') {
            throw new \DomainException('Účetní můstek nepodporuje vstupní snapshot.');
        }
        if (($result['schema_version'] ?? null) !== 'payroll-run-result.v2') {
            throw new \DomainException('Účetní můstek nepodporuje výsledek revize.');
        }
        $expectedHash = hash('sha256', CanonicalJson::encode($snapshot));
        $actualHash = $result['source_snapshot_hash'] ?? null;
        if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
            throw new \DomainException(
                'Výsledek účetního můstku neodpovídá zmrazenému vstupu.',
            );
        }
        $statutory = $this->object(
            $result['statutory'] ?? null,
            'result.statutory',
        );
        if (($statutory['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                'Účetní můstek vyžaduje vypočtené zákonné výsledky.',
            );
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,array{
     *   employments:array<int,array{
     *     employment:array<string,mixed>,
     *     inputs:array<int,array<string,mixed>>
     *   }>,
     *   payout_rules:mixed
     * }>
     */
    private function snapshotPeople(array $snapshot): array
    {
        $result = [];
        foreach ($this->rows($snapshot['people'] ?? null, 'snapshot.people') as $person) {
            $employee = $this->object(
                $person['employee'] ?? null,
                'snapshot.employee',
            );
            $employeeId = $this->positiveInt($employee, 'id');
            if (isset($result[$employeeId])) {
                throw new \DomainException(
                    "Snapshot obsahuje employee:{$employeeId} vícekrát.",
                );
            }
            $employments = [];
            foreach ($this->rows(
                $person['employments'] ?? null,
                'snapshot.employments',
            ) as $employment) {
                $identity = $this->object(
                    $employment['employment'] ?? null,
                    'snapshot.employment',
                );
                $employmentId = $this->positiveInt($identity, 'id');
                if (isset($employments[$employmentId])) {
                    throw new \DomainException(
                        "Snapshot obsahuje employment:{$employmentId} vícekrát.",
                    );
                }
                if (($identity['employee_id'] ?? null) !== $employeeId) {
                    throw new \DomainException(
                        "Vztah employment:{$employmentId} patří jiné osobě.",
                    );
                }
                $inputs = [];
                foreach ($this->rows(
                    $employment['inputs'] ?? null,
                    'snapshot.inputs',
                ) as $input) {
                    $inputId = $this->positiveInt($input, 'id');
                    if (isset($inputs[$inputId])) {
                        throw new \DomainException(
                            "Snapshot obsahuje input:{$inputId} vícekrát.",
                        );
                    }
                    $inputs[$inputId] = $input;
                }
                ksort($inputs, SORT_NUMERIC);
                $employments[$employmentId] = [
                    'employment' => $identity,
                    'inputs' => $inputs,
                    // Starší revize klíč nemají; prázdný seznam znamená totéž co
                    // dřív — účtuje se výchozí předkontací zaměstnavatele.
                    'dimensions' => $employment['dimensions'] ?? [],
                ];
            }
            ksort($employments, SORT_NUMERIC);
            $result[$employeeId] = [
                'employments' => $employments,
                // Zmrazená výplatní pravidla nese jen novější snapshot; starší
                // revize klíč nemají a zápočet se u nich prostě neúčtuje.
                'payout_rules' => $person['payout_rules'] ?? null,
            ];
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,array<string,mixed>>
     */
    private function resultPeople(array $result): array
    {
        $people = [];
        foreach ($this->rows($result['people'] ?? null, 'result.people') as $person) {
            $employeeId = $this->positiveInt($person, 'employee_id');
            if (isset($people[$employeeId])) {
                throw new \DomainException(
                    "Výsledek obsahuje employee:{$employeeId} vícekrát.",
                );
            }
            $people[$employeeId] = $person;
        }
        ksort($people, SORT_NUMERIC);

        return $people;
    }

    /**
     * @param array<string,mixed> $person
     * @return array<int,array<string,mixed>>
     */
    private function resultEmployments(array $person): array
    {
        $result = [];
        foreach ($this->rows(
            $person['employments'] ?? null,
            'result.employments',
        ) as $employment) {
            $id = $this->positiveInt($employment, 'employment_id');
            if (isset($result[$id])) {
                throw new \DomainException(
                    "Výsledek obsahuje employment:{$id} vícekrát.",
                );
            }
            $result[$id] = $employment;
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param array<string,mixed> $employment
     * @return array<int,array<string,mixed>>
     */
    private function resultInputs(array $employment): array
    {
        $result = [];
        foreach ($this->rows(
            $employment['inputs'] ?? null,
            'result.inputs',
        ) as $input) {
            $id = $this->positiveInt($input, 'input_id');
            if (isset($result[$id])) {
                throw new \DomainException(
                    "Výsledek obsahuje input:{$id} vícekrát.",
                );
            }
            $result[$id] = $input;
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param array<string,mixed> $sets
     * @param list<int> $employeeIds
     * @return array<string,array{
     *   root:array<string,mixed>,
     *   people:array<int,array<string,mixed>>,
     *   relationships:array<int,array<int,array<string,mixed>>>
     * }>
     */
    private function sets(array $sets, array $employeeIds): array
    {
        $result = [];
        foreach ([
            'social_insurance',
            'health_insurance',
            'income_tax',
            'net_pay',
        ] as $kind) {
            $set = $sets[$kind] ?? null;
            if (!is_array($set) || array_is_list($set)) {
                throw new \DomainException(
                    "Chybí zákonný výsledek {$kind}.",
                );
            }
            if (($set['result_status'] ?? null) !== 'calculated') {
                throw new \DomainException(
                    "Zákonný výsledek {$kind} není vypočtený.",
                );
            }
            $root = $this->object(
                $set['result_snapshot'] ?? null,
                "{$kind}.result_snapshot",
            );
            $people = [];
            $relationships = [];
            foreach ($this->rows($set['people'] ?? null, "{$kind}.people") as $person) {
                $employeeId = $this->positiveInt($person, 'employee_id');
                if (isset($people[$employeeId])) {
                    throw new \DomainException(
                        "Výsledek {$kind} obsahuje employee:{$employeeId} vícekrát.",
                    );
                }
                if (($person['result_status'] ?? null) !== 'calculated') {
                    throw new \DomainException(
                        "Výsledek {$kind} employee:{$employeeId} není vypočtený.",
                    );
                }
                $people[$employeeId] = $this->object(
                    $person['result_snapshot'] ?? null,
                    "{$kind}.person.result_snapshot",
                );
                $relationships[$employeeId] = $this->setRelationships(
                    $person,
                    $kind,
                    $employeeId,
                );
            }
            ksort($people, SORT_NUMERIC);
            ksort($relationships, SORT_NUMERIC);
            if (array_keys($people) !== $employeeIds) {
                throw new \DomainException(
                    "Výsledek {$kind} nepokrývá přesně osoby revize.",
                );
            }
            $result[$kind] = [
                'root' => $root,
                'people' => $people,
                'relationships' => $relationships,
            ];
        }

        return $result;
    }

    /**
     * Výsledky jednotlivých vztahů osoby, klíčované pracovním vztahem.
     *
     * Revize zmrazené dřív, než se výsledky vztahů ukládaly, klíč nemají —
     * prázdné pole znamená „rozpad na vztahy není doložený" a rozdělení
     * zaměstnavatelského pojistného na střediska se pro ně neudělá.
     *
     * @param array<string,mixed> $person
     * @return array<int,array<string,mixed>>
     */
    private function setRelationships(array $person, string $kind, int $employeeId): array
    {
        $rows = $person['relationships'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return [];
        }
        $result = [];
        foreach ($this->rows($rows, "{$kind}.relationships") as $relationship) {
            $employmentId = $this->positiveInt($relationship, 'employment_id');
            if (isset($result[$employmentId])) {
                throw new \DomainException(
                    "Výsledek {$kind} obsahuje employment:{$employmentId} vícekrát.",
                );
            }
            if (($relationship['result_status'] ?? null) !== 'calculated') {
                return [];
            }
            $result[$employmentId] = $this->object(
                $relationship['result_snapshot'] ?? null,
                "{$kind}.relationship.result_snapshot",
            );
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $relationships
     * @return array<int,array<string,mixed>> employment_id → výsledek vztahu
     */
    private function flattenRelationships(array $relationships): array
    {
        $result = [];
        foreach ($relationships as $perEmployee) {
            if ($perEmployee === []) {
                // Chybí-li rozpad u jediné osoby, nelze rozdělit firemní částku
                // na korunu — rozpad se pak neudělá vůbec.
                return [];
            }
            foreach ($perEmployee as $employmentId => $relationship) {
                if (isset($result[$employmentId])) {
                    return [];
                }
                $result[$employmentId] = $relationship;
            }
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param array{people:array<int,array<string,mixed>>} $set
     * @return array<int,int> employee_id → pojistné zaměstnavatele osoby
     */
    private function healthEmployerPersonTotals(array $set): array
    {
        $totals = [];
        foreach ($set['people'] as $employeeId => $person) {
            $value = $person['employer_contribution_minor_units'] ?? null;
            if (!is_int($value) || $value < 0) {
                return [];
            }
            $totals[$employeeId] = $value;
        }

        return $totals;
    }

    /**
     * @param array{relationships:array<int,array<int,array<string,mixed>>>} $set
     * @return array<int,array<int,int>> employee_id → employment_id → základ
     */
    private function healthEmployerWeights(array $set): array
    {
        $weights = [];
        foreach ($set['relationships'] as $employeeId => $perEmployee) {
            $weights[$employeeId] = [];
            foreach ($perEmployee as $employmentId => $relationship) {
                $base = $relationship['participating_assessment_base_minor_units']
                    ?? null;
                if (!is_int($base) || $base < 0) {
                    return [];
                }
                $weights[$employeeId][$employmentId] = $base;
            }
        }

        return $weights;
    }

    /**
     * Zaměstnavatelské pojistné zaúčtované po nákladových střediscích.
     *
     * Závazek (336) zůstává JEDNOU částkou — dluží se jako celek a rozdělovat
     * ho podle středisek by z alokace udělalo tvrzení o zákonné částce osoby.
     * Rozděluje se jen NÁKLAD (524), a to na pracovní vztahy, protože středisko
     * visí na vztahu. Součet rozdělených debetů se rovná kreditu na korunu,
     * jinak {@see PayrollEmployerInsuranceCostAllocation} rozdělení odmítne
     * a účtuje se jednou řádkou jako dřív.
     *
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $allocations
     * @param array<int,int>|null $shares employment_id → částka
     * @param array<int,?string> $costCenters employment_id → středisko
     */
    private function addEmployerInsurance(
        array &$allocations,
        string $baseKey,
        string $debitAccount,
        string $creditAccount,
        int $amount,
        string $description,
        ?array $shares,
        array $costCenters,
    ): void {
        if ($amount === 0) {
            return;
        }
        if ($shares === null) {
            $this->addPair(
                $allocations,
                $baseKey,
                $debitAccount,
                $creditAccount,
                $amount,
                $description,
            );
            return;
        }
        foreach ($shares as $employmentId => $share) {
            $this->addAllocation(
                $allocations,
                "{$baseKey}:employment:{$employmentId}:debit",
                $debitAccount,
                $share,
                $description,
                $costCenters[$employmentId] ?? null,
            );
        }
        $this->addAllocation(
            $allocations,
            "{$baseKey}:credit",
            $creditAccount,
            -$amount,
            $description,
        );
    }

    /**
     * @param array<string,string> $accounts
     * @return array<string,string>
     */
    private function accounts(array $accounts): array
    {
        $result = [];
        foreach (PayrollAccountingDefaults::ACCOUNTS as $key => $_definition) {
            $value = $accounts[$key] ?? null;
            if (!is_string($value)) {
                throw new \DomainException(
                    "Chybí firemní účetní předkontace {$key}.",
                );
            }
            $result[$key] = $this->account($value, $key);
        }

        return $result;
    }

    private function grossDescription(string $relationType): string
    {
        return match ($relationType) {
            'employment', 'small_scale_employment', 'dpp', 'dpc' =>
                'Mzda zaměstnance mimo výkon funkce',
            'partner_dependent' => 'Závislý příjem společníka',
            'statutory_body' => 'Odměna za výkon funkce člena orgánu',
            default => throw new \InvalidArgumentException(
                "Neznámý typ pracovního vztahu: {$relationType}.",
            ),
        };
    }

    /**
     * Kód nákladového střediska pracovního vztahu, nebo `null`.
     *
     * Je to jiná věc než {@see PayrollDimensionCostAccountResolver}: ten mění ÚČET,
     * tohle plní analytický sloupec `journal_entry_lines.cost_center`. Středisko
     * bez vlastního účtu tak přestává být neviditelné — dosud se mzda takového
     * střediska zaúčtovala na výchozí 521 bez jakékoli stopy po tom, čí náklad to
     * je, a `04-UCETNI-MUSTEK.md` přitom analytiku podle střediska slibuje.
     *
     * Bere se výhradně dimenze typu `cost_center`. Zakázka ani činnost sem
     * nepatří: sloupec je jeden a nacpat do něj podle nálady jednou zakázku
     * a jindy středisko by z něj udělal nečitelnou směs.
     *
     * @param array<string,mixed> $employmentSnapshot zmrazený pracovní vztah
     */
    private function dimensionCostCenter(array $employmentSnapshot): ?string
    {
        $dimensions = $employmentSnapshot['dimensions'] ?? null;
        if (!is_array($dimensions) || !array_is_list($dimensions)) {
            return null;
        }
        foreach ($dimensions as $index => $dimension) {
            $dimension = $this->object($dimension, "employment.dimensions.{$index}");
            if (($dimension['type'] ?? null) !== 'cost_center') {
                continue;
            }
            $code = $dimension['code'] ?? null;
            if (!is_string($code) || $code === '' || strlen($code) > 50) {
                throw new \DomainException(
                    "Kód střediska employment.dimensions.{$index} není platný.",
                );
            }

            return $code;
        }

        return null;
    }

    private function nullableAccount(mixed $value, string $field): ?string
    {
        return $value === null ? null : $this->account($value, $field);
    }

    private function account(mixed $value, string $field): string
    {
        if (!is_string($value) || !PayrollAccountCode::isValid($value)) {
            throw new \DomainException("Účet {$field} není platný.");
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "{$field} musí mít pouze textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }

        return array_map(
            fn (mixed $row): array => $this->object($row, $field),
            $value,
        );
    }

    /** @param array<string,mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("{$field} musí být text.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException(
                "{$field} musí být celé číslo v haléřích.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nonNegativeInt(array $row, string $field): int
    {
        $value = $this->integer($row, $field);
        if ($value < 0) {
            throw new \UnexpectedValueException(
                "{$field} musí být nezáporné.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $field): int
    {
        $value = $this->integer($row, $field);
        if ($value <= 0) {
            throw new \UnexpectedValueException("{$field} musí být kladné.");
        }

        return $value;
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException('Součet účetních částek přetekl.');
        }

        return $left + $right;
    }

    private function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException('Rozdíl účetních částek přetekl.');
        }

        return $left - $right;
    }

    private function absolute(int $value): int
    {
        if ($value === PHP_INT_MIN) {
            throw new \OverflowException(
                'Absolutní účetní částka přesahuje celočíselný rozsah.',
            );
        }

        return abs($value);
    }

    private function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new \InvalidArgumentException(
                'Váhy účetních alokací nesmí být záporné.',
            );
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new \OverflowException(
                'Poměrná účetní alokace přesahuje celočíselný rozsah.',
            );
        }

        return $left * $right;
    }
}
