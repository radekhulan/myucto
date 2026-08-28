<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollForeignPermitException extends \DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $httpStatus = 422,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
