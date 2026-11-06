<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;

trait PaymentReadCorruptionSandbox
{
    private static ?Connection $paymentReadDatabase = null;
    private static ?PDO $paymentReadServer = null;
    private static ?string $paymentReadSchema = null;

    private function paymentReadDatabase(array $triggers): Connection
    {
        if (self::$paymentReadDatabase !== null) return self::$paymentReadDatabase;
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        foreach ($triggers as $trigger) self::assertContains($trigger, [
            'unit_payment_export_items_immutable_delete', 'unit_payment_audit_immutable_delete',
            'unit_payment_exports_immutable_update', 'unit_payment_operations_immutable_update',
        ]);
        $owned = 'myucto_property_read_' . bin2hex(random_bytes(5)) . '_test';
        $server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$paymentReadServer = $server;
        self::$paymentReadSchema = $owned;
        try {
            $cloner = new DatabaseCloner($server);
            $plan = $cloner->plan($source);
            $cloner->copyTables($plan, $owned, static function (): void {});
            $cloner->finalize($plan, $owned);
            foreach ($triggers as $trigger) {
                self::assertSame($owned, $server->query('SELECT DATABASE()')->fetchColumn());
                $server->exec('DROP TRIGGER ' . DatabaseCloner::quote($owned) . '.' . DatabaseCloner::quote($trigger));
            }
            $data = $config->all();
            $data['db']['name'] = $owned;
            $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            self::assertSame($owned, $db->pdo()->query('SELECT DATABASE()')->fetchColumn());
            return self::$paymentReadDatabase = $db;
        } catch (\Throwable $e) {
            if ($server->query('SELECT DATABASE()')->fetchColumn() === $owned) self::tearDownAfterClass();
            else { self::$paymentReadServer = null; self::$paymentReadSchema = null; }
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$paymentReadDatabase !== null) {
            if (self::$paymentReadDatabase->pdo()->inTransaction()) self::$paymentReadDatabase->pdo()->rollBack();
            self::$paymentReadDatabase->close();
            self::$paymentReadDatabase = null;
        }
        if (self::$paymentReadServer !== null && self::$paymentReadSchema !== null) {
            self::assertMatchesRegularExpression('/^myucto_property_read_[a-f0-9]{10}_test$/D', self::$paymentReadSchema);
            self::$paymentReadServer->exec('DROP DATABASE ' . DatabaseCloner::quote(self::$paymentReadSchema));
            self::$paymentReadServer = null;
            self::$paymentReadSchema = null;
        }
        parent::tearDownAfterClass();
    }
}
