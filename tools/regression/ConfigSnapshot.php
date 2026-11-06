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
