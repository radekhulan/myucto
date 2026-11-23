<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

/**
 * Sestavu nelze postavit (neexistující období/účet, chybějící verze mapování
 * výkazu…). Nese strojový kód + HTTP status, aby ji navazující Action přeložila
 * na Json::error bez ztráty kontextu (stejný vzor jako Accounting\PostingException).
 */
final class ReportException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        /** @var array<string,mixed> kontext pro log (bez dat pro klienta) */
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
