<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Repository\PurchaseInvoiceApprovalRepository;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\ClientBankRoutePolicy;
use MyInvoice\Security\ClientStockRoutePolicy;
use MyInvoice\Security\ClientTaxSubmissionPolicy;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Security\FrameworkScopePolicy;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class PermissionMiddleware implements MiddlewareInterface
{
    public const PUBLIC_OR_SELF = [
        '/api/health', '/api/version', '/api/openapi.yaml', '/api/docs', '/api/reference', '/api/scalar',
        '/api/auth/setup-status', '/api/auth/setup-preflight', '/api/auth/setup', '/api/auth/setup-ares-lookup',
        '/api/auth/setup-crpdph-lookup', '/api/auth/setup-sample', '/api/auth/login',
        '/api/auth/domain-context',
        '/api/auth/domain-login/start', '/api/auth/domain-login/authorize', '/api/auth/domain-login/exchange',
        '/api/auth/logout', '/api/auth/me', '/api/auth/api-me', '/api/auth/forgot', '/api/auth/reset',
        '/api/auth/change-password', '/api/auth/totp/status', '/api/auth/totp/setup',
        '/api/auth/totp/enable', '/api/csrf-token',
        '/mcp', '/.well-known/oauth-protected-resource',
        '/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-authorization-server',
        '/oauth/register', '/oauth/authorize', '/oauth/token', '/api/mcp/settings', '/api/mcp/grants',
    ];

    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly RoutePermissionMap $routes,
        private readonly PermissionResolver $roles,
        private readonly PermissionChecker $checker,
        private readonly SupplierAccessResolver $supplierAccess,
        // Nepovinné kvůli testům, které middleware staví ručně; v aplikaci ho předává
        // Bootstrap výslovně (autowiring nepovinné parametry nedoplňuje).
        private readonly ?PurchaseInvoiceApprovalRepository $purchaseApprovals = null,
        private readonly ?FrameworkScopePolicy $frameworkScope = null,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $method = strtoupper($request->getMethod());
        if ($method === 'OPTIONS') return $handler->handle($request);

        // Cestu normalizujeme stejně, jako ji dekóduje router (RequestPath) — jinak
        // `/api/purchase-invoices/%70ayment-orders` mine konkrétní pravidlo a spadne
        // na hrubší modulový catch-all, zatímco router doručí tutéž Action.
        $path = RequestPath::normalize($request->getUri()->getPath());

        $route = $this->routes->match($method === 'HEAD' ? 'GET' : $method, $path);
        if ($route === null) return $this->forbidden($request);
        if ($route->kind === RoutePermissionMap::PUBLIC || $route->kind === RoutePermissionMap::SELF_SERVICE) {
            return $handler->handle($request);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($user['id'] ?? 0) <= 0) {
            return Json::error($this->responseFactory->createResponse(401), 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        if ($route->kind === RoutePermissionMap::ADMIN_PLUS) {
            $role = $this->roles->resolveDefault($request);
            return $role->canCreateSupplier()
                ? $handler->handle($this->withEffectiveRole($request, $role))
                : $this->forbidden($request);
        }

        if ($route->kind !== RoutePermissionMap::SUPERADMIN) {
            $access = $this->supplierAccess->resolve($request);
            if ($access->denied) {
                return Json::error($this->responseFactory->createResponse(403), 'forbidden_supplier', 'K této firmě nemáš oprávnění.', 403);
            }
        }

        $role = $this->roles->resolve($request);
        if ($route->kind === RoutePermissionMap::SUPERADMIN) {
            return $role->isSuperadmin() ? $handler->handle($this->withEffectiveRole($request, $role)) : $this->forbidden($request);
        }
        // Interní role ruší párování původním bank WRITE; klient má úzkou
        // samostatnou akci a bank WRITE mu nesmí otevřít další interní operace.
        $permission = $role->isClientType() && $method === 'POST'
            && preg_match('#^/api/bank-transactions/[0-9]+/unmatch$#D', $path) === 1
            ? 'bank.match' : $route->key;
        if ($permission !== null && array_any([$permission, ...$route->alternativeKeys],
            fn (string $key): bool => $this->checker->allows($role, $key, $route->minimum))) {
            if ($role->isClientType() && (!ClientBankRoutePolicy::allows($method, $path, $route->key)
                || !ClientStockRoutePolicy::allows($method, $path, $route->key)
                || !ClientTaxSubmissionPolicy::allowsRoute($method, $path)
                || (preg_match('#^/api/reports/submissions(?:/|$)#D', $path) === 1
                    && !RequestAuthorization::isSessionAuth($request)))) {
                return $this->forbidden($request);
            }
            // Agendy NNO/504 a SVJ: oprávnění nestačí, firma musí být tou organizací.
            $scope = FrameworkScopePolicy::scopeForPath($path);
            if ($scope !== null && $this->frameworkScope !== null
                && !$this->frameworkScope->allows($access->supplierId, $scope)) {
                return Json::error($this->responseFactory->createResponse(403), 'framework_scope_denied',
                    $scope === FrameworkScopePolicy::SVJ
                        ? 'Agenda SVJ je jen pro společenství vlastníků jednotek.'
                        : 'Agenda je jen pro nevýdělečné organizace a firmy účtující podle vyhlášky 504/2002 Sb.',
                    403, ['framework_scope' => $scope]);
            }
            return $handler->handle($this->withEffectiveRole($request, $role));
        }
        if ($this->isApproverPdf($method, $path, $role, $access->supplierId, (int) $user['id'])) {
            return $handler->handle($this->withEffectiveRole($request, $role));
        }
        return $this->forbidden($request);
    }

    /**
     * Schvalovatel přijatého dokladu (F6) potřebuje vidět jeho PDF, i když roli
     * s modulem Přijaté faktury nemá (stačí mu `purchase_invoices.approve`). Výjimka
     * je úzká: jen GET PDF a jen dokladu, na kterém je v aktuální firmě schvalovatelem.
     */
    private function isApproverPdf(string $method, string $path, \MyInvoice\Security\EffectiveRole $role, int $supplierId, int $userId): bool
    {
        if ($this->purchaseApprovals === null || !in_array($method, ['GET', 'HEAD'], true)
            || preg_match('#^/api/purchase-invoices/([0-9]+)/pdf$#D', $path, $m) !== 1
            || !$this->checker->allows($role, 'purchase_invoices.approve', \MyInvoice\Security\AccessLevel::READ)
        ) {
            return false;
        }
        return $supplierId > 0 && $this->purchaseApprovals->isApproverOf($supplierId, $userId, (int) $m[1]);
    }

    private function withEffectiveRole(Request $request, \MyInvoice\Security\EffectiveRole $role): Request
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $user['effective_role'] = $role;
        $user['is_superadmin'] = $role->isSuperadmin();
        return $request
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withAttribute('auth.effective_role', $role);
    }

    private function forbidden(Request $request): Response
    {
        return Json::error(
            $this->responseFactory->createResponse(403),
            'forbidden_permission',
            'Pro tuto akci nemáš oprávnění.',
            403,
        );
    }
}
