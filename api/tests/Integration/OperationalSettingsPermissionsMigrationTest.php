<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\GlobalSeedTables;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class OperationalSettingsPermissionsMigrationTest extends TestCase
{
    private const MIGRATION = '2057_operational_settings_role_permissions.sql';

    private ?PDO $server = null;
    private ?PDO $db = null;
    private string $database = '';
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 3);
        $config = Config::load($this->rootDir);
        $this->database = 'myucto_operational_' . bin2hex(random_bytes(6));
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
            && preg_match('/^myucto_operational_[0-9a-f]{12}$/D', $this->database) === 1
        ) {
            \MyInvoice\Service\Anonymization\DatabaseCloner::dropDatabase($this->server, $this->database);
        }
    }

    public function testBackfillSeparatesSourceGrantsPreservesExplicitValuesAndEditorRevocation(): void
    {
        $this->db->exec("INSERT INTO roles (id, system_key, name, role_type) VALUES
            (1, NULL, 'Synthetic delegated client', 'client'),
            (2, NULL, 'Synthetic staff company writer', 'staff'),
            (3, NULL, 'Synthetic client reader', 'client'),
            (4, NULL, 'Synthetic staff context writer', 'staff'),
            (5, NULL, 'Synthetic client staff grant', 'client');
            INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES
            (1, 'settings.company', 2), (1, 'settings.email_templates', 0),
            (1, 'settings.branding', 1), (2, 'settings.company.write', 2),
            (3, 'settings.company', 1), (4, 'settings.company', 2),
            (5, 'settings.company.write', 2)");
        $this->runMigrator();
        $expected = [
            '1:settings.branding' => 1, '1:settings.email_profiles' => 2,
            '1:settings.email_templates' => 0, '1:settings.payment_qr' => 2, '1:settings.signing_profiles' => 2,
            '2:settings.email_profiles' => 2, '2:settings.email_templates' => 2, '2:settings.payment_qr' => 2,
        ];
        self::assertSame($expected, $this->operationalPermissions());
        $config = Config::load($this->rootDir);
        $config = new Config(array_replace_recursive($config->all(), ['db' => ['name' => $this->database]]));
        $connection = new \MyInvoice\Infrastructure\Database\Connection($config);
        $roles = new \MyInvoice\Repository\RoleRepository($connection, new \MyInvoice\Security\PermissionCatalog());
        $client = $roles->find(1);
        $roles->update(1, $client['name'], true, ['settings.company' => 2], (string) $client['updated_at']);
        $connection->close();
        $expected = array_filter($expected, static fn ($key) => !str_starts_with($key, '1:'), ARRAY_FILTER_USE_KEY);
        self::assertSame($expected, $this->operationalPermissions());
        $this->db->exec("INSERT INTO app_meta (k, v) VALUES ('latest_version', 'synthetic-cache')");
        self::assertArrayHasKey('app_meta', GlobalSeedTables::RESET_PARTIAL);
        $this->db->exec('DELETE FROM app_meta WHERE ' . GlobalSeedTables::RESET_PARTIAL['app_meta']);
        self::assertSame(['rbac_operational_settings_initialized'], $this->db->query('SELECT k FROM app_meta ORDER BY k')->fetchAll(PDO::FETCH_COLUMN));
        $this->db->exec("DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'");
        $this->runMigrator();
        self::assertSame($expected, $this->operationalPermissions());
        $this->runMigrator();
        self::assertSame($expected, $this->operationalPermissions());
    }

    public function testEmptyInstallationIsRepeatable(): void
    {
        $this->runMigrator();
        self::assertSame([], $this->operationalPermissions());
        $this->db->exec("DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'");
        $this->runMigrator();
        self::assertSame([], $this->operationalPermissions());
    }

    private function operationalPermissions(): array
    {
        return array_map(intval(...), $this->db->query("SELECT CONCAT(role_id, ':', permission_key), access_level FROM role_permissions
            WHERE permission_key IN ('settings.email_profiles','settings.email_templates','settings.branding','settings.payment_qr','settings.signing_profiles')
            ORDER BY role_id, permission_key")->fetchAll(PDO::FETCH_KEY_PAIR));
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
