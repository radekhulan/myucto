<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

final readonly class HealthPaymentOverviewPdfTemplate
{
    public function __construct(
        public string $bytes,
        public string $sourceReference,
        public string $sha256,
    ) {}
}
