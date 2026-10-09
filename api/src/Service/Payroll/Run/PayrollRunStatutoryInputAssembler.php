<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Absence\PayrollObstacleKind;
use MyInvoice\Service\Payroll\Absence\VacationCompensationReturn;
use MyInvoice\Service\Payroll\HealthInsurance\HealthIncomeAttribution;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionInterval;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionReason;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibilitySource;
use MyInvoice\Service\Payroll\HealthInsurance\HealthOtherEmployerBase;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthRelationshipKindMapper;
use MyInvoice\Service\Payroll\IncomeTax\AnnualTaxAccumulatorInput;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKindMapper;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialA1Coverage;
use MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationAggregationGroup;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountAgeCondition;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipKindMapper;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipKindMapping;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsPolicy;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsRules;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojClaimDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojDiscountEligibility;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentEvidence;

final class PayrollRunStatutoryInputAssembler
{
    /** @var list<PayrollRunStatutoryInputIssue> */
    private array $issues = [];

    private readonly PayrollRunStatutoryComponentMapper $components;
    private readonly SocialRelationshipKindMapper $socialKinds;
    private readonly HealthRelationshipKindMapper $healthKinds;
    private readonly EmploymentRelationshipKindMapper $taxKinds;
    private readonly OzuspojDiscountEligibility $discountEligibility;
    private readonly PayrollRiskySavingsPolicy $riskySavingsPolicy;

    public function __construct(
        ?PayrollRunStatutoryComponentMapper $components = null,
        ?SocialRelationshipKindMapper $socialKinds = null,
        ?HealthRelationshipKindMapper $healthKinds = null,
        ?EmploymentRelationshipKindMapper $taxKinds = null,
        ?OzuspojDiscountEligibility $discountEligibility = null,
    ) {
        $this->components = $components ?? new PayrollRunStatutoryComponentMapper();
        $this->socialKinds = $socialKinds ?? new SocialRelationshipKindMapper();
        $this->healthKinds = $healthKinds ?? new HealthRelationshipKindMapper();
        $this->taxKinds = $taxKinds ?? new EmploymentRelationshipKindMapper();
        $this->discountEligibility = $discountEligibility
            ?? new OzuspojDiscountEligibility(new OzuspojClaimDeadlinePolicy());
        $this->riskySavingsPolicy = new PayrollRiskySavingsPolicy();
    }

