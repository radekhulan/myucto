<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\TestCase;

final class PaymentPermissionsMigrationTest extends TestCase
{
    private const MIGRATION = '2090_property_payment_permissions.sql';
    private ?PDO $server = null;
    private string $database = '';

    protected function setUp(): void
    {
        $config = Config::load(dirname(__DIR__, 4));
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->database = 'myucto_payment_roles_' . bin2hex(random_bytes(6)) . '_test';
        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->get('db.host'), $config->get('db.port', 3306)),
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->server->exec("USE `{$this->database}`");
        $this->server->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->server->exec('CREATE TABLE roles (id INT UNSIGNED PRIMARY KEY, system_key VARCHAR(32) NULL) ENGINE=InnoDB');
        $this->server->exec('CREATE TABLE role_permissions (
            role_id INT UNSIGNED NOT NULL, permission_key VARCHAR(120) NOT NULL, access_level TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, permission_key), FOREIGN KEY (role_id) REFERENCES roles(id), CHECK (access_level IN (0,1,2))
        ) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && preg_match('/^myucto_payment_roles_[a-f0-9]{12}_test$/D', $this->database)) {
            $this->server->exec("DROP DATABASE `{$this->database}`");
        }
    }

    public function testNativeMigrationSeedsPresetsWithoutOverwritingExplicitLevelsOrCustomRoles(): void
    {
        $this->server->exec("INSERT INTO roles (id, system_key) VALUES
            (1, 'admin'), (2, 'admin_plus'), (3, 'accountant'), (4, NULL), (5, 'client');
            INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES
            (1, 'property.payments.manage', 0), (3, 'property.payments.post', 1),
            (3, 'property.payments.reverse', 0), (4, 'property', 2), (5, 'property', 1)");
        $before = $this->permissions();
        self::assertCount(3, $before);
        $this->runMigrator();
        $expected = [
            ['role_id' => 1, 'permission_key' => 'property.payments.manage', 'access_level' => 0],
            ['role_id' => 1, 'permission_key' => 'property.payments.post', 'access_level' => 2],
            ['role_id' => 1, 'permission_key' => 'property.payments.reverse', 'access_level' => 2],
            ['role_id' => 2, 'permission_key' => 'property.payments.manage', 'access_level' => 2],
            ['role_id' => 2, 'permission_key' => 'property.payments.post', 'access_level' => 2],
            ['role_id' => 2, 'permission_key' => 'property.payments.reverse', 'access_level' => 2],
            ['role_id' => 3, 'permission_key' => 'property.payments.manage', 'access_level' => 2],
            ['role_id' => 3, 'permission_key' => 'property.payments.post', 'access_level' => 1],
            ['role_id' => 3, 'permission_key' => 'property.payments.reverse', 'access_level' => 0],
        ];
        self::assertSame($expected, $this->permissions());
        $this->server->exec("DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'");
        $this->runMigrator();
        self::assertSame($expected, $this->permissions());
        $this->runMigrator();
        self::assertSame($expected, $this->permissions());
    }

    private function permissions(): array
    {
        return $this->server->query("SELECT role_id, permission_key, access_level FROM role_permissions
            WHERE permission_key LIKE 'property.payments.%' ORDER BY role_id, permission_key")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function runMigrator(): void
    {
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = $this->database;
        $env['MYSQL_DATABASE'] = $this->database;
        $root = dirname(__DIR__, 4);
        $process = proc_open(
            [PHP_BINARY, $root . '/api/bin/migrate.php', '--no-backfills', '--no-analyze', '--only=' . self::MIGRATION],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env, ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "{$stdout}\n{$stderr}");
    }
}
