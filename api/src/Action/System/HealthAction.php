<?php

declare(strict_types=1);

namespace MyInvoice\Action\System;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\System\InstanceHealthProbe;
use MyInvoice\Service\System\InstanceSecurityConfigurationCheck;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Update\VersionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Veřejný health endpoint.
 *
 * Bez fleet API je tohle jediný kanál, kterým se dá zjistit, co na spravované
 * instalaci běží — proto sem patří údržba, rozpracovaná práce, čerstvost cronu,
 * stáří zálohy, verze i stav migrací (H-09). Detaily ale NE: bez autentizace se
 * vrací jen souhrn (čísla, stáří, booleany), nikdy jména skriptů, seznamy
 * migrací, hostname ani chybové hlášky. Sběr dělá {@see InstanceHealthProbe}.
 *
 * Endpoint musí zůstat dostupný i v údržbě a i přes cizí `Host` — jinak hosting
 * nepozná rozdíl mezi plánovanou údržbou a výpadkem a monitoring přes IP by
 * skončil na 421. Výjimky drží {@see \MyInvoice\Middleware\MaintenanceModeMiddleware}
 * a {@see TenantDomainMiddleware}.
 */
final class HealthAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisProbe $redis,
        private readonly SecretEncryption $crypto,
        private readonly VersionService $version,
        private readonly PasskeyService $passkeys,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly SessionLockPolicy $sessionLockPolicy,
        private readonly AppUrlConfiguration $appUrl,
        // Volitelná záměrně: bez probe (a bez DB) musí health pořád odpovědět
        // kompletním tvarem, jen s neznámými hodnotami.
        private readonly ?InstanceHealthProbe $probe = null,
        // Volitelná ze stejného důvodu; v kontejneru ji dodává explicitní bind.
        private readonly ?InstanceSecurityConfigurationCheck $securityConfig = null,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $domainContext = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        $appUrlStatus = $domainContext instanceof TenantDomainContext
            && $domainContext->mode === TenantDomainContext::CONFIGURATION_ERROR
                ? $this->appUrl->hostnameConflictStatus()
                : $this->appUrl->status();
        $summary = $this->probe?->summary() ?? InstanceHealthProbe::unavailableSummary();
        $managed = $this->probe?->managed() ?? ['managed' => false, 'managed_provider' => null];
        $payload = [
            'status'  => 'ok',
            'version' => $this->version->getCurrentVersion(),
            'db'      => $this->db->ping(),
            'redis'   => $this->redis->isAvailable(),
            'time'    => date(\DateTimeInterface::ATOM),
            // `status` zůstává 'ok' i v údržbě — instance odpovídá, což je přesně
            // to, co HTTP status vypovídá. Údržbu nese samostatný příznak, ať
            // existující healthchecky (Docker, CI) nezačnou restartovat kontejner.
            'maintenance' => $summary['maintenance'],
            'jobs'        => $summary['jobs'],
            'cron'        => $summary['cron'],
            'backup'      => $summary['backup'],
            'migrations'  => $summary['migrations'],
            'configuration' => [
                'app_url' => $appUrlStatus,
                // Host gate umí selhat tiše v OBOU směrech (viz
                // AppUrlConfiguration::isConfigured), proto tři samostatné údaje.
                'app_url_configured'   => $this->appUrl->isConfigured(),
                'app_url_matches_host' => $this->appUrl->matchesHost($request->getUri()->getHost()),
                'host_gate_enforced'   => $this->probe?->hostGateEnforced() ?? false,
                'managed'              => $managed['managed'],
            ],
        ];

        // ⚠️ KDO instalaci hostuje, se anonymnímu volajícímu neříká.
        //
        // Aplikace nesmí na svém provozovateli nic stavět a už vůbec ho nesmí
        // vyzrazovat: `/api/health` je veřejný a záměrně odpovídá i na neznámé
        // doméně, takže by stačilo projet `*.myucto.online` a mít celý
        // dodavatelský řetězec i seznam instancí. Zůstává diagnostickým údajem
        // pro toho, kdo je uvnitř — provozovatel sám sebe zná.
        //
        // `managed` (ano/ne) zůstává veřejné: neprozrazuje nikoho a zákaznická
        // instalace podle něj pozná, že si konfiguraci nemá přenastavovat.
        if ($request->getAttribute(AuthMiddleware::ATTR_USER) !== null) {
            $payload['configuration']['managed_provider'] = $managed['managed_provider'];
        }

        // Diagnostické warningy (např. slabý fallback secret_encryption_key) jen
        // pro přihlášené — anonymnímu volajícímu (Docker healthcheck, monitoring)
        // neprozrazujeme detaily konfigurace.
        if ($request->getAttribute(AuthMiddleware::ATTR_USER) !== null) {
            $warnings = [];
            $keyWarning = $this->crypto->validateKey();
            if ($keyWarning !== null) {
                $warnings[] = [
                    'code' => 'secret_encryption_key',
                    'message' => $keyWarning,
                ];
            }
            if ($this->mfaPolicy->isMethodAllowed('passkey')
                && !$this->passkeys->isAvailable()
            ) {
                $warnings[] = [
                    'code' => 'webauthn_configuration',
                    'message' => $this->passkeys->configurationError()
                        ?? 'Konfigurace WebAuthn není platná.',
                ];
            }
            $mfaWarning = $this->mfaPolicy->configurationWarning();
            if ($mfaWarning !== null) {
                $warnings[] = [
                    'code' => 'mfa_methods_configuration',
                    'message' => $mfaWarning,
                ];
            }
            $lockWarning = $this->sessionLockPolicy->configurationWarning();
            if ($lockWarning !== null) {
                $warnings[] = [
                    'code' => 'session_lock_configuration',
                    'message' => $lockWarning,
                ];
            }
            if ($this->sessionLockPolicy->isEnabled() && $this->hasUserWithoutPasskey()) {
                $warnings[] = [
                    'code' => 'session_lock_without_unlock_method',
                    'message' => 'Automatický zámek session je zapnutý, ale někteří aktivní '
                        . 'uživatelé nemají passkey. Zamčenou session pak lze jen odhlásit '
                        . '— registrujte jim passkey, nebo nastavte session.lock_after_minutes = 0.',
                ];
            }
            // Produkční výchozí hodnoty (Secure cookie, app.env) a klíč mzdových otisků.
            foreach ($this->securityConfig?->warnings() ?? [] as $securityWarning) {
                $warnings[] = $securityWarning;
            }
            $payload['warnings'] = $warnings;
        }

        return Json::ok($response, $payload);
    }

    /**
     * Diagnostika, ne guard — proto tiše ustoupí, když tabulka passkeys ještě
     * neexistuje (health musí odpovědět i před doběhnutím migrací).
     */
    private function hasUserWithoutPasskey(): bool
    {
        try {
            $statement = $this->db->pdo()->query(
                'SELECT EXISTS (
                    SELECT 1
                      FROM users u
                     WHERE u.is_active = 1
                       AND NOT EXISTS (
                             SELECT 1
                               FROM webauthn_credentials c
                              WHERE c.user_id = u.id
                                AND c.revoked_at IS NULL
                           )
                 )'
            );
            if ($statement === false) {
                return false;
            }
            return (int) $statement->fetchColumn() === 1;
        } catch (\PDOException) {
            return false;
        }
    }
}
