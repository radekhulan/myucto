<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Action\Auth\Tokens\CreateTokenAction;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use MyInvoice\Service\OAuth\Pkce;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MobileOidcAction
{
    private const INSTANCE = 'https://eet.myucto.online';
    private const REDIRECTS = ['myucto://oauth/callback', 'myucto-dev://oauth/callback'];

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly OidcProviderRegistry $providers,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PermissionResolver $roles,
        private readonly PermissionChecker $permissions,
        private readonly ApiTokenService $tokens,
        private readonly LoginPolicyService $loginPolicy,
    ) {}

    public function providers(Request $request, Response $response): Response
    {
        return Json::ok($response, ['providers' => $this->availableProviders($request)]);
    }

    public function authorize(Request $request, Response $response): Response
    {
        $isPost = $request->getMethod() === 'POST';
        $params = $isPost ? (array) $request->getParsedBody() : $request->getQueryParams();
        $provider = $this->field($params, 'provider');
        $state = $this->field($params, 'state');
        $challenge = $this->field($params, 'code_challenge');
        $redirect = $this->field($params, 'redirect_uri');
        if (!in_array($provider, $this->availableProviders($request), true) || !in_array($redirect, self::REDIRECTS, true)
            || preg_match('/^[a-f0-9]{64}$/D', $state) !== 1 || preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1) {
            return $this->html($response, 'Připojení aplikace vypršelo. Začněte znovu v aplikaci.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
        $userId = (int) ($user['id'] ?? 0);
        $identity = (int) ($session['external_identity_id'] ?? 0);
        $method = 'oidc:' . $provider;
        if (!RequestAuthorization::isSessionAuth($request) || $userId <= 0 || $identity <= 0
            || ($session['auth_method'] ?? null) !== $method || (int) ($session['created_at'] ?? 0) < time() - 600) {
            return $this->html($response, 'Ověřte se znovu stejným poskytovatelem z mobilní aplikace.', 403);
        }

        $choices = [];
        foreach ($this->memberships->listForUser($userId) as $company) {
            $supplier = $company['supplier_id'];
            $scoped = $request->withoutHeader('X-Supplier-Id')->withQueryParams(['supplier_id' => $supplier]);
            $access = $this->supplierAccess->resolve($scoped);
            $role = $this->roles->resolve($scoped);
            if (!$access->denied && $access->supplierId === $supplier && !$role->isClientType()
                && $this->permissions->allows($role, 'profile.tokens', AccessLevel::WRITE)) {
                $choices[$supplier] = $company['name'];
            }
        }
        if ($choices === []) {
            return $this->html($response, 'Účet nemá přístup k firmě, kterou lze připojit do mobilní aplikace.', 403);
        }

        if (!$isPost) {
            $fields = '';
            foreach ([
                'provider' => $provider, 'state' => $state, 'code_challenge' => $challenge, 'redirect_uri' => $redirect,
                'csrf_token' => (string) ($session['csrf_token'] ?? ''),
            ] as $name => $value) {
                $fields .= '<input type="hidden" name="' . $name . '" value="' . self::escape($value) . '">';
            }
            $options = '';
            foreach ($choices as $id => $name) {
                $options .= '<option value="' . $id . '">' . self::escape($name) . '</option>';
            }
            return $this->html($response, 'Připojit aplikaci MyÚčto', content:
                '<p>Účet ' . self::escape((string) ($user['email'] ?? '')) . '</p>'
                . '<form method="post" action="/api/auth/mobile/authorize">'
                . $fields . '<label>Firma <select name="supplier_id">' . $options . '</select></label>'
                . '<p>Aplikace dostane přístup k vybrané firmě v rozsahu vašich oprávnění.</p>'
                . '<button type="submit">Připojit zařízení</button></form>');
        }

        $supplier = filter_var($this->field($params, 'supplier_id'), FILTER_VALIDATE_INT);
        if (!isset($choices[$supplier])) {
            return $this->html($response, 'Vyberte firmu ze seznamu.', 400);
        }
        $pdo = $this->db->pdo();
        $pdo->exec('DELETE FROM mobile_oidc_codes WHERE expires_at <= UTC_TIMESTAMP(6) LIMIT 100');
        $code = bin2hex(random_bytes(32));
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO mobile_oidc_codes (code_hash, code_challenge, user_id, supplier_id, external_identity_id, provider, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6) + INTERVAL 3 MINUTE)'
            );
            $stmt->execute([hash('sha256', $code), $challenge, $userId, $supplier, $identity, $provider]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return $this->html($response, 'Tento požadavek už byl potvrzený. Začněte znovu v mobilní aplikaci.', 409);
            }
            throw $e;
        }

        return $response->withStatus(303)
            ->withHeader('Location', $redirect . '?' . http_build_query(['state' => $state, 'code' => $code], '', '&', PHP_QUERY_RFC3986))
            ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function token(Request $request, Response $response): Response
    {
        if ($this->availableProviders($request) === []) {
            return Json::error($response, 'mobile_sign_in_unavailable', 'Mobilní přihlášení přes Apple a Google není dostupné.', 404);
        }
        $body = (array) $request->getParsedBody();
        $code = $this->field($body, 'code');
        $verifier = $this->field($body, 'code_verifier');
        $device = trim($this->field($body, 'device_name'));
        if (preg_match('/^[a-f0-9]{64}$/D', $code) !== 1 || !Pkce::isValidVerifier($verifier)
            || $device === '' || mb_strlen($device) > 100) {
            return $this->invalidGrant($response);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT c.*, COALESCE(m.role_id, u.role_id) AS effective_role, r.role_type AS default_role_type
                   FROM mobile_oidc_codes c
                   JOIN user_external_identities i ON i.id = c.external_identity_id AND i.user_id = c.user_id AND i.provider = c.provider
                   JOIN users u ON u.id = c.user_id AND u.is_active = 1
                   JOIN roles r ON r.id = u.role_id AND r.is_active = 1
                   JOIN user_suppliers m ON m.user_id = c.user_id AND m.supplier_id = c.supplier_id

                  WHERE c.code_hash = ? AND c.used_at IS NULL AND c.expires_at > UTC_TIMESTAMP(6)
                  FOR UPDATE'
            );
            $hash = hash('sha256', $code);
            $stmt->execute([$hash]);
            $grant = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($grant) || !hash_equals($grant['code_challenge'], Pkce::challenge($verifier))
                || !in_array($grant['provider'], $this->availableProviders($request), true)
                || !$this->loginPolicy->authMethodAllowed('oidc:' . $grant['provider'])) {
                return $this->invalidGrant($response);
            }
            $role = $this->roles->forRoleId((int) $grant['effective_role']);
            if ($role->type !== $grant['default_role_type'] || $role->isClientType()
                || !$this->permissions->allows($role, 'profile.tokens', AccessLevel::WRITE)) {
                return $this->invalidGrant($response);
            }
            $expires = (new \DateTimeImmutable())->modify('+' . ApiTokenService::DEFAULT_EXPIRY_DAYS . ' days');
            $token = $this->tokens->generateInTransaction($pdo, (int) $grant['user_id'], (int) $grant['supplier_id'], $device, 'read_write', $expires);
            $pdo->prepare('UPDATE api_tokens SET issued_auth_method = ?, issued_external_identity_id = ? WHERE id = ?')
                ->execute(['oidc:' . $grant['provider'], (int) $grant['external_identity_id'], $token['id']]);
            $pdo->prepare('UPDATE mobile_oidc_codes SET used_at = UTC_TIMESTAMP(6) WHERE code_hash = ?')->execute([$hash]);
            $pdo->commit();
            return Json::ok($response, ['token' => $token['plaintext']], 201);
        } catch (LicenseSeatLimitExceeded) {
            return CreateTokenAction::seatLimitError($response);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function availableProviders(Request $request): array
    {
        if ($this->config->get('auth.mobile_oidc.enabled', false) !== true
            || $this->config->get('app.url') !== self::INSTANCE
            || strtolower($request->getUri()->getHost()) !== 'eet.myucto.online') {
            return [];
        }
        return array_values(array_filter(
            ['google', 'apple'],
            fn (string $id): bool => $this->providers->get($id) !== null && $this->loginPolicy->authMethodAllowed('oidc:' . $id),
        ));
    }

    private function field(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    private function invalidGrant(Response $response): Response
    {
        return Json::error($response, 'invalid_grant', 'Přihlášení do mobilní aplikace vypršelo. Zkuste to znovu.', 400);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function html(Response $response, string $heading, int $status = 200, string $content = ''): Response
    {
        $response->getBody()->write('<!doctype html><html lang="cs"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>MyÚčto</title>'
            . '<style>body{font:16px system-ui;color:#211743;background:#f5f3fc;margin:0;padding:2rem}'
            . 'main{max-width:30rem;margin:auto}select,button{font:inherit;padding:.7rem;max-width:100%}'
            . 'button{background:#3b2d83;color:white;border:0;border-radius:.5rem}</style>'
            . '<main><h1>' . self::escape($heading) . '</h1>' . $content . '</main></html>');
        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self' myucto: myucto-dev:; frame-ancestors 'none'; base-uri 'none'");
    }
}
