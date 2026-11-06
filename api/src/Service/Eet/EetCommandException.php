<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Odmítnutí příkazu EET s kódem a HTTP statusem pro `Json::error`. Text je česky
 * a smí jít uživateli; nikdy neobsahuje tajemství ani XML.
 */
final class EetCommandException extends \RuntimeException
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
        return new self('eet_validation', $message, 422, $field !== null ? ['field' => $field] + $extra : $extra);
    }

    public static function notConfigured(string $message): self
    {
        return new self('eet_not_configured', $message, 422);
    }

    public static function unsupported(string $message, string $reasonCode): self
    {
        return new self('eet_unsupported_scenario', $message, 422, ['reason_code' => $reasonCode]);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message, 404);
    }

    public static function stateConflict(string $message, array $extra = []): self
    {
        return new self('eet_state_conflict', $message, 409, $extra);
    }
}
