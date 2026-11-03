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
 * u `login_sso_provider`. Heslo, passkey, záložní kód ani sezení vlastní domény
 * odvozené z hesla tam neprojdou. Uživatel, který je členem aspoň jedné takové
 * firmy, se heslem nepřihlásí vůbec: jinak by heslo, které firma zakázala,
 * dál otevíralo jeho účet (profil, API tokeny, ostatní firmy).
 *
 * Výjimkou jsou role z `auth.oidc.break_glass_roles` (výchozí `instance_admin`),
 * aby šlo instalaci spravovat i při výpadku poskytovatele.
 *
 * Politika platí jen s poskytovatelem zapnutým v instalaci. Vypnutý poskytovatel
 * by jinak zamkl všechny uživatele firmy bez náhradní cesty dovnitř.
 */
class LoginPolicyService
{
    /** @var array<int,?string> */
    private array $supplierCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly OidcProviderRegistry $providers,
    ) {}

    /**
     * Poskytovatel, kterým se uživatel musí přihlásit, nebo null.
     */
    public function requiredProviderForUser(int $userId, ?string $roleSystemKey): ?string
    {
        if ($userId < 1 || $this->isBreakGlass($roleSystemKey) || !$this->providers->isEnabled()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.login_sso_provider
               FROM user_suppliers us
               JOIN supplier s ON s.id = us.supplier_id
              WHERE us.user_id = ? AND s.login_policy = 'sso_required'
                AND s.login_sso_provider IS NOT NULL
              ORDER BY s.id
              LIMIT 1"
        );
        $stmt->execute([$userId]);
        $provider = $stmt->fetchColumn();
        return is_string($provider) && $this->providers->get($provider) !== null ? $provider : null;
    }

    /**
     * Poskytovatel, kterým se musí přihlásit každý, kdo pracuje ve firmě, nebo null.
     */
    public function requiredProviderForSupplier(int $supplierId): ?string
    {
        if ($supplierId < 1 || !$this->providers->isEnabled()) {
            return null;
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
        return $provider !== null && $this->providers->get($provider) !== null ? $provider : null;
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
}
