<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Doménová chyba EET s kódem a HTTP stavem pro `Json::error()`.
 *
 * Zpráva je vždy česká a bezpečná pro odpověď: nikdy nenese heslo, obsah
 * PKCS#12 ani text chyby OpenSSL, který by mohl prozradit cokoli o klíči.
 */
final class EetException extends \RuntimeException
{
    public const VALIDATION = 'eet_validation';
    public const NOT_CONFIGURED = 'eet_not_configured';
    public const NOT_FOUND = 'eet_not_found';
    public const CONFLICT = 'eet_conflict';

    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string,mixed> $details */
    public static function validation(string $message, array $details = []): self
    {
        return new self(self::VALIDATION, $message, 422, $details);
    }

    public static function notFound(string $message): self
    {
        return new self(self::NOT_FOUND, $message, 404);
    }
}
