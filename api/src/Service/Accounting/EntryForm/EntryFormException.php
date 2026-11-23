<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\EntryForm;

/**
 * Změnu formy účetního zápisu nelze provést. Nese strojový kód a HTTP status,
 * aby ho Action přeložila na Json::error bez ztráty kontextu (vzor PostingException).
 */
final class EntryFormException extends \RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
