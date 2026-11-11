<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\ClientTaxSubmissionPolicy;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Security\RoutePermission;
use MyInvoice\Security\RoutePermissionMap;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nabídka serverového MCP pro interní role (mimo superadmina a správce firmy).
 *
 * Nástroj se nabídne, jen když všechny jeho API operace (manifest
 * `tool-routes.generated.json`) smí role uživatele aspoň v jedné z jeho firem.
 * Nástroje berou supplier_id při každém volání, proto rozhoduje sjednocení přes
 * firmy včetně per-firemních rolí z `user_suppliers.role_id`. Jde jen o nabídku:
 * práva i vazbu na firmu dál ověřuje API při každém volání.
 *
 * Rozhodnutí o routě zrcadlí roli závislé větve {@see \MyInvoice\Middleware\PermissionMiddleware}
 * pro interní role (klientské role mají vlastní katalog {@see ClientMcpToolPolicy}).
 * Shodu hlídá `StaffMcpToolPolicyTest`.
 */
final class StaffMcpToolPolicy
{
    /** Nástroje, které obslouží přímo hostovaný endpoint bez volání API. */
    private const LOCAL_TOOLS = ['list_suppliers'];

    /** @var array<string, array{write:bool, api:list<array{string,string}>}>|null */
    private ?array $manifest = null;

    /** @var array<string, ?RoutePermission> */
    private array $routeMemo = [];

    /** @var array<string, list<EffectiveRole>|null> */
    private array $rolesMemo = [];

    public function __construct(
        private readonly PermissionResolver $roles,
        private readonly PermissionChecker $checker,
        private readonly RoutePermissionMap $routes,
        private readonly UserSupplierRepository $memberships,
        private readonly string $manifestPath = __DIR__ . '/../../../../MCP/src/tool-routes.generated.json',
    ) {}

    /**
     * Jména nástrojů, které role uživatele může použít; NULL = bez omezení
     * (superadmin, správce firmy, nebo nečitelný manifest jako dosud).
     *
     * @return list<string>|null
     */
    public function allowed(Request $request): ?array
    {
        $manifest = $this->manifest();
        $roles = $this->rolesAcrossCompanies($request);
        if ($manifest === null || $roles === null) return null;
        $allowed = [];
        foreach ($manifest as $name => $contract) {
            if ($this->usable($request, $roles, $name, $contract['api'])) $allowed[] = $name;
        }
        return $allowed;
    }

    /** Totéž rozhodnutí pro jediný nástroj (tools/call), bez procházení celého katalogu. */
    public function allows(Request $request, string $name): bool
    {
        $manifest = $this->manifest();
        $roles = $this->rolesAcrossCompanies($request);
        if ($manifest === null || $roles === null) return true;
        if (!isset($manifest[$name])) return false;
        return $this->usable($request, $roles, $name, $manifest[$name]['api']);
    }

    /**
     * @param list<EffectiveRole> $roles
     * @param list<array{string,string}> $operations
     */
    private function usable(Request $request, array $roles, string $name, array $operations): bool
    {
        if ($operations === [] || in_array($name, self::LOCAL_TOOLS, true)) return true;
        // Bez firmy zbývají jen routy nezávislé na roli (self-service).
        foreach ($roles === [] ? [EffectiveRole::denied()] : $roles as $role) {
            if (array_all($operations, fn (array $operation): bool
                => $this->routeAllows($request, $role, $operation[0], $operation[1]))) return true;
        }
        return false;
    }

    private function routeAllows(Request $request, EffectiveRole $role, string $method, string $template): bool
    {
        // Stejné dosazení vzorového ID jako v ClientMcpToolPolicy; manifest uchovává šablony.
        $path = (string) preg_replace('/\{[a-z_]+\}/', '1', $template);
        $route = $this->routeMemo[$method . ' ' . $path] ??= $this->routes->match($method, $path);
        if ($route === null) return false;
        return match ($route->kind) {
            RoutePermissionMap::PUBLIC, RoutePermissionMap::SELF_SERVICE => true,
            RoutePermissionMap::ADMIN_PLUS => $this->roles->resolveDefault($request)->canCreateSupplier(),
            RoutePermissionMap::PERMISSION => $this->permissionAllows($role, $method, $path, $route),
            default => false,
        };
    }

