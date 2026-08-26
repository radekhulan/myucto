<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

enum PayrollPeriodExportScope: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
}
