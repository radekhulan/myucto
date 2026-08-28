<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

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
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKindMapper;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\OtherWithholdingEligibility;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipKindMapper;
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

            $before = count($this->issues);
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
            if ($social !== null
                && !$this->hasDomainIssueSince('social_insurance', $before)
            ) {
                $socialPeople[] = $social;
            }

            $before = count($this->issues);
            $health = $this->healthPerson(
                $evidence,
                $employments,
                $personReference,
                $periodStart,
                $periodEnd,
            );
            if ($health !== null
                && !$this->hasDomainIssueSince('health_insurance', $before)
            ) {
                $healthPeople[] = $health;
            }

            $before = count($this->issues);
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
            if ($tax !== null
                && !$this->hasDomainIssueSince('income_tax', $before)
            ) {
                $incomeTax[] = $tax;
            }
        }

        if ($people === []) {
            foreach (
                ['social_insurance', 'health_insurance', 'income_tax'] as $domain
            ) {
                $this->issue($domain, 'person_missing');
            }
        }
        $this->sortAndDeduplicateIssues();

        $socialInput = $this->hasDomainIssue('social_insurance')
            || $socialPeople === []
            ? null
            : new SocialInsuranceMonthInput($socialDate, $socialPeople);
        $healthInput = $this->hasDomainIssue('health_insurance')
            || $healthPeople === []
            ? null
            : new HealthInsuranceMonthInput($healthDate, $healthPeople);
        if ($this->hasDomainIssue('income_tax')) {
            $incomeTax = [];
        }

        return new PayrollRunStatutoryInputBundle(
            $socialInput,
            $healthInput,
            $incomeTax,
            $this->issues,
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
        $socialEvidence = $this->object($evidence['social'] ?? null);
        $jurisdictionRow = $this->object($socialEvidence['jurisdiction'] ?? null);
        if ($jurisdictionRow === null) {
            $this->issue(
                'social_insurance',
                'social_jurisdiction_evidence_missing',
                $personReference,
            );
            return null;
        }
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
            return null;
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

        $discountRow = $this->object(
            $socialEvidence['working_pensioner_discount'] ?? null,
        );
        if ($discountRow === null) {
            $this->issue(
                'social_insurance',
                'working_pensioner_discount_evidence_missing',
                $personReference,
            );
            return null;
        }
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
        );
        if ($components === []) {
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
     * verze `jmhz-work-month.v2`. Bez něj nelze úhrn sestavit a hodiny se
     * nevracejí vůbec — kalkulátor pak nárok neuplatní a měsíc jde na ruční
     * posouzení.
     */
    private function socialPartTimeDiscountHours(mixed $timeMonth): ?int
    {
        $month = $this->object($timeMonth);
        $summary = $this->object($month['jmhz_work_summary'] ?? null);
        if ($summary === null
            || ($summary['derivation_version'] ?? null) !== 'jmhz-work-month.v2'
        ) {
            return null;
        }
        $values = $this->object($summary['values'] ?? null);
        if ($values === null) {
            return null;
        }
        $worked = $this->nonNegativeInt($values['worked_millihours'] ?? null);
        $paidUnworked = $this->nonNegativeInt($values['unworked_paid_millihours'] ?? null);
        if ($worked === null || $paidUnworked === null) {
            return null;
        }

        return $worked + $paidUnworked;
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

        $reductions = $this->healthReductions(
            $healthEvidence['minimum_reductions'] ?? null,
            $personReference,
            $periodEnd,
        );
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
                    ? $this->nullableString(
                        $monthEvidenceRow[
                            'top_up_responsibility_evidence_reference'
                        ] ?? null,
                    )
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
        );
        if ($components === []) {
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
        $signed = $term['tax_declaration_signed'] ?? null;
        $evidenceSigned = $declarationStatus === TaxDeclarationStatus::Signed;
        if (!is_bool($signed) || $signed !== $evidenceSigned) {
            $this->issue(
                'income_tax',
                'tax_declaration_term_conflict',
                $personReference,
                $relationshipReference,
            );
        }
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
        );
        if ($components === []) {
            return null;
        }

        [$eligibility, $classificationEvidence] = $this->otherWithholdingEligibility(
            $kind,
            $term,
            $relationshipReference,
        );

        return new EmploymentRelationshipTaxInput(
            $relationshipReference,
            "supplier:{$supplierId}",
            $kind,
            $components,
            $eligibility,
            $classificationEvidence,
        );
    }

    /**
     * Zařazení vztahu pro § 6 odst. 4 písm. b) ZDP.
     *
     * U pracovního poměru, zaměstnání malého rozsahu a DPP plyne odpověď ze
     * samotného druhu vztahu, takže se posílá `Automatic` a zařadí si ho výpočet.
     * U odměny jednatele nebo člena statutárního orgánu, u DPČ a u společníka
     * konajícího práci pro s. r. o. to z druhu vztahu poznat nejde — rozhoduje,
     * jestli sjednaná odměna dosahuje rozhodné částky pro účast na nemocenském
     * pojištění — a odpověď proto nese prohlášení plátce ve smluvních podmínkách.
     *
     * Neznámá nebo nevyplněná hodnota končí na `Unverified`, tedy ručním
     * posouzením. Je to fail-closed záměrně: běhy spočítané před migrací 1403
     * mají snapshot bez tohohle klíče a nesmí se dopočítat jinak, než jak by je
     * spočítal tehdejší kód.
     *
     * @param array<string,mixed> $term
     * @return array{OtherWithholdingEligibility,?string}
     */
    private function otherWithholdingEligibility(
        EmploymentRelationshipKind $kind,
        array $term,
        string $relationshipReference,
    ): array {
        if (!$kind->requiresOtherWithholdingStatement()) {
            return [OtherWithholdingEligibility::Automatic, null];
        }
        $eligibility = match ($term['other_withholding_eligibility'] ?? null) {
            'eligible' => OtherWithholdingEligibility::EligibleVerified,
            'ineligible' => OtherWithholdingEligibility::IneligibleVerified,
            default => OtherWithholdingEligibility::Unverified,
        };
        if ($eligibility === OtherWithholdingEligibility::Unverified) {
            return [$eligibility, null];
        }
        // Doklad o zařazení je ta verze smluvních podmínek, ve které plátce
        // prohlášení uložil — je effective-dated a nese autora i důvod změny.
        $termId = $this->positiveInt($term['id'] ?? null);

        return [
            $eligibility,
            $termId === null
                ? $relationshipReference
                : "employment-term:{$termId}",
        ];
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
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'social_insurance',
            $personReference,
            $relationshipReference,
        );
        $result = [];
        foreach ($inputs as $input) {
            if (!$this->assertCurrentNonNegativeComponent(
                $input,
                'social_insurance',
                $personReference,
                $relationshipReference,
                $periodStart,
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
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'health_insurance',
            $personReference,
            $relationshipReference,
        );
        $result = [];
        foreach ($inputs as $input) {
            if (!$this->assertCurrentNonNegativeComponent(
                $input,
                'health_insurance',
                $personReference,
                $relationshipReference,
                $periodStart,
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
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'income_tax',
            $personReference,
            $relationshipReference,
        );
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

    /** @return list<array<string,mixed>> */
    private function componentInputs(
        mixed $raw,
        string $domain,
        string $personReference,
        string $relationshipReference,
    ): array {
        $inputs = $this->list($raw);
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

    /** @param array<string,mixed> $input */
    private function assertCurrentNonNegativeComponent(
        array $input,
        string $domain,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
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
        if ($amount < 0) {
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

    private function hasDomainIssue(string $domain): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->domain === $domain || $issue->domain === 'snapshot') {
                return true;
            }
        }
        return false;
    }

    private function hasDomainIssueSince(string $domain, int $offset): bool
    {
        foreach (array_slice($this->issues, $offset) as $issue) {
            if ($issue->domain === $domain || $issue->domain === 'snapshot') {
                return true;
            }
        }
        return false;
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
