<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollRiskySavingsConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct(
            'Podklady mezitím změnil jiný uživatel. Načtěte aktuální verzi a změnu zopakujte.',
        );
    }
}
