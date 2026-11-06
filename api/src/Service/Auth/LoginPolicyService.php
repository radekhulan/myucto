<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;

/**
 * Jediné místo, které rozhoduje, jakým přihlášením se smí do firmy.
 *
 * Firma s `login_policy = sso_required` připustí jen sezení vydané přihlášením
 * u `login_sso_provider`. Heslo, passkey, záložní kód, sezení vlastní domény
 * odvozené z hesla ani API token vydaný z takového sezení tam neprojdou.
 * Uživatel, jehož všechny firmy přihlášení u poskytovatele vyžadují, se heslem
 * nepřihlásí vůbec.
 *
 * `auth.oidc.required_provider` vynutí poskytovatele pro celou instalaci: pro
 * každou firmu včetně později založených a pro každého uživatele, i bez firmy.
 *
 * Výjimkou jsou role z `auth.oidc.break_glass_roles` (výchozí `instance_admin`),
 * aby šlo instalaci spravovat i při výpadku poskytovatele.
 *
 * Politika platí jen s poskytovatelem zapnutým v instalaci. Vypnutý poskytovatel
 * by jinak zamkl všechny uživatele firmy bez náhradní cesty dovnitř.
 */
class LoginPolicyService
{
    public const BACKGROUND_AUTH_METHOD = 'background';
    /** @var array<int,?string> */
    private array $supplierCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly OidcProviderRegistry $providers,
    ) {}

    /**
     * Poskytovatel povinný pro celou instalaci, nebo null.
     *
     * Platí jen poskytovatel podporující firemní SSO zapnutý v instalaci. Překlep nebo vypnutý
     * poskytovatel vynucení nezapne, jinak by se nikdo kromě správce instance
     * nepřihlásil.
     */
    public function globalRequiredProvider(): ?string
    {
        $provider = trim((string) $this->config->get('auth.oidc.required_provider', ''));
        if ($provider === '' || !$this->providers->isEnabled()) {
            return null;
        }
        return $this->providers->get($provider)?->supportsLoginPolicy() === true ? $provider : null;
    }

    /**
     * Poskytovatel, kterým se uživatel musí přihlásit, nebo null.
     */
    public function requiredProviderForUser(int $userId, ?string $roleSystemKey): ?string
    {
        if ($userId < 1 || $this->isBreakGlass($roleSystemKey) || !$this->providers->isEnabled()) {
            return null;
        }
        $global = $this->globalRequiredProvider();
        if ($global !== null) {
            return $global;
        }
        // Celý účet se zamkne jen tehdy, když vynucení chtějí VŠECHNY jeho firmy.
        // Správce jedné firmy jinak nesmí účetní, která pracuje i pro jiné firmy,
        // vzít přihlášení heslem do nich. Do jeho firmy ji pak nepustí
        // sessionAllowedForSupplier().
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(s.login_policy = 'sso_required' AND s.login_sso_provider IS NOT NULL) AS enforced,
                    MIN(CASE WHEN s.login_policy = 'sso_required' THEN s.login_sso_provider END) AS provider,
                    COUNT(DISTINCT CASE WHEN s.login_policy = 'sso_required' THEN s.login_sso_provider END) AS providers
               FROM user_suppliers us
               JOIN supplier s ON s.id = us.supplier_id
              WHERE us.user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        if ($total === 0 || (int) ($row['enforced'] ?? 0) !== $total || (int) ($row['providers'] ?? 0) !== 1) {
            return null;
        }
        $provider = $row['provider'] ?? null;
        return is_string($provider) && $this->providers->get($provider)?->supportsLoginPolicy() === true ? $provider : null;
    }

    /**
     * Poskytovatel, kterým se musí přihlásit každý, kdo pracuje ve firmě, nebo null.
     */
    public function requiredProviderForSupplier(int $supplierId): ?string
    {
        if ($supplierId < 1 || !$this->providers->isEnabled()) {
            return null;
        }
        $global = $this->globalRequiredProvider();
        if ($global !== null) {
            return $global;
        }
        if (!array_key_exists($supplierId, $this->supplierCache)) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT login_sso_provider FROM supplier
                  WHERE id = ? AND login_policy = 'sso_required' LIMIT 1"
            );
            $stmt->execute([$supplierId]);
            $provider = $stmt->fetchColumn();
            $this->supplierCache[$supplierId] = is_string($provider) ? $provider : null;
        }
        $provider = $this->supplierCache[$supplierId];
        return $provider !== null && $this->providers->get($provider)?->supportsLoginPolicy() === true ? $provider : null;
    }

    /**
     * Smí sezení s touto metodou pracovat ve firmě?
     */
    public function sessionAllowedForSupplier(int $supplierId, ?string $sessionAuthMethod, ?string $roleSystemKey): bool
    {
        $required = $this->requiredProviderForSupplier($supplierId);
        if ($required === null || $this->isBreakGlass($roleSystemKey)) {
            return true;
        }
        return $sessionAuthMethod === SessionAuthContext::EXTERNAL_PREFIX . $required;
    }

    public function isBreakGlass(?string $roleSystemKey): bool
    {
        if ($roleSystemKey === null || $roleSystemKey === '') {
            return false;
        }
        $roles = $this->config->get('auth.oidc.break_glass_roles', ['instance_admin']);
        return is_array($roles) && in_array($roleSystemKey, $roles, true);
    }

    public function requestAllowedForSupplier(\Psr\Http\Message\ServerRequestInterface $request, int $supplierId): bool
    {
        $method = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD);
        if ($method === self::BACKGROUND_AUTH_METHOD) return true;
        $authMethod = null;
        if (\MyInvoice\Security\RequestAuthorization::isSessionAuth($request)) {
            $session = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_SESSION, []);
            $authMethod = isset($session['auth_method']) ? (string) $session['auth_method'] : null;
        } elseif (\MyInvoice\Security\RequestAuthorization::isBearerAuth($request)) {
            $token = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_API_TOKEN, []);
            $authMethod = isset($token['issued_auth_method']) ? (string) $token['issued_auth_method'] : null;
        }
        $user = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);
        $roleKey = $user['role_summary']['system_key'] ?? null;
        return $this->sessionAllowedForSupplier($supplierId, $authMethod, is_string($roleKey) ? $roleKey : null);
    }

    public function preloadSupplierPolicies(array $supplierIds): void
    {
        if (!$this->providers->isEnabled() || $this->globalRequiredProvider() !== null) return;
        $missing = array_values(array_filter(array_unique(array_map('intval', $supplierIds)),
            fn (int $id): bool => $id > 0 && !array_key_exists($id, $this->supplierCache)));
        if ($missing === []) return;
        $marks = implode(',', array_fill(0, count($missing), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, login_policy, login_sso_provider FROM supplier WHERE id IN ($marks)");
        $stmt->execute($missing);
        foreach ($missing as $id) $this->supplierCache[$id] = null;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['login_policy'] === 'sso_required' && is_string($row['login_sso_provider'])) {
                $this->supplierCache[(int) $row['id']] = $row['login_sso_provider'];
            }
        }
    }
}
