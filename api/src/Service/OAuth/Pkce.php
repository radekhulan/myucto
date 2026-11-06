<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

/** PKCE (RFC 7636), výhradně metoda S256. */
final class Pkce
{
    public const METHOD = 'S256';

    public static function verifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function isValidVerifier(#[\SensitiveParameter] string $verifier): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) === 1;
    }
}
