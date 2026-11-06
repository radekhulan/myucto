<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;

/**
 * Vlastní kopie testovací DB pro testy, které z více spojení COMMITují záměrně
 * nekonzistentní data (souběh, podvržená revize, ruční zásah do deníku).
 *
 * Ve sdílené testovací DB by takové řádky zůstaly napořád: append-only triggery je
 * nedovolí smazat a každý následný klon celé DB (AnonymizationService) na nich
 * správně selže. Kopie se zakládá jednou na třídu a po ní se zahodí.
 */
trait OwnedSandboxDatabase
{
    private static ?PDO $sandboxServer = null;
    private static ?string $sandboxSchema = null;
    private static ?Config $sandboxConfig = null;

    private static function sandboxConfig(string $prefix): Config
    {
        if (self::$sandboxConfig !== null) return self::$sandboxConfig;
        self::assertMatchesRegularExpression('/^[a-z0-9_]{1,30}$/D', $prefix);
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $schema = 'myucto_' . $prefix . '_' . bin2hex(random_bytes(5)) . '_test';
        self::assertNotSame($source, $schema);
        $server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $exists->execute([$schema]);
        self::assertSame(0, (int) $exists->fetchColumn());
        self::$sandboxServer = $server;
        self::$sandboxSchema = $schema;
        $cloner = new DatabaseCloner($server);
        $plan = $cloner->plan($source);
        $cloner->copyTables($plan, $schema, static function (): void {});
        $cloner->finalize($plan, $schema);
        $data = $config->all();
        $data['db']['name'] = $schema;
        return self::$sandboxConfig = new Config($data, $config->dataDir());
    }

    private static function sandboxConnection(string $prefix): Connection
    {
        $config = self::sandboxConfig($prefix);
        $db = Connection::withoutSharedTestConnection(static fn () => new Connection($config));
        self::assertSame(self::$sandboxSchema, (string) $db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        return $db;
    }

    public static function tearDownAfterClass(): void
    {
        try {
            if (self::$sandboxServer !== null && self::$sandboxSchema !== null) {
                OwnedTestDatabaseCleanup::drop(self::$sandboxServer, self::$sandboxSchema, [self::$sandboxSchema]);
            }
        } finally {
            self::$sandboxServer = null;
            self::$sandboxSchema = null;
            self::$sandboxConfig = null;
        }
    }
}
