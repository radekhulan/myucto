<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

/**
 * Rozšifrované rozpracované přihlášení. `secret` je to, co do něj uložil poskytovatel
 * (případně s výsledkem callbacku), `codeVerifier` PKCE verifier vrstvy.
 */
final class OAuthSession
{
    /** @param array<string,mixed> $secret */
    public function __construct(
        public readonly string $stateHash,
        public readonly int $supplierId,
        public readonly int $userId,
        public readonly string $domain,
        public readonly string $provider,
        public readonly int $subjectId,
        public readonly string $flow,
        public readonly string $stage,
        public readonly string $status,
        public readonly bool $expired,
        #[\SensitiveParameter] public readonly array $secret,
        #[\SensitiveParameter] public readonly ?string $codeVerifier,
    ) {}
}
