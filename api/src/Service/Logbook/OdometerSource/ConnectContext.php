<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

use MyInvoice\Service\OAuth\Pkce;

/**
 * Údaje jádra pro zahájení připojení ze společné vrstvy OAuth klienta
 * ({@see \MyInvoice\Service\OAuth\OAuthClientFlow}).
 *
 * `state` a PKCE generuje vrstva, ne zdroj: zdroj s přesměrováním předá službě `state`,
 * `redirectUri` (společný callback) a `codeChallenge()`. Verifier jádro uloží jen
 * šifrovaně a při dalším kroku ho zdroji předá ve vstupu jako `code_verifier`, kód
 * z callbacku jako `code`.
 */
final class ConnectContext
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $state,
        public readonly ?string $redirectUri = null,
        #[\SensitiveParameter] public readonly ?string $codeVerifier = null,
    ) {}

    public function codeChallenge(): ?string
    {
        return $this->codeVerifier !== null ? Pkce::challenge($this->codeVerifier) : null;
    }
}
