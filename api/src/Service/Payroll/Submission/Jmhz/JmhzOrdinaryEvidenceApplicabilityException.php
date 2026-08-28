<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzOrdinaryEvidenceApplicabilityException extends \DomainException
{
    public function __construct(
        public readonly string $validationCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
