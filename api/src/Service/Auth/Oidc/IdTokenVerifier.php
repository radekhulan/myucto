<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use Firebase\JWT\JWT;

/**
 * Ověření id_tokenu (OpenID Connect Core 3.1.3.7): podpis klíčem poskytovatele,
 * jen povolený algoritmus, přesný vydavatel a příjemce, platnost a `nonce`
 * rozběhnutého přihlášení. U Entra ID navíc tenant (`tid`) a stabilní `oid`.
 *
 * Vrací {@see ExternalIdentity}. E-mail z tokenu v ní je jen informativní:
 * Entra ho neověřuje, takže podle něj se nikdy nepáruje.
 */
class IdTokenVerifier
{
    private const LEEWAY = 120;
    private const MAX_AGE = 900;
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(private readonly JwksCache $jwks) {}

    public function verify(
        OidcProvider $provider,
        #[\SensitiveParameter] string $idToken,
        string $expectedNonce,
        ?int $now = null,
    ): ExternalIdentity {
        $header = self::header($idToken);
        $alg = $header['alg'] ?? null;
        $kid = $header['kid'] ?? null;
        if (!is_string($alg) || !in_array($alg, $provider->allowedAlgorithms, true)) {
            throw self::invalid('Nepovolený algoritmus podpisu.');
        }
        if (!is_string($kid) || $kid === '') {
            throw self::invalid('Token nemá identifikátor klíče.');
        }

        $keys = $this->jwks->keys($provider);
        if (!isset($keys[$kid])) {
            $keys = $this->jwks->keys($provider, true);
        }
        if (!isset($keys[$kid]) || $keys[$kid]->getAlgorithm() !== $alg) {
            throw self::invalid('Token je podepsaný neznámým klíčem.');
        }

        $now ??= time();
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = $now;
        try {
            $claims = (array) JWT::decode($idToken, $keys[$kid]);
        } catch (\Throwable) {
            throw self::invalid('Podpis nebo platnost tokenu nesouhlasí.');
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        if (($claims['iss'] ?? null) !== $provider->issuer) {
            throw self::invalid('Token vydal jiný vydavatel.');
        }
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($provider->clientId, $audiences, true)) {
            throw self::invalid('Token je určený jiné aplikaci.');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $provider->clientId) {
            throw self::invalid('Token je určený jiné aplikaci.');
        }
        if (!is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null)) {
            throw self::invalid('Token nemá platnost.');
        }
        if ($claims['iat'] < $now - self::MAX_AGE) {
            throw self::invalid('Token je příliš starý.');
        }
        $nonce = $claims['nonce'] ?? null;
        if (!is_string($nonce) || $expectedNonce === '' || !hash_equals($expectedNonce, $nonce)) {
            throw self::invalid('Token nepatří k tomuto přihlášení.');
        }
        $authTime = isset($claims['auth_time']) && is_int($claims['auth_time']) ? $claims['auth_time'] : null;

        return match ($provider->id) {
            'entra' => $this->entraIdentity($provider, $claims, $authTime),
            default => throw self::invalid('Neznámý poskytovatel.'),
        };
    }

    /** @param array<string,mixed> $claims */
    private function entraIdentity(OidcProvider $provider, array $claims, ?int $authTime): ExternalIdentity
    {
        if (($claims['ver'] ?? null) !== '2.0') {
            throw self::invalid('Token není ve verzi 2.0.');
        }
        $tid = $claims['tid'] ?? null;
        if (!is_string($tid) || $tid !== $provider->tenantId) {
            throw self::invalid('Token je z jiného tenantu.');
        }
        $oid = $claims['oid'] ?? null;
        if (!is_string($oid) || preg_match(self::GUID, $oid) !== 1) {
            throw self::invalid('Token nemá ID objektu uživatele.');
        }
        // Host B2B má v tokenu svého domovského vydavatele v `idp`. Člen tenantu
        // ho nemá, nebo má stejný jako `iss`.
        $idp = $claims['idp'] ?? null;
        $isGuest = is_string($idp) && $idp !== '' && $idp !== $claims['iss'];
        if ($isGuest && !$provider->allowGuests) {
            throw new OidcException(OidcException::NOT_LINKED, 'Hosté tenantu se přihlásit nesmí.');
        }
        $email = self::shortString($claims['preferred_username'] ?? null)
            ?? self::shortString($claims['email'] ?? null);

        return new ExternalIdentity(
            provider: $provider->id,
            issuer: $provider->issuer,
            tenantId: $tid,
            subject: $oid,
            email: $email,
            name: self::shortString($claims['name'] ?? null),
            isGuest: $isGuest,
            authTime: $authTime,
            alternateEmails: array_values(array_filter([self::shortString($claims['email'] ?? null)])),
        );
    }

    /** @return array<string,mixed> */
    private static function header(string $jwt): array
    {
        if (strlen($jwt) > 16384 || substr_count($jwt, '.') !== 2) {
            throw self::invalid('Token nemá tvar JWT.');
        }
        $raw = JWT::urlsafeB64Decode(explode('.', $jwt, 2)[0]);
        $header = json_decode($raw, true);
        if (!is_array($header)) {
            throw self::invalid('Token nemá platnou hlavičku.');
        }
        return $header;
    }

    private static function shortString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 190);
    }

    private static function invalid(string $message): OidcException
    {
        return new OidcException(OidcException::TOKEN_INVALID, $message);
    }
}
