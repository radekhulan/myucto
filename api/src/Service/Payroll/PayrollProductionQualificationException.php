<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollProductionQualificationException extends \DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        /** @var array<string,mixed> */
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
