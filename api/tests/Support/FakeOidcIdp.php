<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use Firebase\JWT\JWT;
use MyInvoice\Service\Auth\Oidc\OidcHttpClient;
use MyInvoice\Service\Auth\Oidc\OidcProvider;

/**
 * Syntetický poskytovatel identity pro testy: vlastní RSA klíč, JWKS
 * a podepisování id_tokenů. Token endpoint vrací předem připravený token,
 * do kterého se doplní `nonce` z poslední autorizační adresy.
 */
final class FakeOidcIdp extends OidcHttpClient
{
    public const TENANT = '11111111-2222-4333-8444-555555555555';
    public const CLIENT = '99999999-8888-4777-8666-555555555555';
    public const KID = 'test-key-1';

    private \OpenSSLAsymmetricKey $key;
    /** @var array<string,mixed> */
    private array $jwks;
    public int $jwksFetches = 0;
    public ?string $lastNonce = null;
    /** @var array<string,mixed> claimy příštího tokenu */
    public array $nextClaims = [];
    public ?string $nextToken = null;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('Nelze vygenerovat RSA klíč (OPENSSL_CONF?).');
        }
        $this->key = $key;
        $details = openssl_pkey_get_details($key);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => self::KID,
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ]]];
    }

    public static function provider(bool $allowGuests = true): OidcProvider
    {
        $authority = 'https://login.microsoftonline.com/' . self::TENANT;
        return new OidcProvider(
            id: 'entra',
            label: 'Microsoft',
            issuer: $authority . '/v2.0',
            authorizeUrl: $authority . '/oauth2/v2.0/authorize',
            tokenUrl: $authority . '/oauth2/v2.0/token',
            jwksUrl: $authority . '/discovery/v2.0/keys',
            clientId: self::CLIENT,
            clientSecret: 'test-secret',
            scope: 'openid profile email',
            tenantId: self::TENANT,
            allowGuests: $allowGuests,
        );
    }

    public const GOOGLE_CLIENT = '1234567890-testclient.apps.googleusercontent.com';

    /** @param list<string> $hostedDomains */
    public static function googleProvider(array $hostedDomains = []): OidcProvider
    {
        return new OidcProvider(
            id: 'google',
            label: 'Google',
            issuer: 'https://accounts.google.com',
            authorizeUrl: 'https://accounts.google.com/o/oauth2/v2/auth',
            tokenUrl: 'https://oauth2.googleapis.com/token',
            jwksUrl: 'https://www.googleapis.com/oauth2/v3/certs',
            clientId: self::GOOGLE_CLIENT,
            clientSecret: 'test-secret',
            scope: 'openid email profile',
            tenantId: null,
            allowGuests: true,
            assurance: OidcProvider::FIRST_FACTOR,
            responseMode: '',
            issuerAliases: ['accounts.google.com'],
            allowedHostedDomains: $hostedDomains,
        );
    }

    public static function appleProvider(): OidcProvider
    {
        return new OidcProvider(
            id: 'apple',
            label: 'Apple',
            issuer: 'https://appleid.apple.com',
            authorizeUrl: 'https://appleid.apple.com/auth/authorize',
            tokenUrl: 'https://appleid.apple.com/auth/token',
            jwksUrl: 'https://appleid.apple.com/auth/keys',
            clientId: 'cz.example.ucto.web',
            clientSecret: '',
            scope: 'openid email',
            tenantId: null,
            allowGuests: true,
            assurance: OidcProvider::FIRST_FACTOR,
            responseMode: 'form_post',
        );
    }

    /** @param array<string,mixed> $override */
    public function googleClaims(string $sub, string $nonce, array $override = []): array
    {
        $now = time();
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_CLIENT,
            'exp' => $now + 3600,
            'iat' => $now,
            'nonce' => $nonce,
            'sub' => $sub,
            'email' => 'tester@example.test',
            'email_verified' => true,
            'name' => 'Test Tester',
        ], $override);
    }

    /** @param array<string,mixed> $override */
    public function claims(string $oid, string $nonce, array $override = []): array
    {
        $now = time();
        return array_merge([
            'iss' => self::provider()->issuer,
            'aud' => self::CLIENT,
            'exp' => $now + 3600,
            'nbf' => $now - 10,
            'iat' => $now,
            'nonce' => $nonce,
            'ver' => '2.0',
            'tid' => self::TENANT,
            'oid' => $oid,
            'sub' => 'pairwise-' . $oid,
            'preferred_username' => 'tester@example.test',
            'name' => 'Test Tester',
        ], $override);
    }

    /** @param array<string,mixed> $claims */
    public function sign(array $claims, string $alg = 'RS256', ?string $kid = self::KID): string
    {
        return JWT::encode($claims, $this->key, $alg, $kid);
    }

    public function authorizationUrl(
        OidcProvider $provider,
        string $redirectUri,
        string $state,
        string $nonce,
        #[\SensitiveParameter] string $codeVerifier,
        array $extra = [],
    ): string {
        $this->lastNonce = $nonce;
        return parent::authorizationUrl($provider, $redirectUri, $state, $nonce, $codeVerifier, $extra);
    }

    public function exchangeCode(OidcProvider $provider, string $code, string $redirectUri, string $codeVerifier): array
    {
        if ($this->nextToken !== null) {
            return ['id_token' => $this->nextToken, 'access_token' => 'access'];
        }
        return ['id_token' => $this->sign(array_merge(['nonce' => $this->lastNonce], $this->nextClaims)), 'access_token' => 'access'];
    }

    public function fetchJwks(string $url): array
    {
        $this->jwksFetches++;
        return $this->jwks;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
