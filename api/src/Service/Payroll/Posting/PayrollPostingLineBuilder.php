<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Service\Payroll\Accounting\PayrollAccountCode;
use MyInvoice\Service\Payroll\IncomeTax\SeparateTaxBaseRegime;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\PayrollEmploymentAccountingClassifier;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Travel\BusinessTripMaterializer;

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

    /** Pořadí typů mzdových dimenzí — shodné s {@see PayrollDimensionCostAccountResolver}. */
    private const DIMENSION_PRIORITY = ['cost_center', 'project', 'activity'];

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
        /** @var array<int,list<array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}>> $partsByEmployment */
        $partsByEmployment = [];
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
                $parts = $this->employmentParts($employmentSnapshot);
                $partsByEmployment[$employmentId] = $parts;
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
                        $explicitDebit = $debit;
                        $this->addGross(
                            $allocations,
                            $baseKey,
                            static fn (array $part): string => $explicitDebit,
                            $credit,
                            $sourceMinor,
                            $description,
                            $parts,
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
                        $credit = $relationAccounts['gross_credit'];
                        $advanceAccount = $this->travelAdvanceAccount($component, $accounts);
                        if ($advanceAccount !== null) {
                            // Odpočet zálohy není hrubá mzda ani náklad — klíč
                            // mimo `gross:` ho drží mimo nákladové účty, které
                            // reconciliace čte jako hrubou mzdu.
                            $this->addPair(
                                $allocations,
                                "travel-advance:employment:{$employmentId}:input:{$inputId}",
                                $advanceAccount,
                                $credit,
                                $cashMinor,
                                'Odpočet zálohy na pracovní cestu',
                            );
                        } else {
                            $travelDebit = $this->travelExpenseAccount(
                                $component,
                                $accounts,
                                $configuredAccounts,
                            );
                            $relationDebit = $relationAccounts['gross_debit'];
                            $this->addGross(
                                $allocations,
                                $baseKey,
                                static fn (array $part): string => $travelDebit
                                    ?? $part['account']
                                    ?? $relationDebit,
                                $credit,
                                $cashMinor,
                                $description,
                                $parts,
                                $inputSnapshot,
                                $accounts,
                                $configuredAccounts,
                            );
                        }
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
        // Rozpad 524 na vztahy má smysl jen tam, kde aspoň jeden vztah nese
        // středisko, firemní dimenzi nebo procentní rozpad. Bez toho zůstává
        // jedna firemní dvojice přesně jako dřív.
        $splitEmployerInsurance = array_filter(
            $partsByEmployment,
            fn (array $parts): bool => !$this->isPlainParts($parts),
        ) !== [];
        $this->addEmployerInsurance(
            $allocations,
            'employer-insurance:social',
            $accounts['employer_insurance_debit'],
            $accounts['social_insurance_credit'],
            $socialEmployer,
            'Sociální pojištění hrazené zaměstnavatelem',
            $splitEmployerInsurance
                ? $this->socialEmployerShares($sets, $socialEmployer)
                : null,
            $partsByEmployment,
        );
        $this->addEmployerInsurance(
            $allocations,
            'employer-insurance:health',
            $accounts['employer_insurance_debit'],
            $accounts['health_insurance_credit'],
            $healthEmployer,
            'Zdravotní pojištění hrazené zaměstnavatelem',
            $splitEmployerInsurance
                ? $this->healthEmployerShares($sets, $healthEmployer)
                : null,
            $partsByEmployment,
        );

        $this->addRiskySavings(
            $allocations,
            $result,
            $accounts,
            $configuredAccounts,
            $partsByEmployment,
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
            // Od roku 2027 je daň ze samostatného základu podle § 6 odst. 4
            // ZDP zálohou (§ 38h), odvádí se jedním převodem se zálohami
            // a patří proto na účet ZÁLOHOVÉ daně, ne na analytiku srážkové.
            if (SeparateTaxBaseRegime::fromResultSnapshot($tax)->isAdvance()) {
                $this->addEmployeeCharge(
                    $allocations,
                    $settlementBuckets,
                    $withholdingTax,
                    $accounts['income_tax_credit'],
                    "employee:{$employeeId}:separate-base-advance-tax",
                    'Záloha na daň ze samostatného základu (§ 6 odst. 4 ZDP)',
                );
            } else {
                $this->addEmployeeCharge(
                    $allocations,
                    $settlementBuckets,
                    $withholdingTax,
                    $this->withholdingTaxAccount($accounts, $configuredAccounts),
                    "employee:{$employeeId}:withholding-tax",
                    'Srážková daň ze závislé činnosti',
                );
            }
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
                $this->enforcementAccount($accounts, $configuredAccounts),
                "employee:{$employeeId}:enforcement",
                'Exekuční a insolvenční srážky',
            );
            // Paušální náhrada nákladů plátce mzdy (§ 270 odst. 2 o. s. ř.)
            // je v sražené částce, ale oprávněnému se neposílá — plátce si ji
            // odečte (§ 3 nař. vlády č. 595/2006 Sb.). Bez převodu zůstávala
            // na 379.200 a účet se žádnou platbou nevyrovnal. Převádí se jen
            // u snapshotu, který výnosový účet nese — starší revize se
            // zaúčtují byte-identicky jako dřív.
            //
            // Strana MD nese TUTÉŽ analytickou dimenzi (`MZ-EX-…`) jako
            // závazek exekučních srážek, aby se na ní saldo vyrovnalo; výnos
            // ji nenese (klíč bez `:enforcement:`), středisko do výnosů nepatří.
            if (PayrollAccountingDefaults::snapshotAllowsSplit(
                $configuredAccounts,
                'enforcement_fee_revenue_credit',
            )) {
                $this->addPair(
                    $allocations,
                    "employee:{$employeeId}:enforcement-fee",
                    $this->enforcementAccount($accounts, $configuredAccounts),
                    $accounts['enforcement_fee_revenue_credit'],
                    $this->nonNegativeInt(
                        $enforcementResult + ['employer_flat_fee_minor_units' => 0],
                        'employer_flat_fee_minor_units',
                    ),
                    'Paušální náhrada nákladů plátce mzdy (§ 270 odst. 2 o. s. ř.)',
                    $this->deductionDimension("employee:{$employeeId}:enforcement:liability"),
                );
            }

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
     * @param \Closure(array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}):string $debitFor
     *        nákladový účet části vztahu
     * @param list<array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}> $parts
     * @param array<string,mixed> $inputSnapshot zmrazený mzdový vstup
     * @param array<string,string> $accounts doplněná sada předkontací
     * @param array<string,mixed> $configuredAccounts surová sada ze snapshotu
     */
    private function addGross(
        array &$allocations,
        string $baseKey,
        \Closure $debitFor,
        string $credit,
        int $amount,
        string $description,
        array $parts,
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
            $this->addCostPair(
                $allocations,
                $baseKey,
                $debitFor,
                $credit,
                $amount,
                $description,
                $parts,
            );

            return;
        }

        $nonDeductibleDebit = $accounts['non_deductible_benefit_debit'];
        $this->addCostPair(
            $allocations,
            "{$baseKey}:non-deductible",
            static fn (array $part): string => $nonDeductibleDebit,
            $credit,
            $nonDeductible,
            $description . ' — osvobozená část (§ 25 odst. 1 písm. h) ZDP)',
            $parts,
        );
        $this->addCostPair(
            $allocations,
            "{$baseKey}:taxable",
            $debitFor,
            $credit,
            $this->subtract($amount, $nonDeductible),
            $description . ' — nadlimitní zdanitelná část',
            $parts,
        );
    }

    /**
     * Nákladová dvojice rozdělená podle podílů pracovního vztahu.
     *
     * Vztah bez procentního rozpadu má jedinou část a zapíše se přesně jako
     * dosud jednou dvojicí ({@see addPair()}), jen s firemními dimenzemi, jsou-li
     * nastavené. S rozpadem se dělí jen NÁKLAD (strana MD) metodou největšího
     * zbytku, takže součet částí sedí na haléř. Protiúčet zůstává jednou
     * částkou — závazek vůči zaměstnanci ani instituci se na střediska nedělí.
     *
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $allocations
     * @param \Closure(array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}):string $debitFor
     * @param list<array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}> $parts
     */
    private function addCostPair(
        array &$allocations,
        string $baseKey,
        \Closure $debitFor,
        string $credit,
        int $amount,
        string $description,
        array $parts,
    ): void {
        if ($amount === 0) {
            return;
        }
        if (count($parts) === 1) {
            $part = $parts[0];
            $this->addPair(
                $allocations,
                $baseKey,
                $debitFor($part),
                $credit,
                $amount,
                $description,
                $part['cost_center'],
                $part['dimensions'],
            );

            return;
        }
        foreach ($this->splitByParts($amount, $parts) as $index => $share) {
            $part = $parts[$index];
            $this->addAllocation(
                $allocations,
                "{$baseKey}:part:{$index}:debit",
                $debitFor($part),
                $share,
                $description,
                $part['cost_center'],
                $part['dimensions'],
            );
        }
        $this->addAllocation(
            $allocations,
            "{$baseKey}:credit",
            $credit,
            -$amount,
            $description,
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
     * Protiúčet odpočtu zálohy na pracovní cestu, nebo `null`.
     *
     * Vstup nese zápornou částku, takže dvojice „MD tento účet / D závazek
     * vztahu" se zapíše obráceně: MD 331 / D 335. Záloha vyplacená z pokladny
     * visí na pohledávce za zaměstnancem a odpočtem ve výplatě se vyrovná.
     * Náklad (512) se tím nesnižuje — ten nese nárok zaúčtovaný v plné výši.
     *
     * Klíč `employee_receivable_debit` je nepovinný s výchozí 335 a starší
     * snapshot tuhle složku obsahovat nemůže, takže se zápis dřív schválených
     * revizí nemění.
     *
     * @param array<string,mixed> $component zmrazená složka
     * @param array<string,string> $accounts
     */
    private function travelAdvanceAccount(array $component, array $accounts): ?string
    {
        return ($component['code'] ?? null) === BusinessTripMaterializer::COMPONENT_ADVANCE
            ? $accounts['employee_receivable_debit']
            : null;
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
     * Závazkový účet EXEKUČNÍCH a insolvenčních srážek (Ú-14).
     *
     * Exekuce se dosud účtovala na týž účet jako dobrovolné srážky ze mzdy
     * a rozlišovala je jen pseudonymní dimenze `MZ-EX-…`/`MZ-SR-…` ve sloupci
     * `cost_center`. Věřitel je přitom v obou případech někdo jiný, peníze
     * jdou jinam (soudní exekutor, insolvenční správce vs. oprávněný z dohody
     * o srážkách) a exekuční srážka má vlastní pořadí i vlastní zákonný režim
     * (§ 276 a násl. o. s. ř., § 398 odst. 3 insolvenčního zákona).
     *
     * Degradace míří na FIREMNÍ účet ostatních srážek, ne na konstantu:
     * přesně to dosud dělal `$accounts['other_deductions_credit']`, takže
     * firma s přenastavenými srážkami dostane touž hodnotu jako dřív.
     *
     * @param array<string,string> $accounts doplněná sada předkontací
     * @param array<string,mixed> $configuredAccounts surová sada ze snapshotu
     */
    private function enforcementAccount(
        array $accounts,
        array $configuredAccounts,
    ): string {
        return PayrollAccountingDefaults::snapshotAllowsSplit(
            $configuredAccounts,
            'enforcement_deductions_credit',
        )
            ? $accounts['enforcement_deductions_credit']
            : $accounts['other_deductions_credit'];
    }

    /**
     * Závazkový účet příspěvku na spoření u RIZIKOVÉ práce (Ú-14).
     *
     * Na rozdíl od exekuce tady degradace NEMÍŘÍ na jiný firemní účet:
     * příspěvek se odjakživa účtoval na výchozí konstantu '379' bez ohledu na
     * to, co si firma nastavila u srážek — jeho vlastní sloupec
     * `risky_savings_credit_account` totiž přibyl až s ním samotným. Snapshot
     * bez klíče proto musí dostat doslovnou historickou hodnotu, jinak by se
     * firmě s přenastavenými srážkami zápis změnil.
     *
     * @param array<string,string> $accounts doplněná sada předkontací
     * @param array<string,mixed> $configuredAccounts surová sada ze snapshotu
     */
    private function riskySavingsAccount(
        array $accounts,
        array $configuredAccounts,
    ): string {
        if (PayrollAccountingDefaults::snapshotAllowsSplit(
            $configuredAccounts,
            'risky_savings_credit',
        )) {
            return $accounts['risky_savings_credit'];
        }
        $preSplit = PayrollAccountingDefaults::preSplitCode('risky_savings_credit');

        return $this->account(
            $preSplit ?? $accounts['risky_savings_credit'],
            'risky_savings_credit',
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
    private function addPair(
        array &$allocations,
        string $baseKey,
        string $debit,
        string $credit,
        int $amount,
        string $description,
        ?string $costCenter = null,
        array $dimensions = [],
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
            $dimensions,
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
        array $dimensions = [],
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
        // Totéž platí pro firemní dimenze (typ → hodnota): bez vazby mzdové
        // dimenze na firemní číselník klíč v alokaci vůbec není.
        if ($dimensions !== []) {
            ksort($dimensions, SORT_NUMERIC);
            $allocation['dimensions'] = $dimensions;
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
     *   cost_center?:string,
     *   dimensions?:array<int,int>
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
        /** @var array<string,array{account_code:string,side:'debit'|'credit',amount_minor:int,description:string,cost_center?:string,dimensions?:array<int,int>}> $grouped */
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
            $dimensions = $source['dimensions'];
            // Klíč seskupení = účet + strana + středisko + vektor firemních
            // dimenzí. Bez dimenzí se klíč nemění, takže se řádky seskupí
            // i seřadí přesně jako dřív.
            $group = $source['account_code']
                . "\0" . $side
                . "\0" . ($costCenter ?? '')
                . ($dimensions === [] ? '' : "\0" . self::dimensionsKey($dimensions));
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
                if ($dimensions !== []) {
                    $grouped[$group]['dimensions'] = $dimensions;
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
     *   cost_center:?string,
     *   dimensions:array<int,int>
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
            $dimensions = $this->allocationDimensions(
                $allocation['dimensions'] ?? null,
                $context,
            );
            $seenKeys[$key] = true;
            // Do vektoru patří i STŘEDISKO. Opravná revize, která zaměstnance
            // jen přeřadí na jiné středisko, mění `cost_center`, ne částku ani
            // účet — bez střediska v klíči by delta vyšla nulově a v deníku by
            // navždy zůstalo staré středisko. S ním se stará dvojice odúčtuje
            // a nová zaúčtuje, takže analytika sedí i po opravě.
            //
            // `target_hash` se tím NEMĚNÍ: počítá se ze samotných alokací, ne
            // z tohohle vektoru, takže zaúčtované revize dál hlásí týž otisk.
            //
            // Firemní dimenze ze stejného důvodu: přeřazení na jinou hodnotu
            // odúčtuje starou a zaúčtuje novou dvojici.
            $vectorKey = $key . "\0" . $account . "\0" . ($costCenter ?? '')
                . ($dimensions === [] ? '' : "\0" . self::dimensionsKey($dimensions));
            $result[$vectorKey] = [
                'account_code' => $this->account($account, $key),
                'signed_minor' => $signed,
                'description' => $description,
                'allocation_key' => $key,
                'cost_center' => $costCenter,
                'dimensions' => $dimensions,
            ];
        }

        return $result;
    }

    /**
     * @return array<int,int> typ firemní dimenze → hodnota
     */
    private function allocationDimensions(mixed $value, string $context): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || $value === []) {
            throw new \InvalidArgumentException("{$context} nemají platný formát.");
        }
        $result = [];
        foreach ($value as $typeId => $valueId) {
            if (!is_int($typeId) || $typeId <= 0 || !is_int($valueId) || $valueId <= 0) {
                throw new \InvalidArgumentException("{$context} nemají platný formát.");
            }
            $result[$typeId] = $valueId;
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /** @param array<int,int> $dimensions */
    private static function dimensionsKey(array $dimensions): string
    {
        ksort($dimensions, SORT_NUMERIC);
        $pairs = [];
        foreach ($dimensions as $typeId => $valueId) {
            $pairs[] = $typeId . ':' . $valueId;
        }

        return implode(',', $pairs);
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
     * Má-li vztah procentní rozpad na střediska (dimenze), podíl vztahu se
     * dál rozdělí mezi jeho části stejnou metodou největšího zbytku jako hrubá
     * mzda — součet zůstává na haléř a závazek se nedělí.
     *
     * @param array<int,int>|null $shares employment_id → částka
     * @param array<int,list<array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}>> $partsByEmployment
     */
    private function addEmployerInsurance(
        array &$allocations,
        string $baseKey,
        string $debitAccount,
        string $creditAccount,
        int $amount,
        string $description,
        ?array $shares,
        array $partsByEmployment,
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
        foreach ($this->employerInsuranceDebits($baseKey, $shares, $partsByEmployment) as $debit) {
            $this->addAllocation(
                $allocations,
                $debit['allocation_key'],
                $debitAccount,
                $debit['signed_minor'],
                $description,
                $debit['cost_center'],
                $debit['dimensions'],
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
     * Nákladový rozpad zaměstnavatelského pojistného na pracovní vztahy a jejich
     * části. Je to tentýž rozpad, který {@see build()} zaúčtuje na 524, když aspoň jeden vztah
     * nese středisko nebo firemní dimenzi.
     *
     * Bez dimenzí se pojistné účtuje jednou firemní částkou a v alokacích po
     * zaměstnancích chybí. Report nákladů na zaměstnance ho proto rozkládá
     * tímhle, aby rozdělení po osobách nevzniklo podruhé jinou cestou.
     *
     * @param array<string,mixed> $snapshot      vstupní snapshot revize
     * @param array<string,mixed> $statutorySets zákonné výsledky revize
     * @return array{
     *   social:?list<array{allocation_key:string,employment_id:int,cost_center:?string,dimensions:array<int,int>,signed_minor:int}>,
     *   health:?list<array{allocation_key:string,employment_id:int,cost_center:?string,dimensions:array<int,int>,signed_minor:int}>
     * } null = rozpad nelze doložit (revize bez výsledků vztahů)
     */
    public function employerInsuranceCostShares(array $snapshot, array $statutorySets): array
    {
        $snapshotPeople = $this->snapshotPeople($snapshot);
        $partsByEmployment = [];
        foreach ($snapshotPeople as $person) {
            foreach ($person['employments'] as $employmentId => $employmentSnapshot) {
                $partsByEmployment[$employmentId] = $this->employmentParts($employmentSnapshot);
            }
        }
        $sets = $this->sets($statutorySets, array_keys($snapshotPeople));
        $social = $this->socialEmployerShares(
            $sets,
            $this->nonNegativeInt($sets['social_insurance']['root'], 'employer_contribution_minor_units'),
        );
        $health = $this->healthEmployerShares(
            $sets,
            $this->nonNegativeInt($sets['health_insurance']['root'], 'employer_contribution_minor_units'),
        );

        return [
            'social' => $social === null
                ? null
                : $this->employerInsuranceDebits('employer-insurance:social', $social, $partsByEmployment),
            'health' => $health === null
                ? null
                : $this->employerInsuranceDebits('employer-insurance:health', $health, $partsByEmployment),
        ];
    }

    /**
     * @param array<string,array{root:array<string,mixed>,people:array<int,array<string,mixed>>,relationships:array<int,array<int,array<string,mixed>>>}> $sets
     * @return array<int,int>|null employment_id → částka
     */
    private function socialEmployerShares(array $sets, int $total): ?array
    {
        return PayrollEmployerInsuranceCostAllocation::social(
            $sets['social_insurance']['root'],
            $this->flattenRelationships($sets['social_insurance']['relationships']),
            $total,
        );
    }

    /**
     * @param array<string,array{root:array<string,mixed>,people:array<int,array<string,mixed>>,relationships:array<int,array<int,array<string,mixed>>>}> $sets
     * @return array<int,int>|null employment_id → částka
     */
    private function healthEmployerShares(array $sets, int $total): ?array
    {
        return PayrollEmployerInsuranceCostAllocation::health(
            $this->healthEmployerPersonTotals($sets['health_insurance']),
            $this->healthEmployerWeights($sets['health_insurance']),
            $total,
        );
    }

    /**
     * Nákladové alokace podílů vztahů; vztah s procentním rozpadem se dál dělí
     * mezi své části (F3).
     *
     * @param array<int,int> $shares employment_id → částka
     * @param array<int,list<array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}>> $partsByEmployment
     * @return list<array{allocation_key:string,employment_id:int,cost_center:?string,dimensions:array<int,int>,signed_minor:int}>
     */
    private function employerInsuranceDebits(string $baseKey, array $shares, array $partsByEmployment): array
    {
        $debits = [];
        foreach ($shares as $employmentId => $share) {
            $parts = $partsByEmployment[$employmentId] ?? [self::plainPart()];
            if (count($parts) === 1) {
                $debits[] = [
                    'allocation_key' => "{$baseKey}:employment:{$employmentId}:debit",
                    'employment_id' => $employmentId,
                    'cost_center' => $parts[0]['cost_center'],
                    'dimensions' => $parts[0]['dimensions'],
                    'signed_minor' => $share,
                ];
                continue;
            }
            foreach ($this->splitByParts($share, $parts) as $index => $partShare) {
                $debits[] = [
                    'allocation_key' => "{$baseKey}:employment:{$employmentId}:part:{$index}:debit",
                    'employment_id' => $employmentId,
                    'cost_center' => $parts[$index]['cost_center'],
                    'dimensions' => $parts[$index]['dimensions'],
                    'signed_minor' => $partShare,
                ];
            }
        }

        return $debits;
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
     * @param array<string,mixed> $configuredAccounts surová sada ze snapshotu
     * @param array<int,list<array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}>> $partsByEmployment
     */
    private function addRiskySavings(
        array &$allocations,
        array $result,
        array $accounts,
        array $configuredAccounts,
        array $partsByEmployment,
    ): void {
        $statutory = $this->object($result['statutory'] ?? null, 'result.statutory');
        $rows = $statutory['risky_savings'] ?? null;
        if ($rows === null) {
            return;
        }
        foreach ($this->rows($rows, 'result.statutory.risky_savings') as $row) {
            $employmentId = $this->positiveInt($row, 'employment_id');
            if (!array_key_exists($employmentId, $partsByEmployment)) {
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
            $riskyDebit = $accounts['risky_savings_debit'];
            $this->addCostPair(
                $allocations,
                "risky-savings:employment:{$employmentId}",
                static fn (array $part): string => $riskyDebit,
                $this->riskySavingsAccount($accounts, $configuredAccounts),
                $contribution,
                'Povinný příspěvek na spoření u rizikové práce',
                $partsByEmployment[$employmentId],
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
     * @param array<string,mixed> $dimension zmrazená dimenze vztahu
     */
    private function dimensionCostCenter(array $dimension, int $index): ?string
    {
        if (($dimension['type'] ?? null) !== 'cost_center') {
            return null;
        }
        $code = $dimension['code'] ?? null;
        if (!is_string($code) || $code === '' || strlen($code) > 50) {
            throw new \DomainException(
                "Kód střediska employment.dimensions.{$index} není platný.",
            );
        }

        return $code;
    }

    /**
     * Části pracovního vztahu, na které se rozpadá jeho náklad.
     *
     * Každá část nese středisko pro textový `cost_center`, firemní dimenze
     * (typ → hodnota) pro `journal_entry_line_dimensions`, výchozí nákladový
     * účet dimenze a celočíselnou váhu.
     *
     * Vztah bez procentního rozpadu má JEDNU část s tím, co dosud četly
     * {@see PayrollDimensionCostAccountResolver} a {@see dimensionCostCenter()}:
     * první středisko a účet podle priority typů. Zaúčtuje se tedy beze změny.
     *
     * Rozpad víc hodnot jednoho typu (`share_bp`, součet 10 000) se kombinuje
     * s ostatními typy kartézským součinem — 70/30 na střediska a 50/50 na
     * zakázky dá čtyři části s váhami 35/15/35/15. Podíly, které nedají 100 %,
     * zaúčtování zastaví: rozpad by jinak část nákladu tiše ztratil.
     *
     * @param array<string,mixed> $employmentSnapshot zmrazený pracovní vztah
     * @return list<array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}>
     */
    private function employmentParts(array $employmentSnapshot): array
    {
        $employmentAccount = $this->dimensionAccounts->resolve($employmentSnapshot);
        $dimensions = $employmentSnapshot['dimensions'] ?? null;
        if (!is_array($dimensions) || !array_is_list($dimensions)) {
            return [self::plainPart()];
        }

        /** @var array<string,list<array{dimension:array<string,mixed>,index:int,share:int}>> $byType */
        $byType = [];
        foreach ($dimensions as $index => $dimension) {
            $dimension = $this->object($dimension, "employment.dimensions.{$index}");
            $this->dimensionCostCenter($dimension, $index);
            $share = $dimension['share_bp'] ?? 10_000;
            if (!is_int($share) || $share <= 0 || $share > 10_000) {
                throw new \DomainException(
                    "Podíl employment.dimensions.{$index}.share_bp není platný.",
                );
            }
            $this->companyDimension($dimension, $index);
            $type = $dimension['type'] ?? null;
            $byType[is_string($type) ? $type : ''][] = [
                'dimension' => $dimension,
                'index' => $index,
                'share' => $share,
            ];
        }

        /** @var list<array{entries:list<array{dimension:array<string,mixed>,index:int,share:int}>,weight:int}> $combinations */
        $combinations = [['entries' => [], 'weight' => 1]];
        foreach ($byType as $type => $entries) {
            if (count($entries) === 1 && $entries[0]['share'] === 10_000) {
                foreach ($combinations as $i => $combination) {
                    $combinations[$i]['entries'][] = $entries[0];
                }
                continue;
            }
            $total = 0;
            foreach ($entries as $entry) {
                $total = $this->add($total, $entry['share']);
            }
            if ($total !== 10_000) {
                throw new \DomainException(
                    "Podíly mzdových dimenzí typu {$type} nedávají dohromady 100 %.",
                );
            }
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($entries as $entry) {
                    $next[] = [
                        'entries' => [...$combination['entries'], $entry],
                        'weight' => $this->multiply($combination['weight'], $entry['share']),
                    ];
                }
            }
            $combinations = $next;
        }

        $divisor = 0;
        foreach ($combinations as $combination) {
            $divisor = self::gcd($divisor, $combination['weight']);
        }

        $parts = [];
        foreach ($combinations as $combination) {
            $costCenter = null;
            $companyDimensions = [];
            foreach ($combination['entries'] as $entry) {
                $costCenter ??= $this->dimensionCostCenter($entry['dimension'], $entry['index']);
            }
            // Dvě mzdové dimenze navázané na týž firemní typ: vyhrává ta podle
            // priority účtu (středisko, zakázka, činnost), stejně jako u účtu.
            foreach (self::DIMENSION_PRIORITY as $priorityType) {
                foreach ($combination['entries'] as $entry) {
                    if (($entry['dimension']['type'] ?? null) !== $priorityType) {
                        continue;
                    }
                    $link = $this->companyDimension($entry['dimension'], $entry['index']);
                    if ($link !== null) {
                        $companyDimensions[$link[0]] ??= $link[1];
                    }
                }
            }
            ksort($companyDimensions, SORT_NUMERIC);
            $parts[] = [
                'cost_center' => $costCenter,
                'dimensions' => $companyDimensions,
                'account' => count($combinations) === 1
                    ? $employmentAccount
                    : $this->dimensionAccounts->resolve([
                        'dimensions' => array_map(
                            static fn (array $entry): array => $entry['dimension'],
                            $combination['entries'],
                        ),
                    ]),
                'weight' => intdiv($combination['weight'], max(1, $divisor)),
            ];
        }

        return $parts;
    }

    /**
     * Vazba zmrazené mzdové dimenze na firemní dimenzi, nebo `null`.
     *
     * @param array<string,mixed> $dimension
     * @return array{int,int}|null [typ, hodnota]
     */
    private function companyDimension(array $dimension, int $index): ?array
    {
        $typeId = $dimension['dimension_type_id'] ?? null;
        $valueId = $dimension['dimension_value_id'] ?? null;
        if ($typeId === null && $valueId === null) {
            return null;
        }
        if (!is_int($typeId) || $typeId <= 0 || !is_int($valueId) || $valueId <= 0) {
            throw new \DomainException(
                "Firemní dimenze employment.dimensions.{$index} není platná.",
            );
        }

        return [$typeId, $valueId];
    }

    /** @return array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int} */
    private static function plainPart(): array
    {
        return ['cost_center' => null, 'dimensions' => [], 'account' => null, 'weight' => 1];
    }

    /**
     * @param list<array{cost_center:?string,dimensions:array<int,int>,account:?string,weight:int}> $parts
     */
    private function isPlainParts(array $parts): bool
    {
        return count($parts) === 1
            && $parts[0]['cost_center'] === null
            && $parts[0]['dimensions'] === [];
    }

    /**
     * Rozdělí částku mezi části vztahu metodou největšího zbytku.
     *
     * Součet je vždy přesně `$amount`; haléř navíc dostane část s největším
     * zbytkem, při shodě ta dřívější. Záporná částka (oprava) se dělí stejně
     * jako kladná, jen se znaménkem.
     *
     * @param list<array{weight:int}> $parts
     * @return array<int,int> index části → částka
     */
    private function splitByParts(int $amount, array $parts): array
    {
        $negative = $amount < 0;
        $absolute = $this->absolute($amount);
        $totalWeight = 0;
        foreach ($parts as $part) {
            if ($part['weight'] <= 0) {
                throw new \DomainException('Část pracovního vztahu nemá kladnou váhu.');
            }
            $totalWeight = $this->add($totalWeight, $part['weight']);
        }
        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach ($parts as $index => $part) {
            $product = $this->multiply($absolute, $part['weight']);
            $shares[$index] = intdiv($product, $totalWeight);
            $remainders[$index] = $product % $totalWeight;
            $allocated = $this->add($allocated, $shares[$index]);
        }
        $order = array_keys($parts);
        usort($order, static fn (int $left, int $right): int =>
            ($remainders[$right] <=> $remainders[$left]) ?: ($left <=> $right));
        for ($i = 0, $rest = $absolute - $allocated; $i < $rest; $i++) {
            $shares[$order[$i]]++;
        }

        return $negative
            ? array_map(static fn (int $share): int => -$share, $shares)
            : $shares;
    }

    private static function gcd(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return abs($left);
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
