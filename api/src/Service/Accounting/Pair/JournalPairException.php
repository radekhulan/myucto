<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

/**
 * Porušení invariantu dvojic jednoduchého zápisu nebo neplatný vstup dvojice.
 * Nese strojový kód (viz konstanty {@see JournalPairInvariant}) a HTTP status;
 * `$context` ukazuje na místo chyby (např. `['pair_no' => 3]`), aby ho rozhraní
 * umělo zvýraznit.
 */
final class JournalPairException extends \RuntimeException
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
