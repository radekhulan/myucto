<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

final class SicknessException extends \RuntimeException
{
    public function __construct(
        public readonly string $validationCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
