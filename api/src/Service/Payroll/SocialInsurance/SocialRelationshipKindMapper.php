<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Employment\PayrollRelationType;

final class SocialRelationshipKindMapper
{
    public function fromRelationType(string $relationType): SocialRelationshipKindMapping
    {
        $type = PayrollRelationType::tryFrom($relationType);
        if ($type === null) {
            throw new InvalidArgumentException(
                'Unsupported payroll relation type for social insurance.',
            );
        }

        return match ($type) {
            PayrollRelationType::Employment => new SocialRelationshipKindMapping(
                SocialEmploymentKind::Employment,
                SocialParticipationAggregationGroup::RegularRelationship,
            ),
            PayrollRelationType::SmallScaleEmployment => new SocialRelationshipKindMapping(
                SocialEmploymentKind::Employment,
                SocialParticipationAggregationGroup::SmallScaleCandidate,
            ),
            PayrollRelationType::Dpp => new SocialRelationshipKindMapping(
                SocialEmploymentKind::Dpp,
                SocialParticipationAggregationGroup::Dpp,
            ),
            PayrollRelationType::Dpc => new SocialRelationshipKindMapping(
                SocialEmploymentKind::Dpc,
                SocialParticipationAggregationGroup::SmallScaleCandidate,
            ),
            PayrollRelationType::PartnerDependent,
            PayrollRelationType::StatutoryBody => new SocialRelationshipKindMapping(
                SocialEmploymentKind::CorporateBody,
                SocialParticipationAggregationGroup::SmallScaleCandidate,
            ),
        };
    }
}
