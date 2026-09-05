<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Security\PermissionChecker;
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
    ];

    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly RoutePermissionMap $routes,
        private readonly PermissionResolver $roles,
        private readonly PermissionChecker $checker,
        private readonly SupplierAccessResolver $supplierAccess,
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
        if ($route->key !== null && $this->checker->allows($role, $route->key, $route->minimum)) {
            return $handler->handle($this->withEffectiveRole($request, $role));
        }
        return $this->forbidden($request);
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
