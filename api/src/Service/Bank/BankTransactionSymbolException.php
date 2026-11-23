<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/** Změnu variabilního symbolu pohybu nejde provést ({@see BankTransactionSymbolService}). */
final class BankTransactionSymbolException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
