<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Infrastructure\Database;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pojistky anonymizace „kopie ještě nemá triggery" stojí na {@see TriggerMetadata::any()}.
 * Odpověď musí odpovídat `information_schema.TRIGGERS` pro databázi spojení: prázdné
 * schéma, schéma s triggerem i spojení bez zvolené databáze.
 */
#[Group('integration')]
final class TriggerMetadataAnyTest extends TestCase
{
    public function testAnswerMatchesInformationSchemaForTheConnectionDatabase(): void
    {
        $config = Config::load(Bootstrap::rootDir());
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            (string) $config->get('db.user'), (string) $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'myucto_trigger_any_' . bin2hex(random_bytes(5)) . '_test';
        $other = 'myucto_trigger_any_' . bin2hex(random_bytes(5)) . '_test';
        $owned = [$schema, $other];
        try {
            self::assertFalse(TriggerMetadata::any($server));

            $server->exec('CREATE DATABASE `' . $schema . '`');
            $server->exec('CREATE DATABASE `' . $other . '`');
            $server->exec('CREATE TABLE `' . $other . '`.t (id INT PRIMARY KEY)');
            $server->exec('CREATE TRIGGER `' . $other . '`.t_bi BEFORE INSERT ON `' . $other . '`.t FOR EACH ROW SET NEW.id = NEW.id');
            $server->exec('USE `' . $schema . '`');
            $server->exec('CREATE TABLE t (id INT PRIMARY KEY)');
            self::assertSame(0, $this->informationSchemaCount($server));
            self::assertFalse(TriggerMetadata::any($server), 'Trigger jiné databáze se nepočítá.');

            $server->beginTransaction();
            self::assertFalse(TriggerMetadata::any($server));
            self::assertTrue($server->inTransaction());
            $server->rollBack();

            $server->exec('CREATE TRIGGER t_bi BEFORE INSERT ON t FOR EACH ROW SET NEW.id = NEW.id');
            self::assertSame(1, $this->informationSchemaCount($server));
            self::assertTrue(TriggerMetadata::any($server));
            self::assertTrue(TriggerMetadata::any($server), 'Opakovaný dotaz nesmí narazit na nedočtený výsledek.');
        } finally {
            foreach ($owned as $name) {
                OwnedTestDatabaseCleanup::drop($server, $name, $owned);
            }
        }
    }

    private function informationSchemaCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchColumn();
    }
}
