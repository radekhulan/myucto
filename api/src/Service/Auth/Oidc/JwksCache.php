<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Podpisové klíče poskytovatele (JWKS) s cache v souboru. Klíče se mění zřídka,
 * ale poskytovatel je rotuje bez ohlášení: neznámé `kid` vynutí jedno nové
 * stažení, nejvýš jednou za minutu, aby podvržené tokeny nemohly zahltit
 * poskytovatele požadavky. Když stažení selže, platí ještě den stará cache.
 */
class JwksCache
{
    private const TTL = 3600;
    private const STALE_TTL = 86400;
    private const REFRESH_COOLDOWN = 60;

    public function __construct(
        private readonly OidcHttpClient $http,
        private readonly ?string $directory = null,
    ) {}

    /** @return array<string,Key> */
    public function keys(OidcProvider $provider, bool $refresh = false): array
    {
        $file = $this->file($provider->jwksUrl);
        $cached = $this->read($file);
        $age = $cached !== null ? time() - $cached['fetched_at'] : PHP_INT_MAX;

        $shouldFetch = $cached === null
            || $age >= self::TTL
            || ($refresh && $age >= self::REFRESH_COOLDOWN);
        if ($shouldFetch) {
            try {
                $jwks = $this->http->fetchJwks($provider->jwksUrl);
                $this->write($file, $jwks);
                return $this->parse($jwks, $provider);
            } catch (OidcException $e) {
                if ($cached === null || $age >= self::STALE_TTL) {
                    throw $e;
                }
            }
        }
        return $this->parse($cached['jwks'], $provider);
    }

    /**
     * @param array<string,mixed> $jwks
     * @return array<string,Key>
     */
    private function parse(array $jwks, OidcProvider $provider): array
    {
        $usable = ['keys' => []];
        foreach ($jwks['keys'] ?? [] as $key) {
            if (!is_array($key) || !is_string($key['kid'] ?? null) || ($key['kty'] ?? '') !== 'RSA') {
                continue;
            }
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            if (isset($key['alg']) && !in_array($key['alg'], $provider->allowedAlgorithms, true)) {
                continue;
            }
            unset($key['x5c'], $key['x5t'], $key['issuer']);
            $usable['keys'][] = $key;
        }
        if ($usable['keys'] === []) {
            throw new OidcException(OidcException::PROVIDER_UNAVAILABLE, 'Poskytovatel nemá žádný použitelný podpisový klíč.');
        }
        try {
            return JWK::parseKeySet($usable, $provider->allowedAlgorithms[0]);
        } catch (\Throwable) {
            throw new OidcException(OidcException::PROVIDER_UNAVAILABLE, 'Klíče poskytovatele nejdou načíst.');
        }
    }

    private function file(string $url): string
    {
        $dir = $this->directory ?? RuntimePaths::storage('cache/oidc');
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'jwks-' . hash('sha256', $url) . '.json';
    }

    /** @return array{fetched_at:int,jwks:array<string,mixed>}|null */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_int($data['fetched_at'] ?? null) || !is_array($data['jwks'] ?? null)) {
            return null;
        }
        return ['fetched_at' => $data['fetched_at'], 'jwks' => $data['jwks']];
    }

    /** @param array<string,mixed> $jwks */
    private function write(string $file, array $jwks): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode(['fetched_at' => time(), 'jwks' => $jwks], JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
