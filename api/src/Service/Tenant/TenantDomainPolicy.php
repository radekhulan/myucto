<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

/** Sdílené rozhodnutí pro Slim requesty i serverový SPA fallback. */
final class TenantDomainPolicy
{
    public function __construct(
        private readonly ClientRoutePolicy $clientRoutes = new ClientRoutePolicy(),
    ) {}

    /** @return array{status:int,code:string,message:string}|null */
    public function denial(TenantDomainContext $context, string $method, string $path): ?array
    {
        $method = strtoupper($method);
        if ($context->mode === TenantDomainContext::CONFIGURATION_ERROR) {
            return [
                'status' => 421,
                'code' => 'canonical_hostname_conflict',
                'message' => 'Canonical hostname koliduje s vlastní doménou firmy.',
            ];
        }

        if ($context->mode === TenantDomainContext::UNKNOWN) {
            return [
                'status' => 421,
                'code' => 'unknown_host',
                'message' => 'Tento hostname není pro aplikaci nakonfigurovaný.',
            ];
        }

        if ($context->mode === TenantDomainContext::VERIFICATION
            && (!in_array($method, ['GET', 'HEAD'], true)
                || preg_match('#^/api/public/domain-verification/[a-f0-9]{64}$#D', $path) !== 1)
        ) {
            return [
                'status' => 421,
                'code' => 'domain_not_active',
                'message' => 'Vlastní doména ještě není aktivní.',
            ];
        }

        if ($context->mode !== TenantDomainContext::CUSTOM) {
            return null;
        }

        $isPublic = str_starts_with($path, '/api/public/')
            || preg_match('#^/(?:invoice|approval|work-report)/#', $path) === 1;
        if ($isPublic) {
            return $context->allowsPublicLinks()
                ? null
                : ['status' => 404, 'code' => 'not_found', 'message' => 'Odkaz nebyl nalezen.'];
        }
        if ($this->isPublicAssetOrContext($path)) {
            return null;
        }
        if (!$context->allowsPortal()) {
            return [
                'status' => 404,
                'code' => 'portal_not_enabled',
                'message' => 'Na této doméně není klientské rozhraní aktivní.',
            ];
        }

        if (($path === '/mcp' && in_array($method, ['GET', 'POST', 'DELETE', 'OPTIONS'], true))
            || (in_array($path, ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'], true)
                && in_array($method, ['GET', 'HEAD'], true))) return null;

        $allowed = str_starts_with($path, '/api/')
            ? $this->clientRoutes->allowsApiRequest($method, $path)
            : ($this->clientRoutes->allowsAuthenticatedPath($path)
                || $this->clientRoutes->allowsFlowPath($path));
        if (!$allowed) {
            return [
                'status' => 404,
                'code' => 'client_surface_only',
                'message' => 'Tato cesta není součástí klientského rozhraní.',
            ];
        }

        return null;
    }

    private function isPublicAssetOrContext(string $path): bool
    {
        return $path === '/api/auth/domain-context'
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/fonts/')
            || str_starts_with($path, '/pwa/')
            || str_starts_with($path, '/styles/')
            || in_array($path, [
                '/favicon.ico',
                '/manifest.webmanifest',
                '/service-worker.js',
                '/sw.js',
            ], true);
    }
}
