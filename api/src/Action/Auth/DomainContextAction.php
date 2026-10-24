<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DomainContextAction
{
    public function __construct(
        private readonly TenantDomainResolver $resolver,
        private readonly TenantDomainFeature $feature,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);
        if (\MyInvoice\Security\InstanceAdminRoutePolicy::isTechnicalUser($user)) {
            $origin = $this->resolver->canonicalOrigin();
            return Json::ok($response, [
                'mode' => TenantDomainContext::CANONICAL,
                'hostname' => (string) parse_url($origin, PHP_URL_HOST), 'origin' => $origin,
                'locked' => false, 'supplier_id' => null, 'purpose' => null,
                'feature_enabled' => false, 'canonical_base_url' => $origin,
                'canonical_login_url' => $origin . '/login',
            ]);
        }
        $context = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        if (!$context instanceof TenantDomainContext) {
            $context = $this->resolver->resolve($request);
        }
        $data = $context->toArray();
        // Frontend podle tohohle příznaku schová správu domén v Nastavení.
        // Autoritou zůstává backend — akce vrací 404 i bez spolupráce UI.
        $data['feature_enabled'] = $this->feature->isEnabled();
        $data['canonical_base_url'] = $this->resolver->canonicalOrigin();
        $data['canonical_login_url'] = $data['canonical_base_url'] . '/login';
        return Json::ok($response, $data);
    }
}
