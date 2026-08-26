<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollEnforcementClaimMutationBlockedException extends \RuntimeException
{
    public function __construct(public readonly string $blockerCode, string $message)
    {
        parent::__construct($message);
    }
}
