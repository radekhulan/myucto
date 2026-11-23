<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use GuzzleHttp\ClientInterface;
use MyInvoice\Service\OAuth\Pkce;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP strana poskytovatele identity: autorizační URL, výměna kódu za tokeny,
 * userinfo a stažení podpisových klíčů. Tokeny ani těla odpovědí se nikam nelogují.
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
            'scope' => $provider->scope,
            'state' => $state,
        ];
        if ($provider->responseMode !== '') {
            $query['response_mode'] = $provider->responseMode;
        }
        if ($provider->protocol === 'oidc') {
            $query['nonce'] = $nonce;
        }
        if ($provider->supportsPkce) {
            $query['code_challenge'] = Pkce::challenge($codeVerifier);
            $query['code_challenge_method'] = Pkce::METHOD;
        }
        $query += $extra;
        return $provider->authorizeUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Výměna kódu (client_secret_post + PKCE). Vrací id_token (OIDC), nebo
     * access_token (OAuth2 poskytovatel bez id_tokenu).
     *
     * @return array{id_token:?string,access_token:?string}
     */
    public function exchangeCode(
        OidcProvider $provider,
        #[\SensitiveParameter] string $code,
        string $redirectUri,
        #[\SensitiveParameter] string $codeVerifier,
    ): array {
        if ($code === '' || strlen($code) > 8192 || preg_match('/[\x00-\x20\x7f]/', $code) === 1) {
            throw new OidcException(OidcException::FLOW_INVALID, 'Neplatný autorizační kód.');
        }
        $form = [
            'client_id' => $provider->clientId,
            'client_secret' => $provider->secret(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];
        if ($provider->supportsPkce) {
            $form['code_verifier'] = $codeVerifier;
        }
        try {
            $response = $this->http->request('POST', $provider->tokenUrl, [
                // Seznam přijímá tělo token endpointu jen jako JSON.
                ($provider->tokenRequestJson ? 'json' : 'form_params') => $form,
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
        $idToken = self::token($data['id_token'] ?? null);
        $accessToken = self::token($data['access_token'] ?? null);
        if ($provider->protocol === 'oidc' && $idToken === null) {
            throw new OidcException(OidcException::TOKEN_INVALID, 'Odpověď poskytovatele neobsahuje id_token.');
        }
        if ($provider->protocol === 'oauth2' && $accessToken === null) {
            throw new OidcException(OidcException::TOKEN_INVALID, 'Odpověď poskytovatele neobsahuje access_token.');
        }
        return ['id_token' => $idToken, 'access_token' => $accessToken];
    }

    /** @return array<string,mixed> */
    public function userinfo(OidcProvider $provider, #[\SensitiveParameter] string $accessToken): array
    {
        if ($provider->userinfoUrl === null) {
            throw new OidcException(OidcException::PROVIDER_ERROR, 'Poskytovatel nemá userinfo.');
        }
        try {
            $response = $this->http->request('GET', $provider->userinfoUrl, [
                'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $accessToken],
                'allow_redirects' => false,
                'verify' => true,
                'http_errors' => false,
                'connect_timeout' => 5,
                'timeout' => 10,
            ]);
        } catch (\Throwable) {
            throw new OidcException(OidcException::PROVIDER_UNAVAILABLE, 'Poskytovatel identity není dostupný.');
        }
        $data = $this->json($response);
        if ($response->getStatusCode() !== 200 || $data === []) {
            throw new OidcException(OidcException::PROVIDER_ERROR, 'Poskytovatel identity nevrátil údaje o uživateli.');
        }
        return $data;
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

    private static function token(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 16384 ? $value : null;
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
