<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

/** Doménová chyba agendy Časové rozlišení (validace, stav položky, zámek období). */
final class AccrualException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?string $field = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
