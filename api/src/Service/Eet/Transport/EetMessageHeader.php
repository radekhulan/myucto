<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Hlavička jednoho pokusu o odeslání. UUID identifikuje datovou ZPRÁVU, ne tržbu:
 * každý pokus (i opakovaný) dostane nové UUID, zatímco údaje tržby zůstávají.
 */
final readonly class EetMessageHeader
{
    public function __construct(
        public string $uuid,
        public \DateTimeImmutable $sentAt,
        public bool $firstAttempt,
        public bool $verification = false,
    ) {}

    public static function create(bool $firstAttempt, bool $verification = false, ?\DateTimeImmutable $now = null): self
    {
        return new self(self::uuidV4(), $now ?? new \DateTimeImmutable('now'), $firstAttempt, $verification);
    }

    /** UUID verze 4 podle RFC 9562 z kryptograficky bezpečného generátoru. */
    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
