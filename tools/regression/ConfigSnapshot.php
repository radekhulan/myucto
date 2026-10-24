<?php

declare(strict_types=1);

namespace MyUctoRegression;

use MyInvoice\Infrastructure\Config\Config;

final class ConfigSnapshot
{
    /**
     * Přesné zkontrolované revize Config: původní loader a jeho ekvivalentní
     * vyhodnocení ENV sdílené s hasEnvironmentOverride() pro technickou správu.
     * Izolace DB, přihlašovacích údajů a runtime cest zůstává v obou stejná.
     */
    private const SOURCE_SIGNATURES = [
        'ba96ba9d57b895a5f31f66c83785248dc3a9cbff345f0edefaead0f472916105',
        '58f8c23bc5640b01812ebedd5793d5e66c56fe41bffd377b122c7fdd7e985bd5',
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
