<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

final class QuoteException extends \DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
