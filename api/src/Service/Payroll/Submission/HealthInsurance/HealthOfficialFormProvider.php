<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

interface HealthOfficialFormProvider
{
    public function form(string $formId): HealthOfficialForm;
}
