<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use GuzzleHttp\ClientInterface;
use MyInvoice\Service\OAuth\Pkce;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP strana poskytovatele identity: autorizační URL, výměna kódu za id_token
 * a stažení podpisových klíčů. Tokeny ani těla odpovědí se nikam nelogují.
 */
class OidcHttpClient
{
    private const MAX_BYTES = 512 * 1024;

    public function __construct(private readonly ClientInterface $http) {}

    /**
     * @param array<string,string> $extra
     */
    public function authorizationUrl(
        OidcProvider $provider,
        string $redirectUri,
        string $state,
        string $nonce,
        #[\SensitiveParameter] string $codeVerifier,
        array $extra = [],
    ): string {
        $query = [
            'client_id' => $provider->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => $provider->scope,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Pkce::challenge($codeVerifier),
            'code_challenge_method' => Pkce::METHOD,
        ] + $extra;
        return $provider->authorizeUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Vrací id_token z odpovědi token endpointu (client_secret_post + PKCE).
     */
    public function exchangeCode(
        OidcProvider $provider,
        #[\SensitiveParameter] string $code,
        string $redirectUri,
        #[\SensitiveParameter] string $codeVerifier,
    ): string {
        if ($code === '' || strlen($code) > 8192 || preg_match('/[\x00-\x20\x7f]/', $code) === 1) {
            throw new OidcException(OidcException::FLOW_INVALID, 'Neplatný autorizační kód.');
        }
        try {
            $response = $this->http->request('POST', $provider->tokenUrl, [
                'form_params' => [
                    'client_id' => $provider->clientId,
                    'client_secret' => $provider->clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $codeVerifier,
                    'scope' => $provider->scope,
                ],
                'headers' => ['Accept' => 'application/json'],
                'allow_redirects' => false,
                'verify' => true,
                'http_errors' => false,
                'connect_timeout' => 5,
                'timeout' => 15,
            ]);
        } catch (\Throwable) {
            throw new OidcException(OidcException::PROVIDER_UNAVAILABLE, 'Poskytovatel identity není dostupný.');
        }
        $data = $this->json($response);
        if ($response->getStatusCode() !== 200) {
            throw new OidcException(OidcException::PROVIDER_ERROR, 'Poskytovatel identity odmítl výměnu kódu.');
        }
        $idToken = $data['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '' || strlen($idToken) > 16384) {
            throw new OidcException(OidcException::TOKEN_INVALID, 'Odpověď poskytovatele neobsahuje id_token.');
        }
        return $idToken;
    }

    /** @return array<string,mixed> */
    public function fetchJwks(string $url): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'allow_redirects' => false,
                'verify' => true,
                'http_errors' => false,
                'connect_timeout' => 5,
                'timeout' => 10,
            ]);
        } catch (\Throwable) {
            throw new OidcException(OidcException::PROVIDER_UNAVAILABLE, 'Klíče poskytovatele nejsou dostupné.');
        }
        $data = $this->json($response);
        if ($response->getStatusCode() !== 200 || !is_array($data['keys'] ?? null)) {
            throw new OidcException(OidcException::PROVIDER_UNAVAILABLE, 'Klíče poskytovatele nejsou dostupné.');
        }
        return $data;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $body = (string) $response->getBody()->read(self::MAX_BYTES + 1);
        if (strlen($body) > self::MAX_BYTES || trim($body) === '') {
            return [];
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }
}
