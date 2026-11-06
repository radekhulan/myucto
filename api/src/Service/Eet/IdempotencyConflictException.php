<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/** Stejný `Idempotency-Key` přišel s jiným obsahem požadavku → 409. */
final class IdempotencyConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Klíč Idempotency-Key už byl použit pro jiný požadavek. Pro novou platbu použijte nový klíč.');
    }
}
