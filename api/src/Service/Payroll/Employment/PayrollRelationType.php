<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Employment;

enum PayrollRelationType: string
{
    case Employment = 'employment';
    case SmallScaleEmployment = 'small_scale_employment';
    case Dpp = 'dpp';
    case Dpc = 'dpc';
    case PartnerDependent = 'partner_dependent';
    case StatutoryBody = 'statutory_body';
}
