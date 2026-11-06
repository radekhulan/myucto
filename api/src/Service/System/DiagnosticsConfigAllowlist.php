<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\PepperPolicy;

/**
 * Výřez konfigurace pro diagnostický balíček — striktně ALLOWLISTEM.
 *
 * Denylist („vynech klíče, které vypadají jako heslo") tu vědomě NENÍ. Strom
 * `cfg.php` má přes dvacet sekcí a tajemství jsou v nich roztroušená
 * (`app.pepper`, `app.secret_encryption_key`, `db.password`, `smtp.*.client_secret`,
 * `signing.passphrase`, `captcha.secret_key`, `cron.backup.password`, …).
 * Jeden zapomenutý vzor by znamenal tajemství v souboru, který zákazník posílá
 * ven — proto se ven dostane výhradně to, co je tady vyjmenované.
 *
 * Dvě množiny:
 *   VALUES   — klíče, jejichž hodnota je neutrální a posílá se doslova,
 *   PRESENCE — klíče, u kterých je diagnosticky podstatné jen „je / není
 *              nastaveno"; hodnota se nahradí `<set>` / `<empty>`.
 *
 * Cokoli, co není v jedné z nich, se do výstupu nedostane vůbec.
 */
final class DiagnosticsConfigAllowlist
{
    /** Klíče vypisované s hodnotou. */
    public const VALUES = [
        'app.env',
        'app.debug',
        'app.timezone',
        'app.url',
        'logging.level',
        'logging.max_files',
        'db.host',
        'db.port',
        'db.charset',
        'db.name',
        'redis.enabled',
        'redis.host',
        'redis.port',
        'redis.database',
        'cache.entities_enabled',
        'cache.routes_enabled',
        'cache.schema_ttl',
        'session.lifetime_minutes',
        'session.lock_after_minutes',
        'auth.allowed_mfa_methods',
        'auth.require_mfa',
        'auth.passwordless_login.enabled',
        'smtp.transport',
        'smtp.host',
        'smtp.port',
        'smtp.encryption',
        'smtp.from_email',
        'smtp.auth_mode',
        'documents.max_file_bytes',
        'storage.invoices_dir',
        'storage.documents_dir',
        'import.provider',
        'import.enabled',
        'ares.enabled',
        'vies.enabled',
        'epo_test',
        'epo.ca_bundle_path',
        'ip_allowlist.enabled',
        'rate_limits.login_per_minute',
        'brute_force.max_attempts',
        'captcha.provider',
        'captcha.enabled',
        'approval.token_ttl_days',
        'approval.max_reminders',
        'bank_import.enabled',
        'purchase_invoice.auto_post',
        'cron.cleanup.login_attempts_hours',
        'cron.cleanup.password_resets_days',
        'cron.cleanup.cache_ttl_days',
        'cron.cleanup.pdf_cache_days',
        'cron.backup.daily_retention_days',
        'cron.backup.monthly_retention_days',
        'license.server_url',
    ];

    /**
     * Klíče, u kterých se posílá jen informace „nastaveno / prázdné".
     *
     * Patří sem každé tajemství, jehož PŘÍTOMNOST je diagnostická informace —
     * prázdný `app.secret_encryption_key` znamená fallback HKDF z pepperu, což
     * hlásí i `/api/health`, a je to reálná příčina incidentů.
     */
    public const PRESENCE = [
        'app.pepper',
        'app.secret_encryption_key',
        'app.payroll_hash_key',
        'license.public_key',
        'db.password',
        'db.user',
        'redis.password',
        'smtp.username',
        'smtp.password',
        'smtp.oauth.client_secret',
        'smtp.oauth.refresh_token',
        'captcha.site_key',
        'captcha.secret_key',
        'signing.passphrase',
        'pdf_signing.passphrase',
        'cron.backup.password',
        'dkim.private_key_path',
    ];

    /**
     * Klíče, které ve SPRAVOVANÉM provozu prozrazují provozovatele.
     *
     * ⚠️ Na self-hostu je jméno databázového a poštovního serveru zákazníkovo
     * vlastní a při ladění se hodí. Ve spravovaném provozu je to ale naše
     * infrastruktura: `db.host` a `smtp.host` z ní dají dohromady, kdo
     * instalaci hostuje — a to je přesně údaj, který aplikace vyzrazovat
     * nesmí (viz {@see ManagedModeGuard}). Diagnostický balíček navíc
     * zákazník posílá ven, klidně třetí straně.
     *
     * Diagnosticky se tím nic neztrácí: pro „proč nejde pošta" stačí vědět,
     * že hostitel nastavený JE.
     *
     * @var list<string>
     */
    private const MANAGED_PRESENCE_ONLY = ['db.host', 'smtp.host', 'redis.host'];

    public function __construct(
        private readonly Config $config,
        private readonly ?ManagedModeGuard $managed = null,
    ) {}

    /**
     * Bezpečný výřez konfigurace.
     *
     * @return array{values:array<string,mixed>,presence:array<string,string>,note:string}
     */
    public function export(): array
    {
        $hideInfrastructure = $this->managed?->isManaged() ?? false;

        $values = [];
        foreach (self::VALUES as $path) {
            if ($hideInfrastructure && in_array($path, self::MANAGED_PRESENCE_ONLY, true)) {
                continue;
            }
            $value = $this->config->get($path, null);
            if ($value === null) {
                continue;
            }
            $values[$path] = self::normalize($value);
        }

        $presenceKeys = self::PRESENCE;
        if ($hideInfrastructure) {
            $presenceKeys = array_merge($presenceKeys, self::MANAGED_PRESENCE_ONLY);
        }
        $presence = [];
        foreach ($presenceKeys as $path) {
            $presence[$path] = self::describePresence($this->config->get($path, null));
        }

        return [
            'values'   => $values,
            'presence' => $presence,
            'note'     => 'Výřez konfigurace pořízený allowlistem. Klíče, které tu nejsou, '
                . 'se do balíčku nedostaly vůbec. U klíčů v sekci „presence" se přenáší '
                . 'pouze informace, jestli jsou nastavené.',
        ];
    }

    /** `<set>` / `<empty>` — nikdy samotná hodnota. */
    private static function describePresence(mixed $value): string
    {
        if ($value === null) {
            return '<unset>';
        }
        if (is_array($value)) {
            return $value === [] ? '<empty>' : '<set:' . count($value) . '>';
        }
        if (is_bool($value)) {
            return $value ? '<set>' : '<empty>';
        }
        $string = trim((string) $value);
        if ($string === '') {
            return '<empty>';
        }
        // „CHANGE-ME" ze vzorové konfigurace je nález, ne tajemství — hlásit ho
        // je užitečnější než ho skrýt.
        return PepperPolicy::isPlaceholder($string) ? '<default:CHANGE-ME>' : '<set>';
    }

    /**
     * Skalární hodnoty projdou; pole se propustí jen tehdy, když jsou celá
     * skalární (typicky `auth.allowed_mfa_methods`). Vnořená struktura by mohla
     * nést neprověřený podstrom, proto se nahradí popisem.
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    return '<array:' . count($value) . '>';
                }
            }
            return array_values($value);
        }
        return '<' . get_debug_type($value) . '>';
    }
}
