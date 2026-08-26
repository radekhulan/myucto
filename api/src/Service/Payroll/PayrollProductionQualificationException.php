<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollProductionQualificationException extends \DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
