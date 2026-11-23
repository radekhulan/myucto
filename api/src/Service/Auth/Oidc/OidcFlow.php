<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

final readonly class OidcFlow
{
    public function __construct(
        public string $provider,
        public string $purpose,
        public string $nonce,
        #[\SensitiveParameter] public string $codeVerifier,
        public ?string $returnTo,
        public ?int $userId,
        public ?string $sessionIdHash,
        public ?string $stepUpOperation,
        public ?int $inviteId,
        public int $createdAt = 0,
        /** Tiché obnovení (`prompt=none`); když vyžaduje uživatele, pokračuje se interaktivně. */
        public bool $silent = false,
    ) {}
}
