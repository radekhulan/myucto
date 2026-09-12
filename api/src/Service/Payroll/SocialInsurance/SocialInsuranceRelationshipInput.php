<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use InvalidArgumentException;

final readonly class SocialInsuranceRelationshipInput
{
    /**
     * Prázdný seznam = měsíc bez příjmu, který vysvětluje nepřítomnost bez
     * náhrady od zaměstnavatele (neplacené volno, PPM, nemoc za oknem
     * náhrady). Vztah trvá a musí se spočítat i vykázat s nulovým základem;
     * pouští ho jen PayrollRunStatutoryInputAssembler::monthWithoutInputsExplained().
     *
     * @var list<SocialAssessmentComponent>
     */
    public array $components;
    public SocialParticipationAggregationGroup $participationAggregationGroup;

    /**
     * CorporateBody represents one insurance relationship. A partner who is also
     * an executive of the same company must therefore be normalized into one input.
     *
     * @param array<mixed> $components
     */
    public function __construct(
        public string $relationshipId,
        public SocialEmploymentKind $kind,
        public ?int $agreedMonthlyIncomeMinorUnits,
        public bool $activeInParticipationMonth,
        public SocialIncomeAttribution $incomeAttribution,
        array $components,
        public SocialDiscountEvidence $partTimeEmployerDiscount = SocialDiscountEvidence::NotClaimed,
        public SocialEmployerRateCategory $employerRateCategory = SocialEmployerRateCategory::Ordinary,
        public bool $agricultureDppEmployeeDiscountRequested = false,
        public ?int $annualMaximumAllocationOrder = null,
        public ?string $partTimeEmployerDiscountEvidenceReference = null,
        ?SocialParticipationAggregationGroup $participationAggregationGroup = null,
        public ?string $employerRateCategoryEvidenceReference = null,
        public ?SocialPartTimeDiscountReason $partTimeEmployerDiscountReason = null,
        public ?int $partTimeDiscountAssessableMillihours = null,
        public ?int $partTimeDiscountEmploymentDays = null,
        public ?int $partTimeDiscountMonthDays = null,
        public ?int $agreedWeeklyWorkingMillihours = null,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $relationshipId) !== 1) {
            throw new InvalidArgumentException('Social insurance relationship ID is not canonical.');
        }
        if ($agreedMonthlyIncomeMinorUnits !== null && $agreedMonthlyIncomeMinorUnits < 0) {
            throw new InvalidArgumentException('Agreed monthly income cannot be negative.');
        }
        if (!array_is_list($components)) {
            throw new InvalidArgumentException(
                'Social insurance relationship components must be a list.',
            );
        }
        foreach ($components as $component) {
            if (!$component instanceof SocialAssessmentComponent) {
                throw new InvalidArgumentException(
                    'Social insurance components must use the dedicated input type.',
                );
            }
        }
        if (
            $activeInParticipationMonth
            && $incomeAttribution === SocialIncomeAttribution::PostTerminationEndMonthVerified
        ) {
            throw new InvalidArgumentException(
                'Post-termination attribution cannot be used for an active relationship.',
            );
        }
        if (
            $agricultureDppEmployeeDiscountRequested
            && $kind !== SocialEmploymentKind::Dpp
        ) {
            throw new InvalidArgumentException(
                'The agriculture employee discount can only be requested for DPP.',
            );
        }
        if ($annualMaximumAllocationOrder !== null && $annualMaximumAllocationOrder < 0) {
            throw new InvalidArgumentException(
                'Annual maximum allocation order cannot be negative.',
            );
        }
        self::assertEvidenceReference(
            $partTimeEmployerDiscount,
            $partTimeEmployerDiscountEvidenceReference,
            'Part-time employer discount',
        );
        /*
         * § 7a odst. 1 vyjmenovává důvody nároku uzavřeným výčtem a § 7a odst. 2
         * i odst. 3 písm. c) na některé z nich váží vlastní podmínky. Doložený
         * nárok bez důvodu proto nedává smysl — nešlo by rozhodnout, které
         * podmínky se mají posoudit, a mlčky použít ty mírnější by slevu
         * přiznalo tam, kam nepatří.
         */
        if (
            $partTimeEmployerDiscount === SocialDiscountEvidence::Verified
            && $partTimeEmployerDiscountReason === null
        ) {
            throw new InvalidArgumentException(
                'Part-time employer discount verification requires a statutory reason.',
            );
        }
        if (
            $partTimeEmployerDiscount !== SocialDiscountEvidence::Verified
            && $partTimeEmployerDiscountReason !== null
        ) {
            throw new InvalidArgumentException(
                'Part-time employer discount reason is only allowed for verified claims.',
            );
        }
        foreach ([
            'Part-time discount assessable hours' => $partTimeDiscountAssessableMillihours,
            'Agreed weekly working time' => $agreedWeeklyWorkingMillihours,
        ] as $label => $millihours) {
            if ($millihours !== null && $millihours < 0) {
                throw new InvalidArgumentException("{$label} cannot be negative.");
            }
        }
        if ($partTimeDiscountMonthDays !== null && $partTimeDiscountMonthDays <= 0) {
            throw new InvalidArgumentException('Part-time discount month length must be positive.');
        }
        if (
            $partTimeDiscountEmploymentDays !== null
            && ($partTimeDiscountEmploymentDays < 0
                || $partTimeDiscountMonthDays === null
                || $partTimeDiscountEmploymentDays > $partTimeDiscountMonthDays)
        ) {
            throw new InvalidArgumentException(
                'Part-time discount employment days must fit into the calendar month.',
            );
        }
        /*
         * Zvýšená sazba podle § 5a odst. 1 písm. b) a c) stojí na věcném
         * zařazení zaměstnance, které se dokládá (rizikové zaměstnání podle
         * § 37d odst. 2 zákona o důchodovém pojištění vzniká z kategorizace
         * prací, ne z políčka ve mzdovém listu). Textový odkaz na podklad je
         * volitelný; běžná sazba naopak žádný takový odkaz nemá a mít nesmí.
         */
        if (
            !in_array(
                $employerRateCategory,
                [SocialEmployerRateCategory::Ordinary, SocialEmployerRateCategory::Unverified],
                true,
            )
            && $employerRateCategoryEvidenceReference !== null
            && preg_match(
                    '/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D',
                    $employerRateCategoryEvidenceReference,
                ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Employer rate category evidence reference is not canonical.',
            );
        }
        if (
            in_array(
                $employerRateCategory,
                [SocialEmployerRateCategory::Ordinary, SocialEmployerRateCategory::Unverified],
                true,
            )
            && $employerRateCategoryEvidenceReference !== null
        ) {
            throw new InvalidArgumentException(
                'Employer rate category evidence reference is only allowed above the ordinary rate.',
            );
        }
        $resolvedAggregationGroup = $participationAggregationGroup ?? match ($kind) {
            SocialEmploymentKind::Employment =>
                SocialParticipationAggregationGroup::RegularRelationship,
            SocialEmploymentKind::Dpp =>
                SocialParticipationAggregationGroup::Dpp,
            SocialEmploymentKind::Dpc, SocialEmploymentKind::CorporateBody =>
                SocialParticipationAggregationGroup::SmallScaleCandidate,
        };
        if (
            ($kind === SocialEmploymentKind::Dpp)
            !== ($resolvedAggregationGroup === SocialParticipationAggregationGroup::Dpp)
        ) {
            throw new InvalidArgumentException(
                'DPP relationship and participation aggregation group must match.',
            );
        }

        $this->components = $components;
        $this->participationAggregationGroup = $resolvedAggregationGroup;
    }

    private static function assertEvidenceReference(
        SocialDiscountEvidence $evidence,
        ?string $reference,
        string $label,
    ): void {
        if (
            $evidence === SocialDiscountEvidence::Verified
            && $reference !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $reference) !== 1
        ) {
            throw new InvalidArgumentException("{$label} evidence reference is not canonical.");
        }
        if ($evidence !== SocialDiscountEvidence::Verified && $reference !== null) {
            throw new InvalidArgumentException(
                "{$label} evidence reference is only allowed for verified claims.",
            );
        }
    }
}
