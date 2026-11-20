<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

/** Odmítnutá vazba konečné faktury na zálohu ({@see PurchaseAdvanceLinks}): kód, hláška a HTTP status. */
final class PurchaseAdvanceLinkException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
