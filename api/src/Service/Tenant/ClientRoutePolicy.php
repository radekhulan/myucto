<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Http\RequestPath;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\ClientBankRoutePolicy;
use MyInvoice\Security\ClientStockRoutePolicy;
use MyInvoice\Security\ClientTaxSubmissionPolicy;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Auth\WebAuthnOperationPolicy;

/**
 * Jediný seznam webových cest klientského rozhraní a odvozená API hranice.
 *
 * Webový manifest sdílí PHP host-gate s Vue routerem. API se z něj naopak
 * neodvozuje podle URL: povolí se jen self-service nebo permission, kterou
 * skutečný PermissionCatalog dovoluje roli typu client.
 */
final class ClientRoutePolicy
{
    private const MANIFEST = __DIR__ . '/../../../../web/shared/client-route-policy.json';

    /** @var array{routes:list<array<string,mixed>>,flow_paths:list<array<string,mixed>>,portal_api_paths:list<array<string,mixed>>}|null */
    private static ?array $manifest = null;

    public function __construct(
        private readonly RoutePermissionMap $permissions = new RoutePermissionMap(),
        private readonly PermissionCatalog $catalog = new PermissionCatalog(),
        private readonly WebAuthnOperationPolicy $webAuthnOperations = new WebAuthnOperationPolicy(),
    ) {}

    /** Cesta už musí být jednou normalizovaná na vstupu middleware. */
    public function allowsAuthenticatedPath(string $path): bool
    {
        return $this->matchesManifestPaths('routes', $path);
    }

    /** Cesta už musí být jednou normalizovaná na vstupu middleware. */
    public function allowsFlowPath(string $path): bool
    {
        return $this->matchesManifestPaths('flow_paths', $path);
    }

    /** Návratová cesta je surová hodnota z těla požadavku, včetně query a hashe. */
    public function allowsReturnPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '') return true;
        if (strlen($path) > 500
            || $path[0] !== '/'
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
        ) {
            return false;
        }

        $parts = parse_url($path);
        if (!is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        return $this->allowsAuthenticatedPath(RequestPath::normalize((string) ($parts['path'] ?? '')));
    }

    /**
     * Vrátí pevný canonical cíl pro klientskou cestu, jejíž WebAuthn operace
     * nesmí běžet na vlastní doméně. Cíl pochází jen ze sdíleného manifestu;
     * query ze vstupu slouží nanejvýš k výběru deklarované varianty.
     */
    public function canonicalHandoffPath(string $path): ?string
    {
        $path = trim($path);
        if (!$this->allowsReturnPath($path)) return null;

        $parts = parse_url($path);
        if (!is_array($parts)) return null;
        $normalized = RequestPath::normalize((string) ($parts['path'] ?? ''));
        $queryCounts = [];
        foreach (explode('&', (string) ($parts['query'] ?? '')) as $field) {
            if ($field === '') continue;
            $key = urldecode(explode('=', $field, 2)[0]);
            $queryCounts[$key] = ($queryCounts[$key] ?? 0) + 1;
        }
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        foreach (self::manifest()['routes'] as $route) {
            $handoff = $route['canonical_handoff'] ?? null;
            if (!is_array($handoff)) continue;

            $pattern = (string) ($route['path_pattern'] ?? '');
            if ($pattern === '' || preg_match('#' . $pattern . '#D', $normalized) !== 1) continue;

            $requiredQuery = $handoff['match_query'] ?? [];
            if (!is_array($requiredQuery)) {
                throw new \RuntimeException('Manifest klientských rout má neplatný canonical handoff.');
            }
            foreach ($requiredQuery as $key => $value) {
                $actual = $query[$key] ?? null;
                $allowed = is_array($value) ? $value : [$value];
                if (($queryCounts[$key] ?? 0) !== 1
                    || !is_string($actual)
                    || !in_array($actual, $allowed, true)
                ) {
                    continue 2;
                }
            }

            $target = (string) ($handoff['to'] ?? '');
            $queryTargets = $handoff['query_targets'] ?? [];
            if (!is_array($queryTargets)) {
                throw new \RuntimeException('Manifest klientských rout má neplatné varianty handoffu.');
            }
            foreach ($queryTargets as $key => $targets) {
                if (($queryCounts[$key] ?? 0) > 1) continue 2;
                $value = $query[$key] ?? null;
                if (is_string($value) && is_array($targets) && isset($targets[$value])) {
                    $target = (string) $targets[$value];
                    break;
                }
            }
            if ($target === '' || !$this->allowsReturnPath($target)) {
                throw new \RuntimeException('Manifest klientských rout obsahuje nebezpečný canonical cíl.');
            }
            return $target;
        }

        return null;
    }

    /** API cesta už musí být jednou normalizovaná na vstupu middleware. */
    public function allowsApiRequest(string $method, string $path): bool
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') $method = 'GET';

        // Passkeys jsou RP/origin-bound ke canonical app.url. Vlastní doména
        // používá serverem svázaný handoff. Rozhodnutí je sémantické: zahrnuje
        // i login a session unlock mimo /webauthn prefix a neblokuje jen podle
        // podobného názvu libovolný budoucí endpoint.
        if ($this->webAuthnOperations->requiresCanonicalOrigin($method, $path)) return false;

        foreach (self::manifest()['portal_api_paths'] as $route) {
            if (($route['method'] ?? null) === $method && ($route['path'] ?? null) === $path) {
                return true;
            }
        }

        $permission = $this->permissions->match($method, $path);
        if ($permission === null) return false;
        if ($permission->kind === RoutePermissionMap::SELF_SERVICE) {
            // Autorizační půlka domain-login toku patří výhradně canonical originu.
            return $path !== '/api/auth/domain-login/authorize';
        }
        if ($permission->kind !== RoutePermissionMap::PERMISSION || $permission->key === null) {
            return false;
        }

        return array_any([$permission->key, ...$permission->alternativeKeys],
                fn (string $key): bool => $this->catalog->allowsRoleType($key, 'client'))
            && ClientBankRoutePolicy::allows($method, $path, $permission->key)
            && ClientStockRoutePolicy::allows($method, $path, $permission->key)
            && ClientTaxSubmissionPolicy::allowsRoute($method, $path);
    }

    /** @return list<array<string,mixed>> */
    public function routes(): array
    {
        return self::manifest()['routes'];
    }

    private function matchesManifestPaths(string $section, string $path): bool
    {
        foreach (self::manifest()[$section] as $route) {
            $pattern = (string) ($route['path_pattern'] ?? '');
            if ($pattern !== '' && preg_match('#' . $pattern . '#D', $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{routes:list<array<string,mixed>>,flow_paths:list<array<string,mixed>>,portal_api_paths:list<array<string,mixed>>}
     */
    private static function manifest(): array
    {
        if (self::$manifest !== null) return self::$manifest;

        $json = file_get_contents(self::MANIFEST);
        if ($json === false) {
            throw new \RuntimeException('Chybí manifest klientských rout.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || !is_array($decoded['routes'] ?? null)
            || !is_array($decoded['flow_paths'] ?? null)
            || !is_array($decoded['portal_api_paths'] ?? null)
        ) {
            throw new \RuntimeException('Manifest klientských rout má neplatnou strukturu.');
        }

        /** @var array{routes:list<array<string,mixed>>,flow_paths:list<array<string,mixed>>,portal_api_paths:list<array<string,mixed>>} $decoded */
        self::$manifest = $decoded;
        return self::$manifest;
    }
}
