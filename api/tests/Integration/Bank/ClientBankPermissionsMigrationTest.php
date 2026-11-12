<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ClientBankPermissionsMigrationTest extends TestCase
{
    private const MIGRATION = '2015_client_bank_permissions.sql';

    private ?PDO $server = null;
    private ?PDO $db = null;
    private string $database = '';
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $config = Config::load($this->rootDir);
        $this->database = 'myucto_client_bank_' . bin2hex(random_bytes(6));
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
                is_active TINYINT(1) NOT NULL DEFAULT 1
             ) ENGINE=InnoDB;
             CREATE TABLE role_permissions (
                role_id INT UNSIGNED NOT NULL,
                permission_key VARCHAR(120) NOT NULL,
                access_level TINYINT UNSIGNED NOT NULL,
                PRIMARY KEY (role_id, permission_key),
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                CHECK (access_level IN (0,1,2))
             ) ENGINE=InnoDB",
        );
    }

    protected function tearDown(): void
    {
        if ($this->server !== null
            && preg_match('/^myucto_client_bank_[0-9a-f]{12}$/D', $this->database) === 1
        ) {
            \MyInvoice\Service\Anonymization\DatabaseCloner::dropDatabase($this->server, $this->database);
        }
    }

    public function testUpgradePreservesStaffAccessAndExplicitNewPermissionValues(): void
    {
        $this->db->exec(
            "INSERT INTO roles (id, system_key, name, role_type, is_active) VALUES
                (1, 'accountant', 'Synthetic accountant', 'staff', 1),
                (2, NULL, 'Synthetic custom writer', 'staff', 1),
                (3, NULL, 'Synthetic reader', 'staff', 1),
                (4, NULL, 'Synthetic matcher', 'staff', 1),
                (5, 'client', 'Synthetic client', 'client', 1),
                (6, 'superadmin', 'Synthetic superadmin', 'superadmin', 1),
                (7, NULL, 'Synthetic explicit denial', 'staff', 1),
                (8, NULL, 'Synthetic explicit read', 'staff', 1),
                (9, NULL, 'Synthetic inactive writer', 'staff', 0);
             INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES
                (1, 'bank', 2), (2, 'bank', 2), (2, 'bank.match', 0),
                (3, 'bank', 1), (4, 'bank.match', 2),
                (5, 'bank', 2), (6, 'bank', 2),
                (7, 'bank', 2), (7, 'bank.ignore', 0),
                (8, 'bank', 2), (8, 'bank.ignore', 1), (9, 'bank', 2)",
        );

        $this->runMigrator();

        self::assertSame([1 => 2, 2 => 2, 7 => 0, 8 => 1, 9 => 2], $this->ignorePermissions());
        self::assertSame(0, (int) $this->db->query(
            "SELECT access_level FROM role_permissions WHERE role_id = 2 AND permission_key = 'bank.match'",
        )->fetchColumn(), 'Upgrade nesmí rozšířit oprávnění k párování.');

        $this->db->exec("UPDATE role_permissions SET access_level = 0 WHERE role_id = 2 AND permission_key = 'bank.ignore'");
        $this->db->exec("DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'");
        $this->runMigrator();

        self::assertSame([1 => 2, 2 => 0, 7 => 0, 8 => 1, 9 => 2], $this->ignorePermissions());
        $this->runMigrator();
        self::assertSame([1 => 2, 2 => 0, 7 => 0, 8 => 1, 9 => 2], $this->ignorePermissions());
    }

    public function testEmptyInstallationHasNoClientPermissionDefaults(): void
    {
        $this->runMigrator();
        self::assertSame([], $this->ignorePermissions());
        $this->db->exec("DELETE FROM migrations WHERE filename = '" . self::MIGRATION . "'");
        $this->runMigrator();
        self::assertSame([], $this->ignorePermissions());
    }

    /** @return array<int, int> */
    private function ignorePermissions(): array
    {
        return array_map(
            intval(...),
            $this->db->query(
                "SELECT role_id, access_level FROM role_permissions WHERE permission_key = 'bank.ignore' ORDER BY role_id",
            )->fetchAll(PDO::FETCH_KEY_PAIR),
        );
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
