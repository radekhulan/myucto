<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/** Neplatný vstup ručního zápisu (tvar řádků, dimenze, vazby na doklady). */
final class ManualJournalEntryException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
