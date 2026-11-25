<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\MyeetCustomerProvisioner;
use MyInvoice\Infrastructure\Cache\SupplierWriteGenerations;
use MyInvoice\Http\RequestPath;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use MyInvoice\Security\InstanceAdminRoutePolicy;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Multi-supplier scope: čte hlavičku `X-Supplier-Id` (z Pinia stores na FE) a
 * vystaví ji jako request attribute. Akce čtou přes:
 *
 *   $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
 *
 * Pravidla (resoluci sdílí SupplierAccessResolver s PermissionResolverem):
 *   - PAT bound na supplier_id → forcuj ho, header/query se ignoruje
 *   - Pokud header chybí nebo není v DB, fallback = MIN(supplier.id), resp.
 *     nejnižší PŘIŘAZENÝ supplier u uživatele s membership (user_suppliers)
 *   - Epic F0: uživatel s neprázdným membership, který si explicitně vyžádá
 *     firmu mimo své membership → 403 (dřív směl kamkoliv — díra F0)
 *   - Non-superadmin bez membership řádků nemá přístup k žádné firmě
 *   - Pokud supplier tabulka prázdná (před setup) → 0 (akce by stejně měly být chráněné Authem)
 *   - Validace se memoizuje v rámci requestu (resolver)
 */
final class SupplierScopeMiddleware implements MiddlewareInterface
{
    public const ATTR_CURRENT_ID = 'supplier.current_id';
    public const ATTR_MYEET_READONLY_SUPPLIER_ID = 'myeet.readonly_supplier_id';
    public const HEADER_NAME     = 'X-Supplier-Id';

    public function __construct(
        private readonly SupplierAccessResolver $resolver,
        private readonly ResponseFactory $responseFactory,
        private readonly ?Config $config = null,
        private readonly ?MyeetCustomerProvisioner $myeet = null,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Ceremonie WebAuthn, step-up MFA a zámek session se dějí mimo kontext firmy —
        // scope by je zablokoval u účtu bez membershipu, který se ale musí umět ověřit.
        // Normalizovaná cesta (viz RequestPath) — výjimky musí platit přesně tam,
        // kam router request doručí.
        $path = RequestPath::normalize($request->getUri()->getPath());
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (InstanceAdminRoutePolicy::isTechnicalUser($user)) {
            if (!RequestAuthorization::isSessionAuth($request)
                || !InstanceAdminRoutePolicy::allows($request->getMethod(), $path)) {
                return Json::error($this->responseFactory->createResponse(403),
                    'forbidden_permission', 'Pro tuto akci nemáte oprávnění.', 403);
            }
            return $handler->handle($request->withAttribute(self::ATTR_CURRENT_ID, 0));
        }
        if (InstanceAdminRoutePolicy::isTechnicalEndpoint($request->getMethod(), $path)) {
            // Technické rozhraní autorizuje vlastní roli; běžné členství zde nemá význam.
            return $handler->handle($request->withAttribute(self::ATTR_CURRENT_ID, 0));
        }
        if (str_starts_with($path, '/api/auth/webauthn/')
            || str_starts_with($path, '/api/auth/mfa/')
            || str_starts_with($path, '/api/auth/session/')
            || str_starts_with($path, '/api/auth/domain-login/')
            // ⚠️ Doručení licence od provozovatele. Volá se server-to-server,
            // BEZ přihlášeného uživatele — a tedy i bez členství ve firmě, ze
            // kterého se scope počítá. Bez téhle výjimky odpoví instalace
            // `forbidden_supplier` a licenci nepřevezme; zaplacená instalace
            // pak běží na zkušební období. Pravost obálky si hlídá sama akce
            // (podpis provozovatele + adresát `instance_id`), scope firmy tu
            // nemá co chránit — licence není doklad žádné firmy.
            || $path === '/api/managed/license'
        ) {
            return $handler->handle($request);
        }
        if ($request->getMethod() === 'POST' && $path === '/api/suppliers') {
            return $handler->handle($request->withAttribute(self::ATTR_CURRENT_ID, 0));
        }

        $access = $this->resolver->resolve($request);

        if ($access->denied) {
            // Self-service cesty (me/logout/change-password/totp…) nesmí denied
            // membership zablokovat — klient bez membershipu (Epic F6, fail-closed)
            // musí umět načíst vlastní účet a odhlásit se. Scope se nepropíše (0).
            if (in_array($path, PermissionMiddleware::PUBLIC_OR_SELF, true)
                || preg_match('#^/api/mcp/grants/[0-9]+$#', $path) === 1
                || str_starts_with($path, '/api/public/')
            ) {
                return $handler->handle($request->withAttribute(self::ATTR_CURRENT_ID, 0));
            }
            $response = $this->responseFactory->createResponse(403);
            return Json::error($response, 'forbidden_supplier', 'K této firmě nemáte oprávnění.', 403);
        }

        $selfService = in_array($path, PermissionMiddleware::PUBLIC_OR_SELF, true);
        if ($access->supplierId > 0 && (int) ($user['id'] ?? 0) > 0
            && !($user['is_superadmin'] ?? false)
            && $this->config?->get('myeet.sale_managed', false) === true
            && $this->config->get('app.url') === 'https://eet.myucto.online'
            && $request->getUri()->getHost() === 'eet.myucto.online'
            && (!$selfService || $path === '/api/auth/me' || $path === '/api/auth/api-me')
            && !str_starts_with($path, '/api/public/')) {
            $license = $this->myeet?->licenseAccess((int) $user['id'], $access->supplierId);
            $now = time();
            if ($license !== null && $license['valid_until'] <= $now) {
                if ($license['readonly_until'] > $now) {
                    $request = $request->withAttribute(self::ATTR_MYEET_READONLY_SUPPLIER_ID, $access->supplierId);
                    if (!$selfService && !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                        return Json::error($this->responseFactory->createResponse(402),
                            'myeet_license_readonly', 'Firma je dočasně dostupná jen pro čtení a export.', 402,
                            ['readonly_until' => $license['readonly_until']]);
                    }
                } elseif (!$selfService) {
                    return Json::error($this->responseFactory->createResponse(402),
                        'myeet_license_expired', 'Platnost licence MyEET pro tuto firmu skončila.', 402);
                }
            }
        }

        SupplierWriteGenerations::setRequestSupplier($access->supplierId);

        return $handler->handle(
            $request->withAttribute(self::ATTR_CURRENT_ID, $access->supplierId),
        );
    }
}
