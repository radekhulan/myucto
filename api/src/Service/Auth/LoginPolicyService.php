<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Jediné místo, které rozhoduje, zda instalace vynucuje přihlášení přes
 * poskytovatele identity.
 *
 * `auth.oidc.required_provider` (ENV `MYINVOICE_OIDC_REQUIRED`) vynutí
 * poskytovatele pro celou instalaci: pro každou firmu i každého uživatele včetně
 * technického správce instance. Heslo se pak nepoužívá vůbec. Neprojde přihlášení
 * heslem, passkey ani záložním kódem, sezení ani API token vydané z takového
 * přihlášení a citlivé operace se potvrzují jen ověřením u poskytovatele.
 *
 * Při výpadku poskytovatele se instalace zpřístupní vypnutím vynucení
 * v konfiguraci, aplikace sama žádnou výjimku nemá.
 *
 * Vynucení platí jen s poskytovatelem zapnutým v instalaci, který podporuje firemní
 * přihlášení. Překlep nebo vypnutý poskytovatel by jinak zamkl všechny uživatele.
 */
class LoginPolicyService
{
    public const BACKGROUND_AUTH_METHOD = 'background';

    public function __construct(
        private readonly Config $config,
        private readonly OidcProviderRegistry $providers,
    ) {}

    /** Poskytovatel povinný pro celou instalaci, nebo null. */
    public function requiredProvider(): ?string
    {
        $provider = trim((string) $this->config->get('auth.oidc.required_provider', ''));
        if ($provider === '' || !$this->providers->isEnabled()) {
            return null;
        }
        return $this->providers->get($provider)?->supportsLoginPolicy() === true ? $provider : null;
    }

    public function isEnforced(): bool
    {
        return $this->requiredProvider() !== null;
    }

    /**
     * Smí step-up proof vydaný touto metodou potvrdit citlivou operaci?
     *
     * Heslo neprojde nikdy. Silný poskytovatel (přihlášení včetně MFA) potvrzuje
     * operace sám, místní faktory pak nemají váhu, protože se jimi nejde ani
     * přihlásit. Poskytovatel jen jako první faktor vyžaduje po přihlášení místní
     * passkey nebo TOTP, a ty potvrzují i operace.
     */
    public function stepUpMethodAllowed(string $authMethod): bool
    {
        $required = $this->requiredProvider();
        if ($required === null) {
            return true;
        }
        if ($authMethod === 'password') {
            return false;
        }
        return $this->providers->get($required)?->isStrong() === true
            ? $authMethod === 'oidc'
            : $authMethod !== 'oidc';
    }

    /** Smí sezení (nebo token) vydané touto metodou přihlášení pracovat? */
    public function authMethodAllowed(?string $authMethod): bool
    {
        $required = $this->requiredProvider();
        return $required === null || $authMethod === SessionAuthContext::EXTERNAL_PREFIX . $required;
    }

    /**
     * Smí požadavek pracovat? U API tokenu a MCP grantu rozhoduje přihlášení, ze
     * kterého byl token vydaný (`api_tokens.issued_auth_method`).
     */
    public function requestAllowed(ServerRequestInterface $request): bool
    {
        if (!$this->isEnforced() || RequestAuthorization::isBackgroundAuth($request)) {
            return true;
        }
        $authMethod = null;
        if (RequestAuthorization::isSessionAuth($request)) {
            $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
            $authMethod = isset($session['auth_method']) ? (string) $session['auth_method'] : null;
        } elseif (RequestAuthorization::isBearerAuth($request)) {
            $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
            $authMethod = isset($token['issued_auth_method']) ? (string) $token['issued_auth_method'] : null;
        }
        return $this->authMethodAllowed($authMethod);
    }
}
