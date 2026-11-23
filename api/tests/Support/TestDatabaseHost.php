<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Pojistka testů, které klonují, mažou nebo zamykají databáze: povolený je jen lokální DB server.
 *
 * Kromě localhost/127.0.0.1/::1 lze výslovně povolit další hosty (např. vývojový server v LAN):
 * - config `db.test_allowed_hosts` (pole), typicky v lokálním cfg.local.php,
 * - nebo ENV `MYINVOICE_TEST_DB_ALLOWED_HOSTS` (čárkami oddělený seznam).
 * Nic jiného se nepovoluje - produkční ani cizí host bez výslovného seznamu test nepustí.
 */
final class TestDatabaseHost
{
    private const LOCAL = ['localhost', '127.0.0.1', '::1'];

    /** @var list<string>|null */
    private static ?array $extra = null;

    public static function isAllowed(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        return in_array($host, self::LOCAL, true) || in_array($host, self::extraHosts(), true);
    }

    /** @return list<string> */
    private static function extraHosts(): array
    {
        if (self::$extra !== null) {
            return self::$extra;
        }

        $hosts = [];
        $env = getenv('MYINVOICE_TEST_DB_ALLOWED_HOSTS');
        if (is_string($env) && $env !== '') {
            $hosts = explode(',', $env);
        }

        try {
            $configured = Config::load(dirname(__DIR__, 3))->get('db.test_allowed_hosts', []);
            if (is_array($configured)) {
                $hosts = array_merge($hosts, $configured);
            }
        } catch (\Throwable) {
            // Bez configu zůstává jen ENV a lokální hosty.
        }

        $normalized = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $normalized[] = $host;
            }
        }

        return self::$extra = array_values(array_unique($normalized));
    }
}
