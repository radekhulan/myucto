<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollSurchargePolicyHistoryLockedException extends \DomainException
{
    public function __construct(
        string $message = 'Zásada zasahuje do období se zmrazenou nebo schválenou revizí mzdového běhu. Použitou zásadu nelze přepsat. Založte novou verzi od dosud nezmrazeného období.',
    ) {
        parent::__construct($message);
    }
}
