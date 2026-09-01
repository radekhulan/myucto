<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\GoPay;

final class GoPayException extends \RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
