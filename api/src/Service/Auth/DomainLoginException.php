<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

final class DomainLoginException extends \RuntimeException
{
    /** @param array<string,mixed> $details doplňková pole chybové odpovědi */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
