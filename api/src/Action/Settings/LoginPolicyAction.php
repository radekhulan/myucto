<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nastavení firmy → Přihlášení: vynucení přihlášení přes poskytovatele identity.
 *
 * Zapnout smí jen správce, který je sám přihlášený přes daného poskytovatele.
 * Jinak by si politikou, kterou jeho vlastní sezení nesplňuje, okamžitě zavřel
 * firmu a bez propojené identity by se do ní už nedostal.
 */
final class LoginPolicyAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly OidcProviderRegistry $providers,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Auth\LoginPolicyService $loginPolicy,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId < 1) {
            return Json::error($response, 'supplier_required', 'Vyberte firmu.', 400);
        }
        $row = $this->policy($supplierId);
        $global = $this->loginPolicy->globalRequiredProvider();
        return Json::ok($response, [
            'login_policy' => $row['login_policy'],
            'login_sso_provider' => $row['login_sso_provider'],
            // Vynucení pro celou instalaci přebíjí nastavení firmy; firma ho jen ukazuje.
            'global_sso_provider' => $global,
            'providers' => array_values(array_filter(
                $this->providers->publicList(),
                fn (array $p): bool => $this->providers->get($p['id'])?->supportsLoginPolicy() === true,
            )),
            'can_manage' => RequestAuthorization::canManageLoginPolicy($request),
            'users_without_identity' => $this->usersWithoutIdentity($supplierId, $global ?? $row['login_sso_provider'] ?? 'entra'),
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::canManageLoginPolicy($request)) {
            return Json::error($response, 'forbidden_permission', 'Přihlášení firmy nastavuje jen správce.', 403);
        }
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId < 1) {
            return Json::error($response, 'supplier_required', 'Vyberte firmu.', 400);
        }
        if ($this->loginPolicy->globalRequiredProvider() !== null) {
            return Json::error(
                $response,
                'login_policy_global',
                'Přihlášení přes poskytovatele identity je vynucené pro celou instalaci, firma ho nemění.',
                409,
            );
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $policy = (string) ($body['login_policy'] ?? '');
        if (!in_array($policy, ['any', 'sso_required'], true)) {
            return Json::error($response, 'invalid_login_policy', 'Neplatná politika přihlášení.', 422);
        }
        $provider = null;
        if ($policy === 'sso_required') {
            $provider = (string) ($body['login_sso_provider'] ?? '');
            if (!$this->providers->isEnabled() || $this->providers->get($provider)?->supportsLoginPolicy() !== true) {
                return Json::error($response, 'sso_disabled', 'Poskytovatel identity není v této instalaci zapnutý.', 422);
            }
            $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
            if (($session['auth_method'] ?? null) !== SessionAuthContext::EXTERNAL_PREFIX . $provider) {
                return Json::error(
                    $response,
                    'sso_session_required',
                    'Vynucení zapněte až po přihlášení přes tohoto poskytovatele, jinak byste si firmu zavřeli.',
                    409,
                );
            }
        }

        $before = $this->policy($supplierId);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET login_policy = ?, login_sso_provider = ? WHERE id = ?'
        )->execute([$policy, $provider, $supplierId]);

        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->activity->log('settings.login_policy_changed', isset($actor['id']) ? (int) $actor['id'] : null, 'supplier', $supplierId, [
            'before' => $before, 'after' => ['login_policy' => $policy, 'login_sso_provider' => $provider],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return $this->show($request, $response);
    }

    /** @return array{login_policy:string,login_sso_provider:?string} */
    private function policy(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT login_policy, login_sso_provider FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'login_policy' => (string) ($row['login_policy'] ?? 'any'),
            'login_sso_provider' => isset($row['login_sso_provider']) ? (string) $row['login_sso_provider'] : null,
        ];
    }

    /** Aktivní členové firmy, kteří by se po zapnutí vynucení nepřihlásili. */
    private function usersWithoutIdentity(int $supplierId, string $provider): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM user_suppliers us
               JOIN users u ON u.id = us.user_id AND u.is_active = 1
              WHERE us.supplier_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM user_external_identities x
                     WHERE x.user_id = us.user_id AND x.provider = ?
                )'
        );
        $stmt->execute([$supplierId, $provider]);
        return (int) $stmt->fetchColumn();
    }
}