    private function permissionAllows(EffectiveRole $role, string $method, string $path, RoutePermission $route): bool
    {
        if ($route->key === null) return false;
        // MCP volá API tokenem, takže archiv podání pro roli v rozsahu klientských podání zůstává zavřený.
        if (RequestAuthorization::roleUsesClientTaxSubmissionScope($role)
            && (!ClientTaxSubmissionPolicy::allowsRoute($method, $path)
                || preg_match('#^/api/reports/submissions(?:/|$)#D', $path) === 1)) return false;
        if (array_any([$route->key, ...$route->alternativeKeys],
            fn (string $key): bool => $this->checker->allows($role, $key, $route->minimum))) return true;
        // Schvalovatel přijatého dokladu smí jeho PDF i bez agendy (PermissionMiddleware::isApproverPdf);
        // vazbu na konkrétní doklad ověří API při volání.
        return $method === 'GET' && preg_match('#^/api/purchase-invoices/[0-9]+/pdf$#D', $path) === 1
            && $this->checker->allows($role, 'purchase_invoices.approve');
    }

    /**
     * Role uživatele ve firmách, kam MCP smí; NULL = nabídku neomezovat.
     * Jedna resoluce na každou odlišnou roli, ne na každou firmu.
     *
     * @return list<EffectiveRole>|null
     */
    private function rolesAcrossCompanies(Request $request): ?array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $bound = RequestAuthorization::boundSupplierId($request);
        $key = $userId . ':' . ($bound ?? '*');
        if (array_key_exists($key, $this->rolesMemo)) return $this->rolesMemo[$key];
        if ($userId <= 0) return $this->rolesMemo[$key] = [];
        $default = $this->roles->resolveDefault($request);
        if ($default->isSuperadmin()) return $this->rolesMemo[$key] = null;

        $defaultRoleId = (int) ($user['role_id'] ?? ($user['role_summary']['id'] ?? 0));
        $representatives = [];
        foreach ($this->memberships->assignmentsForUser($userId) as $supplierId => $override) {
            if ($bound !== null && $supplierId !== $bound) continue;
            $representatives[$override ?? $defaultRoleId] ??= $supplierId;
        }
        $roles = [];
        foreach ($representatives as $supplierId) {
            // Resoluce přes PermissionResolver drží pravidla per-firemních rolí na jednom místě.
            $role = $this->roles->resolve($request->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $supplierId));
            if (!$role->isActive) continue;
            if ($role->isCompanyAdmin()) return $this->rolesMemo[$key] = null;
            $roles[] = $role;
        }
        return $this->rolesMemo[$key] = $roles;
    }

    /**
     * Neúplná distribuce ani poškozený manifest nesmí MCP interním rolím zavřít:
     * nabídka se pak neomezí a práva ověří API jako dosud.
     *
     * @return array<string, array{write:bool, api:list<array{string,string}>}>|null
     */
    private function manifest(): ?array
    {
        if ($this->manifest !== null) return $this->manifest;
        $json = @file_get_contents($this->manifestPath);
        if ($json === false) return null;
        $contracts = json_decode($json, true, 32);
        if (!is_array($contracts) || $contracts === [] || json_last_error() !== JSON_ERROR_NONE) return null;
        foreach ($contracts as $name => $contract) {
            if (!is_string($name) || !is_array($contract) || !is_bool($contract['write'] ?? null)
                || !is_array($contract['api'] ?? null) || !array_is_list($contract['api'])) return null;
            foreach ($contract['api'] as $operation) {
                if (!is_array($operation) || !array_is_list($operation) || count($operation) !== 2) return null;
                [$method, $path] = $operation;
                if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || !is_string($path)
                    || preg_match('#^/api/(?:[a-z][a-z0-9-]*|\{[a-z_]+\})(?:/(?:[a-z][a-z0-9-]*|\{[a-z_]+\}))*$#D', $path) !== 1) return null;
            }
        }
        return $this->manifest = $contracts;
    }
}
