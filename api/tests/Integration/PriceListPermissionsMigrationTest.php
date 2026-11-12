<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\GlobalSeedTables;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PriceListPermissionsMigrationTest extends TestCase
{
    private const MIGRATION = '2056_price_list_role_permissions.sql';

    private ?PDO $server = null;
    private ?PDO $db = null;
    private string $database = '';
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 3);
        $config = Config::load($this->rootDir);
        $this->database = 'myucto_price_list_' . bin2hex(random_bytes(6));
        $this->server = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                (string) $config->get('db.host', '127.0.0.1'),
                (int) $config->get('db.port', 3306),
            ),
            (string) $config->get('db.user'),
            (string) $config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->server->exec("USE `{$this->database}`");
        $this->server->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->db = $this->server;
        $this->db->exec(
            "CREATE TABLE roles (
                id INT UNSIGNED PRIMARY KEY,
                system_key VARCHAR(32) NULL,
                name VARCHAR(120) NOT NULL,
                role_type ENUM('superadmin','staff','client') NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB;
             CREATE TABLE role_permissions (
                role_id INT UNSIGNED NOT NULL,
                permission_key VARCHAR(120) NOT NULL,
                access_level TINYINT UNSIGNED NOT NULL,
                PRIMARY KEY (role_id, permission_key),
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                CHECK (access_level IN (0,1,2))
             ) ENGINE=InnoDB;
             CREATE TABLE app_meta (k VARCHAR(64) PRIMARY KEY, v MEDIUMTEXT NOT NULL);
             CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, role_id INT UNSIGNED);
             CREATE TABLE user_suppliers (user_id INT UNSIGNED, supplier_id INT UNSIGNED, role_id INT UNSIGNED)",
        );
    }

    protected function tearDown(): void
    {
        if ($this->server !== null
            && preg_match('/^myucto_price_list_[0-9a-f]{12}$/D', $this->database) === 1
        ) {
            \MyInvoice\Service\Anonymization\DatabaseCloner::dropDatabase($this->server, $this->database);
        }
    }

    public function testDefaultsPreserveExplicitValuesAndRoleEditorRemovalOnRerun(): void
    {
        $this->db->exec("INSERT INTO roles (id, system_key, name, role_type) VALUES
            (1, 'admin', 'Synthetic admin', 'staff'), (2, 'admin_plus', 'Synthetic admin plus', 'staff'),
            (3, 'accountant', 'Synthetic accountant', 'staff'), (4, 'readonly', 'Synthetic reader', 'staff'),
            (5, 'client', 'Synthetic client', 'client'), (6, NULL, 'Synthetic custom', 'staff');
            INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES
            (2, 'price_list', 1), (4, 'price_list', 0), (5, 'invoices', 2), (6, 'invoices', 2)");
        $this->runMigrator();
        self::assertSame([1 => 2, 2 => 1, 3 => 1, 4 => 0], $this->catalogPermissions());

        $config = Config::load($this->rootDir);
        $config = new Config(array_replace_recursive($config->all(), ['db' => ['name' => $this->database]]));
        $connection = new \MyInvoice\Infrastructure\Database\Connection($config);
        $roles = new \MyInvoice\Repository\RoleRepository($connection, new \MyInvoice\Security\PermissionCatalog());
        $accountant = $roles->find(3);
        $roles->update(3, $accountant['name'], true, [], (string) $accountant['updated_at']);
        $connection->close();
        self::assertSame([1 => 2, 2 => 1, 4 => 0], $this->catalogPermissions());
        $this->db->exec("INSERT INTO app_meta (k, v) VALUES ('latest_version', 'synthetic-cache')");
        self::assertArrayHasKey('app_meta', GlobalSeedTables::RESET_PARTIAL);
        $this->db->exec('DELETE FROM app_meta WHERE ' . GlobalSeedTables::RESET_PARTIAL['app_meta']);
        self::assertSame(['rbac_price_list_defaults_initialized'], $this->db->query('SELECT k FROM app_meta ORDER BY k')->fetchAll(PDO::FETCH_COLUMN));
        $this->db->exec("DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'");
        $this->runMigrator();
        self::assertSame([1 => 2, 2 => 1, 4 => 0], $this->catalogPermissions());
        $this->runMigrator();
        self::assertSame([1 => 2, 2 => 1, 4 => 0], $this->catalogPermissions());
    }

    public function testEmptyInstallationIsRepeatable(): void
    {
        $this->runMigrator();
        self::assertSame([], $this->catalogPermissions());
        $this->db->exec("DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'");
        $this->runMigrator();
        self::assertSame([], $this->catalogPermissions());
    }

    private function catalogPermissions(): array
    {
        return array_map(intval(...), $this->db->query("SELECT role_id, access_level FROM role_permissions WHERE permission_key = 'price_list' ORDER BY role_id")->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private function runMigrator(): void
    {
        $env = getenv();
        self::assertIsArray($env);
        $env['MYINVOICE_DB_NAME'] = $this->database;
        $env['MYSQL_DATABASE'] = $this->database;
        $env['MYINVOICE_SCHEMA_CACHE'] = '0';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $this->rootDir . '/api/bin/migrate.php', '--no-backfills', '--no-analyze', '--only=' . self::MIGRATION],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->rootDir,
            $env,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "Migrátor selhal.\n{$stdout}\n{$stderr}");
    }
}
