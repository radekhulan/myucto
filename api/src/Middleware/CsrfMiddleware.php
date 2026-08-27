<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Tenant\TenantDomainContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Pro mutating requesty (POST/PUT/PATCH/DELETE) ověří:
 *   1) Origin header se shoduje s přesným originem bezpečně rozpoznané domény
 *   2) X-CSRF-Token sedí s session.csrf_token
 *
 * Whitelist endpointů bez CSRF (login, forgot, reset, setup) — ty mají
 * rate-limit + CAPTCHA jako náhradu.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const CSRF_EXEMPT_PATHS = [
        '/api/auth/setup',
        '/api/auth/setup-ares-lookup',
        '/api/auth/setup-crpdph-lookup',
        // setup-sample je auth + má CSRF token (po auto-loginu)
        '/api/auth/login',
        '/api/auth/webauthn/login/options',
        '/api/auth/webauthn/login/verify',
        '/api/auth/domain-login/start',
        '/api/auth/domain-login/exchange',
        '/api/auth/forgot',
        '/api/auth/reset',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ResponseFactory $responseFactory,
        private readonly FirstRunLockMiddleware $firstRunLock,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        // Bearer (API token) auth nepoužívá cookies → CSRF nehrozí, skip.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return $handler->handle($request);
        }

        // Normalizovaná cesta (viz RequestPath) — výjimky musí platit přesně tam,
        // kam router request doručí, ani o cestu víc.
        $path = RequestPath::normalize($request->getUri()->getPath());

        // Public schvalovací endpointy — bez Origin/CSRF (klient přijde z emailu, Origin
        // bude jiný nebo prázdný). Anti-bot ochrana = token v URL + CAPTCHA.
        if (str_starts_with($path, '/api/public/')) {
            return $handler->handle($request);
        }

        // First-run setup: během prvotního setupu (žádný admin) povolíme setup endpointům
        // přijít z libovolného hostu — uživatel ještě nemá šanci nastavit app.url a Docker
        // default `http://localhost:8080` zablokuje přístup z LAN IP. Po vytvoření admina
        // se Origin check znovu zapne. Setup endpointy mají vlastní first-run guard
        // (vrací setup_done/setup_already_done), takže není defense-in-depth riziko.
        if ($this->firstRunLock->needsSetup() && str_starts_with($path, '/api/auth/setup')) {
            return $handler->handle($request);
        }

        // ⚠️ Doručení licence od provozovatele. Volá se SERVER-TO-SERVER, takže
        // Origin ani Referer nemá odkud vzít — a nemá je ani mít: kontrola
        // originu brání cizímu WEBU, aby požadavek vyvolal z prohlížeče
        // uživatele, což u volání bez prohlížeče nedává smysl. Pravost tu stojí
        // na Ed25519 podpisu obálky ověřeném zabudovaným veřejným klíčem
        // (ManagedLicenseAction) a na adresátovi `instance_id` uvnitř ní.
        //
        // Bez téhle výjimky odpoví instalace 403 `origin_mismatch` a licenci
        // nepřevezme — zaplacená instalace pak běží na zkušební období.
        if ($path === '/api/managed/license') {
            return $handler->handle($request);
        }

        // Origin / Referer check (i pro exempt routes)
        $domain = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        $appUrl = $domain instanceof TenantDomainContext
            ? rtrim($domain->origin, '/')
            : rtrim((string) $this->config->get('app.url', ''), '/');
        $expectedOrigin = $this->originOf($appUrl);
        $origin = $request->getHeaderLine('Origin');
        $referer = $request->getHeaderLine('Referer');

        if ($expectedOrigin !== '') {
            $valid = false;
            if ($origin !== '' && $this->originOf($origin) === $expectedOrigin) {
                $valid = true;
            } elseif ($referer !== '' && $this->originOf($referer) === $expectedOrigin) {
                $valid = true;
            }
            // V dev povolíme jen pokud Origin/Referer ukazuje na localhost (cross-port localhost:5173 → :8800).
            // Žádný blanket bypass — chrání proti náhodnému deploy s env=development.
            if (!$valid) {
                $env = (string) $this->config->get('app.env', 'production');
                if ($env === 'development') {
                    $host = parse_url($origin !== '' ? $origin : $referer, PHP_URL_HOST) ?: '';
                    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]') {
                        $valid = true;
                    }
                }
            }

            if (!$valid) {
                $response = $this->responseFactory->createResponse(403);
                return Json::error($response, 'origin_mismatch', 'Origin nesedí s app URL.', 403);
            }
        }

        if (in_array($path, self::CSRF_EXEMPT_PATHS, true)) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        $expectedToken = is_array($session) ? (string) ($session['csrf_token'] ?? '') : '';
        $providedToken = $request->getHeaderLine('X-CSRF-Token');

        if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error($response, 'csrf_failed', 'Neplatný nebo chybějící CSRF token.', 403);
        }

        return $handler->handle($request);
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return '';
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) $port = null;
        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }
}
