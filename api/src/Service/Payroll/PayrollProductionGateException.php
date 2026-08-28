<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollProductionGateException extends \DomainException
{
    public const ERROR_CODE = 'payroll_production_release_pending';

    public function __construct(
        string $message = 'Ostrý mzdový provoz zatím nebyl interně uvolněn v této verzi produktu.',
    )
    {
        parent::__construct($message);
    }
}
