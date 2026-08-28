<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\Employment\PayrollRelationType;
use UnexpectedValueException;

final class EmploymentRelationshipKindMapper
{
    public function fromDatabaseRelationType(string $relationType): EmploymentRelationshipKind
    {
        $type = PayrollRelationType::tryFrom($relationType);
        if ($type === null) {
            throw new UnexpectedValueException(
                "Unsupported payroll relation type {$relationType}.",
            );
        }

        return match ($type) {
            PayrollRelationType::Employment => EmploymentRelationshipKind::Employment,
            PayrollRelationType::SmallScaleEmployment => EmploymentRelationshipKind::SmallScaleEmployment,
            PayrollRelationType::Dpp => EmploymentRelationshipKind::Dpp,
            PayrollRelationType::Dpc => EmploymentRelationshipKind::Dpc,
            PayrollRelationType::PartnerDependent => EmploymentRelationshipKind::ManagingPartnerDependent,
            PayrollRelationType::StatutoryBody => EmploymentRelationshipKind::StatutoryBody,
        };
    }
}
