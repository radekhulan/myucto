<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class AccountingFrameworkException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
