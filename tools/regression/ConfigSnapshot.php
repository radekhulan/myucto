<?php

declare(strict_types=1);

namespace MyUctoRegression;

use MyInvoice\Infrastructure\Config\Config;

final class ConfigSnapshot
{
    /**
     * Přesné zkontrolované revize Config: původní loader, jeho ekvivalentní
     * vyhodnocení ENV sdílené s hasEnvironmentOverride() pro technickou správu
     * a výchozí hodnoty ohlášení plánované údržby (maintenance_notice.*).
     * Izolace DB, přihlašovacích údajů a runtime cest zůstává v obou stejná.
     */
    private const SOURCE_SIGNATURES = [
        'ba96ba9d57b895a5f31f66c83785248dc3a9cbff345f0edefaead0f472916105',
        '58f8c23bc5640b01812ebedd5793d5e66c56fe41bffd377b122c7fdd7e985bd5',
        // Výchozí hodnoty a ENV pro auth.oidc; DB, údaje ani runtime cesty beze změny.
        'a5754f37fffb4c39253cecabadf8f730409abc79048598edb4eff9a5a00a9cb6',
        '9e814cc44989bd9ff1ea7f7add02232761c699e4d35c39d9e6f5d6329e532eb2',
        // auth.oidc spolu s maintenance_notice.*; DB, údaje ani runtime cesty beze změny.
        'da7795bcf5c4d560e9558938d6975d8a801531293dc59ff7ced3b9de163c1f0f',
        // Výchozí popisek Entra „Microsoft Entra ID"; DB, údaje ani runtime cesty beze změny.
        '6355a5ddd02f18e73a5b08e9e55986994d6493d345612a98e7b05c66982d2d60',
        // auth.oidc.required_provider (vynucení pro celou instalaci); DB, údaje ani runtime cesty beze změny.
        '1d329cb9d45b01d670f2213f693628aae5045b443304f68cba3a0f5de2fb8c90',
        // auth.oidc.providers.entra.skip_mfa a jeho ENV; DB, údaje ani runtime cesty beze změny.
        '379afca8f93768980d6b6ed1a16a24d928adc410b90eb10edc04204dab761c27',
        // Bez auth.oidc.break_glass_roles (vynucení i pro správce instance); DB, údaje ani runtime cesty beze změny.
        'aace5ef9e71fa14494ce3e846d0f0122bca05802dbd29a7a546b95ca3e8a3757',
        // auth.oidc.tokens_follow_identity a jeho ENV; DB, údaje ani runtime cesty beze změny.
        '1687fd715e9208862829ae59cf16e6f586fce828330fe416d8e624d6610cceb3',
        // accounting.default_entry_form a jeho ENV; DB, údaje ani runtime cesty beze změny.
        '7d8b12267e92f7ed0f347cedf1e19c8d3ef8149882caeb73ce1a7f1098ee5667',
        // accounting.save_and_post a jeho ENV; DB, údaje ani runtime cesty beze změny.
        'f895751b4f374e3e91d0620f2cf1a4db0ae7e14da7e38c773d83c36fd7693288',
        // api.allow_system_write_tokens a jeho ENV; DB, údaje ani runtime cesty beze změny.
        'b21b33782fa0f0fdb2ce28afce54e6693afb4f8cdfa7e0c9a3aada758512c023',
    ];

    private static ?Config $config = null;

    public static function prepare(string $root, Config $original, string $database, string $host, string $runtime): void
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents($root . '/api/src/Infrastructure/Config/Config.php'));
        if (!in_array(hash('sha256', $source), self::SOURCE_SIGNATURES, true)) {
            throw new \RuntimeException('unsupported_config_source_signature');
        }
        $data = $original->all();
        $data['db']['name'] = $database;
        $data['db']['host'] = $host;
        $data['redis']['enabled'] = false;
        $paths = new \ReflectionMethod(Config::class, 'applyDataDirOverrides');
        self::$config = new Config($paths->invoke(null, $data, $runtime), $runtime);
    }

    public static function get(): Config
    {
        return self::$config ?? throw new \RuntimeException('missing_config_snapshot');
    }
}
