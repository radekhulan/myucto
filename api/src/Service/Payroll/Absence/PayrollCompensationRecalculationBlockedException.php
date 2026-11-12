<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

final class PayrollCompensationRecalculationBlockedException extends \DomainException
{
    public function __construct(
        public readonly string $nextStep,
        string $message,
        public readonly ?int $runId,
    ) {
        parent::__construct($message);
    }
}
