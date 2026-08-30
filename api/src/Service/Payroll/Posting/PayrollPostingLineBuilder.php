<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Service\Payroll\Accounting\PayrollAccountCode;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\PayrollEmploymentAccountingClassifier;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollPostingLineBuilder
{
    /**
     * Koše osvobození, na které míří § 25 odst. 1 písm. h) ZDP — tedy plnění
     * podle § 6 odst. 9 písm. d) ZDP. Ostatní koše uznatelné jsou.
     *
     * @var list<string>
     */
    private const NON_DEDUCTIBLE_BENEFIT_BASKETS = [
        'non_cash_health',
        'non_cash_leisure',
    ];

    /** Druh složky, který se účtuje jako cestovné, ne jako mzda. */
    private const TRAVEL_COMPONENT_KIND = 'travel_reimbursement';

    public function __construct(
        private readonly PayrollEmploymentAccountingClassifier $classifier =
            new PayrollEmploymentAccountingClassifier(),
        private readonly PayrollPartnerSettlementResolver $partnerSettlements =
            new PayrollPartnerSettlementResolver(),
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
        // Surová sada se schovává PŘED doplněním výchozích účtů: podle ní se
        // pozná, jestli zmrazený snapshot o novém dělení vůbec ví. Viz
        // PayrollAccountingDefaults::SNAPSHOT_GATED_ACCOUNTS.
        $configuredAccounts = $accounts;
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
        /** @var array<int,string> $liabilityAccountByEmployee */
        $liabilityAccountByEmployee = [];
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
                // Závazkový účet vztahu s NEJNIŽŠÍM employment_id (vztahy jsou
                // seřazené) je náhradní protiúčet osoby pro případ, že nemá
                // žádný peněžní příjem — viz addEmployeeCharge().
                $liabilityAccountByEmployee[$employeeId] ??=
                    $relationAccounts['gross_credit'];
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
                        $this->addGross(
                            $allocations,
                            $baseKey,
                            $debit,
                            $credit,
                            $sourceMinor,
                            $description,
                            $costCenter,
                            $inputSnapshot,
                            $accounts,
                            $configuredAccounts,
                        );
                    } elseif ($this->isAccountingNeutral(
                        $component,
                        $sourceMinor,
                        $cashMinor,
                    )) {
                        // Účetně neutrální nepeněžní plnění — ŽÁDNÝ zápis.
                        //
                        // Nepeněžní složka bez vlastní předkontace je jen
                        // OCENĚNÍ příjmu pro základ daně a pojistného (1 %
                        // vstupní ceny vozidla podle § 6 odst. 6 ZDP, hodnota
                        // přechodného ubytování, …). Skutečný náklad se do knih
                        // dostal už zdrojovým dokladem (faktura za ubytování,
                        // odpis vozidla), takže vynucená dvojice MD 5xx / D 331
                        // by ho zaúčtovala DRUHÝ RÁZ a navíc by trvale
                        // nadhodnotila závazek vůči zaměstnanci, kterému se
                        // nic nevyplácí.
                        //
                        // Kdo chce plnění přeúčtovat (třeba na 528), nastaví
                        // složce oba účty — tu větev řeší podmínka výš. Zápis
                        // „oba účty, nebo žádný" tím zůstává v platnosti,
                        // jen „žádný" konečně znamená „neúčtovat", ne pád.
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
                        //
                        // Cestovní náhrada přebíjí i účet dimenze: účet dimenze
                        // je nákladový účet HRUBÉ MZDY daného střediska (521.100
                        // a podobně) a náhrada výdaje mzdou není. Analytika
                        // střediska se nepotratí — nese ji sloupec
                        // `cost_center`, který se plní nezávisle na účtu.
                        $debit = $this->travelExpenseAccount(
                                $component,
                                $accounts,
                                $configuredAccounts,
                            )
                            ?? $dimensionDebit
                            ?? $relationAccounts['gross_debit'];
                        $credit = $relationAccounts['gross_credit'];
                        $this->addGross(
                            $allocations,
                            $baseKey,
                            $debit,
                            $credit,
                            $cashMinor,
                            $description,
                            $costCenter,
                            $inputSnapshot,
                            $accounts,
                            $configuredAccounts,
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

        $this->addRiskySavings(
            $allocations,
            $result,
            $accounts,
            $costCenterByEmployment,
        );

        foreach ($resultPeople as $employeeId => $personResult) {
            // Osoba bez peněžního příjmu (celý měsíc neplacené volno, jen
            // doplatek ZP do minimálního vyměřovacího základu podle § 3 odst.
            // 10 z. 592/1992 Sb.) nemá do čeho srážku rozpustit. Poměrové
            // rozdělení nemá váhu, ale závazek vůči zaměstnanci existuje —
            // účtuje se proto celý na závazkový účet jejího vztahu
            // (331 u zaměstnance, 366 u společníka a člena orgánu).
            $settlementBuckets = $buckets[$employeeId] !== []
                ? $buckets[$employeeId]
                : [[
                    'key' => 'liability',
                    'account_code' => $liabilityAccountByEmployee[$employeeId]
                        ?? throw new \DomainException(
                            "Osoba employee:{$employeeId} nemá závazkový účet vztahu.",
                        ),
                    'weight' => 1,
                ]];
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
            // ZÁPORNÁ čistá mzda není chybou vstupu, kterou by měl můstek
            // odmítat: měsíc bez peněžního příjmu s doplatkem ZP do
            // minimálního vyměřovacího základu (§ 3 odst. 10 z. 592/1992 Sb.)
            // ji vyrobí zcela legitimně a zaměstnanec pak dluží zaměstnavateli.
            // Hodnota se nekontroluje znaménkem, ale porovnáním s vlastním
            // účetním předpisem níž — to je přísnější než `nonNegativeInt()`.
            $netPayable = $this->integer(
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
                    $settlementBuckets,
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
                $settlementBuckets,
                $employeeSocial,
                $accounts['social_insurance_credit'],
                "employee:{$employeeId}:social-insurance",
                'Sociální pojištění zaměstnance',
            );
            $this->addEmployeeCharge(
                $allocations,
                $settlementBuckets,
                $employeeHealth,
                $accounts['health_insurance_credit'],
                "employee:{$employeeId}:health-insurance",
                'Zdravotní pojištění zaměstnance',
            );
            $this->addEmployeeCharge(
                $allocations,
                $settlementBuckets,
                $advanceTax,
                $accounts['income_tax_credit'],
                "employee:{$employeeId}:advance-tax",
                'Zálohová daň ze závislé činnosti',
            );
            $this->addEmployeeCharge(
                $allocations,
                $settlementBuckets,
                $withholdingTax,
                $this->withholdingTaxAccount($accounts, $configuredAccounts),
                "employee:{$employeeId}:withholding-tax",
                'Srážková daň ze závislé činnosti',
            );
            $this->addEmployeeBonus(
                $allocations,
                $settlementBuckets,
                $taxBonus,
                $accounts['income_tax_credit'],
                "employee:{$employeeId}:tax-bonus",
            );
            // § 38ch odst. 5 a § 35d odst. 9: vyplacený doplatek ze zúčtování
            // je vrácená záloha na daň, takže snižuje závazek vůči správci
            // daně stejně jako měsíční bonus. Nákladem není.
            $this->addEmployeeBonus(
                $allocations,
                $settlementBuckets,
                $annualSettlement,
                $accounts['income_tax_credit'],
                "employee:{$employeeId}:annual-settlement",
                'Doplatek z ročního zúčtování záloh',
            );
            $this->addEmployeeCharge(
                $allocations,
                $settlementBuckets,
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
            $payableAfterEnforcement = $this->integer(
                $personResult,
                'payable_after_enforcement_minor',
            );
            if ($expectedAfterEnforcement !== $payableAfterEnforcement) {
                throw new \DomainException(
                    "Účetní předpis employee:{$employeeId} nesouhlasí s čistou výplatou po srážkách.",
                );
            }

            // Přeplatek: zaměstnanci se nic nevyplácí, naopak dluží
            // zaměstnavateli. Závazkový účet mzdy by zůstal debetní, což je
            // v rozvaze nesmysl — částka se překlopí na pohledávku za
            // zaměstnancem (MD 335 / D 331, resp. 366 u společníka a člena
            // orgánu). Zápis zůstává vyrovnaný a opravná revize ho odúčtuje
            // rozdílem jako každou jinou alokaci.
            if ($payableAfterEnforcement < 0) {
                $overdrawn = $this->absolute($payableAfterEnforcement);
                $this->addPair(
                    $allocations,
                    "employee:{$employeeId}:employee-receivable",
                    $accounts['employee_receivable_debit'],
                    $liabilityAccountByEmployee[$employeeId]
                        ?? throw new \DomainException(
                            "Osoba employee:{$employeeId} nemá závazkový účet vztahu.",
                        ),
                    $overdrawn,
                    'Pohledávka za zaměstnancem z přeplatku čisté mzdy',
                );
            }

            // Zápočet čisté mzdy na účet společníka (331/366 MD / 365 D) počítá
            // sdílený resolver — tutéž částku potřebuje i reconciliace účetního
            // můstku, takže smí existovat jen jednou. Rozdělení na jednotlivé
            // závazkové účty pak vezme stejný poměrový mechanismus jako srážky
            // (addEmployeeCharge → allocate), takže při více pracovních vztazích
            // se zápočet rozpustí podle jejich peněžního podílu a zápis zůstává
            // vyrovnaný — kontroluje assertBalancedAllocations.
            foreach ($this->partnerSettlements->forEmployee(
                $employeeId,
                $snapshotPeople[$employeeId]['payout_rules'],
                $relationTypesByEmployee[$employeeId],
                $payableAfterEnforcement,
            ) as $settlement) {
                $this->addEmployeeCharge(
                    $allocations,
                    $settlementBuckets,
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
     * Zápis hrubé složky — s rozdělením daňově neuznatelné části benefitu.
     *
     * Jediné místo, kde se zápis složky rozpadá na dvě dvojice. Nedělí se
     * PROTIÚČET (ten zůstává jeden, závazek vůči zaměstnanci se nedělí), dělí
     * se jen NÁKLAD. Součet obou dvojic je na haléř roven zdrojové částce,
     * takže zápis zůstává vyrovnaný a kontrolní součty hrubých mezd
     * (`gross_wages` v reconciliaci) sedí dál: obě části mají klíč `gross:…`,
     * takže je `PayrollPostingReconciliationRepository::grossDebitAccounts()`
     * uvidí jako nákladové účty hrubé mzdy.
     *
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $allocations
     * @param array<string,mixed> $inputSnapshot zmrazený mzdový vstup
     * @param array<string,string> $accounts doplněná sada předkontací
     * @param array<string,mixed> $configuredAccounts surová sada ze snapshotu
     */
    private function addGross(
        array &$allocations,
        string $baseKey,
        string $debit,
        string $credit,
        int $amount,
        string $description,
        ?string $costCenter,
        array $inputSnapshot,
        array $accounts,
        array $configuredAccounts,
    ): void {
        $nonDeductible = $this->nonDeductibleBenefitAmount(
            $inputSnapshot,
            $amount,
            $configuredAccounts,
        );
        if ($nonDeductible === null) {
            $this->addPair(
                $allocations,
                $baseKey,
                $debit,
                $credit,
                $amount,
                $description,
                $costCenter,
            );

            return;
        }

        $this->addPair(
            $allocations,
            "{$baseKey}:non-deductible",
            $accounts['non_deductible_benefit_debit'],
            $credit,
            $nonDeductible,
            $description . ' — osvobozená část (§ 25 odst. 1 písm. h) ZDP)',
            $costCenter,
        );
        $this->addPair(
            $allocations,
            "{$baseKey}:taxable",
            $debit,
            $credit,
            $this->subtract($amount, $nonDeductible),
            $description . ' — nadlimitní zdanitelná část',
            $costCenter,
        );
    }

    /**
     * Kolik z benefitu je pro zaměstnavatele daňově NEUZNATELNÝ náklad?
     *
     * `null` = nedělit (zapíše se jedna dvojice přesně jako dosud).
     *
     * ── Proč je nedaňová OSVOBOZENÁ, a ne nadlimitní část ───────────────────
     * § 25 odst. 1 písm. h) ZDP ve znění od 1. 1. 2024 (zákon č. 349/2023 Sb.)
     * vylučuje z nákladů nepeněžní plnění ve formě rekreace, zájezdu, sportu,
     * kultury, tištěných knih, zdravotnických, vzdělávacích a rekreačních
     * zařízení „a to v rozsahu, ve kterém je toto plnění u zaměstnance
     * osvobozeno od daně z příjmů". Rozsah osvobození u zaměstnance a rozsah
     * neuznatelnosti u zaměstnavatele jsou tedy TATÁŽ částka.
     *
     * Nadlimitní část se naopak zaměstnanci zdaní jako příjem ze závislé
     * činnosti a zaměstnavateli je uznatelná podle § 24 odst. 2 písm. j) bodu 4
     * — zůstává proto na dosavadním nákladovém účtu.
     *
     * Dělí se JEN koše § 6 odst. 9 písm. d) ZDP, protože jen na ně § 25 odst. 1
     * písm. h) míří. Příspěvek na stravování (písm. b), na produkty spoření na
     * stáří (písm. m) ani přechodné ubytování (písm. i) uznatelné jsou —
     * § 24 odst. 2 písm. j) — a dělit se nesmí, jinak by se firmě z uznatelného
     * nákladu stal neuznatelný.
     *
     * @param array<string,mixed> $inputSnapshot
     * @param array<string,mixed> $configuredAccounts
     */
    private function nonDeductibleBenefitAmount(
        array $inputSnapshot,
        int $postedAmount,
        array $configuredAccounts,
    ): ?int {
        if (!PayrollAccountingDefaults::snapshotAllowsSplit(
            $configuredAccounts,
            'non_deductible_benefit_debit',
        )) {
            // Snapshot zmrazený dřív, než firma o rozdělení věděla — účtuje se
            // přesně jako dosud, aby zůstal cílový otisk byte-identický.
            return null;
        }
        $basket = $inputSnapshot['benefit_basket'] ?? null;
        if (!in_array($basket, self::NON_DEDUCTIBLE_BENEFIT_BASKETS, true)) {
            return null;
        }
        $exempt = $this->integer($inputSnapshot, 'benefit_exempt_minor');
        $taxable = $this->integer($inputSnapshot, 'benefit_taxable_minor');
        if ($exempt < 0 || $taxable < 0) {
            throw new \DomainException(
                'Rozpad koše osvobození zmrazeného vstupu je záporný.',
            );
        }
        if ($this->add($exempt, $taxable) !== $postedAmount) {
            throw new \DomainException(
                'Rozpad koše osvobození nedává účtovanou částku mzdového vstupu.',
            );
        }

        return $exempt > 0 ? $exempt : null;
    }

    /**
     * Nákladový účet cestovní náhrady, nebo `null`.
     *
     * Cestovní náhrada je náhrada výdaje podle části sedmé zákoníku práce, ne
     * odměna za práci — do mzdových nákladů (521) nepatří ani tehdy, když se
     * vyplácí spolu se mzdou. Seedované složky `CESTOVNI_NAHRADA*` vlastní
     * předkontaci nemají, takže dosud propadly na výchozí účet hrubé mzdy.
     *
     * Platí to i pro NADLIMITNÍ náhradu: ta je sice zdanitelným příjmem
     * zaměstnance a vstupuje do vyměřovacích základů, ale nákladovým druhem
     * zůstává cestovné. Rozlišení daňové uznatelnosti nákladu se u cestovného
     * neřeší účtem (§ 24 odst. 2 písm. zh) ZDP uznává náhrady do zákonné výše
     * i nad ni, jde-li o sjednané právo zaměstnance).
     *
     * @param array<string,mixed> $component zmrazená složka
     * @param array<string,string> $accounts
     * @param array<string,mixed> $configuredAccounts
     */
    private function travelExpenseAccount(
        array $component,
        array $accounts,
        array $configuredAccounts,
    ): ?string {
        if (!PayrollAccountingDefaults::snapshotAllowsSplit(
            $configuredAccounts,
            'travel_expense_debit',
        )) {
            return null;
        }

        return ($component['component_kind'] ?? null) === self::TRAVEL_COMPONENT_KIND
            ? $accounts['travel_expense_debit']
            : null;
    }

    /**
     * Závazkový účet SRÁŽKOVÉ daně (Ú-13).
     *
     * Srážková daň se dosud účtovala na účet zálohové daně a rozlišoval je jen
     * `allocation_key`, který se do `journal_entry_lines` nepromítá. Saldo 342
     * tak neslo obě daně slité dohromady, přestože se odvádějí dvěma platbami
     * (předčíslí 7704 vs. 7720), v jiných termínech a vykazují se jiným
     * hlášením — rozdíl mezi zůstatkem účtu a odvedenými platbami proto nešlo
     * přiřadit k jedné z nich.
     *
     * Bonus ani doplatek z ročního zúčtování sem NEPATŘÍ: § 35d odst. 9
     * a § 38ch odst. 5 je vracejí ze záloh, takže snižují závazek na účtu
     * ZÁLOHOVÉ daně, ne na účtu srážkové.
     *
     * @param array<string,string> $accounts doplněná sada předkontací
     * @param array<string,mixed> $configuredAccounts surová sada ze snapshotu
     */
    private function withholdingTaxAccount(
        array $accounts,
        array $configuredAccounts,
    ): string {
        // Snapshot zmrazený dřív, než firma o rozdělení věděla, se musí
        // zaúčtovat byte-identicky — jinak opakované zaúčtování dřív schválené
        // revize spadne na kontrolu cílového otisku v PayrollPostingAdapter.
        return PayrollAccountingDefaults::snapshotAllowsSplit(
            $configuredAccounts,
            'withholding_tax_credit',
        )
            ? $accounts['withholding_tax_credit']
            : $accounts['income_tax_credit'];
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
            // Do vektoru patří i STŘEDISKO. Opravná revize, která zaměstnance
            // jen přeřadí na jiné středisko, mění `cost_center`, ne částku ani
            // účet — bez střediska v klíči by delta vyšla nulově a v deníku by
            // navždy zůstalo staré středisko. S ním se stará dvojice odúčtuje
            // a nová zaúčtuje, takže analytika sedí i po opravě.
            //
            // `target_hash` se tím NEMĚNÍ: počítá se ze samotných alokací, ne
            // z tohohle vektoru, takže zaúčtované revize dál hlásí týž otisk.
            $vectorKey = $key . "\0" . $account . "\0" . ($costCenter ?? '');
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
     * Povinný příspěvek zaměstnavatele na spoření u rizikové práce
     * (z. č. 324/2025 Sb., § 5 — 4 % vyměřovacího základu od 1. 1. 2026).
     *
     * Zdrojem je zmrazený výsledek revize (`statutory.risky_savings`), ne
     * databáze: je to tentýž hash-ověřený podklad, ze kterého se příspěvek
     * zmrazuje do `payroll_risky_savings_contributions` a materializuje do
     * platebního závazku. Účetní zápis tak nemůže tvrdit jinou částku, než
     * jaká se skutečně platí, a opravná revize se odúčtuje rozdílem stejně
     * jako všechno ostatní.
     *
     * Vědomě to NENÍ pátá zákonná sada: příspěvek není zákonným výsledkem
     * v `payroll_statutory_results` (nemá vlastní `calculation_kind`) a
     * vyrobit ho tam jen kvůli účtování by znamenalo druhý zdroj pravdy.
     *
     * Zaměstnanci se nevyplácí, takže se do žádného poměrového rozdělení
     * srážek nedostane — je to samostatná dvojice 527 MD / 379 D. Náklad
     * nese středisko pracovního vztahu, závazek zůstává jeden.
     *
     * Revize zmrazené dřív klíč nemají a chovají se přesně jako dosud, takže
     * jejich cílový otisk zůstává byte-identický.
     *
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $allocations
     * @param array<string,mixed> $result
     * @param array<string,string> $accounts
     * @param array<int,?string> $costCenters employment_id → středisko
     */
    private function addRiskySavings(
        array &$allocations,
        array $result,
        array $accounts,
        array $costCenters,
    ): void {
        $statutory = $this->object($result['statutory'] ?? null, 'result.statutory');
        $rows = $statutory['risky_savings'] ?? null;
        if ($rows === null) {
            return;
        }
        foreach ($this->rows($rows, 'result.statutory.risky_savings') as $row) {
            $employmentId = $this->positiveInt($row, 'employment_id');
            if (!array_key_exists($employmentId, $costCenters)) {
                throw new \DomainException(
                    "Povinné spoření employment:{$employmentId} nepatří do revize.",
                );
            }
            if (($row['status'] ?? null) !== 'calculated') {
                // Nedopočítaný podklad se do schválené revize nedostane —
                // zastaví ho PayrollRiskySavingsApprover. Tady se jen
                // neúčtuje, aby se z účetního můstku nestala druhá brána
                // schvalování.
                continue;
            }
            $contribution = $this->nonNegativeInt($row, 'contribution_minor');
            $this->addPair(
                $allocations,
                "risky-savings:employment:{$employmentId}",
                $accounts['risky_savings_debit'],
                $accounts['risky_savings_credit'],
                $contribution,
                'Povinný příspěvek na spoření u rizikové práce',
                $costCenters[$employmentId] ?? null,
            );
        }
    }

    /**
     * @param array<string,string> $accounts
     * @return array<string,string>
     */
    private function accounts(array $accounts): array
    {
        $result = [];
        foreach (PayrollAccountingDefaults::ACCOUNTS as $key => $definition) {
            $value = $accounts[$key] ?? null;
            if (!is_string($value)) {
                // Předkontace, které do sady přibyly až po zmrazení starších
                // snapshotů, se doplní ze směrné osnovy — jinak by přidání
                // nové předkontace shodilo opakované zaúčtování dřív
                // schválené revize. Viz PayrollAccountingDefaults::OPTIONAL_ACCOUNTS.
                if (!PayrollAccountingDefaults::isOptional($key)) {
                    throw new \DomainException(
                        "Chybí firemní účetní předkontace {$key}.",
                    );
                }
                $value = $definition['code'];
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

    /**
     * Je zmrazená složka účetně neutrální?
     *
     * Neutralita se NEUKLÁDÁ jako další sloupec — plyne přímo z klasifikace,
     * kterou složka nese od začátku: nepeněžní plnění (`value_kind`
     * = `non_monetary`, tedy `cash_payable_minor` = 0) BEZ vlastní dvojice
     * účtů nemá co zaúčtovat. Účetní má obě volby dál v ruce: chce-li zápis,
     * vyplní složce předkontaci; nechce-li, nechá ji prázdnou.
     *
     * Fail-closed: rozhoduje se podle zmrazené složky I podle spočtených
     * částek. Snapshot, který tvrdí `non_monetary`, ale nese peněžní příjem,
     * neutrální není a propadne do původní větve — jinak by se ztratil
     * závazek vůči zaměstnanci. Starší snapshot bez `value_kind` se chová
     * jako dřív.
     *
     * @param array<string,mixed> $component
     */
    private function isAccountingNeutral(
        array $component,
        int $sourceMinor,
        int $cashMinor,
    ): bool {
        return ($component['value_kind'] ?? null) === 'non_monetary'
            && $cashMinor === 0
            && $sourceMinor >= 0;
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
