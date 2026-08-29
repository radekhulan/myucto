<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollSurchargePolicyConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Zásadu příplatků mezitím změnil jiný uživatel.');
    }
}