    /** @param array<string,mixed> $snapshot */
    public function assemble(array $snapshot): PayrollRunStatutoryInputBundle
    {
        $this->issues = [];
        if (($snapshot['schema_version'] ?? null) !== 'payroll-run-input.v2') {
            return $this->invalidSnapshot('unsupported_snapshot_schema');
        }
        $supplierId = $this->positiveInt($snapshot['supplier_id'] ?? null);
        $periodStart = $this->date($snapshot['period_start'] ?? null);
        $periodEnd = $this->date($snapshot['period_end'] ?? null);
        $statutoryPeriod = $this->object($snapshot['statutory_period'] ?? null);
        $taxDate = $this->date($statutoryPeriod['tax_calculation_date'] ?? null);
        $socialDate = $this->date($statutoryPeriod['social_calculation_date'] ?? null);
        $healthDate = $this->date($statutoryPeriod['health_calculation_date'] ?? null);
        $riskySavingsRuleset = $this->object($snapshot['risky_savings_ruleset'] ?? null);
        $people = $this->list($snapshot['people'] ?? null);
        if ($supplierId === null
            || $periodStart === null
            || $periodEnd === null
            || $taxDate === null
            || $socialDate === null
            || $healthDate === null
            || $people === null
        ) {
            return $this->invalidSnapshot('snapshot_shape_invalid');
        }

        usort(
            $people,
            static fn (mixed $left, mixed $right): int =>
                ((int) ($left['employee']['id'] ?? 0))
                <=> ((int) ($right['employee']['id'] ?? 0)),
        );
        $socialPeople = [];
        $healthPeople = [];
        $incomeTax = [];
        $seenEmployeeIds = [];
        $seenEmploymentIds = [];
        foreach ($people as $person) {
            if (!is_array($person) || array_is_list($person)) {
                $this->issue('snapshot', 'person_shape_invalid');
                continue;
            }
            $employee = $this->object($person['employee'] ?? null);
            $employeeId = $this->positiveInt($employee['id'] ?? null);
            if ($employeeId === null) {
                $this->issue('snapshot', 'employee_reference_invalid');
                continue;
            }
            $personReference = "employee:{$employeeId}";
            if (isset($seenEmployeeIds[$employeeId])) {
                $this->issue(
                    'snapshot',
                    'duplicate_employee_reference',
                    $personReference,
                );
                continue;
            }
            $seenEmployeeIds[$employeeId] = true;
            $evidence = $this->object($person['statutory_evidence'] ?? null);
            if ($evidence === null
                || ($evidence['schema_version'] ?? null)
                    !== 'payroll-person-statutory-evidence.v1'
                || ($evidence['employee_id'] ?? null) !== $employeeId
                || ($evidence['effective_on'] ?? null) !== $taxDate
            ) {
                foreach (
                    ['social_insurance', 'health_insurance', 'income_tax'] as $domain
                ) {
                    $this->issue(
                        $domain,
                        'statutory_evidence_snapshot_missing_or_mismatched',
                        $personReference,
                    );
                }
                continue;
            }
            $employments = $this->list($person['employments'] ?? null);
            if ($employments === null || $employments === []) {
                foreach (
                    ['social_insurance', 'health_insurance', 'income_tax'] as $domain
                ) {
                    $this->issue(
                        $domain,
                        'employment_relationship_missing',
                        $personReference,
                    );
                }
                continue;
            }
            usort(
                $employments,
                static fn (mixed $left, mixed $right): int =>
                    ((int) ($left['employment']['id'] ?? 0))
                    <=> ((int) ($right['employment']['id'] ?? 0)),
            );
            $hasDuplicateEmployment = false;
            foreach ($employments as $employmentRow) {
                if (!is_array($employmentRow) || array_is_list($employmentRow)) {
                    continue;
                }
                $employment = $this->object($employmentRow['employment'] ?? null);
                $employmentId = $this->positiveInt($employment['id'] ?? null);
                if ($employmentId === null) {
                    continue;
                }
                $relationshipReference = "employment:{$employmentId}";
                if (isset($seenEmploymentIds[$employmentId])) {
                    $this->issue(
                        'snapshot',
                        'duplicate_employment_reference',
                        $personReference,
                        $relationshipReference,
                    );
                    $hasDuplicateEmployment = true;
                    continue;
                }
                $seenEmploymentIds[$employmentId] = true;
            }
            if ($hasDuplicateEmployment) {
                continue;
            }

            $social = $this->socialPerson(
                $person,
                $evidence,
                $employments,
                $supplierId,
                $employeeId,
                $personReference,
                $periodStart,
                $periodEnd,
                $riskySavingsRuleset,
            );
            if ($social !== null) {
                $socialPeople[$employeeId] = $social;
            }

            $health = $this->healthPerson(
                $evidence,
                $employments,
                $personReference,
                $periodStart,
                $periodEnd,
            );
            if ($health !== null) {
                $healthPeople[$employeeId] = $health;
            }

            $tax = $this->incomeTaxPerson(
                $person,
                $evidence,
                $employments,
                $supplierId,
                $employeeId,
                $personReference,
                $periodStart,
                $taxDate,
            );
            if ($tax !== null) {
                $incomeTax[$employeeId] = $tax;
            }
        }

        if ($people === []) {
            foreach (
                ['social_insurance', 'health_insurance', 'income_tax'] as $domain
            ) {
                $this->issue($domain, 'person_missing');
            }
        }

        /*
         * Osobní problém vyřadí jen svou osobu, a to ze VŠECH tří vstupů:
         * čistá mzda potřebuje pojistné i daň současně, takže osoba spočítaná
         * v jedné doméně a chybějící v jiné by výsledek stejně neměla.
         *
         * Do 13. 9. 2026 shodil jediný osobní problém celou doménu a s ní celý
         * zákonný výpočet. Běh s 225 lidmi, z nichž nikdo neměl zákonnou
         * evidenci, tak účetní ukázal 1 125 blokujících řádků a nikomu nic
         * nespočítal — skutečnou výjimku by mezi nimi nenašla. Firemní souhrny
         * (odvody, JMHZ, přehledy, závazky) i schválení běhu dál vyžadují úplnost;
         * hlídá to kořenový stav výsledku, viz PayrollRunStatutoryCalculationService.
         *
         * Globální problém ({@see PayrollRunStatutoryInputIssue::isGlobal()})
         * dál blokuje všechny — nevíme u něj, komu výsledek patří.
         */
        $global = false;
        $blockedPeople = [];
        foreach ($this->issues as $issue) {
            if ($issue->isGlobal()) {
                $global = true;
                continue;
            }
            $blockedPeople[$this->personId((string) $issue->personReference)] = true;
        }
        if (!$global) {
            foreach (array_keys($seenEmployeeIds) as $employeeId) {
                if (isset($blockedPeople[$employeeId])) {
                    continue;
                }
                // Pojistka invariantu „osoba je buď ve všech třech vstupech,
                // nebo vyřazená s důvodem". Vstup bez vlastního problému chybět
                // nemá; kdyby přesto chyběl, osoba nesmí tiše vypadnout z běhu.
                foreach ([
                    'social_insurance' => $socialPeople,
                    'health_insurance' => $healthPeople,
                    'income_tax' => $incomeTax,
                ] as $domain => $inputs) {
                    if (!isset($inputs[$employeeId])) {
                        $this->issue(
                            $domain,
                            'statutory_input_incomplete',
                            "employee:{$employeeId}",
                        );
                    }
                }
            }
        }
        $this->sortAndDeduplicateIssues();

        $blockedPeople = [];
        foreach ($this->issues as $issue) {
            if (!$issue->isGlobal()) {
                $blockedPeople[$this->personId((string) $issue->personReference)][] = $issue;
            }
        }
        ksort($blockedPeople, SORT_NUMERIC);
        if ($global) {
            return new PayrollRunStatutoryInputBundle(
                null,
                null,
                [],
                $this->issues,
                $blockedPeople,
            );
        }
        $socialPeople = array_values(array_diff_key($socialPeople, $blockedPeople));
        $healthPeople = array_values(array_diff_key($healthPeople, $blockedPeople));
        $incomeTax = array_values(array_diff_key($incomeTax, $blockedPeople));

        return new PayrollRunStatutoryInputBundle(
            $socialPeople === []
                ? null
                : new SocialInsuranceMonthInput($socialDate, $socialPeople),
            $healthPeople === []
                ? null
                : new HealthInsuranceMonthInput($healthDate, $healthPeople),
            $incomeTax,
            $this->issues,
            $blockedPeople,
        );
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $evidence
     * @param list<mixed> $employments
     */
    private function socialPerson(
        array $person,
        array $evidence,
        array $employments,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
        string $periodEnd,
        ?array $riskySavingsRuleset,
    ): ?SocialPersonMonthInput {
        /*
         * Příslušnost a slevu důchodce hlásí obě, i když chybí obě naráz. Dřív
         * chybějící příslušnost vrátila `null` hned, takže sleva se nehlásila:
         * účetní doplnila příslušnost, přepočítala a teprve pak se dozvěděla
         * o druhé chybějící evidenci. Editor evidence
         * (PayrollPersonStatutoryEvidenceRepository::blockers) je hlásí obě
         * odjakživa, takže stránka a výpočet si odporovaly.
         */
        $socialEvidence = $this->object($evidence['social'] ?? null);
        $jurisdictionRow = $this->object($socialEvidence['jurisdiction'] ?? null);
        $jurisdiction = null;
        if ($jurisdictionRow === null) {
            $this->issue(
                'social_insurance',
                'social_jurisdiction_evidence_missing',
                $personReference,
            );
        } else {
            $jurisdiction = $this->enum(
                SocialJurisdictionEvidence::class,
                $jurisdictionRow['jurisdiction'] ?? null,
            );
            if (!$jurisdiction instanceof SocialJurisdictionEvidence) {
                $this->issue(
                    'social_insurance',
                    'social_jurisdiction_evidence_invalid',
                    $personReference,
                );
                $jurisdiction = null;
            }
        }
        if ($jurisdiction === SocialJurisdictionEvidence::Unverified) {
            $this->issue(
                'social_insurance',
                'social_jurisdiction_evidence_unverified',
                $personReference,
            );
        }
        if ($jurisdiction === SocialJurisdictionEvidence::ForeignRegimeVerified
            && ($jurisdictionRow['a1_status'] ?? null) !== 'verified'
        ) {
            $this->issue(
                'social_insurance',
                'social_a1_evidence_unverified',
                $personReference,
            );
        }
        if ($jurisdiction === SocialJurisdictionEvidence::CzechRegimeVerified
            && ($jurisdictionRow['a1_status'] ?? null) !== 'not_applicable'
        ) {
            $this->issue(
                'social_insurance',
                'social_a1_evidence_conflict',
                $personReference,
            );
        }
        if ($jurisdiction === SocialJurisdictionEvidence::ForeignRegimeVerified
            && ($jurisdictionRow['a1_status'] ?? null) === 'verified'
            && !SocialA1Coverage::coversMonth($jurisdictionRow, $periodEnd)
        ) {
            /*
             * Cizí právní předpisy platí jen po dobu platnosti A1 (čl. 19
             * nařízení 987/2009). Skončí-li A1 v měsíci a věta příslušnosti
             * pokračuje, dny po jeho konci by se nulové pojistné spočítalo bez
             * dokladu. Dřív tenhle stav shodil celý snímek běhu výjimkou
             * validátoru evidence; teď zastaví jen dotčenou osobu.
             */
            $this->issue(
                'social_insurance',
                'social_a1_expired',
                $personReference,
            );
        }
        if ($jurisdiction !== null && $jurisdictionRow !== null) {
            $this->assertTermLegislationMatches($jurisdiction, $jurisdictionRow, $employments, $personReference);
        }

        /*
         * Slevu pracujícího důchodce (§ 7d zákona č. 589/1992 Sb.) uplatňuje
         * zaměstnanec u zaměstnavatele sám; bez záznamu tedy není uplatněná.
         * Aplikace nevede údaj „pobírá starobní důchod", takže nevyplněná
         * evidence nesmí posílat každého zaměstnance do ručního posouzení.
         * Stejné pravidlo drží editor evidence
         * ({@see PayrollPersonStatutoryEvidenceRepository::blockers()}).
         */
        $discountRow = $this->object(
            $socialEvidence['working_pensioner_discount'] ?? null,
        ) ?? ['status' => SocialDiscountEvidence::NotClaimed->value];
        $discount = $this->enum(
            SocialDiscountEvidence::class,
            $discountRow['status'] ?? null,
        );
        if (!$discount instanceof SocialDiscountEvidence) {
            $this->issue(
                'social_insurance',
                'working_pensioner_discount_evidence_invalid',
                $personReference,
            );
            return null;
        }
        if ($discount === SocialDiscountEvidence::Unverified) {
            $this->issue(
                'social_insurance',
                'working_pensioner_discount_evidence_unverified',
                $personReference,
            );
        }
        if ($jurisdiction === null) {
            return null;
        }

        $yearToDate = $this->socialAccumulator(
            $person,
            $supplierId,
            $employeeId,
            $personReference,
            $periodStart,
        );
        $relationships = [];
        foreach ($employments as $employmentSnapshot) {
            $relationship = $this->socialRelationship(
                $employmentSnapshot,
                $personReference,
                $periodStart,
                $periodEnd,
                $riskySavingsRuleset,
            );
            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }
        if ($yearToDate === null || $relationships === []) {
            return null;
        }

        try {
            return new SocialPersonMonthInput(
                $personReference,
                $jurisdiction,
                $yearToDate,
                $relationships,
                $discount,
                $jurisdiction === SocialJurisdictionEvidence::ForeignRegimeVerified
                    ? $this->nullableString(
                        $jurisdictionRow['jurisdiction_evidence_reference'] ?? null,
                    )
                    : null,
                $discount === SocialDiscountEvidence::Verified
                    ? $this->nullableString(
                        $discountRow['evidence_reference'] ?? null,
                    )
                    : null,
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'social_insurance',
                'social_evidence_mapping_failed',
                $personReference,
            );
            return null;
        }
    }

    /** @param mixed $snapshot */
    private function socialRelationship(
        mixed $snapshot,
        string $personReference,
        string $periodStart,
        string $periodEnd,
        ?array $riskySavingsRuleset,
    ): ?SocialInsuranceRelationshipInput {
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            $this->issue(
                'social_insurance',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employment = $this->object($snapshot['employment'] ?? null);
        if ($employment === null) {
            $this->issue(
                'social_insurance',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employmentId = $this->positiveInt($employment['id'] ?? null);
        if ($employmentId === null) {
            $this->issue(
                'social_insurance',
                'employment_reference_invalid',
                $personReference,
            );
            return null;
        }
        $relationshipReference = "employment:{$employmentId}";
        if (($employment['employee_id'] ?? null)
            !== $this->personId($personReference)
        ) {
            $this->issue(
                'social_insurance',
                'employment_person_mismatch',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $term = $this->object($snapshot['term'] ?? null);
        if ($term === null) {
            $this->issue(
                'social_insurance',
                'employment_term_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        if (($term['social_insurance_participation'] ?? null) !== 'automatic') {
            $this->issue(
                'social_insurance',
                'participation_override_unsupported',
                $personReference,
                $relationshipReference,
            );
        }
        $relationType = $this->nonEmptyString($employment['relation_type'] ?? null);
        if ($relationType === null) {
            $this->issue(
                'social_insurance',
                'relationship_kind_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        try {
            $mapping = $this->socialKinds->fromRelationType($relationType);
            if (PayrollEmploymentJmhzActivityFamily::isOutsideStatutoryInsurance($term['activity_code'] ?? null)) {
                $mapping = new SocialRelationshipKindMapping(
                    $mapping->kind,
                    SocialParticipationAggregationGroup::OutsideInsurance,
                );
            }
        } catch (\InvalidArgumentException) {
            $this->issue(
                'social_insurance',
                'relationship_kind_unsupported',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $dates = $this->employmentDates($employment);
        if ($dates === null) {
            $this->issue(
                'social_insurance',
                'employment_dates_invalid',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        [$employmentFrom, $employmentTo] = $dates;
        $active = $employmentFrom <= $periodEnd
            && ($employmentTo === null || $employmentTo >= $periodStart);
        $attribution = SocialIncomeAttribution::CurrentEmploymentMonth;
        if (!$active) {
            if ($employmentTo !== null
                && substr($employmentTo, 0, 7) === substr($periodStart, 0, 7)
            ) {
                $attribution =
                    SocialIncomeAttribution::PostTerminationEndMonthVerified;
            } elseif ($employmentTo !== null
                && $employmentTo < $periodStart
                && $mapping->aggregationGroup
                    === SocialParticipationAggregationGroup::RegularRelationship
                && self::deferredIncomeType($snapshot) === '1'
            ) {
                /*
                 * Odložený příjem typu 1 potvrzený účetní (JMHZ scénář 8):
                 * pravidla podání JMHZ, kap. 6 bod 1: pojistné se platí za
                 * měsíc, kdy byl příjem zúčtován. U pracovního poměru účast
                 * na výši příjmu nestojí, takže se nic zpětně nezakládá.
                 */
                $attribution =
                    SocialIncomeAttribution::PostTerminationPaymentMonthVerified;
            } elseif ($employmentTo !== null
                && $employmentTo < $periodStart
                && self::deferredIncomeType($snapshot) === '1'
            ) {
                /*
                 * Tentýž odložený příjem u vztahu, jehož účast stojí na výši
                 * příjmu (zaměstnání malého rozsahu, DPČ, DPP, člen orgánu). Pravidla podání
                 * JMHZ 1.4.5, kap. 6 bod 1: v součtu s příjmem posledního měsíce
                 * výkonu může zpětně založit účast, a pak je nutná oprava
                 * hlášení za ten měsíc (10356, 10245); opravu kvůli 10476 je
                 * třeba zaslat vždy. Tu aplikace nesestaví, proto se měsíc
                 * zastaví s pokynem podat hlášení i opravu ručně. Obecná výzva
                 * „potvrďte odložený příjem" by tu jen vedla dokola.
                 */
                $this->issue(
                    'social_insurance',
                    'post_termination_deferred_income_retroactive_participation_unsupported',
                    $personReference,
                    $relationshipReference,
                );
                $attribution = SocialIncomeAttribution::Unverified;
            } else {
                $this->issue(
                    'social_insurance',
                    'post_termination_income_attribution_unverified',
                    $personReference,
                    $relationshipReference,
                );
                $attribution = SocialIncomeAttribution::Unverified;
            }
        }
        $components = $this->socialComponents(
            $snapshot['inputs'] ?? null,
            $personReference,
            $relationshipReference,
            $periodStart,
            $snapshot['absences'] ?? null,
        );
        if ($components === [] && !$this->monthWithoutInputsExplained($snapshot['absences'] ?? null)) {
            return null;
        }

        [$rateCategory, $rateCategoryEvidence] = $this->socialEmployerRateCategory($term);
        $riskySavingsEvidence = $this->object(
            $snapshot['risky_savings_evidence'] ?? null,
        );
        if ($riskySavingsEvidence !== null) {
            if ($this->riskySavingsPolicy->issues(
                $riskySavingsEvidence,
                $periodStart,
            ) !== []) {
                $this->issue(
                    'social_insurance',
                    'risky_savings_evidence_invalid',
                    $personReference,
                    $relationshipReference,
                );
                $rateCategory = SocialEmployerRateCategory::Unverified;
                $rateCategoryEvidence = null;
            } else {
                $riskySavingsRules = null;
                try {
                    $riskySavingsRules = PayrollRiskySavingsRules::fromSnapshot(
                        $riskySavingsRuleset ?? [],
                    );
                } catch (\InvalidArgumentException | \OverflowException) {
                    $this->issue(
                        'social_insurance',
                        'risky_savings_ruleset_invalid',
                        $personReference,
                        $relationshipReference,
                    );
                    $rateCategory = SocialEmployerRateCategory::Unverified;
                    $rateCategoryEvidence = null;
                }
                if ($riskySavingsRules !== null && $this->riskySavingsPolicy->obligationArises(
                    $riskySavingsEvidence,
                    $periodStart,
                    $riskySavingsRules,
                )) {
                    // § 5a odst. 3 zákona č. 589/1992 Sb.: vznikne-li za měsíc
                    // povinný příspěvek, přednost má běžná sazba zaměstnavatele.
                    $rateCategory = SocialEmployerRateCategory::Ordinary;
                    // Běžná kategorie sama odkaz na kategorizaci nenese; původ
                    // přepnutí zůstává ve zmrazené evidenci povinného spoření.
                    $rateCategoryEvidence = null;
                }
            }
        }
        [$discountEvidence, $discountReason, $discountEvidenceReference] =
            $this->socialPartTimeDiscount(
                $term,
                $mapping->kind,
                $periodStart,
                $periodEnd,
                $employmentFrom,
                $employmentTo,
            );

        try {
            return new SocialInsuranceRelationshipInput(
                $relationshipReference,
                $mapping->kind,
                $this->nonNegativeInt($employment['monthly_gross_minor'] ?? null),
                $active,
                $attribution,
                $components,
                partTimeEmployerDiscount: $discountEvidence,
                employerRateCategory: $rateCategory,
                partTimeEmployerDiscountEvidenceReference: $discountEvidenceReference,
                participationAggregationGroup: $mapping->aggregationGroup,
                employerRateCategoryEvidenceReference: $rateCategoryEvidence,
                partTimeEmployerDiscountReason: $discountReason,
                partTimeDiscountAssessableMillihours:
                    $this->socialPartTimeDiscountHours($snapshot['time_month'] ?? null),
                partTimeDiscountEmploymentDays: $this->calendarDaysInPeriod(
                    $employmentFrom,
                    $employmentTo,
                    $periodStart,
                    $periodEnd,
                ),
                partTimeDiscountMonthDays: $this->calendarDaysInMonth($periodStart, $periodEnd),
                agreedWeeklyWorkingMillihours: $this->weeklyWorkingMillihours($term),
                // Klíč chybí ve zmrazených revizích starších než posouzení § 7a odst. 3
                // písm. d); ty se počítají jako dřív, tedy bez tohoto vyloučení.
                employerOnProtectedLaborMarket:
                    ($term['employer_protected_labor_market'] ?? false) === true,
                listedInPartialWorkOverview: self::partialWorkInPeriod(
                    $snapshot['absences'] ?? null,
                    $periodStart,
                    $periodEnd,
                ),
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'social_insurance',
                'relationship_mapping_failed',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
    }

    /**
     * Byl zaměstnanec v měsíci v částečné práci s příspěvkem? Překážka
     * `partial_work` (§ 120a a násl. zákona o zaměstnanosti) znamená, že ho
     * zaměstnavatel uvádí v měsíčním přehledu nákladů na náhrady mezd
     * (§ 120e odst. 5), a za takový měsíc mu sleva podle § 7a odst. 3
     * písm. e) zákona č. 589/1992 Sb. nenáleží.
     */
    private static function partialWorkInPeriod(
        mixed $absences,
        string $periodStart,
        string $periodEnd,
    ): bool {
        if (!is_array($absences) || !array_is_list($absences)) {
            return false;
        }
        foreach ($absences as $absence) {
            if (is_array($absence)
                && ($absence['absence_type'] ?? null) === PayrollObstacleKind::EMPLOYER_SIDE_TYPE
                && ($absence['obstacle_kind'] ?? null) === PayrollObstacleKind::PartialWork->value
                && is_string($absence['date_from'] ?? null)
                && is_string($absence['date_to'] ?? null)
                && $absence['date_from'] <= $periodEnd
                && $absence['date_to'] >= $periodStart
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sazbová kategorie zaměstnavatele podle § 5a odst. 1 a volitelný odkaz na podklad.
     *
     * Zmrazená revize starší než sloupec kategorie klíč vůbec nemá. Takový
     * snapshot se čte jako běžná sazba — přesně to, co se z něj počítalo
     * v době, kdy vznikl; dosadit dnešní fail-closed by přepsalo historii.
     * Hodnota, která JE, ale kategorii nepojmenovává, je naopak neznámé
     * zařazení a končí ručním posouzením.
     *
     * @param array<string,mixed> $term
     * @return array{0:SocialEmployerRateCategory,1:?string}
     */
    private function socialEmployerRateCategory(array $term): array
    {
        if (!array_key_exists('social_employer_rate_category', $term)) {
            return [SocialEmployerRateCategory::Ordinary, null];
        }
        $category = SocialEmployerRateCategory::tryFrom(
            is_string($term['social_employer_rate_category'] ?? null)
                ? $term['social_employer_rate_category']
                : '',
        );
        if ($category === null || $category === SocialEmployerRateCategory::Unverified) {
            return [SocialEmployerRateCategory::Unverified, null];
        }
        if ($category === SocialEmployerRateCategory::Ordinary) {
            return [$category, null];
        }
        $evidence = is_string($term['social_employer_rate_category_evidence'] ?? null)
            ? trim($term['social_employer_rate_category_evidence'])
            : '';
        return [$category, $evidence === '' ? null : $evidence];
    }

    /**
     * Nárok na slevu zaměstnavatele podle § 7a a jeho doložení.
     *
     * Sleva je výhoda ZAMĚSTNAVATELE: § 7c odst. 3 dělá z přeplacené slevy dluh
     * na pojistném, kdežto z neuplatněné žádný nedoplatek nevzniká. Fail-closed
     * proto míří na NEUPLATNĚNÍ — chybějící nebo pozdní oznámení ČSSZ i
     * nepodporovaný druh vztahu končí jako nedoložený nárok (ruční posouzení),
     * nikdy jako tichá uplatněná sleva. Textový odkaz na podklad je volitelný.
     *
     * § 7a odst. 5 podmiňuje nárok tím, že zaměstnavatel „nejpozději
     * s uplatněním této slevy oznámil České správě sociálního zabezpečení záměr
     * uplatňovat tuto slevu za tohoto zaměstnance; oznámením tohoto záměru se
     * rozumí okamžik jeho DORUČENÍ České správě sociálního zabezpečení".
     *
     * Do 18. 8. 2026 se tahle podmínka posuzovala z jediného ručně opsaného
     * data na kartě vztahu (`social_part_time_discount_notified_on`) a
     * porovnávala se s koncem období. Bylo to špatně ve dvou směrech naráz:
     *
     *   * PŘÍSNĚ — oznámení doručené 5. dne následujícího měsíce je podle
     *     § 7c odst. 2 pořád včas (sleva se uplatňuje až hlášením do splatnosti
     *     pojistného), ale porovnání s koncem období ho zahodilo;
     *   * BENEVOLENTNĚ — datum nikdo neověřoval a nešlo z něj poznat, NA JAKÉ
     *     OBDOBÍ je záměr oznámen ani jestli mezitím neskončil. Kontrola 291
     *     katalogu kontrol MH je přitom propustná, takže by se nesoulad projevil
     *     až protokolem, kdy je pojistné odvedené ponížené a § 7c odst. 3 z toho
     *     dělá dluh.
     *
     * Nárok se proto odvozuje z EVIDENCE ZÁMĚRŮ (podání OZUSPOJ, § 23e), která
     * drží den doručení odděleně od období platnosti a od stavu přijetí. Ručně
     * opsané datum už nárok nezakládá — zůstává jen ve zmrazených revizích,
     * které vznikly dřív.
     *
     * Zmrazená revize starší než sloupec důvodu klíč `social_part_time_discount_reason`
     * vůbec nemá — čte se jako neuplatněná sleva, přesně tak, jak se z ní tehdy
     * počítalo. Revize, která důvod má, ale klíč `social_part_time_discount_intent`
     * ne, pochází z doby před evidencí záměrů; přepočítat ji dnešním pravidlem
     * by přepsalo historii, takže si podrží tehdejší posouzení podle ručně
     * zadaného data.
     *
     * @param array<string,mixed> $term
     * @return array{0:SocialDiscountEvidence,1:?SocialPartTimeDiscountReason,2:?string}
     */
    private function socialPartTimeDiscount(
        array $term,
        SocialEmploymentKind $kind,
        string $periodStart,
        string $periodEnd,
        string $employmentFrom,
        ?string $employmentTo,
    ): array {
        $raw = is_string($term['social_part_time_discount_reason'] ?? null)
            ? $term['social_part_time_discount_reason']
            : 'none';
        if ($raw === 'none') {
            return [SocialDiscountEvidence::NotClaimed, null, null];
        }
        $reason = SocialPartTimeDiscountReason::tryFrom($raw);
        if ($reason === null || $kind !== SocialEmploymentKind::Employment) {
            return [SocialDiscountEvidence::Unverified, null, null];
        }
        $evidence = is_string($term['social_part_time_discount_evidence'] ?? null)
            ? trim($term['social_part_time_discount_evidence'])
            : '';
        $evidence = $evidence === '' ? null : $evidence;
        if (!array_key_exists('social_part_time_discount_intent', $term)) {
            return $this->legacySocialPartTimeDiscount(
                $term,
                $reason,
                $evidence,
                $periodEnd,
            );
        }
        $intent = OzuspojIntentEvidence::fromRow(
            $this->object($term['social_part_time_discount_intent'] ?? null) ?? [],
        );
        $verdict = $this->discountEligibility->assess(
            $intent,
            $periodStart,
            $periodEnd,
            $employmentFrom,
            $employmentTo,
        );
        if (!$verdict->allowsDiscount()) {
            return [SocialDiscountEvidence::Unverified, null, null];
        }
        // Věková hranice důvodu (§ 7a odst. 1 písm. a, d, g): snímek ji nese
        // jako odvozený výrok. Starší revize klíč nemají a posuzují se tak,
        // jak se z nich počítalo; revize s klíčem, kde věk nevychází nebo ho
        // nejde ověřit, slevu neuplatní (sleva je výhoda zaměstnavatele a
        // přeplacená je podle § 7c odst. 3 dluh).
        if (array_key_exists('social_part_time_discount_age_condition', $term)
            && !in_array(
                $term['social_part_time_discount_age_condition'],
                [
                    SocialPartTimeDiscountAgeCondition::MET,
                    SocialPartTimeDiscountAgeCondition::NOT_APPLICABLE,
                ],
                true,
            )
        ) {
            return [SocialDiscountEvidence::Unverified, null, null];
        }

        return [SocialDiscountEvidence::Verified, $reason, $evidence];
    }

    /**
     * Posouzení revizí zmrazených před zavedením evidence záměrů. Beze změny
     * proti stavu do 18. 8. 2026, aby přepočet staré revize dal totéž co tehdy.
     *
     * @param array<string,mixed> $term
     * @return array{0:SocialDiscountEvidence,1:?SocialPartTimeDiscountReason,2:?string}
     */
    private function legacySocialPartTimeDiscount(
        array $term,
        SocialPartTimeDiscountReason $reason,
        ?string $evidence,
        string $periodEnd,
    ): array {
        $notifiedOn = is_string($term['social_part_time_discount_notified_on'] ?? null)
            ? trim($term['social_part_time_discount_notified_on'])
            : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $notifiedOn) !== 1
            || $notifiedOn > $periodEnd
        ) {
            return [SocialDiscountEvidence::Unverified, null, null];
        }

        return [SocialDiscountEvidence::Verified, $reason, $evidence];
    }

    /**
     * Hodiny pro § 7a odst. 3 písm. b) a c) — odpracované plus ty, za které
     * náleží náhrada mzdy nebo platu („za odpracovanou hodinu se považuje též
     * hodina, za kterou … náleží náhrada mzdy nebo platu").
     *
     * Rozpad placených neodpracovaných hodin nese teprve pracovní souhrn JMHZ
     * verze `jmhz-work-month.v2` a novější. Bez něj nelze úhrn sestavit a hodiny se
     * nevracejí vůbec — kalkulátor pak nárok neuplatní a měsíc jde na ruční
     * posouzení.
     */
    private function socialPartTimeDiscountHours(mixed $timeMonth): ?int
    {
        $month = $this->object($timeMonth);
        $summary = $this->object($month['jmhz_work_summary'] ?? null);
        if ($summary === null
            || !in_array(
                $summary['derivation_version'] ?? null,
                \MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder::CONDITIONAL_VERSIONS,
                true,
            )
        ) {
            return null;
        }
        $values = $this->object($summary['values'] ?? null);
        if ($values === null) {
            return null;
        }
        $worked = $this->nonNegativeInt($values['worked_millihours'] ?? null);
        $paidUnworked = $this->nonNegativeInt($values['unworked_paid_millihours'] ?? null);
        // Bez neodpracovaných hodin (IN07 nenastala) souhrn 10275–10280 neuvádí,
        // takže prázdné hodiny s náhradou jsou potvrzená nula, ne chybějící údaj.
        // Typicky měsíc bez svátku a bez nepřítomnosti: dřív zastavil slevu.
        $interactions = $this->object($summary['interactions'] ?? null);
        if ($paidUnworked === null
            && ($values['unworked_paid_millihours'] ?? null) === null
            && ($interactions['IN07'] ?? null) === false
        ) {
            $paidUnworked = 0;
        }
        if ($worked === null || $paidUnworked === null) {
            return null;
        }
        // Svátky v jinak pracovní dny přičítá souhrn od v7 do 10276 kvůli
        // hlášení (pokyny MPSV). Pro § 7a se nezapočítávaly dřív a neodečtené
        // by změnily nárok na slevu bez zákonného důvodu, jen verzí souhrnu.
        $holiday = $this->nonNegativeInt($values['holiday_millihours'] ?? 0) ?? 0;

        return $worked + $paidUnworked - $holiday;
    }

    /** Sjednaná týdenní pracovní doba v tisícinách hodiny (§ 7a odst. 2). */
    /** @param array<string,mixed> $term */
    private function weeklyWorkingMillihours(array $term): ?int
    {
        $raw = $term['weekly_hours'] ?? null;
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return null;
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/D', (string) $raw) !== 1) {
            return null;
        }

        return (int) round(((float) $raw) * 1_000);
    }

    private function calendarDaysInMonth(string $periodStart, string $periodEnd): ?int
    {
        $days = $this->dayDifference($periodStart, $periodEnd);

        return $days === null ? null : $days + 1;
    }

    private function calendarDaysInPeriod(
        string $from,
        ?string $to,
        string $periodStart,
        string $periodEnd,
    ): ?int {
        $start = max($from, $periodStart);
        $end = $to === null ? $periodEnd : min($to, $periodEnd);
        if ($start > $end) {
            return 0;
        }
        $days = $this->dayDifference($start, $end);

        return $days === null ? null : $days + 1;
    }

    private function dayDifference(string $from, string $to): ?int
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, new \DateTimeZone('UTC'));
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, new \DateTimeZone('UTC'));
        if ($start === false || $end === false) {
            return null;
        }

        return (int) $start->diff($end)->days;
    }

    /**
     * @param array<string,mixed> $evidence
     * @param list<mixed> $employments
     */
    private function healthPerson(
        array $evidence,
        array $employments,
        string $personReference,
        string $periodStart,
        string $periodEnd,
    ): ?HealthPersonMonthInput {
        $healthEvidence = $this->object($evidence['health'] ?? null);
        $coverage = $this->object($healthEvidence['coverage'] ?? null);
        if ($coverage === null) {
            $this->issue(
                'health_insurance',
                'health_coverage_evidence_missing',
                $personReference,
            );
            return null;
        }
        $jurisdiction = $this->enum(
            HealthJurisdictionEvidence::class,
            $coverage['jurisdiction'] ?? null,
        );
        $insurerStatus = $this->enum(
            HealthInsurerSnapshotStatus::class,
            $coverage['insurer_status'] ?? null,
        );
        if (!$jurisdiction instanceof HealthJurisdictionEvidence
            || !$insurerStatus instanceof HealthInsurerSnapshotStatus
        ) {
            $this->issue(
                'health_insurance',
                'health_coverage_evidence_invalid',
                $personReference,
            );
            return null;
        }
        if ($jurisdiction === HealthJurisdictionEvidence::Unverified) {
            $this->issue(
                'health_insurance',
                'health_jurisdiction_evidence_unverified',
                $personReference,
            );
        }
        if ($insurerStatus === HealthInsurerSnapshotStatus::Unverified) {
            $this->issue(
                'health_insurance',
                'health_insurer_evidence_unverified',
                $personReference,
            );
        }
        if (($jurisdiction === HealthJurisdictionEvidence::CzechRegimeVerified
                && $insurerStatus !== HealthInsurerSnapshotStatus::Verified)
            || ($jurisdiction
                    === HealthJurisdictionEvidence::ForeignRegimeVerified
                && $insurerStatus
                    !== HealthInsurerSnapshotStatus::NotApplicable)
        ) {
            $this->issue(
                'health_insurance',
                'health_coverage_evidence_conflict',
                $personReference,
            );
        }

        /*
         * Chybějící měsíční evidence zdravotního minima = zákonný výchozí stav,
         * ne mezera v podkladech.
         *
         * § 3 odst. 10 zákona č. 592/1992 Sb.: „Pokud je vyměřovací základ
         * zaměstnance nižší než minimální vyměřovací základ, je zaměstnanec
         * povinen doplatit zdravotní pojišťovně prostřednictvím svého
         * zaměstnavatele pojistné ve výši 13,5 % z rozdílu těchto základů. […]
         * Pokud je vyměřovací základ nižší z důvodů překážek na straně
         * organizace, je tento rozdíl povinen doplatit zaměstnavatel."
         *
         * Plátcem je tedy ze zákona ZAMĚSTNANEC a zaměstnavatel je výjimka
         * vázaná na skutkovou okolnost (překážky na jeho straně), kterou musí
         * někdo doložit. Vyžadovat řádek i pro pravidlo znamenalo u firmy
         * s tisícem lidí 12 000 zápisů ročně, které jen opakují text zákona.
         *
         * Ptá se, až když to nastane: dopočet vůbec nevznikne, když vyměřovací
         * základ dosahuje minima nebo se na osobu minimum nevztahuje, a
         * HealthMinimumResolver hlásí `minimum_top_up_responsibility_unverified`
         * i `selected_top_up_employer_*` jen při nenulové mezeře. V měsíci bez
         * dopočtu proto nevznikne ani issue, ani požadavek na vstup.
         *
         * Doklad se drží tam, kde má co dokládat: u výjimky
         * `employer_obstacle_verified` ho vynucuje HealthPersonMonthInput,
         * u volby jiného zaměstnavatele při souběhu HealthMinimumResolver.
         * U výchozího stavu žádný není a být nemá.
         *
         * Že hodnota vznikla odvozením ze zákona, nese snímek výpočtu vlastním
         * klíčem — viz HealthMinimumTopUpResponsibilitySource.
         */
        $monthEvidence = $this->object(
            $healthEvidence['month_evidence'] ?? null,
        );
        $monthEvidenceRow = $monthEvidence ?? [];
        $responsibility = HealthMinimumTopUpResponsibility::Employee;
        $responsibilitySource =
            HealthMinimumTopUpResponsibilitySource::StatutoryDefault;
        $responsibilityEvidence = null;
        if ($monthEvidence === null) {
            [$responsibility, $responsibilitySource, $responsibilityEvidence]
                = self::obstacleTopUpResponsibility($employments, $periodStart, $periodEnd)
                    ?? [$responsibility, $responsibilitySource, null];
        }
        if ($monthEvidence !== null) {
            $responsibilitySource =
                HealthMinimumTopUpResponsibilitySource::Declared;
            $declared = $this->enum(
                HealthMinimumTopUpResponsibility::class,
                $monthEvidenceRow['top_up_responsibility'] ?? null,
            );
            if (!$declared instanceof HealthMinimumTopUpResponsibility) {
                $this->issue(
                    'health_insurance',
                    'health_minimum_responsibility_invalid',
                    $personReference,
                );
                return null;
            }
            // Explicitní `unverified` je prohlášení „nevíme", ne absence
            // prohlášení — a to zůstává důvodem k ručnímu posouzení.
            if ($declared === HealthMinimumTopUpResponsibility::Unverified) {
                $this->issue(
                    'health_insurance',
                    'health_minimum_responsibility_unverified',
                    $personReference,
                );
            }
            $responsibility = $declared;
        }

        $reductions = [
            ...$this->healthReductions(
                $healthEvidence['minimum_reductions'] ?? null,
                $personReference,
                $periodEnd,
            ),
            ...self::absenceHealthReductions($employments, $periodStart, $periodEnd),
            ...self::derivedHealthReductions($evidence, $periodStart, $periodEnd),
        ];
        $otherEmployers = $this->healthOtherEmployers(
            $healthEvidence['other_employer_bases'] ?? null,
            $personReference,
        );
        $relationships = [];
        foreach ($employments as $employmentSnapshot) {
            $relationship = $this->healthRelationship(
                $employmentSnapshot,
                $personReference,
                $periodStart,
                $periodEnd,
            );
            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }
        if ($relationships === []) {
            return null;
        }
        $selectedEmployer = $this->nullableString(
            $monthEvidenceRow['selected_top_up_employer_reference'] ?? null,
        );
        $selection = $selectedEmployer === null
            ? HealthMinimumTopUpEmployerSelection::ThisEmployer
            : HealthMinimumTopUpEmployerSelection::OtherEmployer;

        try {
            return new HealthPersonMonthInput(
                $personReference,
                $jurisdiction,
                $jurisdiction === HealthJurisdictionEvidence::ForeignRegimeVerified
                    ? $this->nullableString(
                        $coverage['jurisdiction_evidence_reference'] ?? null,
                    )
                    : null,
                $insurerStatus,
                $this->nullableString($coverage['insurer_code'] ?? null),
                $this->nullableString(
                    $coverage['insurer_evidence_reference'] ?? null,
                ),
                $relationships,
                $reductions,
                $otherEmployers,
                $responsibility,
                $responsibility ===
                    HealthMinimumTopUpResponsibility::EmployerObstacleVerified
                    ? ($responsibilityEvidence ?? $this->nullableString(
                        $monthEvidenceRow[
                            'top_up_responsibility_evidence_reference'
                        ] ?? null,
                    ))
                    : null,
                $this->nullableString(
                    $monthEvidenceRow[
                        'selected_top_up_employer_evidence_reference'
                    ] ?? null,
                ),
                $selection,
                $responsibilitySource,
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'health_insurance',
                'health_evidence_mapping_failed',
                $personReference,
            );
            return null;
        }
    }

    /** @param mixed $snapshot */
    private function healthRelationship(
        mixed $snapshot,
        string $personReference,
        string $periodStart,
        string $periodEnd,
    ): ?HealthInsuranceRelationshipInput {
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            $this->issue(
                'health_insurance',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employment = $this->object($snapshot['employment'] ?? null);
        if ($employment === null) {
            $this->issue(
                'health_insurance',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employmentId = $this->positiveInt($employment['id'] ?? null);
        if ($employmentId === null) {
            $this->issue(
                'health_insurance',
                'employment_reference_invalid',
                $personReference,
            );
            return null;
        }
        $relationshipReference = "employment:{$employmentId}";
        if (($employment['employee_id'] ?? null)
            !== $this->personId($personReference)
        ) {
            $this->issue(
                'health_insurance',
                'employment_person_mismatch',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $term = $this->object($snapshot['term'] ?? null);
        if ($term === null) {
            $this->issue(
                'health_insurance',
                'employment_term_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        if (($term['health_insurance_participation'] ?? null) !== 'automatic') {
            $this->issue(
                'health_insurance',
                'participation_override_unsupported',
                $personReference,
                $relationshipReference,
            );
        }
        $relationType = $this->nonEmptyString($employment['relation_type'] ?? null);
        $dates = $this->employmentDates($employment);
        if ($relationType === null || $dates === null) {
            $this->issue(
                'health_insurance',
                'relationship_kind_or_dates_invalid',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        try {
            $kind = $this->healthKinds->fromDatabaseRelationType($relationType);
        } catch (\UnexpectedValueException) {
            $this->issue(
                'health_insurance',
                'relationship_kind_unsupported',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        [$employmentFrom, $employmentTo] = $dates;
        $active = $employmentFrom <= $periodEnd
            && ($employmentTo === null || $employmentTo >= $periodStart);
        $attribution = HealthIncomeAttribution::CurrentEmploymentMonth;
        if (!$active) {
            if (($kind->value === 'dpp' || $kind->value === 'dpc')
                && $employmentTo !== null
                && substr($employmentTo, 0, 7) === substr($periodStart, 0, 7)
            ) {
                $attribution =
                    HealthIncomeAttribution::PostTerminationEndMonthVerified;
            } elseif (($kind->value !== 'dpp' && $kind->value !== 'dpc')
                && $employmentTo !== null
                && $employmentTo < $periodStart
            ) {
                $attribution =
                    HealthIncomeAttribution::PostTerminationPaymentMonthVerified;
            } else {
                $this->issue(
                    'health_insurance',
                    'post_termination_income_attribution_unverified',
                    $personReference,
                    $relationshipReference,
                );
                $attribution = HealthIncomeAttribution::Unverified;
            }
        }
        $components = $this->healthComponents(
            $snapshot['inputs'] ?? null,
            $personReference,
            $relationshipReference,
            $periodStart,
            $snapshot['absences'] ?? null,
        );
        if ($components === [] && !$this->monthWithoutInputsExplained($snapshot['absences'] ?? null)) {
            return null;
        }

        try {
            return new HealthInsuranceRelationshipInput(
                $relationshipReference,
                $kind,
                $employmentFrom,
                $employmentTo,
                $attribution,
                $components,
                // Klíč nese jen vztah člena družstva nebo SVJ; starší revize
                // a ostatní vztahy ho nemají a počítají se jako dřív.
                associationMember: ($term['health_association_member'] ?? false) === true,
                outsideInsurance: PayrollEmploymentJmhzActivityFamily::isOutsideStatutoryInsurance(
                    $term['activity_code'] ?? null,
                ),
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'health_insurance',
                'relationship_mapping_failed',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $evidence
     * @param list<mixed> $employments
     */
    private function incomeTaxPerson(
        array $person,
        array $evidence,
        array $employments,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
        string $calculationDate,
    ): ?MonthlyEmploymentIncomeTaxInput {
        $taxEvidence = $this->object($evidence['income_tax'] ?? null);
        $declarationRow = $this->object($taxEvidence['declaration'] ?? null);
        $residenceRow = $this->object($taxEvidence['residence'] ?? null);
        if ($declarationRow === null) {
            $this->issue(
                'income_tax',
                'tax_declaration_evidence_missing',
                $personReference,
            );
        }
        if ($residenceRow === null) {
            $this->issue(
                'income_tax',
                'tax_residence_evidence_missing',
                $personReference,
            );
        }
        if ($declarationRow === null || $residenceRow === null) {
            return null;
        }
        $declarationStatus = $this->enum(
            TaxDeclarationStatus::class,
            $declarationRow['status'] ?? null,
        );
        $residence = $this->enum(
            TaxResidence::class,
            $residenceRow['residence'] ?? null,
        );
        if (!$declarationStatus instanceof TaxDeclarationStatus
            || !$residence instanceof TaxResidence
        ) {
            $this->issue(
                'income_tax',
                'tax_evidence_invalid',
                $personReference,
            );
            return null;
        }
        if ($declarationStatus === TaxDeclarationStatus::Unverified) {
            $this->issue(
                'income_tax',
                'tax_declaration_evidence_unverified',
                $personReference,
            );
        }
        if ($residence === TaxResidence::Unverified) {
            $this->issue(
                'income_tax',
                'tax_residence_evidence_unverified',
                $personReference,
            );
        }

        $annual = $this->incomeTaxAccumulator(
            $person,
            $supplierId,
            $employeeId,
            $personReference,
            $periodStart,
        );
        $relationships = [];
        foreach ($employments as $employmentSnapshot) {
            $relationship = $this->incomeTaxRelationship(
                $employmentSnapshot,
                $personReference,
                $supplierId,
                $periodStart,
                $declarationStatus,
            );
            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }
        $creditClaims = $this->taxCredits(
            $taxEvidence['credit_claims'] ?? null,
            $personReference,
        );
        $childClaims = $this->taxChildren(
            $taxEvidence['child_claims'] ?? null,
            $personReference,
        );
        $monthlyCreditsWithheld = !self::employedInPeriod($employments, $periodStart);
        if ($monthlyCreditsWithheld) {
            /*
             * Příjem zúčtovaný až po skončení všech vztahů u plátce (odložený
             * příjem, JMHZ scénář 8): záloha zůstává zálohou, protože prohlášení
             * bylo učiněno na zdaňovací období (§ 38h odst. 4 ZDP) a hlášení
             * ho dál uvádí (10419). Měsíční slevu § 35ba a daňové zvýhodnění
             * ale za měsíc, ve kterém už u plátce nepracuje, neuplatní: slevu
             * za kalendářní měsíc smí poskytnout jen jeden plátce (§ 38k
             * odst. 3 a odst. 4 písm. b) ZDP) a prohlášení u dosavadního
             * zaměstnavatele skončením pracovního poměru pro další měsíce
             * končí. Dřív se sleva poskytla znovu a u nového zaměstnavatele
             * vznikla dvakrát. Nárok si poplatník uplatní v ročním zúčtování
             * nebo v přiznání. Slevu na poplatníka odvozenou z podepsaného
             * prohlášení (TaxpayerCreditEntitlement) vypíná příznak vstupu.
             */
            $creditClaims = [];
            $childClaims = [];
        }
        if ($annual === null || $relationships === []) {
            return null;
        }
        try {
            return new MonthlyEmploymentIncomeTaxInput(
                $calculationDate,
                $personReference,
                $relationships,
                [new TaxDeclarationEvidence(
                    $declarationStatus,
                    $this->requiredString($declarationRow['effective_from'] ?? null),
                    $this->nullableString($declarationRow['effective_to'] ?? null),
                    $declarationStatus === TaxDeclarationStatus::Unverified
                        ? null
                        : $this->nullableString(
                            $declarationRow['evidence_reference'] ?? null,
                        ),
                )],
                new TaxResidenceEvidence(
                    $residence,
                    $this->requiredString($residenceRow['effective_from'] ?? null),
                    $this->nullableString($residenceRow['effective_to'] ?? null),
                    $residence === TaxResidence::Unverified
                        ? null
                        : $this->nullableString(
                            $residenceRow['evidence_reference'] ?? null,
                        ),
                ),
                $creditClaims,
                $childClaims,
                $annual,
                [],
                "supplier:{$supplierId}",
                $monthlyCreditsWithheld,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            $this->issue(
                'income_tax',
                'tax_evidence_mapping_failed',
                $personReference,
            );
            return null;
        }
    }

    /** @param mixed $snapshot */
    private function incomeTaxRelationship(
        mixed $snapshot,
        string $personReference,
        int $supplierId,
        string $periodStart,
        TaxDeclarationStatus $declarationStatus,
    ): ?EmploymentRelationshipTaxInput {
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            $this->issue(
                'income_tax',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employment = $this->object($snapshot['employment'] ?? null);
        if ($employment === null) {
            $this->issue(
                'income_tax',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employmentId = $this->positiveInt($employment['id'] ?? null);
        if ($employmentId === null) {
            $this->issue(
                'income_tax',
                'employment_reference_invalid',
                $personReference,
            );
            return null;
        }
        $relationshipReference = "employment:{$employmentId}";
        if (($employment['employee_id'] ?? null)
            !== $this->personId($personReference)
        ) {
            $this->issue(
                'income_tax',
                'employment_person_mismatch',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $term = $this->object($snapshot['term'] ?? null);
        if ($term === null) {
            $this->issue(
                'income_tax',
                'employment_term_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        // Prohlášení k dani se ZDE nekontroluje proti snímku smluvních podmínek.
        // Býval to blokátor `tax_declaration_term_conflict`, jenže obě strany
        // dnes pochází z téhož zdroje: `PayrollRunSnapshotBuilder` plní
        // `term.tax_declaration_signed` ze zákonné evidence osoby, protože
        // prohlášení se podepisuje v průběhu vztahu a druhé editovatelné místo
        // pro tentýž údaj bylo past, ne kontrola. Formatter hlášku zná dál —
        // starší revize si svůj text nesou s sebou.
        //
        // `tax_regime` je override VÝSLEDKU („zdaň to srážkou / v cizině / ručně")
        // a podporovaná je z něj zatím jen `advance`. Zařazení podle § 6 odst. 4
        // písm. b) ZDP se proto NEBERE odsud: to je vstupní skutečnost, na kterou
        // výpočet teprve aplikuje rozhodnou částku, kdežto `tax_regime` by ji
        // přeskočil a srazil daň i nad ní. Jede vlastním sloupcem — viz
        // otherWithholdingEligibility().
        if (($term['tax_regime'] ?? null) !== 'advance') {
            $this->issue(
                'income_tax',
                'tax_regime_override_unsupported',
                $personReference,
                $relationshipReference,
            );
        }
        $relationType = $this->nonEmptyString($employment['relation_type'] ?? null);
        if ($relationType === null) {
            $this->issue(
                'income_tax',
                'relationship_kind_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        try {
            $kind = $this->taxKinds->fromDatabaseRelationType($relationType);
        } catch (\UnexpectedValueException) {
            $this->issue(
                'income_tax',
                'relationship_kind_unsupported',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $components = $this->taxComponents(
            $snapshot['inputs'] ?? null,
            $personReference,
            $relationshipReference,
            $periodStart,
            $snapshot['absences'] ?? null,
        );
        if ($components === [] && !$this->monthWithoutInputsExplained($snapshot['absences'] ?? null)) {
            return null;
        }

        // Zařazení podle § 6 odst. 4 ZDP si výpočet odvodí z druhu vztahu
        // a úhrnu příjmů v měsíci. Sloupec smluvních podmínek
        // `other_withholding_eligibility` (migrace 1403, „prohlášení plátce
        // o účasti na pojištění“) se sem vědomě nečte: písm. b) se na účast
        // neptá, jen na úhrn pod rozhodnou částkou.
        return new EmploymentRelationshipTaxInput(
            $relationshipReference,
            "supplier:{$supplierId}",
            $kind,
            $components,
        );
    }

    /** @param array<string,mixed> $person */
    private function socialAccumulator(
        array $person,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
    ): ?int {
        $state = $this->accumulator(
            $person,
            'social_insurance',
            $supplierId,
            $employeeId,
            $personReference,
            $periodStart,
        );
        if ($state === null) {
            return null;
        }
        $value = $this->nonNegativeInt(
            $state['totals']['assessment_base_minor_units'] ?? null,
        );
        if ($value === null) {
            $this->issue(
                'social_insurance',
                'annual_accumulator_invalid',
                $personReference,
            );
            return null;
        }
        return $value;
    }

    /** @param array<string,mixed> $person */
    private function incomeTaxAccumulator(
        array $person,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
    ): ?AnnualTaxAccumulatorInput {
        $state = $this->accumulator(
            $person,
            'income_tax',
            $supplierId,
            $employeeId,
            $personReference,
            $periodStart,
        );
        if ($state === null) {
            return null;
        }
        $totals = $this->object($state['totals'] ?? null);
        $values = [
            'completed_months' => $this->nonNegativeInt(
                $totals['completed_months'] ?? null,
            ),
            'advance_base_minor_units' => $this->nonNegativeInt(
                $totals['advance_base_minor_units'] ?? null,
            ),
            'withholding_base_minor_units' => $this->nonNegativeInt(
                $totals['withholding_base_minor_units'] ?? null,
            ),
            'advance_tax_minor_units' => $this->nonNegativeInt(
                $totals['advance_tax_minor_units'] ?? null,
            ),
            'withholding_tax_minor_units' => $this->nonNegativeInt(
                $totals['withholding_tax_minor_units'] ?? null,
            ),
            'applied_non_refundable_credits_minor_units' =>
                $this->nonNegativeInt(
                    $totals[
                        'applied_non_refundable_credits_minor_units'
                    ] ?? null,
                ),
            'applied_child_credit_minor_units' => $this->nonNegativeInt(
                $totals['applied_child_credit_minor_units'] ?? null,
            ),
            'tax_bonus_minor_units' => $this->nonNegativeInt(
                $totals['tax_bonus_minor_units'] ?? null,
            ),
            'bonus_qualifying_income_minor_units' => $this->nonNegativeInt(
                $totals['bonus_qualifying_income_minor_units'] ?? null,
            ),
        ];
        if (in_array(null, $values, true)) {
            $this->issue(
                'income_tax',
                'annual_accumulator_invalid',
                $personReference,
            );
            return null;
        }
        try {
            return new AnnualTaxAccumulatorInput(
                (int) substr($periodStart, 0, 4),
                $values['completed_months'],
                $values['advance_base_minor_units'],
                $values['withholding_base_minor_units'],
                $values['advance_tax_minor_units'],
                $values['withholding_tax_minor_units'],
                $values['applied_non_refundable_credits_minor_units'],
                $values['applied_child_credit_minor_units'],
                $values['tax_bonus_minor_units'],
                $values['bonus_qualifying_income_minor_units'],
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'income_tax',
                'annual_accumulator_invalid',
                $personReference,
            );
            return null;
        }
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,mixed>|null
     */
    private function accumulator(
        array $person,
        string $kind,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
    ): ?array {
        $domain = $kind;
        $accumulators = $this->object(
            $person['statutory_accumulators'] ?? null,
        );
        if (($accumulators['schema_version'] ?? null)
            !== 'payroll-person-statutory-accumulators.v1'
        ) {
            $this->issue(
                $domain,
                'annual_accumulator_missing',
                $personReference,
            );
            return null;
        }
        $wrapper = $this->object($accumulators[$kind] ?? null);
        $state = $this->object($wrapper['state'] ?? null);
        if (($wrapper['status'] ?? null) !== 'verified' || $state === null) {
            $issueCode = $wrapper['issue_code'] ?? null;
            $this->issue(
                $domain,
                is_string($issueCode)
                    && preg_match('/^[a-z][a-z0-9_]*$/D', $issueCode) === 1
                    ? $issueCode
                    : 'annual_accumulator_missing',
                $personReference,
            );
            return null;
        }
        if (($state['schema_version'] ?? null)
                !== 'payroll-statutory-accumulator-state.v1'
            || ($state['calculation_kind'] ?? null) !== $kind
            || ($state['supplier_id'] ?? null) !== $supplierId
            || ($state['employee_id'] ?? null) !== $employeeId
            || ($state['year'] ?? null) !== (int) substr($periodStart, 0, 4)
            || ($state['before_period_start'] ?? null) !== $periodStart
            || $this->object($state['totals'] ?? null) === null
        ) {
            $this->issue(
                $domain,
                'annual_accumulator_invalid',
                $personReference,
            );
            return null;
        }
        return $state;
    }

    /**
     * @return list<\MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent>
     */
    private function socialComponents(
        mixed $raw,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
        mixed $absences = null,
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'social_insurance',
            $personReference,
            $relationshipReference,
            $absences,
        );
        $domainTotal = $this->domainTotal($inputs, 'social_insurance');
        $result = [];
        foreach ($inputs as $input) {
            if (!$this->assertCurrentNonNegativeComponent(
                $input,
                'social_insurance',
                $personReference,
                $relationshipReference,
                $periodStart,
                $domainTotal,
            )) {
                continue;
            }
            $component = $this->object($input['component'] ?? null);
            if (in_array(
                'manual_review',
                [
                    $component['social_participation_treatment'] ?? null,
                    $component['social_treatment'] ?? null,
                ],
                true,
            )) {
                $this->issue(
                    'social_insurance',
                    'component_treatment_unverified',
                    $personReference,
                    $relationshipReference,
                );
                continue;
            }
            try {
                array_push($result, ...$this->components->social($input));
            } catch (\InvalidArgumentException|\ValueError|\UnexpectedValueException) {
                $this->issue(
                    'social_insurance',
                    'component_mapping_failed',
                    $personReference,
                    $relationshipReference,
                );
            }
        }
        return $result;
    }

    /**
     * @return list<\MyInvoice\Service\Payroll\HealthInsurance\HealthAssessmentComponent>
     */
    private function healthComponents(
        mixed $raw,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
        mixed $absences = null,
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'health_insurance',
            $personReference,
            $relationshipReference,
            $absences,
        );
        $domainTotal = $this->domainTotal($inputs, 'health_insurance');
        $result = [];
        foreach ($inputs as $input) {
            if (!$this->assertCurrentNonNegativeComponent(
                $input,
                'health_insurance',
                $personReference,
                $relationshipReference,
                $periodStart,
                $domainTotal,
            )) {
                continue;
            }
            $component = $this->object($input['component'] ?? null);
            if (in_array(
                'manual_review',
                [
                    $component['health_participation_treatment'] ?? null,
                    $component['health_treatment'] ?? null,
                ],
                true,
            )) {
                $this->issue(
                    'health_insurance',
                    'component_treatment_unverified',
                    $personReference,
                    $relationshipReference,
                );
                continue;
            }
            try {
                array_push($result, ...$this->components->health($input, $periodStart));
            } catch (\InvalidArgumentException|\ValueError|\UnexpectedValueException) {
                $this->issue(
                    'health_insurance',
                    'component_mapping_failed',
                    $personReference,
                    $relationshipReference,
                );
            }
        }
        return $result;
    }

    /**
     * @return list<\MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent>
     */
    private function taxComponents(
        mixed $raw,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
        mixed $absences = null,
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'income_tax',
            $personReference,
            $relationshipReference,
            $absences,
        );
        $domainTotal = $this->domainTotal($inputs, 'income_tax');
        $result = [];
        foreach ($inputs as $input) {
            $component = $this->object($input['component'] ?? null);
            $treatment = $component['tax_treatment'] ?? null;
            $usable = true;
            // Osvobození je jinak nedoložené tvrzení a výpočet se u něj zastaví.
            // Na otázku, čím je podložené, odpovídá sdílený
            // {@see PayrollExemptionEvidence} — týž doklad pak mapper vloží do
            // složky, takže se tahle brána a brána výpočtu daně nemůžou rozejít.
            if ($treatment === 'exempt'
                && PayrollExemptionEvidence::resolve($input) === null
            ) {
                $this->issue(
                    'income_tax',
                    'tax_component_exemption_evidence_missing',
                    $personReference,
                    $relationshipReference,
                );
                $usable = false;
            }
            if ($treatment === 'manual_review') {
                $this->issue(
                    'income_tax',
                    'component_treatment_unverified',
                    $personReference,
                    $relationshipReference,
                );
                $usable = false;
            }
            if (!$this->assertCurrentNonNegativeComponent(
                $input,
                'income_tax',
                $personReference,
                $relationshipReference,
                $periodStart,
                $domainTotal,
            )) {
                $usable = false;
            }
            if (!$usable) {
                continue;
            }
            try {
                array_push($result, ...$this->components->incomeTax($input, $periodStart));
            } catch (\InvalidArgumentException|\ValueError|\UnexpectedValueException) {
                $this->issue(
                    'income_tax',
                    'component_mapping_failed',
                    $personReference,
                    $relationshipReference,
                );
            }
        }
        return $result;
    }

    /**
     * Měsíc bez jediného vstupu je pojistka proti zapomenuté mzdě, proto
     * blokuje. Výjimkou je vztah, u kterého nepřítomnost bez náhrady od
     * zaměstnavatele vysvětluje, proč vstup chybí: celý měsíc neplaceného
     * volna, rodičovské, PPM, nemoci za oknem náhrady nebo náhradního volna.
     * Tam uživatel nemá co zadat a běh musel stát. Zda je takový měsíc dobou
     * pojištění, rozhoduje dál ELDP
     * ({@see \MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver::insuranceMonthStatus()}).
     * Dovolená a překážky v práci mezi výjimkami nejsou: jejich schválení
     * vždy zakládá vstup náhrady mzdy.
     */
    private const ABSENCES_EXPLAINING_MONTH_WITHOUT_INPUTS = [
        ...\MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver::INCOME_LESS_TYPES,
        'ppm',
        'paternity',
        'dpn',
        'quarantine',
        'ocr',
        'long_term_care',
        'compensatory_time_off',
        // Vztah trval po neplatném skončení, náhrada mzdy přiznána nebyla.
        'invalid_termination',
    ];

    /** Jediné pravidlo i pro varování `employment_without_inputs` v PayrollRunSnapshotBuilder. */
    public static function monthWithoutInputsExplained(mixed $absences): bool
    {
        if (!is_array($absences) || !array_is_list($absences)) {
            return false;
        }
        foreach ($absences as $absence) {
            if (is_array($absence) && in_array(
                $absence['absence_type'] ?? null,
                self::ABSENCES_EXPLAINING_MONTH_WITHOUT_INPUTS,
                true,
            )) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string,mixed>> */
    private function componentInputs(
        mixed $raw,
        string $domain,
        string $personReference,
        string $relationshipReference,
        mixed $absences = null,
    ): array {
        $inputs = $this->list($raw);
        if ($inputs === [] && $this->monthWithoutInputsExplained($absences)) {
            return [];
        }
        if ($inputs === null || $inputs === []) {
            $this->issue(
                $domain,
                'payroll_component_missing',
                $personReference,
                $relationshipReference,
            );
            return [];
        }
        $result = [];
        foreach ($inputs as $input) {
            if (!is_array($input) || array_is_list($input)) {
                $this->issue(
                    $domain,
                    'payroll_component_invalid',
                    $personReference,
                    $relationshipReference,
                );
                continue;
            }
            $result[] = $input;
        }
        return $result;
    }

    /**
     * Vstupuje složka do vyměřovacího základu TÉHLE domény?
     *
     * Why: zápornou částku má smysl hlídat jen tam, kde se o základ opravdu
     * opře. Náhrada mzdy při DPN je osvobozená od daně (§ 6 odst. 9 písm. p)
     * ZDP) a tím pádem mimo vyměřovací základ sociálního i zdravotního
     * pojistného (§ 5 odst. 1 zák. 589/1992 Sb. — pozor, v taxativním výčtu
     * odst. 2 ji nenajdete, vypadává už přes odstavec 1). Do žádné ze tří
     * domén tedy nevstupuje.
     *
     * Přesto shazovala všechny tři: kontrola nezápornosti běžela PŘED filtrem
     * podle zacházení, takže stornovaná nemocenská poslala celý běh do ručního
     * posouzení a nešla schválit. Období se dalo odblokovat jen ručním SQL.
     *
     * `manual_review` se tu bere jako „vstupuje": nevíme to jistě, a mlčet
     * u záporné částky, o které nevíme, kam patří, by bylo horší než falešný
     * poplach.
     */
    private static function entersDomainBase(array $component, string $domain): bool
    {
        return match ($domain) {
            'social_insurance' => ($component['social_treatment'] ?? 'manual_review') !== 'excluded',
            'health_insurance' => ($component['health_treatment'] ?? 'manual_review') !== 'excluded',
            'income_tax' => !in_array(
                $component['tax_treatment'] ?? 'manual_review',
                ['exempt', 'withholding_candidate'],
                true,
            ),
            default => true,
        };
    }

    /**
     * Úhrn částek vztahu, které vstupují do základu domény. Záporná vrácená
     * náhrada za dovolenou projde jen tehdy, když ho nestáhne pod nulu.
     *
     * @param list<array<string,mixed>> $inputs
     */
    private function domainTotal(array $inputs, string $domain): int
    {
        $total = 0;
        foreach ($inputs as $input) {
            $amount = $this->integer($input['amount_minor'] ?? null);
            if ($amount !== null
                && self::entersDomainBase($this->object($input['component'] ?? null) ?? [], $domain)
            ) {
                $total += $amount;
            }
        }

        return $total;
    }

    /** @param array<string,mixed> $input */
    private function assertCurrentNonNegativeComponent(
        array $input,
        string $domain,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
        int $domainTotal,
    ): bool {
        $valid = true;
        $sourcePeriod = $input['source_period_start'] ?? null;
        if ($sourcePeriod !== null && $sourcePeriod !== $periodStart) {
            $this->issue(
                $domain,
                'prior_period_component_requires_revision',
                $personReference,
                $relationshipReference,
            );
            $valid = false;
        }
        $amount = $this->integer($input['amount_minor'] ?? null);
        if ($amount === null) {
            $this->issue(
                $domain,
                'component_amount_invalid',
                $personReference,
                $relationshipReference,
            );
            return false;
        }
        $component = $this->object($input['component'] ?? null) ?? [];
        if ($amount < 0
            && self::entersDomainBase($component, $domain)
            // Vrácená náhrada za dovolenou (§ 147 odst. 1 písm. e) ZP) je
            // zápornou částkou TOHOTO měsíce, ne opravou minulého.
            && !(VacationCompensationReturn::mayBeNegative($component['code'] ?? null) && $domainTotal >= 0)
        ) {
            $this->issue(
                $domain,
                'negative_component_requires_revision',
                $personReference,
                $relationshipReference,
            );
            $valid = false;
        }
        return $valid;
    }

    /**
     * Schválené nepřítomnosti, které ze zákona snižují minimální vyměřovací
     * základ zdravotního pojištění.
     *
     * - § 3 odst. 9 písm. b) zák. č. 592/1992 Sb.: pracovní volno pro
     *   důležité osobní překážky v práci — nemoc, karanténa, ošetřování
     *   a dlouhodobé ošetřování (§ 191 a § 191a ZP).
     * - § 3 odst. 8 písm. d) a odst. 9 písm. c) téhož zákona ve spojení
     *   s § 7 odst. 1 písm. d) zák. č. 48/1997 Sb.: za ženu na mateřské,
     *   osobu na rodičovské dovolené a příjemce PPM platí pojistné stát; za
     *   celý měsíc minimum neplatí, za část se poměrně snižuje.
     *
     * Neplacené volno ani neomluvená absence minimum nesnižují (doplatek hradí
     * zaměstnanec).
     *
     * Otcovská minimum NESNIŽUJE. To je rozhodnutí, ne mezera. Důvody snížení
     * jsou v § 3 odst. 8 a 9 zákona č. 592/1992 Sb. vyjmenované taxativně
     * a otcovská poporodní péče mezi nimi není: nejde o státního pojištěnce
     * (§ 7 odst. 1 zákona č. 48/1997 Sb. jmenuje mateřskou, rodičovskou a PPM,
     * ne otcovskou) a zákon ji nejmenuje ani v odst. 9 písm. b). Obecné
     * důležité osobní překážky zůstávají mimo automatiku.
     *
     * Dřív se snížení četlo JEN z ruční evidence, ke které nevedla žádná
     * obrazovka ani API. Měsíc s nemocí nebo PPM proto dorovnával pojistné do
     * plného minima.
     *
     * @param list<mixed> $employments
     * @return list<HealthMinimumReductionInterval>
     */
    private static function absenceHealthReductions(
        array $employments,
        string $periodStart,
        string $periodEnd,
    ): array {
        $reasons = [
            'dpn' => HealthMinimumReductionReason::SicknessCareOrQuarantine,
            'quarantine' => HealthMinimumReductionReason::SicknessCareOrQuarantine,
            'ocr' => HealthMinimumReductionReason::SicknessCareOrQuarantine,
            'long_term_care' => HealthMinimumReductionReason::SicknessCareOrQuarantine,
            'ppm' => HealthMinimumReductionReason::StateInsured,
            'parental' => HealthMinimumReductionReason::StateInsured,
        ];
        $result = [];
        foreach ($employments as $employment) {
            $absences = is_array($employment) ? ($employment['absences'] ?? null) : null;
            if (!is_array($absences) || !array_is_list($absences)) {
                continue;
            }
            foreach ($absences as $absence) {
                if (!is_array($absence)) {
                    continue;
                }
                $reason = $reasons[$absence['absence_type'] ?? ''] ?? null;
                $from = $absence['date_from'] ?? null;
                $to = $absence['date_to'] ?? null;
                $id = $absence['id'] ?? null;
                if ($reason === null || !is_string($from) || !is_string($to) || !is_int($id)
                    || $from > $periodEnd || $to < $periodStart
                ) {
                    continue;
                }
                $result[] = new HealthMinimumReductionInterval(
                    max($from, $periodStart),
                    min($to, $periodEnd),
                    $reason,
                    "absence:{$id}",
                );
            }
        }

        return $result;
    }

    /**
     * Kdo hradí doplatek do minima ZP, když ho nikdo neprohlásil a v měsíci je
     * překážka na straně zaměstnavatele.
     *
     * § 3 odst. 10 věta třetí zákona č. 592/1992 Sb.: „Pokud je vyměřovací
     * základ nižší z důvodů překážek na straně organizace, je tento rozdíl
     * povinen doplatit zaměstnavatel." Základ snižuje jen překážka, za kterou
     * přísluší náhrada NIŽŠÍ než průměrný výdělek — prostoj a povětrnostní
     * vlivy (§ 207 ZP) a částečná nezaměstnanost (§ 209 ZP). Jiná překážka
     * podle § 208 ZP se platí průměrem a základ nesnižuje, stejně jako
     * překážky na straně zaměstnance (vždy 100 %).
     *
     * Druh i sazba jsou zmrazené v absenci ({@see \MyInvoice\Service\Payroll\Absence\PayrollObstacleKind}),
     * takže výjimka má doklad (`absence:{id}`) a nevyžaduje od účetní nic
     * navíc. Je-li v měsíci zároveň neplacená nepřítomnost, která základ
     * snižuje z viny zaměstnance, dělit doplatek odhadem nejde — odpovědnost
     * se nechá neověřená a HealthMinimumResolver při nenulovém doplatku
     * požádá o rozhodnutí v měsíční evidenci. Prohlášená měsíční evidence má
     * vždy přednost; sem se dojde jen bez ní.
     *
     * @param list<mixed> $employments
     * @return array{0:HealthMinimumTopUpResponsibility,1:HealthMinimumTopUpResponsibilitySource,2:?string}|null
     */
    private static function obstacleTopUpResponsibility(
        array $employments,
        string $periodStart,
        string $periodEnd,
    ): ?array {
        $employeeCauses = [
            'unpaid_leave', 'unexcused', 'compensatory_time_off',
            'employee_obstacle_unpaid', 'public_function',
        ];
        $obstacleId = null;
        $mixed = false;
        foreach ($employments as $employment) {
            $absences = is_array($employment) ? ($employment['absences'] ?? null) : null;
            if (!is_array($absences) || !array_is_list($absences)) {
                continue;
            }
            foreach ($absences as $absence) {
                if (!is_array($absence)
                    || !is_string($absence['date_from'] ?? null)
                    || !is_string($absence['date_to'] ?? null)
                    || $absence['date_from'] > $periodEnd
                    || $absence['date_to'] < $periodStart
                ) {
                    continue;
                }
                $type = $absence['absence_type'] ?? null;
                if (in_array($type, $employeeCauses, true)) {
                    $mixed = true;
                    continue;
                }
                $rate = $absence['compensation_rate_basis_points'] ?? null;
                if ($type === PayrollObstacleKind::EMPLOYER_SIDE_TYPE
                    && is_string($absence['obstacle_kind'] ?? null)
                    && is_int($rate)
                    && $rate < PayrollObstacleKind::FULL_RATE_BASIS_POINTS
                    && is_int($absence['id'] ?? null)
                ) {
                    $obstacleId ??= $absence['id'];
                }
            }
        }
        if ($obstacleId === null) {
            return null;
        }

        return $mixed
            ? [
                HealthMinimumTopUpResponsibility::Unverified,
                HealthMinimumTopUpResponsibilitySource::DerivedMixedCauses,
                null,
            ]
            : [
                HealthMinimumTopUpResponsibility::EmployerObstacleVerified,
                HealthMinimumTopUpResponsibilitySource::DerivedEmployerObstacle,
                "absence:{$obstacleId}",
            ];
    }

    /**
     * Výjimky z minima, které ZE ZÁKONA plynou z jiné, už doložené evidence
     * osoby, účetní je nemá zadávat podruhé.
     *
     * - Ověřená sleva na pojistném pracujícího důchodce (§ 7d zákona
     *   č. 589/1992 Sb.) náleží jen poživateli starobního důchodu. Za
     *   poživatele důchodu platí pojistné i stát (§ 7 odst. 1 písm. b) zákona
     *   č. 48/1997 Sb.) a na takovou osobu se minimum nevztahuje (§ 3 odst. 8
     *   písm. d) zákona č. 592/1992 Sb.), v části měsíce se poměrně snižuje
     *   (odst. 9 písm. c)).
     * - Ověřená sleva na dani pro držitele průkazu ZTP/P (§ 35ba odst. 1
     *   písm. e) ZDP) dokládá průkaz ZTP/P, a ten zakládá výjimku podle § 3
     *   odst. 8 písm. a) zákona č. 592/1992 Sb.
     *
     * Záměrně NE:
     * - `not_claimed` / `unverified` u slevy důchodce: neuplatnění slevy nic
     *   neříká o tom, jestli osoba důchod pobírá;
     * - slevy na invaliditu (§ 35ba odst. 1 písm. c) a d) ZDP) se přiznávají
     *   i tomu, komu nárok na invalidní důchod nevznikl, takže státního
     *   pojištěnce nedokládají. Invalidní důchodce se zadá jako výjimka ručně.
     *
     * Odkaz na doklad nese původ (`social_discount_claim:{id}`,
     * `tax_credit_claim:{id}`), ať je ve snímku výpočtu vidět, odkud výjimka
     * přišla.
     *
     * @param array<string,mixed> $evidence
     * @return list<HealthMinimumReductionInterval>
     */
    private static function derivedHealthReductions(
        array $evidence,
        string $periodStart,
        string $periodEnd,
    ): array {
        $sources = [];
        $discount = $evidence['social']['working_pensioner_discount'] ?? null;
        if (is_array($discount) && ($discount['status'] ?? null) === 'verified') {
            $sources[] = [
                $discount,
                HealthMinimumReductionReason::StateInsured,
                'social_discount_claim',
            ];
        }
        $credits = $evidence['income_tax']['credit_claims'] ?? null;
        foreach (is_array($credits) ? $credits : [] as $credit) {
            if (is_array($credit)
                && ($credit['credit_kind'] ?? null) === 'ztp-p'
                && ($credit['evidence_status'] ?? null) === 'verified'
            ) {
                $sources[] = [
                    $credit,
                    HealthMinimumReductionReason::ZtpOrZtpP,
                    'tax_credit_claim',
                ];
            }
        }

        $result = [];
        foreach ($sources as [$row, $reason, $origin]) {
            $from = $row['effective_from'] ?? null;
            $to = $row['effective_to'] ?? null;
            $id = $row['id'] ?? null;
            if (!is_string($from) || ($to !== null && !is_string($to)) || !is_int($id)
                || $from > $periodEnd || ($to !== null && $to < $periodStart)
            ) {
                continue;
            }
            $result[] = new HealthMinimumReductionInterval(
                max($from, $periodStart),
                $to === null ? $periodEnd : min($to, $periodEnd),
                $reason,
                "{$origin}:{$id}",
            );
        }

        return $result;
    }

    /** @return list<HealthMinimumReductionInterval> */
    private function healthReductions(
        mixed $raw,
        string $personReference,
        string $periodEnd,
    ): array {
        $rows = $this->list($raw);
        if ($rows === null) {
            $this->issue(
                'health_insurance',
                'health_minimum_reductions_invalid',
                $personReference,
            );
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->issue(
                    'health_insurance',
                    'health_minimum_reduction_invalid',
                    $personReference,
                );
                continue;
            }
            $reason = $this->enum(
                HealthMinimumReductionReason::class,
                $row['reason'] ?? null,
            );
            if (!$reason instanceof HealthMinimumReductionReason
                || $reason === HealthMinimumReductionReason::Unverified
            ) {
                $this->issue(
                    'health_insurance',
                    'health_minimum_reduction_unverified',
                    $personReference,
                );
                continue;
            }
            try {
                $result[] = new HealthMinimumReductionInterval(
                    $this->requiredString($row['effective_from'] ?? null),
                    $this->requiredString(
                        $row['effective_to'] ?? $periodEnd,
                    ),
                    $reason,
                    $this->nullableString($row['evidence_reference'] ?? null),
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $this->issue(
                    'health_insurance',
                    'health_minimum_reduction_invalid',
                    $personReference,
                );
            }
        }
        return $result;
    }

    /** @return list<HealthOtherEmployerBase> */
    private function healthOtherEmployers(
        mixed $raw,
        string $personReference,
    ): array {
        $rows = $this->list($raw);
        if ($rows === null) {
            $this->issue(
                'health_insurance',
                'health_other_employer_evidence_invalid',
                $personReference,
            );
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->issue(
                    'health_insurance',
                    'health_other_employer_evidence_invalid',
                    $personReference,
                );
                continue;
            }
            try {
                $result[] = new HealthOtherEmployerBase(
                    $this->requiredString($row['employer_reference'] ?? null),
                    $this->requiredNonNegativeInt(
                        $row['assessment_base_minor_units'] ?? null,
                    ),
                    $this->requiredString($row['employment_from'] ?? null),
                    $this->nullableString($row['employment_to'] ?? null),
                    $this->nullableString($row['evidence_reference'] ?? null),
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $this->issue(
                    'health_insurance',
                    'health_other_employer_evidence_invalid',
                    $personReference,
                );
            }
        }
        return $result;
    }

    /** @return list<TaxCreditClaim> */
    private function taxCredits(mixed $raw, string $personReference): array
    {
        $rows = $this->list($raw);
        if ($rows === null) {
            $this->issue(
                'income_tax',
                'tax_credit_evidence_invalid',
                $personReference,
            );
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->issue(
                    'income_tax',
                    'tax_credit_evidence_invalid',
                    $personReference,
                );
                continue;
            }
            $status = $this->enum(
                TaxEvidenceStatus::class,
                $row['evidence_status'] ?? null,
            );
            $kind = $this->enum(TaxCreditKind::class, $row['credit_kind'] ?? null);
            if (!$status instanceof TaxEvidenceStatus
                || !$kind instanceof TaxCreditKind
                || $status === TaxEvidenceStatus::Unverified
            ) {
                $this->issue(
                    'income_tax',
                    'tax_credit_evidence_unverified',
                    $personReference,
                );
                continue;
            }
            try {
                $result[] = new TaxCreditClaim(
                    $kind,
                    $this->requiredString($row['effective_from'] ?? null),
                    $this->nullableString($row['effective_to'] ?? null),
                    $status,
                    $this->nullableString($row['evidence_reference'] ?? null),
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $this->issue(
                    'income_tax',
                    'tax_credit_evidence_invalid',
                    $personReference,
                );
            }
        }
        return $result;
    }

    /** @return list<TaxChildClaim> */
    private function taxChildren(mixed $raw, string $personReference): array
    {
        $rows = $this->list($raw);
        if ($rows === null) {
            $this->issue(
                'income_tax',
                'tax_child_evidence_invalid',
                $personReference,
            );
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->issue(
                    'income_tax',
                    'tax_child_evidence_invalid',
                    $personReference,
                );
                continue;
            }
            $status = $this->enum(
                TaxEvidenceStatus::class,
                $row['evidence_status'] ?? null,
            );
            if (!$status instanceof TaxEvidenceStatus
                || $status === TaxEvidenceStatus::Unverified
            ) {
                $this->issue(
                    'income_tax',
                    'tax_child_evidence_unverified',
                    $personReference,
                );
                continue;
            }
            // Chybějící `credit_status` = uplatňované dítě: snímek ho nese jen
            // u dítěte „N" (PayrollPersonStatutoryEvidenceValidator).
            $creditStatus = $row['credit_status'] ?? 'claimed';
            if (!in_array($creditStatus, ['claimed', 'claimed_by_other'], true)) {
                $this->issue(
                    'income_tax',
                    'tax_child_evidence_invalid',
                    $personReference,
                );
                continue;
            }
            try {
                $result[] = new TaxChildClaim(
                    $this->requiredString($row['child_reference'] ?? null),
                    $this->requiredPositiveInt($row['child_order'] ?? null),
                    $this->requiredBool($row['ztp_p'] ?? null),
                    $this->requiredString($row['effective_from'] ?? null),
                    $this->nullableString($row['effective_to'] ?? null),
                    $status,
                    $this->requiredBool(
                        $row['shared_household_confirmed'] ?? null,
                    ),
                    $this->requiredBool(
                        $row['other_claimant_excluded'] ?? null,
                    ),
                    $this->nullableString($row['evidence_reference'] ?? null),
                    $creditStatus === 'claimed',
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $this->issue(
                    'income_tax',
                    'tax_child_evidence_invalid',
                    $personReference,
                );
            }
        }
        return $result;
    }

    /**
     * Typ odloženého příjmu (JMHZ 10548) potvrzený za vztah a měsíc,
     * zmrazený ve vstupu běhu; `null` = nepotvrzeno.
     *
     * @param array<string,mixed> $snapshot
     */
    /**
     * Cizí právní předpisy se evidují na dvou místech: v zákonné evidenci
     * osoby (příslušnost a A1, podle ní se počítá pojistné) a v podmínkách
     * vztahu (účast „zahraniční", stát cizích předpisů a platnost A1, z nich
     * vychází REGZEC A1 a odvod na spoření). Výpočet bere jako jediný zdroj
     * evidenci osoby; podmínky vztahu jí nesmí odporovat, jinak by registrace
     * tvrdila jiný stát nebo režim, než podle jakého se odvedlo pojistné.
     *
     * @param array<string,mixed> $row
     * @param list<mixed> $employments
     */
    private function assertTermLegislationMatches(
        SocialJurisdictionEvidence $jurisdiction,
        array $row,
        array $employments,
        string $personReference,
    ): void {
        if ($jurisdiction === SocialJurisdictionEvidence::Unverified) {
            return;
        }
        $foreign = $jurisdiction === SocialJurisdictionEvidence::ForeignRegimeVerified;
        $country = $foreign ? ($row['foreign_country_code'] ?? null) : null;
        $a1Until = $foreign && ($row['a1_status'] ?? null) === 'verified' ? ($row['a1_valid_until'] ?? null) : null;
        foreach ($employments as $snapshot) {
            $term = is_array($snapshot) ? ($snapshot['term'] ?? null) : null;
            $employment = is_array($snapshot) ? ($snapshot['employment'] ?? null) : null;
            if (!is_array($term)) {
                continue;
            }
            $termForeign = ($term['social_insurance_participation'] ?? null) === 'foreign';
            $termCountry = $term['foreign_legislation_country_code'] ?? null;
            $termA1 = $term['a1_certificate_until'] ?? null;
            $conflict = $termForeign !== $foreign
                || ($termForeign && is_string($termCountry) && $termCountry !== $country)
                || (!$foreign && is_string($termA1) && $termA1 !== '')
                || ($foreign && is_string($termA1) && $termA1 !== '' && $termA1 !== $a1Until);
            if ($conflict) {
                $employmentId = is_array($employment) ? $this->positiveInt($employment['id'] ?? null) : null;
                $this->issue(
                    'social_insurance',
                    'social_jurisdiction_term_conflict',
                    $personReference,
                    $employmentId === null ? null : "employment:{$employmentId}",
                );
            }
        }
    }

    /**
     * Trvá u plátce v měsíci aspoň jeden vztah osoby? Skončený vztah má
     * `end_date` před začátkem měsíce; vztah bez data skončení trvá.
     *
     * @param list<mixed> $employments
     */
    private static function employedInPeriod(array $employments, string $periodStart): bool
    {
        foreach ($employments as $snapshot) {
            $employment = is_array($snapshot) ? ($snapshot['employment'] ?? null) : null;
            if (!is_array($employment)) {
                return true;
            }
            $end = $employment['end_date'] ?? null;
            if (!is_string($end) || $end >= $periodStart) {
                return true;
            }
        }

        return $employments === [];
    }

    private static function deferredIncomeType(array $snapshot): ?string
    {
        $deferred = $snapshot['deferred_income'] ?? null;
        $type = is_array($deferred) ? ($deferred['deferred_type'] ?? null) : null;

        return is_string($type) ? $type : null;
    }

    /**
     * @param array<string,mixed> $employment
     * @return array{string,?string}|null
     */
    private function employmentDates(array $employment): ?array
    {
        $from = $this->date(
            $employment['actual_start_date']
                ?? $employment['start_date']
                ?? null,
        );
        $to = $employment['end_date'] ?? null;
        if ($from === null
            || ($to !== null && $this->date($to) === null)
            || (is_string($to) && $to < $from)
        ) {
            return null;
        }
        return [$from, is_string($to) ? $to : null];
    }

    private function issue(
        string $domain,
        string $code,
        ?string $personReference = null,
        ?string $relationshipReference = null,
    ): void {
        $this->issues[] = new PayrollRunStatutoryInputIssue(
            $domain,
            $code,
            $personReference,
            $relationshipReference,
        );
    }

    private function sortAndDeduplicateIssues(): void
    {
        $unique = [];
        foreach ($this->issues as $issue) {
            $unique[$issue->sortKey()] = $issue;
        }
        ksort($unique, SORT_STRING);
        $this->issues = array_values($unique);
    }

    private function invalidSnapshot(string $code): PayrollRunStatutoryInputBundle
    {
        $this->issue('snapshot', $code);
        return new PayrollRunStatutoryInputBundle(
            null,
            null,
            [],
            $this->issues,
        );
    }

    /** @return array<string,mixed>|null */
    private function object(mixed $value): ?array
    {
        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    /** @return list<mixed>|null */
    private function list(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value) ? $value : null;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = $this->integer($value);
        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        $integer = $this->integer($value);
        return $integer !== null && $integer >= 0 ? $integer : null;
    }

    private function requiredPositiveInt(mixed $value): int
    {
        return $this->positiveInt($value)
            ?? throw new \UnexpectedValueException('Hodnota musí být kladná.');
    }

    private function requiredNonNegativeInt(mixed $value): int
    {
        return $this->nonNegativeInt($value)
            ?? throw new \UnexpectedValueException('Hodnota musí být nezáporná.');
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->nonEmptyString($value);
    }

    private function requiredString(mixed $value): string
    {
        return $this->nonEmptyString($value)
            ?? throw new \UnexpectedValueException('Hodnota musí být text.');
    }

    private function requiredBool(mixed $value): bool
    {
        return is_bool($value)
            ? $value
            : throw new \UnexpectedValueException('Hodnota musí být boolean.');
    }

    private function personId(string $personReference): int
    {
        return (int) substr($personReference, strlen('employee:'));
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value
            ? $value
            : null;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    private function enum(string $enum, mixed $value): ?\BackedEnum
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return $enum::from($value);
        } catch (\ValueError) {
            return null;
        }
    }
}
