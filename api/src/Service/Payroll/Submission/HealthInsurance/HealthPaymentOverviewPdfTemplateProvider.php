<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

interface HealthPaymentOverviewPdfTemplateProvider
{
    public function vzpPaymentOverview(): HealthPaymentOverviewPdfTemplate;
}
