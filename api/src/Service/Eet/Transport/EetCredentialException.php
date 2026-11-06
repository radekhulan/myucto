<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Pokladní certifikát nelze použít. `reason` je strojový kód
 * (`invalid_password`, `unreadable`, `private_key_missing`, `key_mismatch`,
 * `unsupported_key`, `signing_failed`); hláška nikdy neobsahuje heslo ani klíč.
 */
final class EetCredentialException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
