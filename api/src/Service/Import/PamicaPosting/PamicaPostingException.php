<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import\PamicaPosting;

/**
 * Chyba načtení zaúčtování mezd z PAMICY. Vždy validační (4xx s hláškou): nečitelný
 * soubor, jiný formát, nebo kontrola, která import nepustí.
 */
final class PamicaPostingException extends \RuntimeException
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
