<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Klientský katalog odvozuje práva z operací nástrojů; manifest vzniká z MCP katalogu. */
final class ClientMcpToolPolicy
{
    public function __construct(
        private readonly PermissionResolver $roles,
        private readonly PermissionChecker $checker,
        private readonly RoutePermissionMap $routes,
        private readonly ClientRoutePolicy $clientRoutes,
        private readonly string $manifestPath = __DIR__ . '/../../../../MCP/src/tool-api.generated.json',
    ) {}

    /** @return list<string> */
    public function allowed(Request $request): array
    {
        $contracts = $this->contracts();
        if ($contracts === []) return [];
        $role = $this->roles->resolve($request);
        $allowed = [];
        foreach ($contracts as $name => $contract) {
            foreach ($contract['api'] as [$method, $template]) {
                // Jediné dosazení vzorového ID pro sdílenou mapu rout; manifest uchovává šablony.
                $path = preg_replace('/\{[a-z_]+\}/', '1', $template);
                $route = $this->routes->match($method, $path);
                if ($route === null || !$this->clientRoutes->allowsApiRequest($method, $path)) continue 2;
                if ($route->kind === RoutePermissionMap::SELF_SERVICE) continue;
                if ($route->kind !== RoutePermissionMap::PERMISSION || $route->key === null
                    || !array_any([$route->key, ...$route->alternativeKeys],
                        fn (string $key): bool => $this->checker->allows($role, $key, $route->minimum))) continue 2;
            }
            $allowed[] = $name;
        }
        return $allowed;
    }

    /** Neúplná distribuce ani poškozený manifest nesmí otevřít katalog či skončit HTTP 500.
     *  @return array<string, array{write:bool, api:list<array{string,string}>}>
     */
    private function contracts(): array
    {
        $json = @file_get_contents($this->manifestPath);
        if ($json === false) return [];
        $contracts = json_decode($json, true, 32);
        if (!is_array($contracts) || json_last_error() !== JSON_ERROR_NONE) return [];
        foreach ($contracts as $name => $contract) {
            if (!is_string($name) || !is_array($contract) || !is_bool($contract['write'] ?? null)
                || !is_array($contract['api'] ?? null) || !array_is_list($contract['api']) || $contract['api'] === []) return [];
            foreach ($contract['api'] as $operation) {
                if (!is_array($operation) || !array_is_list($operation) || count($operation) !== 2) return [];
                [$method, $path] = $operation;
                if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
                    || ($method !== 'GET' && !$contract['write']) || !is_string($path)
                    || preg_match('#^/api/(?:[a-z][a-z0-9-]*|\{[a-z_]+\})(?:/(?:[a-z][a-z0-9-]*|\{[a-z_]+\}))*$#D', $path) !== 1) return [];
            }
        }
        return $contracts;
    }
}
