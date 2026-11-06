<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

/**
 * Nové přihlášení: state a PKCE vygenerované vrstvou {@see OAuthClientFlow}. State
 * a challenge jdou poskytovateli, verifier zůstává jen v šifrovaném rozpracovaném
 * přihlášení.
 */
final class OAuthAuthorization
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $state,
        #[\SensitiveParameter] public readonly ?string $codeVerifier,
    ) {}

    public function stateHash(): string
    {
        return hash('sha256', $this->state);
    }

    public function codeChallenge(): ?string
    {
        return $this->codeVerifier !== null ? Pkce::challenge($this->codeVerifier) : null;
    }
}
