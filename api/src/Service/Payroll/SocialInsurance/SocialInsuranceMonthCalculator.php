<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use LogicException;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployeeSocialInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployeeSocialInsuranceInput;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceEmployeeInput;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceInput;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use OverflowException;
use UnexpectedValueException;

final class SocialInsuranceMonthCalculator
{
    private readonly SocialAssessmentBaseResolver $assessmentBaseResolver;
    private readonly SocialParticipationResolver $participationResolver;
    private readonly MonthlyEmployeeSocialInsuranceCalculator $employeeCalculator;
    private readonly MonthlyEmployerSocialInsuranceCalculator $employerCalculator;

    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
        ?SocialAssessmentBaseResolver $assessmentBaseResolver = null,
        ?SocialParticipationResolver $participationResolver = null,
    ) {
        $this->assessmentBaseResolver = $assessmentBaseResolver ??
            new SocialAssessmentBaseResolver();
        $this->participationResolver = $participationResolver ??
            new SocialParticipationResolver();
        $this->employeeCalculator = new MonthlyEmployeeSocialInsuranceCalculator($rulesets);
        $this->employerCalculator = new MonthlyEmployerSocialInsuranceCalculator($rulesets);
    }

    public function calculate(SocialInsuranceMonthInput $input): SocialInsuranceMonthResult
    {
        $ruleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::SocialInsurance,
            $input->calculationDate,
        );
        $smallScaleThreshold = $this->moneyParameter(
            $ruleset,
            'participation.small_scale.minimum',
        );
        $dppThreshold = $this->moneyParameter(
            $ruleset,
            'participation.dpp.minimum',
        );

        $peopleInputs = $input->people;
        usort(
            $peopleInputs,
            static fn (SocialPersonMonthInput $left, SocialPersonMonthInput $right): int =>
                $left->personId <=> $right->personId,
        );

        $people = [];
        $issues = [];
        foreach ($peopleInputs as $personInput) {
            $person = $this->calculatePerson(
                $input->calculationDate,
                $ruleset,
                $personInput,
                $smallScaleThreshold,
                $dppThreshold,
            );
            $people[] = $person;
            foreach ($person->issues as $issue) {
                $issues[] = "person:{$person->personId}:{$issue}";
            }
        }
        sort($issues, SORT_STRING);

        if ($issues !== []) {
            return new SocialInsuranceMonthResult(
                $input->calculationDate,
                SocialCalculationStatus::ManualReview,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                [],
                $people,
                $issues,
                $ruleset->id,
                $ruleset->canonicalHash,
            );
        }

        $participatingBase = 0;
        $cappedBase = 0;
        $employeeContribution = 0;
        $partTimeDiscountBase = 0;
        $employerInputs = [];
        $categoryBases = [];
        foreach ($people as $person) {
            $participatingBase = $this->add(
                $participatingBase,
                $person->participatingAssessmentBaseMinorUnits,
            );
            $cappedBase = $this->add($cappedBase, $person->cappedAssessmentBaseMinorUnits);
            $employeeContribution = $this->add(
                $employeeContribution,
                $person->employeeContributionMinorUnits ?? 0,
            );

            $participates = false;
            foreach ($person->relationships as $relationship) {
                $participates = $participates
                    || $relationship->participation->status ===
                        SocialParticipationStatus::Participates;
                if (
                    $relationship->partTimeEmployerDiscount ===
                        SocialDiscountEvidence::Verified
                    && $relationship->partTimeEmployerDiscountOutcome ===
                        SocialPartTimeDiscountOutcome::Applied
                ) {
                    $partTimeDiscountBase = $this->add(
                        $partTimeDiscountBase,
                        $relationship->cappedAssessmentBaseMinorUnits,
                    );
                }
                /*
                 * § 5a odst. 1 dělí vyměřovací základ zaměstnavatele podle
                 * kategorie ZAMĚSTNANCE, ale rozhodující je vztah: jeden
                 * člověk může mít u téhož zaměstnavatele rizikový i běžný
                 * vztah a každý spadne pod jiné písmeno. Sčítá se proto
                 * vztahový podíl základu po ročním maximu — přesně ta částka,
                 * kterou podání vykazuje pod 10478/10479/10480.
                 */
                $categoryValue = $relationship->employerRateCategory->value;
                $categoryBases[$categoryValue] = $this->add(
                    $categoryBases[$categoryValue] ?? 0,
                    $relationship->cappedAssessmentBaseMinorUnits,
                );
            }

            $employerInputs[] = new MonthlyEmployerSocialInsuranceEmployeeInput(
                $person->participatingAssessmentBaseMinorUnits,
                $person->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                $participates,
                false,
            );
        }

        $employer = $this->employerCalculator->calculate(
            $input->calculationDate,
            new MonthlyEmployerSocialInsuranceInput($employerInputs),
        );
        if ($employer->cappedAssessmentBaseMinorUnits !== $cappedBase) {
            throw new LogicException(
                'Employee and employer annual-maximum allocations must have the same total.',
            );
        }
        [$employerCategories, $employerBeforeDiscount] = $this->employerCategoryResults(
            $ruleset,
            $categoryBases,
            $cappedBase,
        );
        /*
         * Souběh kategorií mění firemní částku, jedna kategorie ne. Když je
         * v měsíci jen běžná sazba, MUSÍ kategoriální výpočet vyjít na haléř
         * stejně jako souhrnný kalkulátor zaměstnavatele — jinak se dvě cesty
         * k témuž číslu rozešly a nikdo by si toho nevšiml.
         */
        $onlyOrdinary = array_filter(
            $employerCategories,
            static fn (SocialEmployerCategoryResult $category): bool =>
                $category->category !== SocialEmployerRateCategory::Ordinary,
        ) === [];
        if ($onlyOrdinary && $employerBeforeDiscount !== $employer->contributionBeforeDiscountMinorUnits) {
            throw new LogicException(
                'Ordinary-only employer contribution must match the aggregate calculator.',
            );
        }

        $discountStep = null;
        $discount = 0;
        if ($partTimeDiscountBase > 0) {
            $discountStep = CalculationStep::calculate(
                'monthly-employer-part-time-discount-verified-relationships',
                $partTimeDiscountBase,
                $this->rateParameter($ruleset, 'employer.discount.part_time'),
                RoundingMode::Ceil,
            );
            /*
             * § 7c odst. 1: slevu zaměstnavatel odečte „z částky pojistného
             * stanoveného podle § 5a a § 7 odst. 1 písm. a) až c)" — tedy
             * z celkového pojistného za všechny kategorie, ne uvnitř jedné.
             */
            $discount = min(
                $employerBeforeDiscount,
                PayrollRounding::ceilToCzk($discountStep->outputMinorUnits),
            );
        }

        return new SocialInsuranceMonthResult(
            $input->calculationDate,
            SocialCalculationStatus::Calculated,
            $participatingBase,
            $cappedBase,
            $employeeContribution,
            $employerBeforeDiscount,
            $partTimeDiscountBase,
            $discount,
            $employerBeforeDiscount - $discount,
            count($employerCategories) === 1 ? $employerCategories[0]->contributionStep : null,
            $discountStep,
            $employerCategories,
            $people,
            [],
            $ruleset->id,
            $ruleset->canonicalHash,
        );
    }

    /**
     * Vyměřovací základy a pojistné zaměstnavatele po kategoriích § 5a odst. 1.
     *
     * Zaokrouhluje se PO KATEGORII, ne až ze součtu: § 7 odst. 1 dává každému
     * ze tří základů vlastní sazbu a § 7 odst. 3 pak zaokrouhluje pojistné
     * nahoru na celé koruny. Sečíst pojistné a zaokrouhlit jednou by u firmy
     * se dvěma kategoriemi dalo jinou částku, než jakou ČSSZ počítá
     * v kontrolách 8, 10 a 167 nad JMHZ (10024 ze 10023, 10026 ze 10025,
     * 10484 ze 10483, a teprve 10027 je jejich součet).
     *
     * Dílčí základ se tu už zaokrouhlovat nemusí: § 5d se uplatňuje o řád níž,
     * na vyměřovacím základu vztahu podle § 5 ({@see SocialAssessmentBaseResolver}).
     * Vyměřovací základ zaměstnavatele podle § 5a odst. 1 je jejich úhrn, takže
     * je celými korunami sám od sebe a druhé zaokrouhlení by bylo bez účinku.
     * Kdyby se zaokrouhlovalo teprve tady, rozešel by se rozpad kategorií
     * se souhrnným kalkulátorem u firem s jedinou kategorií.
     *
     * @param array<string,int> $categoryBases
     * @return array{0:list<SocialEmployerCategoryResult>,1:int}
     */
    private function employerCategoryResults(
        PayrollRulesetVersion $ruleset,
        array $categoryBases,
        int $cappedBase,
    ): array {
        if (array_sum($categoryBases) !== $cappedBase) {
            throw new LogicException(
                'Employer rate categories must partition the capped assessment base.',
            );
        }
        $statutory = array_map(
            static fn (SocialEmployerRateCategory $category): string => $category->value,
            SocialEmployerRateCategory::statutoryOrder(),
        );
        if (array_diff(array_keys($categoryBases), $statutory) !== []) {
            throw new LogicException(
                'A calculated month cannot carry an unverified employer rate category.',
            );
        }
        $categories = [];
        $total = 0;
        foreach (SocialEmployerRateCategory::statutoryOrder() as $category) {
            if (!array_key_exists($category->value, $categoryBases)) {
                continue;
            }
            $base = $categoryBases[$category->value];
            $step = CalculationStep::calculate(
                "monthly-employer-social-insurance-{$category->value}",
                $base,
                $this->rateParameter($ruleset, $category->rateParameter()),
                RoundingMode::Ceil,
            );
            $contribution = PayrollRounding::ceilToCzk($step->outputMinorUnits);
            $total = $this->add($total, $contribution);
            $categories[] = new SocialEmployerCategoryResult(
                $category,
                $base,
                $contribution,
                $step,
            );
        }

        return [$categories, $total];
    }

    private function calculatePerson(
        string $calculationDate,
        PayrollRulesetVersion $ruleset,
        SocialPersonMonthInput $input,
        int $smallScaleThreshold,
        int $dppThreshold,
    ): SocialPersonMonthResult {
        $relationships = $input->relationships;
        usort(
            $relationships,
            static fn (
                SocialInsuranceRelationshipInput $left,
                SocialInsuranceRelationshipInput $right,
            ): int => $left->relationshipId <=> $right->relationshipId,
        );
        $facts = array_map(
            fn (SocialInsuranceRelationshipInput $relationship): SocialRelationshipFacts =>
                $this->assessmentBaseResolver->resolve($relationship),
            $relationships,
        );

        if ($input->jurisdiction !== SocialJurisdictionEvidence::CzechRegimeVerified) {
            return $this->nonCzechPersonResult($input, $facts, $smallScaleThreshold, $dppThreshold);
        }

        $decisions = $this->participationResolver->resolve(
            $facts,
            $smallScaleThreshold,
            $dppThreshold,
        );
        $issues = [];
        $verifiedPartTimeClaims = 0;
        foreach ($facts as $fact) {
            $relationship = $fact->relationship;
            $decision = $decisions[$relationship->relationshipId];
            if ($decision->status === SocialParticipationStatus::ManualReview) {
                foreach ($decision->reasonCodes as $reason) {
                    $issues[] = "relationship:{$relationship->relationshipId}:{$reason}";
                }
            }
            if ($relationship->partTimeEmployerDiscount === SocialDiscountEvidence::Unverified) {
                $issues[] =
                    "relationship:{$relationship->relationshipId}:part_time_discount_unverified";
            }
            if ($relationship->partTimeEmployerDiscount === SocialDiscountEvidence::Verified) {
                $verifiedPartTimeClaims++;
                if ($relationship->kind !== SocialEmploymentKind::Employment) {
                    $issues[] =
                        "relationship:{$relationship->relationshipId}:part_time_discount_relationship_kind_unsupported";
                }
            }
            /*
             * Doložená kategorie § 5a odst. 1 se počítá vlastní sazbou; ruční
             * posouzení si vynutí jen NEDOLOŽENÉ zařazení. Dřív blokovalo běh
             * každé písmeno mimo a) — jenže tím se nikdy neodvedlo správně,
             * a hlavně: zařazení, které do vstupu nikdy nedoteklo, se tvářilo
             * jako běžná sazba a odvedlo o 3 procentní body míň.
             */
            if ($relationship->employerRateCategory === SocialEmployerRateCategory::Unverified) {
                $issues[] =
                    "relationship:{$relationship->relationshipId}:employer_rate_category_unverified";
            }
            if ($relationship->agricultureDppEmployeeDiscountRequested) {
                $issues[] =
                    "relationship:{$relationship->relationshipId}:agriculture_dpp_discount_requires_manual_review";
            }
        }
        if ($verifiedPartTimeClaims > 1) {
            $issues[] = 'part_time_discount_may_select_only_one_relationship_per_person';
        }
        if ($verifiedPartTimeClaims === 1) {
            foreach ($this->partTimeDiscountDataGaps($facts) as $gap) {
                $issues[] = $gap;
            }
        }
        if ($input->workingPensionerDiscount === SocialDiscountEvidence::Unverified) {
            $issues[] = 'working_pensioner_discount_unverified';
        }
        $participatingBase = 0;
        $participates = false;
        foreach ($facts as $fact) {
            $decision = $decisions[$fact->relationship->relationshipId];
            if ($decision->status !== SocialParticipationStatus::Participates) {
                continue;
            }
            $participates = true;
            $participatingBase = $this->add(
                $participatingBase,
                $fact->assessmentBaseMinorUnits,
            );
        }
        /*
         * Neznámý úhrn předchozích měsíců roku (převzetí mezd v průběhu roku
         * bez počátečních stavů) mění pojistné jen přes roční maximální
         * vyměřovací základ (§ 15a zákona č. 589/1992 Sb.). Ten nemohl být
         * dosažen, když ani měsíční základ násobený počtem měsíců roku do
         * tohoto včetně na něj nedosáhne; jinak výpočet počká na počáteční stav.
         */
        if ($input->yearToDateUnknown && $participatingBase > 0) {
            $months = (int) substr($calculationDate, 5, 2);
            if ($participatingBase * $months >= $this->moneyParameter($ruleset, 'maximum_assessment_base.yearly')) {
                $issues[] = 'annual_accumulator_missing';
            }
        }
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);

        if ($issues !== []) {
            return new SocialPersonMonthResult(
                $input->personId,
                SocialCalculationStatus::ManualReview,
                $input->jurisdiction,
                $input->jurisdictionEvidenceReference,
                $input->workingPensionerDiscountEvidenceReference,
                $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                $participatingBase,
                0,
                null,
                null,
                null,
                null,
                null,
                $this->relationshipResults($facts, $decisions, []),
                $issues,
            );
        }

        $employee = $this->employeeCalculator->calculate(
            $calculationDate,
            new MonthlyEmployeeSocialInsuranceInput(
                $participatingBase,
                $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                $participates,
                $participates
                    && $input->workingPensionerDiscount === SocialDiscountEvidence::Verified,
            ),
        );
        if (
            $employee->cappedAssessmentBaseMinorUnits < $participatingBase
            && $this->participatingRelationshipCount($decisions) > 1
            && !$this->hasUnambiguousMaximumAllocationOrder($facts, $decisions)
        ) {
            $issues = ['annual_maximum_relationship_allocation_order_required'];

            return new SocialPersonMonthResult(
                $input->personId,
                SocialCalculationStatus::ManualReview,
                $input->jurisdiction,
                $input->jurisdictionEvidenceReference,
                $input->workingPensionerDiscountEvidenceReference,
                $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                $participatingBase,
                0,
                null,
                null,
                null,
                null,
                null,
                $this->relationshipResults($facts, $decisions, []),
                $issues,
            );
        }
        $allocations = $this->allocateCappedBase(
            $facts,
            $decisions,
            $employee->cappedAssessmentBaseMinorUnits,
        );
        $contributions = $this->relationshipEmployeeContributions(
            $ruleset,
            $allocations,
            $participates
                && $input->workingPensionerDiscount === SocialDiscountEvidence::Verified,
        );
        $before = 0;
        $discount = 0;
        $contributing = 0;
        foreach ($contributions as $relationshipId => $contribution) {
            $before = $this->add($before, $contribution['before']);
            $discount = $this->add($discount, $contribution['discount']);
            if ($allocations[$relationshipId] > 0) {
                $contributing++;
            }
        }
        /*
         * Jediný vztah se základem dává po vztazích totéž co výpočet za osobu
         * (stejný základ, stejná sazba, jedno zaokrouhlení). Kdyby ne, rozešla
         * by se dvě cesty k témuž číslu a v měsíčním hlášení by kontrola 118
         * ČSSZ napočítala jiné pojistné než výplatní páska.
         */
        if ($contributing <= 1
            && ($before !== $employee->employeeContributionBeforeDiscountMinorUnits
                || $discount !== $employee->workingPensionerDiscountMinorUnits)
        ) {
            throw new LogicException(
                'Single-relationship employee contribution must match the person calculator.',
            );
        }

        return new SocialPersonMonthResult(
            $input->personId,
            SocialCalculationStatus::Calculated,
            $input->jurisdiction,
            $input->jurisdictionEvidenceReference,
            $input->workingPensionerDiscountEvidenceReference,
            $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
            $participatingBase,
            $employee->cappedAssessmentBaseMinorUnits,
            $before,
            $discount,
            $before - $discount,
            // Víc vztahů se základem se počítá víc kroky; ty nese každý vztah
            // sám, jeden krok za osobu by částku nedal.
            $contributing <= 1 ? $employee->contributionStep : null,
            $contributing <= 1 ? $employee->discountStep : null,
            $this->relationshipResults(
                $facts,
                $decisions,
                $allocations,
                $this->partTimeDiscountOutcomes($ruleset, $facts),
                $contributions,
            ),
            [],
        );
    }

    /**
     * Pojistné zaměstnance a sleva pracujícího důchodce PO VZTAZÍCH.
     *
     * Měsíční hlášení vykazuje pojistné zaměstnance na formuláři každého
     * vztahu (10370) a kontrola 118 ČSSZ (nepropustná) chce na něm přesně
     * 7,1 % z vyměřovacího základu toho formuláře (10477), zaokrouhleno na
     * celé koruny nahoru. Pojistná část pak vykazuje úhrn (10028), který
     * kontrola 12 poměřuje se součtem 10370 přes formuláře, a pokyny MPSV
     * k 10028 zaokrouhlují „v každém jednotlivém případě". Sleva pracujícího
     * důchodce se podle pokynů k 10487 stanovuje a zaokrouhluje „u každého
     * zaměstnance (a každého jeho zaměstnání, má-li jich u zaměstnavatele více)
     * samostatně".
     *
     * Jedno zaokrouhlení z úhrnu vztahů by u osoby se dvěma účastnými vztahy
     * dalo o korunu méně, než kolik formuláře musí vykázat, a hlášení by pak
     * neprošlo buď kontrolou 118, nebo kontrolou 12. Proto se počítá po
     * vztazích z jejich podílu základu po ročním maximu a osoba je součet.
     *
     * @param array<string,int> $allocations
     * @return array<string,array{before:int,discount:int,contribution_step:?CalculationStep,discount_step:?CalculationStep}>
     */
    private function relationshipEmployeeContributions(
        PayrollRulesetVersion $ruleset,
        array $allocations,
        bool $workingPensionerDiscount,
    ): array {
        $contributions = [];
        foreach ($allocations as $relationshipId => $base) {
            if ($base <= 0) {
                $contributions[$relationshipId] = [
                    'before' => 0,
                    'discount' => 0,
                    'contribution_step' => null,
                    'discount_step' => null,
                ];
                continue;
            }
            $contributionStep = CalculationStep::calculate(
                'monthly-employee-social-insurance-relationship',
                $base,
                $this->rateParameter($ruleset, 'employee.rate.ordinary'),
                RoundingMode::Ceil,
            );
            $before = PayrollRounding::ceilToCzk($contributionStep->outputMinorUnits);
            $discountStep = null;
            $discount = 0;
            if ($workingPensionerDiscount) {
                $discountStep = CalculationStep::calculate(
                    'monthly-working-pensioner-social-discount-relationship',
                    $base,
                    $this->rateParameter($ruleset, 'employee.discount.working_pensioner'),
                    RoundingMode::Ceil,
                );
                $discount = min(
                    $before,
                    PayrollRounding::ceilToCzk($discountStep->outputMinorUnits),
                );
            }
            $contributions[$relationshipId] = [
                'before' => $before,
                'discount' => $discount,
                'contribution_step' => $contributionStep,
                'discount_step' => $discountStep,
            ];
        }

        return $contributions;
    }

    /**
     * Chybějící podklady pro posouzení § 7a odst. 2 a 3.
     *
     * Limity odstavce 3 se počítají z ÚHRNU za všechna zaměstnání v pracovním
     * nebo služebním poměru u téhož zaměstnavatele, takže chybějící hodiny
     * u kteréhokoli z nich znemožní posoudit nárok u toho, kde se sleva
     * uplatňuje. Sleva je výhoda zaměstnavatele a § 7c odst. 3 z přeplacené
     * slevy dělá dluh na pojistném — proto se při chybějícím údaji NEUPLATNÍ
     * a měsíc jde na ruční posouzení, nikdy naopak.
     *
     * @param list<SocialRelationshipFacts> $facts
     * @return list<string>
     */
    private function partTimeDiscountDataGaps(array $facts): array
    {
        $reason = null;
        foreach ($facts as $fact) {
            if (
                $fact->relationship->partTimeEmployerDiscount === SocialDiscountEvidence::Verified
            ) {
                $reason = $fact->relationship->partTimeEmployerDiscountReason;
            }
        }
        if ($reason === null) {
            return [];
        }
        $gaps = [];
        foreach ($facts as $fact) {
            $relationship = $fact->relationship;
            if ($relationship->kind !== SocialEmploymentKind::Employment) {
                continue;
            }
            $id = $relationship->relationshipId;
            if ($relationship->partTimeDiscountAssessableMillihours === null) {
                $gaps[] = "relationship:{$id}:part_time_discount_worked_hours_missing";
            }
            if ($relationship->agreedWeeklyWorkingMillihours === null) {
                $gaps[] = "relationship:{$id}:part_time_discount_weekly_working_time_missing";
            }
            if (
                $reason->requiresShorterWorkingTime()
                && ($relationship->partTimeDiscountEmploymentDays === null
                    || $relationship->partTimeDiscountMonthDays === null)
            ) {
                $gaps[] = "relationship:{$id}:part_time_discount_employment_length_missing";
            }
        }

        return $gaps;
    }

    /**
     * Posouzení § 7a odst. 2 a odst. 3 nad ÚHRNEM zaměstnání téže osoby.
     *
     * Doložený nárok ještě není uplatněná sleva. Odstavec 3 vyjmenovává meze,
     * při jejichž překročení sleva „nenáleží", a všechny se počítají z úhrnu za
     * všechna zaměstnání v pracovním nebo služebním poměru u téhož
     * zaměstnavatele — proto se sčítá přes vztahy, ne přes jediný vybraný.
     * Sazba se pak podle § 7b odst. 2 počítá jen z vyměřovacího základu toho
     * zaměstnání, ze kterého se sleva uplatňuje.
     *
     * Vedle výsledku vrací i úhrn sjednané týdenní doby, nad kterým se
     * rozhodlo: týž úhrn vykazuje měsíční hlášení jako rozsah kratší pracovní
     * doby (10373), takže se nesmí počítat podruhé jinde.
     *
     * @param list<SocialRelationshipFacts> $facts
     * @return array<string,array{outcome:SocialPartTimeDiscountOutcome,weekly_millihours:int}>
     */
    private function partTimeDiscountOutcomes(
        PayrollRulesetVersion $ruleset,
        array $facts,
    ): array {
        $claim = null;
        foreach ($facts as $fact) {
            if (
                $fact->relationship->partTimeEmployerDiscount === SocialDiscountEvidence::Verified
            ) {
                $claim = $fact;
            }
        }
        if ($claim === null || $claim->relationship->partTimeEmployerDiscountReason === null) {
            return [];
        }
        $reason = $claim->relationship->partTimeEmployerDiscountReason;

        $baseSum = 0;
        $millihours = 0;
        $weeklyMillihours = 0;
        $employmentDays = 0;
        $monthDays = 0;
        $listedInPartialWorkOverview = false;
        foreach ($facts as $fact) {
            $relationship = $fact->relationship;
            $listedInPartialWorkOverview = $listedInPartialWorkOverview
                || $relationship->listedInPartialWorkOverview;
            if ($relationship->kind !== SocialEmploymentKind::Employment) {
                continue;
            }
            $baseSum = $this->add($baseSum, $fact->assessmentBaseMinorUnits);
            $millihours = $this->add(
                $millihours,
                $relationship->partTimeDiscountAssessableMillihours ?? 0,
            );
            $weeklyMillihours = $this->add(
                $weeklyMillihours,
                $relationship->agreedWeeklyWorkingMillihours ?? 0,
            );
            $employmentDays = max($employmentDays, $relationship->partTimeDiscountEmploymentDays ?? 0);
            $monthDays = max($monthDays, $relationship->partTimeDiscountMonthDays ?? 0);
        }

        $averageWage = $this->moneyParameter($ruleset, 'average_wage.monthly');
        $outcome = SocialPartTimeDiscountOutcome::Applied;
        if ($listedInPartialWorkOverview) {
            // § 7a odst. 3 písm. e): zaměstnanec uvedený v měsíčním přehledu
            // nákladů na náhrady mezd pro příspěvek v době částečné práce
            // (§ 120e odst. 5 zákona o zaměstnanosti).
            $outcome = SocialPartTimeDiscountOutcome::PartialWorkContribution;
        } elseif (
            // § 7a odst. 3 písm. d): zaměstnanec s postižením (odst. 1 písm. f)
            // v pracovním poměru k zaměstnavateli na chráněném trhu práce.
            $reason === SocialPartTimeDiscountReason::DisabledPerson
            && $claim->relationship->employerOnProtectedLaborMarket
        ) {
            $outcome = SocialPartTimeDiscountOutcome::ProtectedLaborMarket;
        } elseif (
            $reason->requiresShorterWorkingTime()
            && ($weeklyMillihours < $this->integerParameter(
                $ruleset,
                'employer.discount.part_time.minimum_weekly_millihours',
            )
                || $weeklyMillihours > $this->integerParameter(
                    $ruleset,
                    'employer.discount.part_time.maximum_weekly_millihours',
                ))
        ) {
            $outcome = SocialPartTimeDiscountOutcome::ShorterWorkingTimeOutsideRange;
        } elseif ($baseSum > PayrollRounding::ceilToCzk(CalculationStep::calculate(
            'part-time-discount-assessment-base-limit',
            $averageWage,
            $this->rateParameter(
                $ruleset,
                'employer.discount.part_time.assessment_base_limit_multiple',
            ),
            RoundingMode::Ceil,
        )->outputMinorUnits)) {
            $outcome = SocialPartTimeDiscountOutcome::AssessmentBaseAboveLimit;
        } elseif ($this->hourlyAssessmentBaseAboveLimit($ruleset, $averageWage, $baseSum, $millihours)) {
            $outcome = SocialPartTimeDiscountOutcome::HourlyAssessmentBaseAboveLimit;
        } elseif (
            $reason->requiresShorterWorkingTime()
            && $millihours > $this->monthlyHourLimit($ruleset, $employmentDays, $monthDays)
        ) {
            $outcome = SocialPartTimeDiscountOutcome::WorkedHoursAboveLimit;
        }

        return [$claim->relationship->relationshipId => [
            'outcome' => $outcome,
            'weekly_millihours' => $weeklyMillihours,
        ]];
    }

    /**
     * § 7a odst. 3 písm. b) — úhrn vyměřovacích základů připadající na 1 hodinu
     * z úhrnu odpracovaných hodin. Obě porovnávané částky se zaokrouhlují nahoru
     * na celé koruny, teprve pak se srovnávají.
     *
     * Nula hodin s nenulovým základem nedává podíl, ze kterého by šlo nárok
     * doložit — nedoložený nárok se neuplatňuje.
     */
    private function hourlyAssessmentBaseAboveLimit(
        PayrollRulesetVersion $ruleset,
        int $averageWage,
        int $baseSum,
        int $millihours,
    ): bool {
        if ($baseSum === 0) {
            return false;
        }
        if ($millihours === 0) {
            return true;
        }
        $perHour = PayrollRounding::ceilToCzk(
            intdiv($baseSum * 1_000 + $millihours - 1, $millihours),
        );
        $limit = PayrollRounding::ceilToCzk(CalculationStep::calculate(
            'part-time-discount-hourly-assessment-base-limit',
            $averageWage,
            $this->rateParameter(
                $ruleset,
                'employer.discount.part_time.hourly_assessment_base_limit',
            ),
            RoundingMode::Ceil,
        )->outputMinorUnits);

        return $perHour > $limit;
    }

    /**
     * § 7a odst. 3 písm. c) — 138 hodin, a netrvalo-li zaměstnání celý kalendářní
     * měsíc, v poměru kalendářních dnů se zaokrouhlením na celé hodiny nahoru.
     */
    private function monthlyHourLimit(
        PayrollRulesetVersion $ruleset,
        int $employmentDays,
        int $monthDays,
    ): int {
        $limit = $this->integerParameter(
            $ruleset,
            'employer.discount.part_time.maximum_monthly_millihours',
        );
        if ($monthDays <= 0 || $employmentDays >= $monthDays) {
            return $limit;
        }

        return PayrollRounding::ceilToMultiple(
            intdiv($limit * $employmentDays + $monthDays - 1, $monthDays),
            1_000,
        );
    }

    /**
     * @param list<SocialRelationshipFacts> $facts
     */
    private function nonCzechPersonResult(
        SocialPersonMonthInput $input,
        array $facts,
        int $smallScaleThreshold,
        int $dppThreshold,
    ): SocialPersonMonthResult {
        /*
         * Neznámá příslušnost nevadí tam, kde pojistné nevznikne v žádném
         * režimu: v cizím se české neplatí a v českém dohoda pod rozhodným
         * příjmem účast nezakládá (§ 7 zákona č. 187/2006 Sb.). Typicky
         * dohoda bez práce v měsíci (0 Kč) nebo brigádník pod limitem.
         */
        $czechDecisions = $input->jurisdiction === SocialJurisdictionEvidence::Unverified
            ? $this->participationResolver->resolve($facts, $smallScaleThreshold, $dppThreshold)
            : null;
        $noLiabilityInAnyRegime = $czechDecisions !== null
            && array_filter(
                $czechDecisions,
                static fn (SocialParticipationDecision $decision): bool =>
                    $decision->status !== SocialParticipationStatus::DoesNotParticipate,
            ) === [];
        $manual = $input->jurisdiction === SocialJurisdictionEvidence::Unverified
            && !$noLiabilityInAnyRegime;
        $reason = $manual
            ? ($input->jurisdictionEvidenceMissing
                ? 'social_jurisdiction_evidence_missing'
                : 'social_security_jurisdiction_unverified')
            : 'foreign_social_security_regime_verified';
        $decisions = [];
        foreach ($facts as $fact) {
            if ($noLiabilityInAnyRegime) {
                $decisions[$fact->relationship->relationshipId] =
                    $czechDecisions[$fact->relationship->relationshipId];
                continue;
            }
            $decisions[$fact->relationship->relationshipId] =
                new SocialParticipationDecision(
                    $fact->relationship->relationshipId,
                    $manual
                        ? SocialParticipationStatus::ManualReview
                        : SocialParticipationStatus::DoesNotParticipate,
                    $fact->participationIncomeMinorUnits,
                    $fact->participationIncomeMinorUnits,
                    null,
                    [$reason],
                );
        }

        return new SocialPersonMonthResult(
            $input->personId,
            $manual
                ? SocialCalculationStatus::ManualReview
                : SocialCalculationStatus::Calculated,
            $input->jurisdiction,
            $input->jurisdictionEvidenceReference,
            $input->workingPensionerDiscountEvidenceReference,
            $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
            0,
            0,
            $manual ? null : 0,
            $manual ? null : 0,
            $manual ? null : 0,
            null,
            null,
            $this->relationshipResults(
                $facts,
                $decisions,
                [],
                [],
                $manual ? null : array_fill_keys(
                    array_map(
                        static fn (SocialRelationshipFacts $fact): string =>
                            $fact->relationship->relationshipId,
                        $facts,
                    ),
                    [
                        'before' => 0,
                        'discount' => 0,
                        'contribution_step' => null,
                        'discount_step' => null,
                    ],
                ),
            ),
            $manual ? [$reason] : [],
        );
    }

    /**
     * @param list<SocialRelationshipFacts> $facts
     * @param array<string, SocialParticipationDecision> $decisions
     * @return array<string,int>
     */
    private function allocateCappedBase(
        array $facts,
        array $decisions,
        int $cappedBase,
    ): array {
        $remaining = $cappedBase;
        $allocations = [];
        $allocationFacts = $facts;
        usort(
            $allocationFacts,
            static function (
                SocialRelationshipFacts $left,
                SocialRelationshipFacts $right,
            ): int {
                $leftOrder = $left->relationship->annualMaximumAllocationOrder ?? PHP_INT_MAX;
                $rightOrder = $right->relationship->annualMaximumAllocationOrder ?? PHP_INT_MAX;

                return ($leftOrder <=> $rightOrder)
                    ?: ($left->relationship->relationshipId <=>
                        $right->relationship->relationshipId);
            },
        );
        foreach ($allocationFacts as $fact) {
            $relationshipId = $fact->relationship->relationshipId;
            $allocation = 0;
            if (
                $decisions[$relationshipId]->status === SocialParticipationStatus::Participates
                && $remaining > 0
            ) {
                $allocation = min($fact->assessmentBaseMinorUnits, $remaining);
                $remaining -= $allocation;
            }
            $allocations[$relationshipId] = $allocation;
        }
        foreach ($facts as $fact) {
            $allocations[$fact->relationship->relationshipId] ??= 0;
        }
        if ($remaining !== 0) {
            throw new LogicException('Annual maximum allocation did not consume the capped base.');
        }

        return $allocations;
    }

    /**
     * @param list<SocialRelationshipFacts> $facts
     * @param array<string, SocialParticipationDecision> $decisions
     * @param array<string,int> $allocations
     * @param array<string,array{outcome:SocialPartTimeDiscountOutcome,weekly_millihours:int}> $discountOutcomes
     * @param array<string,array{before:int,discount:int,contribution_step:?CalculationStep,discount_step:?CalculationStep}> $contributions
     * @return list<SocialRelationshipResult>
     */
    private function relationshipResults(
        array $facts,
        array $decisions,
        array $allocations,
        array $discountOutcomes = [],
        ?array $contributions = null,
    ): array {
        return array_map(
            static function (SocialRelationshipFacts $fact) use (
                $decisions,
                $allocations,
                $discountOutcomes,
                $contributions,
            ): SocialRelationshipResult {
                $relationship = $fact->relationship;
                $contribution = $contributions[$relationship->relationshipId] ?? null;
                $discountOutcome = $discountOutcomes[$relationship->relationshipId] ?? null;

                return new SocialRelationshipResult(
                    $relationship->relationshipId,
                    $relationship->kind,
                    $decisions[$relationship->relationshipId],
                    $fact->assessmentBaseMinorUnits,
                    $allocations[$relationship->relationshipId] ?? 0,
                    $fact->includedParticipationComponents,
                    $fact->excludedParticipationComponents,
                    $fact->includedAssessmentBaseComponents,
                    $fact->excludedAssessmentBaseComponents,
                    $relationship->partTimeEmployerDiscount,
                    $relationship->employerRateCategory,
                    $relationship->annualMaximumAllocationOrder,
                    $relationship->partTimeEmployerDiscountEvidenceReference,
                    $relationship->employerRateCategoryEvidenceReference,
                    $relationship->partTimeEmployerDiscountReason,
                    $discountOutcome['outcome'] ?? null,
                    $relationship->agreedWeeklyWorkingMillihours,
                    $contribution['before'] ?? null,
                    $contribution === null ? null : $contribution['discount'],
                    $contribution['contribution_step'] ?? null,
                    $contribution['discount_step'] ?? null,
                    $discountOutcome['weekly_millihours'] ?? null,
                );
            },
            $facts,
        );
    }

    /** @param array<string, SocialParticipationDecision> $decisions */
    private function participatingRelationshipCount(array $decisions): int
    {
        return count(array_filter(
            $decisions,
            static fn (SocialParticipationDecision $decision): bool =>
                $decision->status === SocialParticipationStatus::Participates,
        ));
    }

    /**
     * @param list<SocialRelationshipFacts> $facts
     * @param array<string, SocialParticipationDecision> $decisions
     */
    private function hasUnambiguousMaximumAllocationOrder(
        array $facts,
        array $decisions,
    ): bool {
        $orders = [];
        foreach ($facts as $fact) {
            if (
                $decisions[$fact->relationship->relationshipId]->status !==
                SocialParticipationStatus::Participates
            ) {
                continue;
            }
            $order = $fact->relationship->annualMaximumAllocationOrder;
            if ($order === null || in_array($order, $orders, true)) {
                return false;
            }
            $orders[] = $order;
        }

        return true;
    }

    private function moneyParameter(PayrollRulesetVersion $ruleset, string $key): int
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'money_minor' || !is_int($parameter->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not money.");
        }

        return $parameter->value;
    }

    private function integerParameter(PayrollRulesetVersion $ruleset, string $key): int
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'integer' || !is_int($parameter->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not an integer.");
        }

        return $parameter->value;
    }

    private function rateParameter(PayrollRulesetVersion $ruleset, string $key): DecimalRate
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'decimal_rate' || !is_string($parameter->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not a rate.");
        }

        return DecimalRate::fromString($parameter->value);
    }

    private function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new LogicException('Social insurance final aggregation expects non-negative values.');
        }
        if ($left > PHP_INT_MAX - $right) {
            throw new OverflowException('Social insurance aggregation exceeds the integer range.');
        }

        return $left + $right;
    }
}
