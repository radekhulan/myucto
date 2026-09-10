<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

final class SalesOrderException extends \RuntimeException
{
    /** @param list<array<string,mixed>> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
