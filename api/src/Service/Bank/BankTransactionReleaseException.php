<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Pohyb nelze bezpečně uvolnit (zrušit párování / smazat s výpisem). Volající
 * musí celou operaci zastavit a vrátit `errorCode` s `httpStatus`.
 */
final class BankTransactionReleaseException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
