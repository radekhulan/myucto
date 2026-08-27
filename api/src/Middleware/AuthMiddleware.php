<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\I18n\Locale;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\ApiRequestLogger;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Načte session z cookie, pokud existuje, a vloží usera do request attributes.
 * Pokud route má atribut `requires_auth=true` a session není, vrátí 401.
 *
 * Public routes (login, forgot, reset, setup, health, csrf-token) jsou whitelisted.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const ATTR_USER       = 'auth.user';
    public const ATTR_SESSION    = 'auth.session';
    public const ATTR_TOKEN      = 'auth.token';
    public const ATTR_API_TOKEN  = 'auth.api_token';
    public const ATTR_METHOD     = 'auth.method'; // 'session' | 'bearer'
    public const ATTR_LOGOUT_TOMBSTONE = 'auth.logout_tombstone';

    private const PUBLIC_PATHS = [
        '/api/health',
        '/api/version',
        '/api/openapi.yaml',
        '/api/docs',
        '/api/reference',
        '/api/scalar',
        '/api/auth/setup-status',
        '/api/auth/setup-preflight',
        '/api/auth/setup',
        '/api/auth/setup-ares-lookup',
        '/api/auth/setup-crpdph-lookup',
        '/api/auth/setup-sample',
        '/api/auth/login',
        '/api/auth/webauthn/login/options',
        '/api/auth/webauthn/login/verify',
        '/api/auth/domain-context',
        '/api/auth/domain-login/start',
        '/api/auth/domain-login/exchange',
        '/api/auth/forgot',
        '/api/auth/reset',
        // ⚠️ Doručení licence do spravované instalace volá licenční server,
        // ne člověk — session nemá čím získat. Autentizace je Ed25519 podpis
        // obálky ověřený zabudovaným veřejným klíčem (ManagedLicenseAction);
        // bez podpisu endpoint neudělá nic.
        //
        // ⚠️ Seznamy jsou DVA: tenhle (bez session) a RoutePermissionMap
        // (bez oprávnění). Zapsat routu jen do jednoho znamená 401 ještě
        // před akcí — přesně tak endpoint po vydání 5.28.2 mlčel.
        '/api/managed/license',
    ];

    /**
     * Zahájení nového přihlášení nesmí zdědit identitu ze staré browserové
     * session. Jinak legacy session při povinném MFA zastaví request dřív,
     * než ji může úspěšné přihlášení nahradit strong session.
     *
     * @var array<string,list<string>>
     */
    private const SESSION_IGNORED_PATHS = [
        'GET' => [
            '/api/auth/setup-status',
        ],
        'POST' => [
            '/api/auth/login',
            '/api/auth/webauthn/login/options',
            '/api/auth/webauthn/login/verify',
            '/api/auth/domain-login/start',
            '/api/auth/domain-login/exchange',
        ],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly SessionManager $sessions,
        private readonly Connection $db,
        private readonly ResponseFactory $responseFactory,
        private readonly ApiTokenService $apiTokens,
        private readonly IpMatcher $ipMatcher,
        private readonly UserRoleProfile $roleProfile,
        private readonly ApiRequestLogger $apiRequestLog,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Resolve locale per-request: user.locale > Accept-Language > default
        Locale::set(self::detectLocale($request->getHeaderLine('Accept-Language')));

        // 1) Bearer (API token) — pokud je hlavička, použij ji a session ignoruj.
        $authHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $plaintext = substr($authHeader, 7);
            $tokenRow = $this->apiTokens->validate($plaintext);
            if ($tokenRow === null) {
                // Neplatný token se do `api_request_log` schválně NEZAPISUJE: volání je
                // plně pod kontrolou útočníka, takže by šlo tabulku libovolně nafouknout.
                // Stopu po takových pokusech nese aplikační log a rate limiter.
                $response = $this->responseFactory->createResponse(401);
                return Json::error($response, 'invalid_token', 'Neplatný nebo expirovaný API token.', 401);
            }

            $ip = $this->ipMatcher->clientIp(
                $request->getServerParams(),
                (array) $this->config->get('ip_allowlist.trusted_proxies', []),
                (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For'),
            );

            // Volitelný IP allowlist tokenu. PRÁZDNÝ SEZNAM = bez omezení — jinak by
            // se zamkly všechny tokeny vydané před zavedením téhle funkce.
            $ipRules = $this->apiTokens->ipRulesFor((int) $tokenRow['id']);
            if ($ipRules !== [] && !$this->ipMatcher->matches($ip, $ipRules)) {
                // Tohle naopak logujeme — token je známý, takže objem je omezený jeho
                // rate limitem, a majitel potřebuje vidět, že mu někdo volá odjinud.
                $this->apiRequestLog->log([
                    'token_id'       => (int) $tokenRow['id'],
                    'user_id'        => (int) $tokenRow['user_id'],
                    'supplier_id'    => $tokenRow['supplier_id'],
                    'ip'             => $ip,
                    'method'         => $request->getMethod(),
                    // Schválně SYROVÁ cesta — do logu patří to, co klient reálně poslal.
                    'route'          => $request->getUri()->getPath(),
                    'query'          => $request->getUri()->getQuery(),
                    'status'         => 403,
                    'scope_used'     => (string) ($tokenRow['scope'] ?? ''),
                    'client'         => $request->getHeaderLine('X-MyUcto-Client'),
                    'client_version' => $request->getHeaderLine('X-MyUcto-Client-Version'),
                    'tool'           => $request->getHeaderLine('X-MyUcto-Tool'),
                    'error_code'     => 'token_ip_forbidden',
                ]);
                // …a ještě do aplikačního logu. `api_request_log` je provozní přehled
                // volání tokenu, kdežto tohle je bezpečnostní událost: majitel
                // instalace ji hledá v log/app.log vedle ostatních odmítnutých
                // přístupů, ne v přehledu API. Bez uvedené IP je hláška k ničemu —
                // právě ta adresa se buď povoluje, nebo prošetřuje.
                $this->logger?->warning('API token odmítnut: IP adresa není v allowlistu tokenu.', [
                    'ip'       => $ip,
                    'token_id' => (int) $tokenRow['id'],
                    'user_id'  => (int) $tokenRow['user_id'],
                    'method'   => $request->getMethod(),
                    'route'    => $request->getUri()->getPath(),
                ]);
                $response = $this->responseFactory->createResponse(403);
                return Json::error(
                    $response,
                    'token_ip_forbidden',
                    // IP je v hlášce schválně: volající je držitel platného tokenu
                    // a svou odchozí adresu za NATem/proxy jinak nezjistí, takže
                    // bez ní neví, co si má do allowlistu doplnit.
                    'API token není povolen z této IP adresy (' . $ip . ').',
                    403,
                );
            }

            $user = [
                'id'           => $tokenRow['user_id'],
                'email'        => $tokenRow['user_email'],
                'name'         => $tokenRow['user_name'],
                'role_id'      => $tokenRow['user_role_id'],
                'role_summary' => [
                    'id'         => $tokenRow['user_role_id'],
                    'name'       => $tokenRow['user_role_name'],
                    'type'       => $tokenRow['user_role_type'],
                    'is_active'  => $tokenRow['user_role_active'],
                    'system_key' => $tokenRow['user_role_system_key'],
                ],
                'is_superadmin' => $tokenRow['user_role_system_key'] === 'superadmin',
                'locale'       => $tokenRow['user_locale'],
                'is_active'    => true,
                'totp_enabled' => $tokenRow['user_totp_enabled'],
            ];
            Locale::set((string) ($user['locale'] ?? 'cs'));

            $this->apiTokens->touch($tokenRow['id'], $ip);

            $request = $request
                ->withAttribute(self::ATTR_USER, $user)
                ->withAttribute(self::ATTR_API_TOKEN, $tokenRow)
                ->withAttribute(self::ATTR_METHOD, 'bearer');

            return $handler->handle($request);
        }

        // 2) Session cookie (browser SPA)
        $method     = strtoupper($request->getMethod());
        // Normalizovaná cesta (viz RequestPath) — SESSION_IGNORED_PATHS i detekce
        // logoutu musí sedět na to, co router doručí.
        $path       = RequestPath::normalize($request->getUri()->getPath());
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $cookies    = $request->getCookieParams();
        $token      = self::isAllowed($method, $path, self::SESSION_IGNORED_PATHS)
            ? ''
            : (string) ($cookies[$cookieName] ?? '');
        $isLogout   = $method === 'POST' && $path === '/api/auth/logout';

        $authentication = $token !== ''
            ? $this->sessions->loadAuthenticationContext($token)
            : null;
        $session = $authentication['session'] ?? null;
        $user = $authentication['user'] ?? null;
        $logoutTombstone = false;
        if ($session === null && $token !== '' && $isLogout) {
            $session = $this->sessions->loadTombstoneForLogout($token);
            $logoutTombstone = $session !== null;
            $user = $logoutTombstone
                ? $this->loadUser((int) $session['user_id'])
                : null;
        }

        if ($session !== null) {
            if (is_array($user) && $user['is_active'] === true) {
                // SessionManager vrací účet v upstream tvaru (legacy `users.role`).
                // PermissionMiddleware rozhoduje podle `role_id` a tabulky `roles`.
                $user = $this->roleProfile->enrich($user);
                Locale::set((string) ($user['locale'] ?? 'cs'));
                $request = $request
                    ->withAttribute(self::ATTR_USER, $user)
                    ->withAttribute(self::ATTR_SESSION, $session)
                    ->withAttribute(self::ATTR_TOKEN, $token)
                    ->withAttribute(self::ATTR_METHOD, 'session');
                if ($logoutTombstone) {
                    $request = $request->withAttribute(self::ATTR_LOGOUT_TOMBSTONE, true);
                }

                if (!$logoutTombstone) {
                    $this->sessions->touchIfStale(
                        $token,
                        (int) ($session['last_seen'] ?? 0),
                        (int) ($session['evaluated_at_epoch'] ?? 0),
                    );
                }
            } else {
                // User smazán/deaktivován — invaliduj session
                $this->sessions->destroy($token);
                $session = null;
            }
        }

        if (in_array($path, self::PUBLIC_PATHS, true)
            || str_starts_with($path, '/api/public/')
        ) {
            return $handler->handle($request);
        }

        if ($request->getAttribute(self::ATTR_USER) === null) {
            $response = $this->responseFactory->createResponse(401);
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        return $handler->handle($request);
    }

    /**
     * Tombstone se načítá jen při opakovaném logoutu. Běžná autorizace získá
     * uživatele společně se session v SessionManageru.
     *
     * @return array<string,mixed>|null
     */
    private function loadUser(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, email, name, role, locale, is_active, totp_enabled,
                    session_lock_after_minutes
               FROM users
              WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($user === false) {
            return null;
        }

        $user['id'] = (int) $user['id'];
        $user['is_active'] = (int) $user['is_active'] === 1;
        $user['totp_enabled'] = (int) ($user['totp_enabled'] ?? 0) === 1;
        $user['session_lock_after_minutes'] = $user['session_lock_after_minutes'] !== null
            ? (int) $user['session_lock_after_minutes']
            : null;
        return $user;
    }

    /**
     * RFC 7231 Accept-Language parser — vybere nejvíc preferovaný jazyk z whitelist (cs, en).
     * Příklad: "cs-CZ,cs;q=0.9,en;q=0.8" → 'cs'.
     * Při shodě q=1 vyhrává cs (default tržního prostoru).
     */
    private static function detectLocale(string $acceptLanguage): string
    {
        $best = ['lang' => 'cs', 'q' => 0.0];
        $hasCs = false;
        foreach (explode(',', strtolower($acceptLanguage)) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $q = 1.0;
            if (str_contains($part, ';')) {
                [$tag, $params] = array_pad(explode(';', $part, 2), 2, '');
                $tag = trim($tag);
                if (preg_match('/q\s*=\s*([0-9.]+)/', $params, $m)) {
                    $q = (float) $m[1];
                }
            } else {
                $tag = $part;
            }
            $primary = strtolower(explode('-', $tag, 2)[0]);
            if (!in_array($primary, ['cs', 'en'], true)) continue;
            if ($primary === 'cs') $hasCs = true;
            if ($q > $best['q']) $best = ['lang' => $primary, 'q' => $q];
        }
        // Tie-break: pokud má klient cs i en se stejnou q, preferuj cs
        if ($hasCs && $best['lang'] === 'en' && $best['q'] === 1.0) {
            return 'cs';
        }
        return $best['lang'];
    }

    /**
     * @param array<string,list<string>> $allowlist
     */
    private static function isAllowed(string $method, string $path, array $allowlist): bool
    {
        return in_array($path, $allowlist[$method] ?? [], true);
    }
}
