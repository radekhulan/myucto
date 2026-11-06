<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/** Fakturu nelze navázat na ostatní pohledávku nebo od ní odpojit; zpráva je pro uživatele. */
final class InvoiceOtherItemLinkException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
