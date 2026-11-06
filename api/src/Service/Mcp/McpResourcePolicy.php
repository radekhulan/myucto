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

    /** Stejná kontrola při vydání, obnově tokenu i při každém jeho použití. */
    public function allowsGrant(string $resource, int $userId, ?int $supplierId, string $scope): bool
    {
        $target = $this->resolve($resource);
        if ($target === null) return false;
        // Dosavadní canonical připojení mohou být i bez vazby na jedinou firmu.
        if ($target['supplier_id'] === null) return true;
        if ($target['supplier_id'] !== $supplierId) return false;
        $statement = $this->db->pdo()->prepare(
            'SELECT u.id, u.role_id, r.role_type, r.system_key FROM users u JOIN roles r ON r.id=u.role_id
             JOIN user_suppliers us ON us.user_id=u.id AND us.supplier_id=?
             WHERE u.id=? AND u.is_active=1 AND r.is_active=1'
        );
        $statement->execute([$supplierId, $userId]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($user)) return false;
        $user['role_summary'] = ['type' => $user['role_type'], 'system_key' => $user['system_key']];
        $request = (new ServerRequestFactory())->createServerRequest('GET', $this->canonical())
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => $supplierId]);
        $request = $request->withAttribute('auth.effective_role', $this->roles->resolve($request));
        return RequestAuthorization::canUseMcp($request, $scope === 'read_write' ? AccessLevel::WRITE : AccessLevel::READ);
    }
}
