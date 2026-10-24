<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Always-available endpoint. Frontend ho zavolá při startu, aby věděl, jestli
 * spustit setup wizard, a získal public Turnstile site_key.
 */
final class SetupStatusAction
{
    public function __construct(
        private readonly FirstRunLockMiddleware $lockProbe,
        private readonly Config $config,
        private readonly PasskeyService $passkeys,
        private readonly MfaPolicyService $mfaPolicy,
        // H-02: tenhle payload čte SPA při startu, takže je to jediné místo,
        // odkud se rozhraní dozví, že si instance nesmí sahat na konfiguraci.
        // Vědomě se nese jen `managed` (ano/ne) — kdo instanci hostuje,
        // aplikace vědět nesmí a `app.managed_provider` zůstává diagnostikou
        // v /api/health.
        private readonly ManagedModeGuard $managed,
        private readonly OverduePolicy $overduePolicy,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Přihlašovací údaje ukázky se vydávají ANONYMNĚ, takže `demo.enabled` sám
        // nestačí: instance, která ten příznak zdědí z cizího cfg nad ostrou
        // databází, by rozdávala funkční přístup k reálným datům. Platí proto
        // tytéž dvě pojistky, kterými se řídí provisioning (assertSafeTarget()).
        $user = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);
        $demoEnabled = !\MyInvoice\Security\InstanceAdminRoutePolicy::isTechnicalUser($user)
            && (bool) $this->config->get('demo.enabled', false) && $this->demoTargetMatches();
        $demoEmail = $demoEnabled ? trim((string) $this->config->get('demo.login_email', '')) : '';
        $demoPassword = $demoEnabled ? (string) $this->config->get('demo.login_password', '') : '';

        return Json::ok($response, [
            'needs_setup' => $this->lockProbe->needsSetup(),
            'version'     => '0.1.0',
            'managed'     => $this->managed->isManaged(),
            'overdue_includes_today' => $this->overduePolicy->includesToday(),
            'passwordless_login_enabled' =>
                (bool) $this->config->get('auth.passwordless_login.enabled', false)
                && $this->passkeys->isAvailable()
                && $this->mfaPolicy->isMethodAllowed('passkey'),
            'captcha'     => [
                'provider'   => $this->config->get('captcha.provider', 'none'),
                'site_key'   => $this->config->get('captcha.site_key', ''),
                'script_url' => $this->config->get('captcha.script_url', ''),
            ],
            'demo'        => [
                'enabled'    => $demoEnabled,
                'auto_login' => $demoEnabled
                    && (bool) $this->config->get('demo.auto_login', true)
                    && $demoEmail !== ''
                    && $demoPassword !== '',
                'email'       => $demoEmail,
                'password'    => $demoPassword,
            ],
        ]);
    }

    /**
     * Běží tahle instalace opravdu jako ukázka? Shoda `app.url` s `demo.expected_host`
     * a `db.name` s `demo.expected_database` — záměrně stejné dvě podmínky, jaké
     * vynucuje {@see \MyInvoice\Service\Demo\DemoProvisioner::assertSafeTarget()}.
     * Prázdná očekávaná hodnota se bere jako neshoda, ať se pojistka nedá obejít
     * tím, že se konfigurace prostě nevyplní.
     */
    private function demoTargetMatches(): bool
    {
        $expectedHost = strtolower(trim((string) $this->config->get('demo.expected_host', '')));
        $actualHost = strtolower((string) (parse_url((string) $this->config->get('app.url', ''), PHP_URL_HOST) ?: ''));
        $expectedDatabase = trim((string) $this->config->get('demo.expected_database', ''));
        $actualDatabase = trim((string) $this->config->get('db.name', ''));

        return $expectedHost !== '' && $expectedHost === $actualHost
            && $expectedDatabase !== '' && $expectedDatabase === $actualDatabase;
    }
}
