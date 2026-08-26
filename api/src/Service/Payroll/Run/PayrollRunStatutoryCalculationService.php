<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPayoutService;
use MyInvoice\Service\Payroll\Calculation\Money;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\Net\NetRelationshipIncome;
use MyInvoice\Service\Payroll\Net\PayrollDeductionRequest;
use MyInvoice\Service\Payroll\Net\PayrollNetCalculator;
use MyInvoice\Service\Payroll\Net\PayrollNetInputAssembler;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsCalculator;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsPolicy;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerCategoryResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthCalculator;

final class PayrollRunStatutoryCalculationService
{
    private readonly SocialInsuranceMonthCalculator $social;
    private readonly HealthInsuranceMonthCalculator $health;
    private readonly MonthlyEmploymentIncomeTaxCalculator $tax;
    private readonly PayrollNetInputAssembler $netInputs;
    private readonly PayrollNetCalculator $net;
    private readonly PayrollRiskySavingsCalculator $riskySavings;

    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollRunStatutoryInputAssembler $inputs,
        private readonly PayrollRunStatutoryResultPersister $persister,
        private readonly ?AnnualSettlementPayoutService $annualSettlements = null,
    ) {
        $this->social = new SocialInsuranceMonthCalculator($rulesets);
        $this->health = new HealthInsuranceMonthCalculator($rulesets);
        $this->tax = new MonthlyEmploymentIncomeTaxCalculator($rulesets);
        $this->netInputs = new PayrollNetInputAssembler();
        $this->net = new PayrollNetCalculator();
        $this->riskySavings = new PayrollRiskySavingsCalculator(
            new PayrollRiskySavingsPolicy(),
        );
    }

    /**
     * `$voluntaryDeductionCapacities` dostane čistou mzdu osoby PŘED dobrovolnými
     * srážkami a vrátí, kolik z ní smí dohoda o srážkách ukousnout. Pořadí
     * podle § 148 zákoníku práce je tím dané: zákonné odvody a daň → čistá mzda
     * → exekuční a insolvenční srážky → teprve zbytek dobrovolným dohodám.
     * Bez resolveru je kapacita nulová — fail-closed, nikdy „vezmi si vše".
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $baseResult
     * @param (callable(array<int,int>):array<int,int>)|null $voluntaryDeductionCapacities
     * @return array<string,mixed>
     */
    public function calculateAndPersist(
        int $supplierId,
        int $revisionId,
        ?int $actorUserId,
        array $snapshot,
        array $baseResult,
        ?callable $voluntaryDeductionCapacities = null,
    ): array {
        $period = self::object($snapshot['statutory_period'] ?? null, 'statutory_period');
        $inactive = [];
        foreach ([
            [
                PayrollRulesetDomain::SocialInsurance,
                self::string($period, 'social_calculation_date'),
            ],
            [
                PayrollRulesetDomain::HealthInsurance,
                self::string($period, 'health_calculation_date'),
            ],
            [
                PayrollRulesetDomain::IncomeTax,
                self::string($period, 'tax_calculation_date'),
            ],
        ] as [$domain, $date]) {
            $ruleset = $this->rulesets->forDate($domain, $date);
            if ($ruleset->lifecycle !== PayrollRulesetLifecycle::Active) {
                $inactive[] = "ruleset_not_active:{$domain->value}:{$ruleset->lifecycle->value}";
            }
        }
        if ($inactive !== []) {
            sort($inactive, SORT_STRING);
            return $this->blockedEnvelope($inactive);
        }

        $periodStart = self::string($period, 'period_start');
        // § 38ch odst. 5 a § 35d odst. 8: doplatky ze zúčtování, které se
        // vyplácejí s touhle mzdou. Nejsou vstupem výpočtu — nevstupují do
        // žádného základu — ale musí být v běhu, jinak by se přeplatek
        // zaměstnanci nikdy nevrátil a odvod záloh by se o něj nesnížil.
        $annualSettlements = $this->annualSettlements?->payoutsForRevision(
            $supplierId,
            $revisionId,
            $periodStart,
        ) ?? [];

        $bundle = $this->inputs->assemble($snapshot);
        if ($bundle->issues !== []
            || $bundle->socialInsurance === null
            || $bundle->healthInsurance === null
            || $bundle->incomeTax === []
        ) {
            $issues = array_map(
                static fn (PayrollRunStatutoryInputIssue $issue): string =>
                    implode(':', array_filter([
                        $issue->domain,
                        $issue->code,
                        $issue->personReference,
                        $issue->relationshipReference,
                    ], static fn (?string $value): bool => $value !== null)),
                $bundle->issues,
            );
            return $this->blockedEnvelope(
                $issues === [] ? ['statutory_input_incomplete'] : $issues,
            );
        }

        $social = $this->social->calculate($bundle->socialInsurance);
        $riskySavings = $this->riskySavingsResults(
            $snapshot,
            $social,
            $periodStart,
        );
        $health = $this->health->calculate($bundle->healthInsurance);
        $taxByEmployee = [];
        foreach ($bundle->incomeTax as $taxInput) {
            $employeeId = self::referenceId($taxInput->employeeReference, 'employee');
            $taxByEmployee[$employeeId] = $this->tax->calculate($taxInput);
        }

        $socialPeople = [];
        foreach ($social->people as $person) {
            $socialPeople[self::referenceId($person->personId, 'employee')] = $person;
        }
        $healthPeople = [];
        foreach ($health->people as $person) {
            $healthPeople[self::referenceId($person->personId, 'employee')] = $person;
        }
        $resultPeople = self::rows($baseResult['people'] ?? null, 'result.people');
        $resultByEmployee = [];
        foreach ($resultPeople as $person) {
            $resultByEmployee[self::positiveInt($person, 'employee_id')] = $person;
        }

        $netByEmployee = [];
        $prepared = [];
        $netBeforeDeductions = [];
        foreach (self::rows($snapshot['people'] ?? null, 'snapshot.people') as $person) {
            $employee = self::object($person['employee'] ?? null, 'employee');
            $employeeId = self::positiveInt($employee, 'id');
            $reference = "employee:{$employeeId}";
            $socialPerson = $socialPeople[$employeeId] ?? null;
            $healthPerson = $healthPeople[$employeeId] ?? null;
            $taxPerson = $taxByEmployee[$employeeId] ?? null;
            if ($socialPerson === null || $healthPerson === null || $taxPerson === null
                || $socialPerson->status !== SocialCalculationStatus::Calculated
                || $healthPerson->status !== HealthCalculationStatus::Calculated
                || $taxPerson->status !== TaxCalculationStatus::Calculated
            ) {
                $netByEmployee[$employeeId] = new PayrollStatutoryBlockedPerson(
                    $reference,
                    'manual_review',
                    ['insurance_or_tax_result_requires_manual_review'],
                );
                continue;
            }
            $calculatedPerson = $resultByEmployee[$employeeId]
                ?? throw new \DomainException("Chybí základ výpočtu {$reference}.");
            $relationships = [];
            foreach (self::rows(
                $calculatedPerson['employments'] ?? null,
                'result.employments',
            ) as $employment) {
                $employmentId = self::positiveInt($employment, 'employment_id');
                $totals = self::object($employment['totals'] ?? null, 'employment.totals');
                $cash = self::nonNegativeInt($totals, 'cash_payable_minor');
                $source = self::nonNegativeInt($totals, 'source_amount_minor');
                if ($source < $cash) {
                    throw new \DomainException(
                        "Vztah employment:{$employmentId} má rozporný peněžní příjem.",
                    );
                }
                $relationships[] = new NetRelationshipIncome(
                    "employment:{$employmentId}",
                    $cash,
                    $source - $cash,
                );
            }
            $advanceTax = $taxPerson->advanceTax;
            $netBeforeDeductions[$employeeId] = $this->netBeforeDeductions(
                $relationships,
                $socialPerson->employeeContributionMinorUnits ?? 0,
                $healthPerson->employeeContributionMinorUnits ?? 0,
                $advanceTax === null ? 0 : $advanceTax->taxAfterCreditsMinorUnits,
                $taxPerson->withholdingTaxMinorUnits,
                $advanceTax === null ? 0 : $advanceTax->taxBonusMinorUnits,
            );
            $prepared[$employeeId] = [
                'reference' => $reference,
                'relationships' => $relationships,
                'social' => $socialPerson,
                'health' => $healthPerson,
                'tax' => $taxPerson,
                'deductions' => $this->deductions($person),
            ];
        }

        $capacities = $voluntaryDeductionCapacities === null || $prepared === []
            ? []
            : $voluntaryDeductionCapacities($netBeforeDeductions);
        foreach ($prepared as $employeeId => $entry) {
            $capacity = $capacities[$employeeId] ?? 0;
            if (!is_int($capacity) || $capacity < 0) {
                throw new \DomainException(
                    "Kapacita dobrovolných srážek {$entry['reference']} není platná.",
                );
            }
            $netResult = $this->net->calculate(
                $this->netInputs->assemble(
                    $entry['reference'],
                    $entry['relationships'],
                    $entry['social'],
                    $entry['health'],
                    $entry['tax'],
                    0,
                    min($capacity, $netBeforeDeductions[$employeeId]),
                    $entry['deductions'],
                    $annualSettlements[$employeeId] ?? 0,
                ),
            );
            if ($netResult->netBeforeDeductionsMinorUnits
                !== $netBeforeDeductions[$employeeId]
            ) {
                throw new \DomainException(
                    "Základ exekuční srážky {$entry['reference']} neodpovídá čisté mzdě.",
                );
            }
            $netByEmployee[$employeeId] = $netResult;
        }
        ksort($taxByEmployee, SORT_NUMERIC);
        ksort($netByEmployee, SORT_NUMERIC);

        $ids = $this->persister->persist(
            $supplierId,
            $revisionId,
            $actorUserId,
            $snapshot,
            $social,
            $health,
            $taxByEmployee,
            $netByEmployee,
        );
        // Vazba se zapisuje až po uložení výsledku a jen na to, co se do
        // výsledku opravdu dostalo. Osoba, která z běhu vypadla na ruční
        // kontrolu, nic vyplacené nemá.
        $this->annualSettlements?->recordPayouts(
            $supplierId,
            $revisionId,
            $periodStart,
            array_intersect_key(
                $annualSettlements,
                array_filter(
                    $netByEmployee,
                    static fn ($result): bool
                        => !$result instanceof PayrollStatutoryBlockedPerson,
                ),
            ),
        );
        $status = $social->status === SocialCalculationStatus::Calculated
            && $health->status === HealthCalculationStatus::Calculated
            && array_reduce(
                $taxByEmployee,
                static fn (bool $ok, $result): bool =>
                    $ok && $result->status === TaxCalculationStatus::Calculated,
                true,
            )
            && array_reduce(
                $netByEmployee,
                static fn (bool $ok, $result): bool =>
                    $ok && !$result instanceof PayrollStatutoryBlockedPerson,
                true,
            )
            && array_reduce(
                $riskySavings,
                static fn (bool $ok, array $result): bool =>
                    $ok && ($result['status'] ?? null) !== 'manual_review',
                true,
            )
            ? 'calculated'
            : 'manual_review';
        $people = [];
        foreach ($netByEmployee as $employeeId => $netResult) {
            $personStatus = $netResult instanceof PayrollStatutoryBlockedPerson
                ? $netResult->status
                : 'calculated';
            $people[] = [
                'person_reference' => "employee:{$employeeId}",
                'status' => $personStatus,
                'social_insurance' => $socialPeople[$employeeId]->jsonSerialize(),
                'health_insurance' => $healthPeople[$employeeId]->jsonSerialize(),
                'income_tax' => $taxByEmployee[$employeeId]->jsonSerialize(),
                'net_pay' => $netResult->jsonSerialize(),
                'net_payable_minor_units' =>
                    $netResult instanceof PayrollStatutoryBlockedPerson
                        ? null
                        : $netResult->netPayableMinorUnits,
            ];
        }

        return [
            'schema_version' => 'payroll-run-statutory-result.v1',
            'status' => $status,
            'issues' => [],
            'employer_social_before_discount_minor_units' =>
                $status === 'calculated'
                    ? ($social->employerContributionBeforeDiscountMinorUnits
                        ?? throw new \LogicException(
                            'Vypočtený zákonný výsledek nemá pojistné před slevou.',
                        ))
                    : null,
            'employer_social_part_time_discount_minor_units' =>
                $status === 'calculated'
                    ? ($social->partTimeDiscountMinorUnits
                        ?? throw new \LogicException(
                            'Vypočtený zákonný výsledek nemá slevu zaměstnavatele.',
                        ))
                    : null,
            'employer_social_minor_units' => $status === 'calculated'
                ? ($social->employerContributionMinorUnits
                    ?? throw new \LogicException(
                        'Vypočtený zákonný výsledek nemá pojistné zaměstnavatele.',
                    ))
                : null,
            /*
             * Rozpad podle § 5a odst. 1 písm. a) až c). Výplatní páska z něj
             * rozděluje firemní částku na osoby a dělit smí jen UVNITŘ
             * kategorie — každá vznikla jinou sazbou podle § 7 odst. 1.
             */
            'employer_social_categories' => $status === 'calculated'
                ? array_map(
                    static fn (SocialEmployerCategoryResult $category): array =>
                        $category->jsonSerialize(),
                    $social->employerCategories,
                )
                : [],
            'result_set_ids' => $ids,
            'risky_savings_period_start' => $periodStart,
            'risky_savings' => $riskySavings,
            'people' => $people,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return list<array<string,mixed>>
     */
    private function riskySavingsResults(
        array $snapshot,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthResult $social,
        string $periodStart,
    ): array {
        $bases = [];
        foreach ($social->people as $person) {
            foreach ($person->relationships as $relationship) {
                $employmentId = self::referenceId(
                    $relationship->relationshipId,
                    'employment',
                );
                $bases[$employmentId] = $relationship->assessmentBaseMinorUnits;
            }
        }
        $results = [];
        foreach (self::rows($snapshot['people'] ?? null, 'snapshot.people') as $person) {
            foreach (self::rows(
                $person['employments'] ?? null,
                'snapshot.employments',
            ) as $employmentSnapshot) {
                $evidence = $employmentSnapshot['risky_savings_evidence'] ?? null;
                if ($evidence === null) {
                    continue;
                }
                $employment = self::object(
                    $employmentSnapshot['employment'] ?? null,
                    'snapshot.employment',
                );
                $employmentId = self::positiveInt($employment, 'id');
                if (!is_array($evidence) || array_is_list($evidence)
                    || !array_key_exists($employmentId, $bases)
                ) {
                    $results[] = [
                        'employment_id' => $employmentId,
                        'status' => 'manual_review',
                        'issues' => ['risky_savings_assessment_base_missing'],
                        'assessment_base_minor' => null,
                        'contribution_minor' => null,
                    ];
                    continue;
                }
                $results[] = $this->riskySavings->calculate(
                    $employmentId,
                    $periodStart,
                    $bases[$employmentId],
                    $evidence,
                );
            }
        }
        usort(
            $results,
            static fn (array $left, array $right): int =>
                (int) $left['employment_id'] <=> (int) $right['employment_id'],
        );
        return $results;
    }

    /**
     * @param array<string,mixed> $person
     * @return list<PayrollDeductionRequest>
     */
    private function deductions(array $person): array
    {
        $result = [];
        foreach (self::rows(
            $person['deduction_agreements'] ?? null,
            'deduction_agreements',
        ) as $agreement) {
            $total = self::nullableNonNegativeInt($agreement, 'total_limit_minor');
            $withheld = self::nonNegativeInt($agreement, 'withheld_total_minor');
            $remaining = $total === null ? null : max(0, $total - $withheld);
            $result[] = new PayrollDeductionRequest(
                'agreement:' . self::positiveInt($agreement, 'id'),
                self::nonNegativeInt($agreement, 'priority_no'),
                self::nonNegativeInt($agreement, 'requested_minor'),
                $remaining,
                true,
            );
        }
        return $result;
    }

    /** @param list<NetRelationshipIncome> $relationships */
    private function netBeforeDeductions(
        array $relationships,
        int $social,
        int $health,
        int $advanceTax,
        int $withholdingTax,
        int $taxBonus,
    ): int {
        $result = new Money(0);
        foreach ($relationships as $relationship) {
            $result = $result->add(new Money($relationship->cashIncomeMinorUnits));
        }
        $result = $result
            ->subtract(new Money($social))
            ->subtract(new Money($health))
            ->subtract(new Money($advanceTax))
            ->subtract(new Money($withholdingTax))
            ->add(new Money($taxBonus));
        if ($result->minorUnits < 0) {
            throw new \DomainException('Čistá mzda před srážkami nesmí být záporná.');
        }
        return $result->minorUnits;
    }

    /**
     * @param list<string> $issues
     * @return array<string,mixed>
     */
    private function blockedEnvelope(array $issues): array
    {
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);
        return [
            'schema_version' => 'payroll-run-statutory-result.v1',
            'status' => 'manual_review',
            'issues' => $issues,
            'employer_social_before_discount_minor_units' => null,
            'employer_social_part_time_discount_minor_units' => null,
            'employer_social_minor_units' => null,
            'employer_social_categories' => [],
            'result_set_ids' => [],
            'risky_savings_period_start' => null,
            'risky_savings' => [],
            'people' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        return $value;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        return array_map(
            static fn (mixed $row): array => self::object($row, $field),
            $value,
        );
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("{$field} musí být text.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = self::nonNegativeInt($row, $field);
        if ($value === 0) {
            throw new \UnexpectedValueException("{$field} musí být kladné.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException("{$field} musí být nezáporné celé číslo.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableNonNegativeInt(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null
            ? null
            : self::nonNegativeInt($row, $field);
    }

    private static function referenceId(string $reference, string $kind): int
    {
        if (preg_match("/^{$kind}:([1-9][0-9]*)$/D", $reference, $match) !== 1) {
            throw new \UnexpectedValueException("Reference {$reference} není platná.");
        }
        return (int) $match[1];
    }
}
