<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\RiskySavings;

enum PayrollRiskySavingsRiskFactor: string
{
    case VIBRATION = 'vibration';
    case COLD = 'cold';
    case HEAT = 'heat';
    case DYNAMIC_PHYSICAL_LOAD = 'dynamic_physical_load';
}
