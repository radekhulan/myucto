<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

final class EldpManualCompletionException extends \DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?int $currentRowVersion = null,
    ) {
        parent::__construct($message);
    }
}
