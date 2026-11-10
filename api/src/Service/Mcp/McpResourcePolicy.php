<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tenant\TenantDomainContext;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Přesná OAuth audience a aktuální oprávnění připojení na vlastní doméně. */
final class McpResourcePolicy
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly PermissionResolver $roles,
        private readonly \MyInvoice\Service\Auth\LoginPolicyService $loginPolicy,
    ) {}

    public function canonical(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/') . '/mcp';
    }

    public function endpoint(Request $request): string
    {
        $domain = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        return $domain instanceof TenantDomainContext && $domain->locksSupplier() && $domain->allowsPortal()
            ? $domain->origin . '/mcp' : $this->canonical();
    }

    /** @return array{resource:string,supplier_id:?int}|null */
    public function resolve(string $resource): ?array
    {
        if (hash_equals($this->canonical(), $resource)) return ['resource' => $resource, 'supplier_id' => null];
        if (!filter_var($this->config->get('domains.enabled', false), FILTER_VALIDATE_BOOL)) return null;
        $host = parse_url($resource, PHP_URL_HOST);
        if (!is_string($host) || $resource !== 'https://' . strtolower($host) . '/mcp') return null;
        // Nejde o cache: vypnutí či převod domény musí ukončit již vydané připojení.
        $statement = $this->db->pdo()->prepare(
            "SELECT supplier_id FROM supplier_domains WHERE hostname = ? AND status = 'active' AND purpose IN ('portal', 'all')"
        );
        $statement->execute([$host]);
        $supplier = $statement->fetchColumn();
        return $supplier !== false && (int) $supplier > 0
            ? ['resource' => $resource, 'supplier_id' => (int) $supplier] : null;
    }

    /**
     * Adresa MCP, kterou klientská stránka nabídne pro firmu: aktivní klientská
     * doména firmy, jinak kanonická adresa instalace.
     */
    public function clientEndpoint(int $supplierId): string
    {
        if ($supplierId > 0 && filter_var($this->config->get('domains.enabled', false), FILTER_VALIDATE_BOOL)) {
            $statement = $this->db->pdo()->prepare(
                "SELECT hostname FROM supplier_domains
                  WHERE supplier_id = ? AND status = 'active' AND purpose IN ('portal', 'all')
                  ORDER BY id LIMIT 1"
            );
            $statement->execute([$supplierId]);
            $host = $statement->fetchColumn();
            if (is_string($host) && $host !== '') {
                $endpoint = 'https://' . strtolower($host) . '/mcp';
                if ($this->resolve($endpoint) !== null) return $endpoint;
            }
        }
        return $this->canonical();
    }

    /** Je výchozí role uživatele klientská? Typ role se per firmu změnit nesmí (PermissionResolver). */
    public function isClientUser(int $userId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT r.role_type FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
        );
        $statement->execute([$userId]);
        return $statement->fetchColumn() === 'client';
    }

    /**
     * Stejná kontrola při vydání, obnově tokenu i při každém jeho použití.
     *
     * Kanonická adresa: interní role mají připojení napříč firmami a práva se
     * kontrolují při každém volání API. Klientská role dostane připojení jen pro
     * jednu firmu a stejně jako na vlastní doméně se ověřuje členství a profile.mcp.
     */
    public function allowsGrant(string $resource, int $userId, ?int $supplierId, string $scope, ?string $issuedAuthMethod = null): bool
    {
        // Grant z přihlášení, které vynucený poskytovatel identity nepřipouští,
        // se nevydá ani neobnoví.
        if (!$this->loginPolicy->authMethodAllowed($issuedAuthMethod)) return false;
        $target = $this->resolve($resource);
        if ($target === null) return false;
        if ($target['supplier_id'] === null) {
            if (!$this->isClientUser($userId)) return true;
            // Klientské připojení bez vazby na firmu se nevydá ani neobnoví.
            if ($supplierId === null || $supplierId < 1) return false;
        } elseif ($target['supplier_id'] !== $supplierId) {
            return false;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT u.id, u.role_id, r.role_type, r.system_key FROM users u JOIN roles r ON r.id=u.role_id
             JOIN user_suppliers us ON us.user_id=u.id AND us.supplier_id=?
             WHERE u.id=? AND u.is_active=1 AND r.is_active=1'
        );
        $statement->execute([$supplierId, $userId]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($user)) return false;
        $user['role_summary'] = ['type' => $user['role_type'], 'system_key' => $user['system_key']];
        $request = RequestAuthorization::asBearer((new ServerRequestFactory())->createServerRequest('GET', $this->canonical()))
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => $supplierId, 'issued_auth_method' => $issuedAuthMethod]);
        $request = $request->withAttribute('auth.effective_role', $this->roles->resolve($request));
        return RequestAuthorization::canUseMcp($request, $scope === 'read_write' ? AccessLevel::WRITE : AccessLevel::READ);
    }
}
