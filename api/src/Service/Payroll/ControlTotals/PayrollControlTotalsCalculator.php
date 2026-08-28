<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\ControlTotals;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollControlTotalsCalculator
{
    private const METRICS = [
        'source_amount_minor',
        'cash_payable_minor',
        'tax_base_minor',
        'social_base_minor',
        'health_base_minor',
        'average_earning_base_minor',
        'enforcement_base_minor',
        'jmhz_amount_minor',
    ];

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array<string,mixed> $resultSnapshot
     */
    public function calculate(
        int $supplierId,
        int $revisionId,
        array $inputSnapshot,
        array $resultSnapshot,
        string $sourceResultHash,
    ): PayrollControlTotals {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma i revize kontrolních součtů musí být kladné.',
            );
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $sourceResultHash) !== 1) {
            throw new \InvalidArgumentException(
                'Otisk schváleného výsledku není platný.',
            );
        }
        if (($inputSnapshot['schema_version'] ?? null)
                !== 'payroll-run-input.v2'
            || ($resultSnapshot['schema_version'] ?? null)
                !== 'payroll-run-result.v2'
        ) {
            throw new \DomainException(
                'Kontrolní součty vyžadují úplný mzdový snapshot v2.',
            );
        }
        $inputHash = hash(
            'sha256',
            CanonicalJson::encode($inputSnapshot),
        );
        $resultHash = hash(
            'sha256',
            CanonicalJson::encode($resultSnapshot),
        );
        if (($resultSnapshot['source_snapshot_hash'] ?? null) !== $inputHash
            || !hash_equals($sourceResultHash, $resultHash)
        ) {
            throw new \DomainException(
                'Kontrolní součty neodpovídají otiskům schváleného výsledku.',
            );
        }

        $frozenEmployments = $this->frozenEmployments($inputSnapshot);
        $resultPeople = $this->rows(
            $resultSnapshot['people'] ?? null,
            'result.people',
        );
        usort(
            $resultPeople,
            fn(array $left, array $right): int
                => $this->positiveInt($left['employee_id'] ?? null, 'employee_id')
                <=> $this->positiveInt($right['employee_id'] ?? null, 'employee_id'),
        );

        $relationships = [];
        $people = [];
        $officeMaps = [];
        $company = $this->zeroMetrics();
        $accountingMap = [];
        $seenEmployments = [];
        $liabilityMap = array_fill_keys([
            'advance_tax',
            'employee_receivable',
            'health_insurance',
            'net_wage',
            'social_insurance',
            'standard_deduction',
            'withholding_tax',
        ], 0);
        $employeeSocial = 0;
        // § 35d odst. 5 a 9 a § 38ch odst. 5: vyplacené měsíční bonusy
        // a doplatky ze zúčtování snižují odvod záloh správci daně. Sčítají se
        // za celou firmu a odečtou se až z firemního odvodu — po jednotlivci
        // by mohl vyjít záporný odvod u člověka, který zálohu vůbec neplatí.
        $advanceTaxOffset = 0;
        $seenEmployees = [];

        foreach ($resultPeople as $person) {
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                'result.people.employee_id',
            );
            if (isset($seenEmployees[$employeeId])) {
                throw new \DomainException(
                    "Výsledek obsahuje osobu {$employeeId} vícekrát.",
                );
            }
            $seenEmployees[$employeeId] = true;
            $employmentRows = $this->rows(
                $person['employments'] ?? null,
                "result.people.{$employeeId}.employments",
            );
            usort(
                $employmentRows,
                fn(array $left, array $right): int
                    => $this->positiveInt(
                        $left['employment_id'] ?? null,
                        'employment_id',
                    )
                    <=> $this->positiveInt(
                        $right['employment_id'] ?? null,
                        'employment_id',
                    ),
            );
            $personTotals = $this->zeroMetrics();
            $employmentCash = [];
            foreach ($employmentRows as $employment) {
                $employmentId = $this->positiveInt(
                    $employment['employment_id'] ?? null,
                    'result.employment_id',
                );
                $frozen = $frozenEmployments[$employmentId] ?? null;
                if ($frozen === null
                    || $frozen['employee_id'] !== $employeeId
                    || isset($seenEmployments[$employmentId])
                ) {
                    throw new \DomainException(
                        "Výsledek vztahu {$employmentId} neodpovídá zmrazenému vstupu.",
                    );
                }
                $seenEmployments[$employmentId] = true;
                $employmentTotals = $this->metrics(
                    $employment['totals'] ?? null,
                    "result.employment.{$employmentId}.totals",
                );
                $personTotals = $this->addMetrics(
                    $personTotals,
                    $employmentTotals,
                );
                $officeId = $frozen['office_id'];
                $officeMaps[$officeId] ??= $this->zeroMetrics();
                $officeMaps[$officeId] = $this->addMetrics(
                    $officeMaps[$officeId],
                    $employmentTotals,
                );
                $relationships[] = [
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'office_id' => $officeId,
                    'totals' => $employmentTotals,
                ];
                $employmentCash[$employmentId] = [
                    'cash' => $employmentTotals['cash_payable_minor'],
                    'non_cash' => $this->subtract(
                        $employmentTotals['source_amount_minor'],
                        $employmentTotals['cash_payable_minor'],
                    ),
                ];
                $this->collectAccounting(
                    $accountingMap,
                    $this->rows(
                        $employment['inputs'] ?? null,
                        "result.employment.{$employmentId}.inputs",
                    ),
                );
            }
            if ($this->metrics(
                $person['totals'] ?? null,
                "result.person.{$employeeId}.totals",
            ) !== $personTotals) {
                throw new \DomainException(
                    "Kontrolní součet osoby {$employeeId} nesouhlasí s jejími vztahy.",
                );
            }
            $people[] = [
                'employee_id' => $employeeId,
                'totals' => $personTotals,
            ];
            $company = $this->addMetrics($company, $personTotals);
            $statutory = $this->statutoryPerson(
                $person['statutory'] ?? null,
                $employeeId,
                $employmentCash,
            );
            $employeeSocial = $this->add(
                $employeeSocial,
                $statutory['employee_social'],
            );
            $advanceTaxOffset = $this->add(
                $advanceTaxOffset,
                $statutory['advance_tax_offset'],
            );
            foreach ($statutory['liabilities'] as $kind => $amount) {
                $liabilityMap[$kind] = $this->add(
                    $liabilityMap[$kind],
                    $amount,
                );
            }
        }

        $expectedEmploymentIds = array_keys($frozenEmployments);
        $actualEmploymentIds = array_keys($seenEmployments);
        sort($expectedEmploymentIds, SORT_NUMERIC);
        sort($actualEmploymentIds, SORT_NUMERIC);
        if ($expectedEmploymentIds !== $actualEmploymentIds) {
            throw new \DomainException(
                'Výsledek nepokrývá přesně všechny zmrazené pracovní vztahy.',
            );
        }
        if ($this->metrics(
            $resultSnapshot['totals'] ?? null,
            'result.totals',
        ) !== $company) {
            throw new \DomainException(
                'Firemní kontrolní součet nesouhlasí se součtem osob.',
            );
        }

        $statutoryEnvelope = $this->object(
            $resultSnapshot['statutory'] ?? null,
            'result.statutory',
        );
        if (($statutoryEnvelope['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                'Kontrolní součty nelze vytvořit z neuzavřeného zákonného výsledku.',
            );
        }
        $employerSocial = $this->nonNegativeInt(
            $statutoryEnvelope['employer_social_minor_units'] ?? null,
            'statutory.employer_social_minor_units',
        );
        $liabilityMap['social_insurance'] = $this->add(
            $employeeSocial,
            $employerSocial,
        );
        // § 35d odst. 5: „O vyplacený měsíční daňový bonus plátce daně sníží
        // odvod záloh na daň za příslušný kalendářní měsíc." Totéž říká
        // § 35d odst. 9 o doplatku na bonusu z ročního zúčtování a § 38ch
        // odst. 5 o vráceném přeplatku. Odvod nemůže klesnout pod nulu —
        // zbytek se podle týchž ustanovení řeší buď snížením odvodů
        // v následujících měsících, nebo žádostí správci daně; obojí je úkon
        // plátce, ne výpočet, a modul za něj nerozhoduje.
        $liabilityMap['advance_tax'] = max(
            0,
            $this->subtract($liabilityMap['advance_tax'], $advanceTaxOffset),
        );

        ksort($officeMaps, SORT_NUMERIC);
        $offices = [];
        foreach ($officeMaps as $officeId => $officeTotals) {
            $offices[] = [
                'office_id' => $officeId,
                'totals' => $officeTotals,
            ];
        }
        ksort($accountingMap, SORT_STRING);
        $accounting = array_values($accountingMap);
        $storedAccounting = $this->accountingRows(
            $resultSnapshot['accounting_totals'] ?? null,
        );
        if ($storedAccounting !== $accounting) {
            throw new \DomainException(
                'Kontrolní součet účetních dimenzí nesouhlasí se vstupy vztahů.',
            );
        }
        ksort($liabilityMap, SORT_STRING);
        $liabilities = [];
        foreach ($liabilityMap as $kind => $amount) {
            // Každá položka je nezáporná ČÁSTKA a směr říká, kterým směrem
            // peníze tečou. Pohledávka za zaměstnancem z přeplatku čisté mzdy
            // je jediná příchozí — firma ji od zaměstnance inkasuje (zápočtem
            // v dalším měsíci nebo úhradou), neposílá ji.
            if ($amount < 0) {
                throw new \DomainException(
                    "Kontrolní součet závazku {$kind} vyšel záporně.",
                );
            }
            $liabilities[] = [
                'liability_kind' => $kind,
                'direction' => $kind === 'employee_receivable'
                    ? 'incoming'
                    : 'outgoing',
                'amount_minor' => $amount,
            ];
        }

        $payload = [
            'schema_version' => 'payroll-control-totals.v1',
            'supplier_id' => $supplierId,
            'revision_id' => $revisionId,
            'source_result_hash' => $sourceResultHash,
            'relationships' => $relationships,
            'people' => $people,
            'offices' => $offices,
            'company' => $company,
            'liabilities' => $liabilities,
            'accounting_dimensions' => $accounting,
        ];

        return new PayrollControlTotals(
            $supplierId,
            $revisionId,
            $sourceResultHash,
            $relationships,
            $people,
            $offices,
            $company,
            $liabilities,
            $accounting,
            hash('sha256', CanonicalJson::encode($payload)),
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,array{employee_id:int,office_id:int}>
     */
    private function frozenEmployments(array $snapshot): array
    {
        $result = [];
        $seenEmployees = [];
        foreach ($this->rows($snapshot['people'] ?? null, 'snapshot.people') as $person) {
            $employee = $this->object(
                $person['employee'] ?? null,
                'snapshot.employee',
            );
            $employeeId = $this->positiveInt(
                $employee['id'] ?? null,
                'snapshot.employee.id',
            );
            if (isset($seenEmployees[$employeeId])) {
                throw new \DomainException(
                    "Zmrazený vstup obsahuje osobu {$employeeId} vícekrát.",
                );
            }
            $seenEmployees[$employeeId] = true;
            foreach ($this->rows(
                $person['employments'] ?? null,
                "snapshot.employee.{$employeeId}.employments",
            ) as $employmentRow) {
                $employment = $this->object(
                    $employmentRow['employment'] ?? null,
                    'snapshot.employment',
                );
                $employmentId = $this->positiveInt(
                    $employment['id'] ?? null,
                    'snapshot.employment.id',
                );
                $officeId = $this->positiveInt(
                    $employment['office_id'] ?? null,
                    'snapshot.employment.office_id',
                );
                if (isset($result[$employmentId])) {
                    throw new \DomainException(
                        "Zmrazený vstup obsahuje vztah {$employmentId} vícekrát.",
                    );
                }
                $result[$employmentId] = [
                    'employee_id' => $employeeId,
                    'office_id' => $officeId,
                ];
            }
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /**
     * @param array<int,array{cash:int,non_cash:int}> $employmentCash
     * @return array{
     *   employee_social:int,
     *   advance_tax_offset:int,
     *   liabilities:array<string,int>
     * }
     */
    private function statutoryPerson(
        mixed $value,
        int $employeeId,
        array $employmentCash,
    ): array {
        $statutory = $this->object($value, 'person.statutory');
        if (($statutory['status'] ?? null) !== 'calculated'
            || ($statutory['person_reference'] ?? null)
                !== "employee:{$employeeId}"
        ) {
            throw new \DomainException(
                "Osoba {$employeeId} nemá uzavřený zákonný výsledek.",
            );
        }
        $social = $this->object(
            $statutory['social_insurance'] ?? null,
            'person.social_insurance',
        );
        $health = $this->object(
            $statutory['health_insurance'] ?? null,
            'person.health_insurance',
        );
        $tax = $this->object(
            $statutory['income_tax'] ?? null,
            'person.income_tax',
        );
        $net = $this->object(
            $statutory['net_pay'] ?? null,
            'person.net_pay',
        );
        if (($net['person_reference'] ?? null) !== "employee:{$employeeId}") {
            throw new \DomainException(
                "Čistá mzda osoby {$employeeId} má cizí identitu.",
            );
        }

        $cash = 0;
        $nonCash = 0;
        $seen = [];
        foreach ($this->rows(
            $net['relationships'] ?? null,
            'person.net_pay.relationships',
        ) as $relationship) {
            $reference = $relationship['relationship_reference'] ?? null;
            if (!is_string($reference)
                || preg_match('/^employment:([1-9][0-9]*)$/D', $reference, $match) !== 1
            ) {
                throw new \DomainException(
                    "Čistá mzda osoby {$employeeId} má neplatný vztah.",
                );
            }
            $employmentId = (int) $match[1];
            $expected = $employmentCash[$employmentId] ?? null;
            if ($expected === null || isset($seen[$employmentId])) {
                throw new \DomainException(
                    "Čistá mzda osoby {$employeeId} má cizí nebo duplicitní vztah.",
                );
            }
            $seen[$employmentId] = true;
            $relationshipCash = $this->nonNegativeInt(
                $relationship['cash_income_minor_units'] ?? null,
                'net.relationship.cash',
            );
            $relationshipNonCash = $this->nonNegativeInt(
                $relationship['non_cash_income_minor_units'] ?? null,
                'net.relationship.non_cash',
            );
            if ($expected !== [
                'cash' => $relationshipCash,
                'non_cash' => $relationshipNonCash,
            ]) {
                throw new \DomainException(
                    "Čistá mzda osoby {$employeeId} nesouhlasí s výsledkem vztahu.",
                );
            }
            $cash = $this->add($cash, $relationshipCash);
            $nonCash = $this->add($nonCash, $relationshipNonCash);
        }
        $expectedIds = array_keys($employmentCash);
        $actualIds = array_keys($seen);
        sort($expectedIds, SORT_NUMERIC);
        sort($actualIds, SORT_NUMERIC);
        if ($expectedIds !== $actualIds) {
            throw new \DomainException(
                "Čistá mzda osoby {$employeeId} nepokrývá přesně její vztahy.",
            );
        }

        $employeeSocial = $this->nonNegativeInt(
            $social['employee_contribution_minor_units'] ?? null,
            'social.employee_contribution_minor_units',
        );
        $healthEmployee = $this->nonNegativeInt(
            $health['employee_contribution_minor_units'] ?? null,
            'health.employee_contribution_minor_units',
        );
        $healthEmployer = $this->nonNegativeInt(
            $health['employer_contribution_minor_units'] ?? null,
            'health.employer_contribution_minor_units',
        );
        $healthTotal = $this->nonNegativeInt(
            $health['total_contribution_minor_units'] ?? null,
            'health.total_contribution_minor_units',
        );
        if ($this->add($healthEmployee, $healthEmployer) !== $healthTotal) {
            throw new \DomainException(
                "Zdravotní pojištění osoby {$employeeId} nemá úplný součet.",
            );
        }
        $advance = $tax['advance_tax'] ?? null;
        $advanceTax = 0;
        if ($advance !== null) {
            $advanceRow = $this->object($advance, 'income_tax.advance_tax');
            $advanceTax = $this->nonNegativeInt(
                $advanceRow['tax_after_credits_minor_units'] ?? null,
                'income_tax.advance_tax.tax_after_credits_minor_units',
            );
        }
        $withholdingTax = $this->nonNegativeInt(
            $tax['withholding_tax_minor_units'] ?? null,
            'income_tax.withholding_tax_minor_units',
        );
        $deducted = 0;
        foreach ($this->rows($net['deductions'] ?? null, 'net.deductions') as $deduction) {
            $deducted = $this->add(
                $deducted,
                $this->nonNegativeInt(
                    $deduction['applied_minor_units'] ?? null,
                    'net.deduction.applied_minor_units',
                ),
            );
        }
        $netDeducted = $this->nonNegativeInt(
            $net['deducted_minor_units'] ?? null,
            'net.deducted_minor_units',
        );
        // ZÁPORNÁ čistá mzda je legitimní výsledek, ne poškozený podklad.
        // Osoba celý měsíc na neplaceném volnu nemá peněžní příjem, ale platí
        // (prostřednictvím zaměstnavatele, § 3 odst. 12 z. č. 592/1992 Sb.)
        // doplatek zdravotního pojištění do minimálního vyměřovacího základu
        // podle § 3 odst. 10 téhož zákona. Znaménko proto nekontrolujeme —
        // kontroluje se ROVNOST s vlastním rozpadem čisté mzdy o pár řádků
        // níž, což je přísnější brána než `nonNegativeInt()`: přehodit
        // znaménko jedné položce nestačí, musela by sedět celá soustava.
        //
        // Fail-closed zůstávají všechny ostatní položky: odvody, daň, bonus
        // ani sražené částky záporné být nesmí a dál padají.
        $netPayable = $this->integer(
            $net['net_payable_minor_units'] ?? null,
            'net.net_payable_minor_units',
        );
        if ($cash !== $this->nonNegativeInt(
            $net['cash_income_minor_units'] ?? null,
            'net.cash_income_minor_units',
        )
            || $nonCash !== $this->nonNegativeInt(
                $net['non_cash_income_minor_units'] ?? null,
                'net.non_cash_income_minor_units',
            )
            || $employeeSocial !== $this->nonNegativeInt(
                $net['employee_social_minor_units'] ?? null,
                'net.employee_social_minor_units',
            )
            || $healthEmployee !== $this->nonNegativeInt(
                $net['employee_health_minor_units'] ?? null,
                'net.employee_health_minor_units',
            )
            || $advanceTax !== $this->nonNegativeInt(
                $net['advance_tax_minor_units'] ?? null,
                'net.advance_tax_minor_units',
            )
            || $withholdingTax !== $this->nonNegativeInt(
                $net['withholding_tax_minor_units'] ?? null,
                'net.withholding_tax_minor_units',
            )
            || $deducted !== $netDeducted
            || $netPayable !== $this->integer(
                $statutory['net_payable_minor_units'] ?? null,
                'statutory.net_payable_minor_units',
            )
        ) {
            throw new \DomainException(
                "Čistá mzda osoby {$employeeId} nesouhlasí se zákonnými výsledky.",
            );
        }
        $before = $this->add(
            $cash,
            $this->integer(
                $net['correction_minor_units'] ?? null,
                'net.correction_minor_units',
            ),
        );
        foreach ([
            $employeeSocial,
            $healthEmployee,
            $advanceTax,
            $withholdingTax,
        ] as $deduction) {
            $before = $this->subtract($before, $deduction);
        }
        $taxBonus = $this->nonNegativeInt(
            $net['tax_bonus_minor_units'] ?? null,
            'net.tax_bonus_minor_units',
        );
        $before = $this->add($before, $taxBonus);
        // Doplatek ze zúčtování stojí za srážkami (§ 35d odst. 8) — do čisté
        // mzdy před srážkami nepatří, do výplaty ano.
        $annualSettlement = $this->nonNegativeInt(
            $net['annual_settlement_minor_units'] ?? 0,
            'net.annual_settlement_minor_units',
        );
        // Také čistá mzda PŘED srážkami smí být záporná — je to tentýž měsíc
        // bez peněžního příjmu s doplatkem ZP. Hlídá se rovnost s vlastním
        // rozpadem (`$before`), ne znaménko.
        if ($before !== $this->integer(
            $net['net_before_deductions_minor_units'] ?? null,
            'net.net_before_deductions_minor_units',
        )
            || $this->add($this->subtract($before, $netDeducted), $annualSettlement)
                !== $netPayable
        ) {
            throw new \DomainException(
                "Čistá mzda osoby {$employeeId} nemá přesný kontrolní součet.",
            );
        }

        // Závazek a pohledávka jsou dvě RŮZNÉ veličiny na dvou stranách
        // rozvahy, ne jedno číslo se znaménkem. Kdyby se záporná čistá mzda
        // sečetla do `net_wage`, firemní závazek vůči zaměstnancům by tvrdil
        // méně, než se skutečně vyplácí, a nesouhlasil by ani s deníkem
        // (kde přeplatek odchází dvojicí MD 335 / D 331, viz
        // PayrollPostingLineBuilder), ani s platebními závazky MZ-17
        // (kde se osobě se zápornou výplatou závazek vůbec nezaloží).
        // Rozpad na dvě položky drží všechny tři pohledy na jednom čísle.
        return [
            'employee_social' => $employeeSocial,
            'advance_tax_offset' => $this->add($taxBonus, $annualSettlement),
            'liabilities' => [
                'advance_tax' => $advanceTax,
                'employee_receivable' => $netPayable < 0
                    ? $this->subtract(0, $netPayable)
                    : 0,
                'health_insurance' => $healthTotal,
                'net_wage' => max(0, $netPayable),
                'standard_deduction' => $netDeducted,
                'withholding_tax' => $withholdingTax,
            ],
        ];
    }

    /**
     * @param array<string,array{
     *   debit_code:string,
     *   credit_code:string,
     *   amount_minor:int
     * }> $accounting
     * @param list<array<string,mixed>> $inputs
     */
    private function collectAccounting(array &$accounting, array $inputs): void
    {
        foreach ($inputs as $input) {
            $dimension = $this->object(
                $input['accounting'] ?? null,
                'result.input.accounting',
            );
            $debit = $dimension['debit_code'] ?? null;
            $credit = $dimension['credit_code'] ?? null;
            if ($debit === null && $credit === null) {
                continue;
            }
            if (!is_string($debit) || $debit === ''
                || !is_string($credit) || $credit === ''
            ) {
                throw new \DomainException(
                    'Účetní dimenze vstupu není úplná.',
                );
            }
            $key = $debit . "\0" . $credit;
            $accounting[$key] ??= [
                'debit_code' => $debit,
                'credit_code' => $credit,
                'amount_minor' => 0,
            ];
            $accounting[$key]['amount_minor'] = $this->add(
                $accounting[$key]['amount_minor'],
                $this->integer(
                    $dimension['amount_minor'] ?? null,
                    'result.input.accounting.amount_minor',
                ),
            );
        }
    }

    /**
     * @return list<array{
     *   debit_code:string,
     *   credit_code:string,
     *   amount_minor:int
     * }>
     */
    private function accountingRows(mixed $value): array
    {
        $map = [];
        foreach ($this->rows($value, 'result.accounting_totals') as $row) {
            $debit = $row['debit_code'] ?? null;
            $credit = $row['credit_code'] ?? null;
            if (!is_string($debit) || $debit === ''
                || !is_string($credit) || $credit === ''
            ) {
                throw new \DomainException(
                    'Uložená účetní dimenze není úplná.',
                );
            }
            $key = $debit . "\0" . $credit;
            if (isset($map[$key])) {
                throw new \DomainException(
                    'Uložená účetní dimenze je duplicitní.',
                );
            }
            $map[$key] = [
                'debit_code' => $debit,
                'credit_code' => $credit,
                'amount_minor' => $this->integer(
                    $row['amount_minor'] ?? null,
                    'result.accounting_totals.amount_minor',
                ),
            ];
        }
        ksort($map, SORT_STRING);
        return array_values($map);
    }

    /** @return array<string,int> */
    private function metrics(mixed $value, string $field): array
    {
        $row = $this->object($value, $field);
        $result = [];
        foreach (self::METRICS as $metric) {
            $result[$metric] = $this->integer(
                $row[$metric] ?? null,
                "{$field}.{$metric}",
            );
        }
        return $result;
    }

    /** @return array<string,int> */
    private function zeroMetrics(): array
    {
        return array_fill_keys(self::METRICS, 0);
    }

    /**
     * @param array<string,int> $left
     * @param array<string,int> $right
     * @return array<string,int>
     */
    private function addMetrics(array $left, array $right): array
    {
        foreach (self::METRICS as $metric) {
            $left[$metric] = $this->add($left[$metric], $right[$metric]);
        }
        return $left;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "{$field} musí mít textové klíče.",
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
            throw new \DomainException("{$field} musí být seznam.");
        }
        return array_map(
            fn(mixed $row): array => $this->object($row, $field),
            $value,
        );
    }

    /**
     * Identita, ne částka. Vlastní hlášku má proto, že sdílená {@see integer()}
     * mluví o haléřích — u chybějícího `office_id` z toho vycházelo
     * „snapshot.employment.office_id musí být přesná částka v celých haléřích",
     * což nikomu neřeklo, že vztah nemá mzdovou účtárnu.
     */
    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \DomainException("{$field} musí být kladné celé číslo.");
        }
        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        $value = $this->integer($value, $field);
        if ($value < 0) {
            throw new \DomainException("{$field} nesmí být záporné.");
        }
        return $value;
    }

    private function integer(mixed $value, string $field): int
    {
        if (!is_int($value)) {
            throw new \DomainException(
                "{$field} musí být přesná částka v celých haléřích.",
            );
        }
        return $value;
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException('Kontrolní součet částek přetekl.');
        }
        return $left + $right;
    }

    private function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException('Kontrolní rozdíl částek přetekl.');
        }
        return $left - $right;
    }
}
