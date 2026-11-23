<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use Firebase\JWT\JWT;

/**
 * Tajemství klienta pro Sign in with Apple: krátce platný JWT podepsaný ES256
 * klíčem (.p8) z vývojářského účtu Apple. Vyrábí se při každé výměně kódu,
 * platí pět minut, takže uniklé tajemství rychle vyprší.
 */
final class AppleClientSecret
{
    private const TTL = 300;

    public function __construct(
        private readonly string $teamId,
        private readonly string $keyId,
        private readonly string $servicesId,
        #[\SensitiveParameter] private readonly string $privateKeyPem,
    ) {}

    public function generate(?int $now = null): string
    {
        $now ??= time();
        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false) {
            throw new OidcException(OidcException::PROVIDER_ERROR, 'Klíč Sign in with Apple nejde načíst.');
        }
        return JWT::encode([
            'iss' => $this->teamId,
            'iat' => $now,
            'exp' => $now + self::TTL,
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->servicesId,
        ], $key, 'ES256', $this->keyId);
    }
}
