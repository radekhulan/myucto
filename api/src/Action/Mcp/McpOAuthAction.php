<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use MyInvoice\Service\Mcp\McpOAuth;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class McpOAuthAction
{
    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly McpOAuth $oauth,
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $suppliers,
        private readonly PermissionResolver $roles,
        private readonly PermissionChecker $permissions,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly BruteForceGuard $bruteForce,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly MfaStepUpService $stepUp,
        private readonly \MyInvoice\Service\Mcp\McpResourcePolicy $resources,
    ) {}

    public function protectedResource(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        return Json::ok($response, [
            'resource' => $this->resources->endpoint($request),
            'authorization_servers' => [$this->base()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['read', 'read_write', 'offline_access'],
            'resource_name' => 'MyÚčto MCP',
        ]);
    }

    public function serverMetadata(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        return Json::ok($response, [
            'issuer' => $this->base(),
            'authorization_endpoint' => $this->base() . '/oauth/authorize',
            'token_endpoint' => $this->base() . '/oauth/token',
            'registration_endpoint' => $this->base() . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['read', 'read_write', 'offline_access'],
        ]);
    }

    public function register(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        if ($request->getBody()->getSize() > 16384) return $this->oauthError($response, 'invalid_client_metadata', 413);
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) return $this->oauthError($response, 'invalid_client_metadata', 400);
        if (isset($body['client_name']) && !is_string($body['client_name'])) {
            return $this->oauthError($response, 'invalid_client_metadata', 400);
        }
        $name = trim($body['client_name'] ?? 'MCP klient');
        $redirects = $body['redirect_uris'] ?? null;
        if ($name === '' || mb_strlen($name) > 100 || !is_array($redirects)
            || array_is_list($redirects) === false || count($redirects) < 1 || count($redirects) > 10
            || ($body['token_endpoint_auth_method'] ?? 'none') !== 'none'
        ) {
            return $this->oauthError($response, 'invalid_client_metadata', 400);
        }
        foreach ($redirects as $uri) {
            if (!is_string($uri) || !$this->validRedirect($uri)) {
                return $this->oauthError($response, 'invalid_redirect_uri', 400);
            }
        }
        $redirects = array_values(array_unique($redirects));
        $id = $this->oauth->register($name, $redirects);
        return Json::ok($response, [
            'client_id' => $id,
            'client_name' => $name,
            'redirect_uris' => $redirects,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_id_issued_at' => time(),
        ], 201);
    }

    public function authorize(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        if ($request->getMethod() === 'POST' && $request->getBody()->getSize() > 8192) {
            return $this->html($response, '<h1>Neplatný požadavek na připojení MCP.</h1>', 413);
        }
        $params = $request->getMethod() === 'POST'
            ? (array) $request->getParsedBody()
            : $request->getQueryParams();
        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge',
            'code_challenge_method', 'state', 'resource', 'scope', 'supplier_id', 'decision',
            'password', 'totp_code', 'step_up_token', 'grant_scope'] as $key) {
            if (isset($params[$key]) && !is_string($params[$key]) && !is_int($params[$key])) {
                return $this->html($response, '<h1>Neplatný požadavek na připojení MCP.</h1>', 400);
            }
        }
        $clientId = (string) ($params['client_id'] ?? '');
        $client = $this->oauth->client($clientId);
        $redirect = (string) ($params['redirect_uri'] ?? '');
        $challenge = (string) ($params['code_challenge'] ?? '');
        $state = (string) ($params['state'] ?? '');
        $resource = (string) ($params['resource'] ?? $this->hosted->endpoint());
        $scope = $this->scope((string) ($params['scope'] ?? 'read'));
        $target = $this->resources->resolve($resource);
        if ($client === null
            || !in_array($redirect, $client['redirect_uris'], true)
            || ($params['response_type'] ?? '') !== 'code'
            || ($params['code_challenge_method'] ?? '') !== 'S256'
            || preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $challenge) !== 1
            || strlen($state) > 512 || $target === null
            || $scope === null
        ) {
            return $this->html($response, '<h1>Neplatný požadavek na připojení MCP.</h1>', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($user['id'] ?? 0) < 1) {
            $target = '/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $location = '/login?return_to=' . rawurlencode('/oauth/continue?target=' . rawurlencode($target));
            return $response->withStatus(302)->withHeader('Location', $location)->withHeader('Cache-Control', 'no-store');
        }
        if (!RequestAuthorization::isSessionAuth($request)) {
            return $this->html($response, '<h1>Připojení vyžaduje přihlášení v prohlížeči.</h1>', 401);
        }

        if ($request->getMethod() === 'POST' && isset($params['supplier_id'])) {
            return $this->html($response, '<h1>Obnovte stránku pro nové připojení MCP.</h1>', 409);
        }
        $choices = [];
        $accessibleCount = 0;
        $canGrantWrite = false;
        $available = ($user['is_superadmin'] ?? false) === true
            ? $this->db->pdo()->query('SELECT id AS supplier_id, COALESCE(NULLIF(display_name, \'\'), company_name) AS name FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC)
            : $this->memberships->listForUser((int) $user['id']);
        foreach ($available as $choice) {
            $candidateId = (int) $choice['supplier_id'];
            if ($target['supplier_id'] !== null && $target['supplier_id'] !== $candidateId) continue;
            $candidateRequest = $request->withQueryParams(['supplier_id' => $candidateId]);
            $access = $this->suppliers->resolve($candidateRequest);
            if (!$access->denied && $access->supplierId === $candidateId) {
                $accessibleCount++;
                $role = $this->roles->resolve($candidateRequest);
                if ($target['supplier_id'] !== null
                    ? $this->resources->allowsGrant($resource, (int) $user['id'], $candidateId, 'read')
                    : $this->permissions->allows($role, 'profile.tokens', AccessLevel::READ)) {
                    $choices[] = $choice;
                    $canGrantWrite = $canGrantWrite || ($target['supplier_id'] !== null
                        ? $this->resources->allowsGrant($resource, (int) $user['id'], $candidateId, 'read_write')
                        : $this->permissions->allows($role, 'profile.tokens', AccessLevel::WRITE));
                }
            }
        }
        if ($choices === []) {
            return $this->html($response, '<h1>Nemáte oprávnění k vydání API tokenu pro žádnou firmu.</h1>', 403);
        }
        $identity = $this->db->pdo()->prepare(
            'SELECT totp_secret, totp_enabled FROM users WHERE id = ? AND is_active = 1'
        );
        $identity->execute([(int) $user['id']]);
        $credentials = $identity->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($credentials)) return $this->html($response, '<h1>Účet není aktivní.</h1>', 403);
        $totpRequired = (int) $credentials['totp_enabled'] === 1 && $credentials['totp_secret'] !== null;
        $passkeyAvailable = $this->mfaPolicy->isMethodAllowed('passkey')
            && $this->credentials->countActiveForUser((int) $user['id']) > 0;

        if ($request->getMethod() === 'GET') {
            $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
            $csrf = (string) ($session['csrf_token'] ?? '');
            $host = parse_url($redirect, PHP_URL_HOST) ?: $redirect;
            $fields = '';
            foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge',
                'code_challenge_method', 'state', 'resource', 'scope'] as $key) {
                $value = (string) ($params[$key] ?? ($key === 'resource' ? $resource : ($key === 'scope' ? $scope : '')));
                $fields .= '<input type="hidden" name="' . $key . '" value="' . self::escape($value) . '">';
            }
            $fields .= '<input type="hidden" name="csrf_token" value="' . self::escape($csrf) . '">';
            $grantChoice = $scope === 'read_write' && $canGrantWrite
                ? '<label class="field">Udělit přístup<select name="grant_scope">'
                    . '<option value="read" selected>Pouze čtení</option>'
                    . '<option value="read_write">Čtení a zápis</option>'
                    . '</select></label><p class="field-hint">Výchozí je pouze čtení. I při povolení zápisu platí oprávnění vašeho účtu.</p>'
                : '<input type="hidden" name="grant_scope" value="read">';
            $verification = '';
            if ($passkeyAvailable) {
                $verification .= '<input type="hidden" name="step_up_token" id="mcp-step-up-token">'
                    . '<button type="button" id="mcp-passkey-button" class="passkey-button">Ověřit passkey</button>'
                    . '<span id="mcp-passkey-status" class="field-hint" role="status" aria-live="polite"></span>';
            }
            if ($totpRequired) {
                $verification .= '<label class="field">Aktuální kód ověřovací aplikace'
                    . '<input name="totp_code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"'
                    . ($passkeyAvailable ? '' : ' required') . '></label>'
                    . '<p class="field-hint">Použijte nový šestimístný kód. Kód použitý při přihlášení už nelze použít znovu.</p>';
            } elseif (!$passkeyAvailable) {
                $verification = '<p class="field-hint">Jste přihlášeni. Pro připojení stačí potvrdit přístup.</p>';
            }
            $permission = $scope === 'read_write' ? 'čtení a zápis' : 'pouze čtení';
            $bindingNotice = $target['supplier_id'] !== null
                ? '<p class="muted">Přístup platí pouze pro firmu ' . self::escape((string) ($choices[0]['name'] ?? $choices[0]['supplier_name'] ?? $target['supplier_id']))
                    . ' na adrese ' . self::escape($resource) . '. Další firmy přidělené uživateli se nezpřístupní. Přístup můžete odvolat v klientském portálu v části MCP.</p>'
                : '<p class="muted">Přístup se vztahuje na všechny firmy, ke kterým máte práva, včetně těch přidaných později. Aktuálně jich můžete použít '
                    . $accessibleCount . '. Práva ke každé firmě se kontrolují při každém volání. Přístup můžete kdykoli odvolat na stránce Firma → MCP server.</p>';
            $content = '<h1>Připojit MyÚčto k AI asistentovi</h1>'
                . '<p class="lead">Aplikace <strong>' . self::escape((string) $client['client_name']) . '</strong> ('
                . self::escape((string) $host) . ') žádá o přístup k vašim datům.</p>'
                . '<div class="access-summary"><span>Asistent požaduje nejvýše</span><strong>' . $permission . '</strong></div>'
                . $bindingNotice
                . '<form id="mcp-consent-form" method="post" action="/oauth/authorize">' . $fields . $grantChoice
                . '<div class="verification"><h2>Ověření identity</h2>'
                . ($passkeyAvailable && $totpRequired ? '<p class="field-hint">Zvolte passkey nebo nový kód z ověřovací aplikace.</p>' : '')
                . $verification . '</div>'
                . '<div class="actions"><button type="submit" name="decision" value="approve">Povolit přístup</button>'
                . '<button type="submit" name="decision" value="deny" formnovalidate>Zamítnout</button></div>'
                . '<span id="mcp-form-status" class="field-hint" role="status" aria-live="polite"></span></form>'
                . '<script src="/assets/mcp-consent-v3.js" defer></script>';
            return $this->html($response, $content);
        }

        if (($params['decision'] ?? '') !== 'approve') {
            return $this->finishConsent($response, self::redirectWith($redirect, [
                'error' => 'access_denied', 'state' => $state,
            ]));
        }
        $grantScope = (string) ($params['grant_scope'] ?? 'read');
        if (!in_array($grantScope, ['read', 'read_write'], true)
            || ($scope === 'read' && $grantScope !== 'read')) {
            return $this->html($response, '<h1>Neplatný rozsah přístupu.</h1>', 400);
        }
        if ($grantScope === 'read_write' && !$canGrantWrite) {
            return $this->html($response, '<h1>Pro zápis nemáte oprávnění.</h1>', 403);
        }
        $proof = (string) ($params['step_up_token'] ?? '');
        $totpCode = trim((string) ($params['totp_code'] ?? ''));
        if ($totpRequired && $totpCode !== '') {
            if ($this->bruteForce->isTotpLocked((int) $user['id'])) {
                return $this->consentError($response, $params, 'Příliš mnoho pokusů. Zkuste to později.', 429);
            }
            try {
                $secret = $this->crypto->decrypt((string) $credentials['totp_secret']);
            } catch (\RuntimeException) {
                return $this->html($response, '<h1>Ověření nyní není dostupné.</h1>', 500);
            }
            if (!$this->totp->verifyAndConsume($this->db, $secret, $totpCode)) {
                $this->bruteForce->recordTotpFailure((int) $user['id']);
                return $this->consentError($response, $params, 'Kód je neplatný nebo už byl použit. Zadejte nový kód.', 401);
            }
            $this->bruteForce->recordTotpSuccess((int) $user['id']);
        } elseif ($proof !== '') {
            try {
                $this->stepUp->consume(
                    $proof, (int) $user['id'],
                    (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                    MfaStepUpService::OPERATION_API_TOKEN_CREATE,
                );
            } catch (OneTimeTokenException|StepUpOperationException) {
                return $this->consentError($response, $params, 'Ověření passkey už neplatí. Ověřte ji znovu.', 403);
            }
        } elseif ($totpRequired || $passkeyAvailable) {
            return $this->consentError($response, $params, 'Ověřte passkey nebo zadejte nový kód ověřovací aplikace.', 401);
        }
        $code = $this->oauth->createCode(
            $clientId, (int) $user['id'], $target['supplier_id'], $redirect, $challenge, $grantScope, $resource,
            ((array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []))['auth_method'] ?? null,
        );
        return $this->finishConsent($response, self::redirectWith($redirect, [
            'code' => $code, 'state' => $state,
        ]));
    }

    public function token(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        if ($request->getBody()->getSize() > 8192) return $this->oauthError($response, 'invalid_request', 413);
        $params = (array) $request->getParsedBody();
        foreach (['grant_type', 'client_id', 'code_verifier', 'code', 'redirect_uri', 'resource', 'refresh_token'] as $key) {
            if (isset($params[$key]) && !is_string($params[$key])) {
                return $this->oauthError($response, 'invalid_request', 400);
            }
        }
        $grant = (string) ($params['grant_type'] ?? '');
        $clientId = (string) ($params['client_id'] ?? '');
        if ($this->oauth->client($clientId) === null) {
            return $this->oauthError($response, 'invalid_client', 401);
        }
        if ($grant === 'authorization_code') {
            $verifier = (string) ($params['code_verifier'] ?? '');
            if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
                return $this->oauthError($response, 'invalid_grant', 400);
            }
            try {
                $result = $this->oauth->exchange(
                    (string) ($params['code'] ?? ''), $clientId,
                    (string) ($params['redirect_uri'] ?? ''), $verifier,
                    (string) ($params['resource'] ?? $this->hosted->endpoint()),
                );
            } catch (LicenseSeatLimitExceeded) {
                // Oprávnění se zápisem by podle parametru licence zabralo další místo.
                return $this->oauthError($response, 'access_denied', 403);
            }
        } elseif ($grant === 'refresh_token') {
            $result = $this->oauth->refresh((string) ($params['refresh_token'] ?? ''), $clientId, $params['resource'] ?? null);
        } else {
            return $this->oauthError($response, 'unsupported_grant_type', 400);
        }
        return $result !== null ? Json::ok($response, $result) : $this->oauthError($response, 'invalid_grant', 400);
    }

    private function base(): string
    {
        return substr($this->hosted->endpoint(), 0, -4);
    }

    private function validRedirect(string $uri): bool
    {
        if (strlen($uri) > 2048 || str_contains($uri, '#')) return false;
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) return false;
        if ($parts['scheme'] === 'https') return true;
        return $parts['scheme'] === 'http'
            && in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function scope(string $scope): ?string
    {
        $items = array_values(array_filter(explode(' ', trim($scope))));
        foreach ($items as $item) {
            if (!in_array($item, ['read', 'read_write', 'offline_access'], true)) return null;
        }
        return in_array('read_write', $items, true) ? 'read_write' : 'read';
    }

    private function html(Response $response, string $content, int $status = 200): Response
    {
        $css = <<<'CSS'
            :root{color-scheme:light;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#fbfafd;color:#15131d}
            *{box-sizing:border-box}
            body{margin:0;min-height:100vh;padding:clamp(1.25rem,5vw,4rem) 1rem;display:grid;place-items:start center}
            main{width:min(100%,34rem);margin:auto;background:#fff;border:1px solid #e7e3ee;border-radius:14px;box-shadow:0 20px 55px rgba(33,26,75,.09);padding:clamp(1.5rem,5vw,2.5rem)}
            .brand{display:flex;align-items:center;gap:.75rem;margin-bottom:2rem;color:#3b2d83;font-weight:700;letter-spacing:-.02em}
            .brand-mark{display:grid;place-items:center;width:2.5rem;height:2.5rem;border-radius:10px;background:#3b2d83;color:#fff;font-size:1.35rem}
            .brand small{display:block;color:#7a748c;font-size:.73rem;font-weight:500;letter-spacing:0}
            h1{font-size:clamp(1.55rem,4vw,1.95rem);line-height:1.2;letter-spacing:-.035em;margin:0 0 1rem}
            h2{font-size:1rem;margin:0 0 .75rem}
            p{line-height:1.55;margin:.75rem 0}
            .lead{font-size:.98rem;color:#403b52}
            .muted,.field-hint{font-size:.82rem;color:#5a5470}
            .access-summary{display:flex;justify-content:space-between;gap:1rem;align-items:center;background:#f4f2fb;border:1px solid #c9c0e9;border-radius:10px;padding:.9rem 1rem;margin:1.5rem 0 .75rem}
            .access-summary span{font-size:.82rem;color:#5a5470}
            .access-summary strong{color:#3b2d83;text-align:right}
            .retry-link{display:inline-flex;align-items:center;min-height:2.7rem;margin-top:1rem;padding:.55rem .95rem;border-radius:7px;background:#3b2d83;color:#fff;text-decoration:none;font-weight:600}
            .retry-link:hover{background:#2e2367}
            form{display:grid;gap:1.2rem;margin-top:1.7rem}
            .field{display:grid;gap:.45rem;font-size:.88rem;font-weight:600}
            input,select{width:100%;min-height:2.65rem;padding:.55rem .75rem;border:1px solid #d2ccdf;border-radius:7px;background:#fff;color:#15131d;font:inherit;font-weight:400}
            input:focus,select:focus,button:focus-visible{outline:2px solid #6753ae;outline-offset:2px}
            input[name=totp_code]{max-width:13rem;font-variant-numeric:tabular-nums;letter-spacing:.12em}
            .verification{display:grid;gap:.75rem;border-top:1px solid #e7e3ee;padding-top:1.35rem}
            .verification .field-hint{margin:0}
            button{min-height:2.7rem;padding:.55rem .95rem;border:1px solid transparent;border-radius:7px;font:inherit;font-weight:600;cursor:pointer}
            button:disabled{opacity:.6;cursor:wait}
            .passkey-button{justify-self:start;background:#fff;border-color:#c9c0e9;color:#3b2d83}
            .passkey-button:hover{background:#f4f2fb}
            .actions{display:flex;flex-wrap:wrap;gap:.7rem;margin-top:.3rem}
            .actions button[value=approve]{background:#3b9665;color:#fff}
            .actions button[value=approve]:hover{background:#2e7b53}
            .actions button[value=deny]{background:#fff;border-color:#d2ccdf;color:#5a5470}
            .actions button[value=deny]:hover{background:#f4f2f8}
            @media(max-width:480px){.access-summary{align-items:flex-start;flex-direction:column;gap:.3rem}.access-summary strong{text-align:left}.actions button{flex:1}}
            @media(prefers-color-scheme:dark){:root{color-scheme:dark;background:#15131d;color:#f4f2f8}main{background:#211e2b;border-color:#403b52;box-shadow:none}.brand{color:#c9c0e9}.brand-mark{background:#6753ae}.brand small,.muted,.field-hint,.lead,.access-summary span{color:#c4bfd0}.access-summary{background:#2e293c;border-color:#5a5470}.access-summary strong{color:#e5e0f4}.verification{border-color:#403b52}input,select{background:#2a2638;border-color:#5a5470;color:#fff}.passkey-button,.actions button[value=deny]{background:#2a2638;color:#e5e0f4;border-color:#5a5470}.passkey-button:hover,.actions button[value=deny]:hover{background:#403b52}}
            CSS;
        $body = '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" '
            . 'content="width=device-width, initial-scale=1"><title>MyÚčto MCP</title>'
            . '<style>' . $css . '</style>'
            . '<main><div class="brand"><span class="brand-mark" aria-hidden="true">M</span><span>MyÚčto<small>Bezpečné propojení</small></span></div>'
            . $content . '</main></html>';
        $response->getBody()->write($body);
        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function finishConsent(Response $response, string $destination): Response
    {
        $content = '<h1>Vracíme vás do AI asistenta</h1>'
            . '<p class="muted">Pokud se stránka sama nepřesměruje, pokračujte tlačítkem.</p>'
            . '<a id="mcp-oauth-continue" class="retry-link" href="' . self::escape($destination) . '">Pokračovat do asistenta</a>'
            . '<script src="/assets/mcp-oauth-redirect-v1.js" defer></script>';
        return $this->html($response, $content)->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function consentError(Response $response, array $params, string $message, int $status): Response
    {
        $retry = [];
        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge',
            'code_challenge_method', 'state', 'resource', 'scope', 'supplier_id'] as $key) {
            if (isset($params[$key]) && (is_string($params[$key]) || is_int($params[$key]))) {
                $retry[$key] = (string) $params[$key];
            }
        }
        $url = '/oauth/authorize?' . http_build_query($retry, '', '&', PHP_QUERY_RFC3986);
        return $this->html($response, '<h1>Ověření se nepodařilo</h1><p>' . self::escape($message)
            . '</p><a class="retry-link" href="' . self::escape($url) . '">Zkusit znovu</a>', $status);
    }

    private function oauthError(Response $response, string $error, int $status): Response
    {
        return Json::ok($response, ['error' => $error], $status);
    }

    private static function redirectWith(string $url, array $query): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
