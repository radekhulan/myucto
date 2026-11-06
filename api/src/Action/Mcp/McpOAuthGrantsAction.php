<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mcp\McpOAuth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Připojení MCP přes OAuth. Uživatel vidí a odebírá svá připojení, superadmin
 * s `?all=1` připojení všech uživatelů instance a smí je odebrat (zapíše se do
 * auditního logu i s vlastníkem připojení).
 */
final class McpOAuthGrantsAction
{
    public function __construct(
        private readonly McpOAuth $oauth,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly PermissionResolver $roles,
    ) {}

    public function listing(Request $request, Response $response): Response
    {
        $userId = $this->sessionUserId($request);
        if ($userId <= 0) return Json::sessionRequired($response);
        $domain = $request->getAttribute(\MyInvoice\Middleware\TenantDomainMiddleware::ATTR_CONTEXT);
        $bound = $domain instanceof \MyInvoice\Service\Tenant\TenantDomainContext && $domain->locksSupplier();
        $all = filter_var($request->getQueryParams()['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($all && ($bound || !$this->isSuperadmin($request))) {
            return Json::error($response, 'forbidden', 'Připojení všech uživatelů vidí jen správce instance.', 403);
        }
        return Json::ok($response, ['grants' => $all ? $this->oauth->listAll() : $this->oauth->listForUser($userId, $bound ? $domain->supplierId : null, $bound ? $domain->origin . '/mcp' : null)])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $userId = $this->sessionUserId($request);
        if ($userId <= 0) return Json::sessionRequired($response);
        $id = (int) ($args['id'] ?? 0);
        $domain = $request->getAttribute(\MyInvoice\Middleware\TenantDomainMiddleware::ATTR_CONTEXT);
        $bound = $domain instanceof \MyInvoice\Service\Tenant\TenantDomainContext && $domain->locksSupplier();
        if ($id > 0 && $this->oauth->revokeForUser($id, $userId, $bound ? $domain->supplierId : null, $bound ? $domain->origin . '/mcp' : null)) {
            return Json::ok($response, ['revoked' => true]);
        }
        if ($id > 0 && !$bound && $this->isSuperadmin($request)) {
            $ownerId = $this->oauth->revokeAny($id);
            if ($ownerId !== null) {
                $this->activity->log(
                    'mcp_grant.revoked_by_admin',
                    $userId,
                    'mcp_oauth_grant',
                    $id,
                    ['owner_user_id' => $ownerId],
                    $this->ipMatcher->clientIp($request->getServerParams(), [], 'X-Forwarded-For'),
                    $request->getHeaderLine('User-Agent'),
                );
                return Json::ok($response, ['revoked' => true]);
            }
        }
        return Json::error($response, 'not_found', 'Připojení nebylo nalezeno.', 404);
    }

    /**
     * Routa je self-service, PermissionMiddleware tedy roli nedoplní. Superadmin
     * je role účtu, ne firmy, proto stačí výchozí role.
     */
    private function isSuperadmin(Request $request): bool
    {
        return $this->roles->resolveDefault($request)->isSuperadmin();
    }

    private function sessionUserId(Request $request): int
    {
        if (!RequestAuthorization::isSessionAuth($request)) return 0;
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }
}
