<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

/**
 * Autorizační server poskytovatele: jen adresy a parametry. Tok přihlášení, state,
 * PKCE a uložení rozpracovaného přihlášení dělá {@see OAuthClientFlow}.
 *
 * `clientSecret` se posílá v těle požadavku (client_secret_post). Parametry navíc
 * (`authorizationParams`, `deviceParams`) jsou nestandardní požadavky konkrétního
 * serveru, např. `response_type=device_code` u BMW.
 */
final class OAuthProviderConfig
{
    /**
     * @param array<string,string> $authorizationParams
     * @param array<string,string> $deviceParams
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $tokenUrl,
        public readonly ?string $authorizationUrl = null,
        public readonly ?string $deviceAuthorizationUrl = null,
        public readonly string $scope = '',
        #[\SensitiveParameter] public readonly ?string $clientSecret = null,
        public readonly array $authorizationParams = [],
        public readonly array $deviceParams = [],
    ) {
        foreach ([$tokenUrl, $authorizationUrl, $deviceAuthorizationUrl] as $url) {
            if ($url !== null && !self::isHttpsUrl($url)) {
                throw new OAuthException(OAuthException::INVALID_REQUEST, 'Adresa autorizačního serveru musí být https.');
            }
        }
        if ($clientId === '' || strlen($clientId) > 512 || preg_match('/[\x00-\x20\x7f]/', $clientId)) {
            throw new OAuthException(OAuthException::INVALID_CLIENT, 'Neplatné client ID.');
        }
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }
}
