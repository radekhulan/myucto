<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use InvalidArgumentException;

final readonly class SocialPersonMonthInput
{
    /** @var non-empty-list<SocialInsuranceRelationshipInput> */
    public array $relationships;

    /** @param array<mixed> $relationships */
    public function __construct(
        public string $personId,
        public SocialJurisdictionEvidence $jurisdiction,
        public int $yearToDateAssessmentBaseBeforeMonthMinorUnits,
        array $relationships,
        public SocialDiscountEvidence $workingPensionerDiscount = SocialDiscountEvidence::NotClaimed,
        public ?string $jurisdictionEvidenceReference = null,
        public ?string $workingPensionerDiscountEvidenceReference = null,
        public bool $jurisdictionEvidenceMissing = false,
    ) {
        if ($jurisdictionEvidenceMissing && $jurisdiction !== SocialJurisdictionEvidence::Unverified) {
            throw new InvalidArgumentException(
                'Missing social jurisdiction evidence cannot carry a jurisdiction.',
            );
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $personId) !== 1) {
            throw new InvalidArgumentException('Social insurance person ID is not canonical.');
        }
        if ($yearToDateAssessmentBaseBeforeMonthMinorUnits < 0) {
            throw new InvalidArgumentException(
                'Year-to-date social insurance assessment base cannot be negative.',
            );
        }
        if (!array_is_list($relationships) || $relationships === []) {
            throw new InvalidArgumentException(
                'Social insurance person relationships must be a non-empty list.',
            );
        }
        foreach ($relationships as $relationship) {
            if (!$relationship instanceof SocialInsuranceRelationshipInput) {
                throw new InvalidArgumentException(
                    'Social insurance relationships must use the dedicated input type.',
                );
            }
        }
        $ids = array_map(
            static fn (SocialInsuranceRelationshipInput $relationship): string =>
                $relationship->relationshipId,
            $relationships,
        );
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException(
                'Social insurance relationship IDs must be unique within a person.',
            );
        }
        if (
            $jurisdiction === SocialJurisdictionEvidence::ForeignRegimeVerified
            && $jurisdictionEvidenceReference !== null
            && preg_match(
                    '/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D',
                    $jurisdictionEvidenceReference,
                ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Foreign social insurance regime evidence reference is not canonical.',
            );
        }
        if (
            $jurisdiction !== SocialJurisdictionEvidence::ForeignRegimeVerified
            && $jurisdictionEvidenceReference !== null
        ) {
            throw new InvalidArgumentException(
                'Jurisdiction evidence reference is only allowed for a verified foreign regime.',
            );
        }
        if (
            $workingPensionerDiscount === SocialDiscountEvidence::Verified
            && $workingPensionerDiscountEvidenceReference !== null
            && preg_match(
                    '/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D',
                    $workingPensionerDiscountEvidenceReference,
                ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Working pensioner discount evidence reference is not canonical.',
            );
        }
        if (
            $workingPensionerDiscount !== SocialDiscountEvidence::Verified
            && $workingPensionerDiscountEvidenceReference !== null
        ) {
            throw new InvalidArgumentException(
                'Working pensioner evidence reference is only allowed for a verified claim.',
            );
        }

        $this->relationships = $relationships;
    }
}
