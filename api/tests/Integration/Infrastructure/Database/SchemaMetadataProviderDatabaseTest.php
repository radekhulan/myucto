<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Infrastructure\Database;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\LoggingPdo;
use MyInvoice\Infrastructure\Database\SchemaCache;
use MyInvoice\Infrastructure\Database\SchemaMetadataProvider;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class SchemaMetadataProviderDatabaseTest extends TestCase
{
    public function testClosingNonsharedConnectionImmediatelyReleasesPdo(): void
    {
        $connection = Connection::withoutSharedTestConnection(
            static fn () => new Connection(Config::load(Bootstrap::rootDir())),
        );
        $pdo = $connection->pdo();
        $reference = \WeakReference::create($pdo);
        unset($pdo);
        $connection->close();
        self::assertNull($reference->get());
    }

    public function testDroppingCurrentDatabaseInvalidatesItsPersistentCache(): void
    {
        $data = Config::load(Bootstrap::rootDir())->all();
        $dsn = 'mysql:host=' . $data['db']['host'] . ';port=' . (int) ($data['db']['port'] ?? 3306);
        $user = (string) $data['db']['user'];
        $password = (string) ($data['db']['pass'] ?? '');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $server = new PDO($dsn, $user, $password, $options);
        $name = 'schema_drop_' . bin2hex(random_bytes(6)) . '_test';
        $directory = sys_get_temp_dir() . '/schema-drop-cache-' . bin2hex(random_bytes(6));
        $pathFor = static fn (string $database): ?string => SchemaCache::pathFor($directory, $database);
        $pdo = null;
        try {
            $server->exec("CREATE DATABASE `{$name}`");
            $server->exec("CREATE TABLE `{$name}`.original (id INT PRIMARY KEY)");
            $pdo = new LoggingPdo($dsn . ';dbname=' . $name, $user, $password, $options, new \Psr\Log\NullLogger(), $pathFor);
            $cache = new SchemaCache($pathFor($name), $name);
            $cache->put('table:original', true);
            $cache->putSnapshot(SchemaMetadataProvider::load($pdo), $cache->generation());
            $cache->flush();
            self::assertNotNull((new SchemaCache($pathFor($name), $name))->snapshot());
            $pdo->exec("DROP DATABASE `{$name}`");
            self::assertSame('', $pdo->databaseName());
            $server->exec("CREATE DATABASE `{$name}`");
            $server->exec("CREATE TABLE `{$name}`.replacement (new_id INT PRIMARY KEY)");
            $reader = new SchemaCache($pathFor($name), $name);
            self::assertNull($reader->get('table:original'));
            self::assertNull($reader->snapshot());
            $recreated = new PDO($dsn . ';dbname=' . $name, $user, $password, $options);
            self::assertSame(['replacement' => 'BASE TABLE'], SchemaMetadataProvider::load($recreated)['tables']);
        } finally {
            $pdo = null;
            $server->exec("DROP DATABASE IF EXISTS `{$name}`");
            foreach (glob($directory . '/storage/cache/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory . '/storage/cache');
            @rmdir($directory . '/storage');
            @rmdir($directory);
            SchemaMetadataProvider::invalidate();
        }
    }

    public function testDatabasesDdlAndDatabaseRecreationAreIsolated(): void
    {
        $config = Config::load(Bootstrap::rootDir());
        $data = $config->all();
        $server = new PDO(
            'mysql:host=' . $data['db']['host'] . ';port=' . (int) ($data['db']['port'] ?? 3306),
            (string) $data['db']['user'],
            (string) ($data['db']['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $prefix = 'schema_metadata_' . bin2hex(random_bytes(6));
        $names = [$prefix . '_a_test', $prefix . '_b_test'];
        $connections = [];
        try {
            foreach ($names as $index => $name) {
                $server->exec("CREATE DATABASE `{$name}`");
                $server->exec("CREATE TABLE `{$name}`.example (id INT PRIMARY KEY, value_{$index} INT)");
                $dbConfig = $data;
                $dbConfig['db']['name'] = $name;
                $connections[] = Connection::withoutSharedTestConnection(static fn () => new Connection(new Config($dbConfig)));
            }
            [$first, $second] = $connections;
            self::assertArrayHasKey('value_0', $first->schemaSnapshot()['columns']['example']);
            self::assertArrayNotHasKey('value_1', $first->schemaSnapshot()['columns']['example']);
            self::assertArrayHasKey('value_1', $second->schemaSnapshot()['columns']['example']);
            self::assertFalse($first->hasColumn('example', 'added'));
            $first->pdo()->prepare('ALTER TABLE example ADD COLUMN added INT')->execute();
            self::assertTrue($first->hasColumn('example', 'added'));
            self::assertArrayHasKey('added', $first->schemaSnapshot()['columns']['example']);
            $first->pdo()->exec("USE `{$names[1]}`");
            self::assertArrayHasKey('value_1', $first->schemaSnapshot()['columns']['example']);
            self::assertFalse($first->hasColumn('example', 'added'));
            $first->pdo()->exec("USE `{$names[0]}`");
            self::assertTrue($first->hasColumn('example', 'added'));
            $server->exec("DROP DATABASE `{$names[0]}`");
            $server->exec("CREATE DATABASE `{$names[0]}`");
            $server->exec("CREATE TABLE `{$names[0]}`.replacement (new_id INT PRIMARY KEY)");
            SchemaMetadataProvider::invalidate();
            self::assertSame(['replacement' => 'BASE TABLE'], $first->schemaSnapshot()['tables']);
            self::assertFalse($first->hasTable('example'));
            self::assertTrue($first->hasTable('replacement'));
        } finally {
            foreach ($connections as $connection) {
                $connection->close();
            }
            foreach ($names as $name) {
                $server->exec("DROP DATABASE IF EXISTS `{$name}`");
            }
            SchemaMetadataProvider::invalidate();
        }
    }
}
