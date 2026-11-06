<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mobile;

/**
 * Odmítnutí rychlé účtenky s kódem a HTTP statusem pro `Json::error`. Text je česky
 * a smí jít uživateli.
 */
final class QuickReceiptException extends \RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message, ?string $field = null, array $extra = []): self
    {
        return new self('validation_failed', $message, 422, $field !== null ? ['field' => $field] + $extra : $extra);
    }
}
