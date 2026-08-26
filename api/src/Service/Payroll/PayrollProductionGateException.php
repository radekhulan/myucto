<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollProductionGateException extends \DomainException
{
    public const ERROR_CODE = 'payroll_production_qualification_required';

    public function __construct()
    {
        parent::__construct(
            'Ostrý mzdový provoz vyžaduje dokončenou a auditovanou produkční kvalifikaci firmy.',
        );
    }
}
