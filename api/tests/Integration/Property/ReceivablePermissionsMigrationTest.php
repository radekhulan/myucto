<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\{AccessLevel, EffectiveRole, PermissionCatalog, PermissionChecker, PermissionResolver};
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ReceivablePermissionsMigrationTest extends TestCase
{
    private const MIGRATION = '2094_property_receivable_permissions.sql';
    private const KEYS = ['property.receivables.manage', 'property.receivables.confirm', 'property.receivables.certificates'];
    private ?PDO $server = null;
    private ?Connection $db = null;
    private string $database = '';
    private bool $ownsDatabase = false;

    protected function tearDown(): void
    {
        $this->db?->close();
        if ($this->ownsDatabase && $this->server !== null && preg_match('/^myucto_receivable_roles_[a-f0-9]{12}_test$/D', $this->database)) {
            $this->server->exec("DROP DATABASE `{$this->database}`");
        }
    }

    public function testLegacyAccountantCanPrepareAndCertifyButCannotConfirmLegalTransfers(): void
    {
        $catalog = new PermissionCatalog();
        $checker = new PermissionChecker($catalog);
        $accountant = new EffectiveRole(3, 'Synthetic accountant', 'staff', true, $catalog->legacyPreset('accountant'), 'accountant');
        self::assertTrue($checker->allows($accountant, self::KEYS[0], AccessLevel::WRITE));
        self::assertTrue($checker->allows($accountant, self::KEYS[2], AccessLevel::WRITE));
        self::assertFalse($checker->allows($accountant, self::KEYS[1], AccessLevel::READ));
        foreach (['readonly', 'client'] as $key) {
            $role = new EffectiveRole(4, 'Synthetic excluded', $key === 'client' ? 'client' : 'staff', true, $catalog->legacyPreset($key), $key);
            foreach (self::KEYS as $permission) self::assertFalse($checker->allows($role, $permission));
        }
    }

    public function testCatalogVersionInvalidatesThePreviousPermissionSet(): void
    {
        self::assertNotSame('2026-10-property-payments-invoice-edit-issued-v1', PermissionCatalog::VERSION);
    }

    public function testNativeMigrationSeedsOnlyDefaultsAndPreservesEveryExplicitLevel(): void
    {
        $this->createDatabase();
        $this->server->exec("INSERT INTO roles (id,system_key,name,role_type,is_active) VALUES
            (1,'admin','Synthetic admin','staff',1),(2,'admin_plus','Synthetic admin plus','staff',1),
            (3,'accountant','Synthetic accountant','staff',1),(4,NULL,'Synthetic custom','staff',1),
            (5,'client','Synthetic client','client',1),(6,'readonly','Synthetic readonly','staff',1);
            INSERT INTO supplier(id) VALUES(1);
            INSERT INTO user_suppliers(user_id,supplier_id,role_id) VALUES(1,1,NULL);
            INSERT INTO role_permissions(role_id,permission_key,access_level) VALUES
            (4,'property',2),(5,'property',1),(6,'property',0)");
        foreach (range(1, 6) as $roleId) $this->assertEffectiveLevels($roleId, [0, 0, 0]);
        $untouched = $this->permissions();
        $this->runMigrator();
        foreach ([1 => [2, 2, 2], 2 => [2, 2, 2], 3 => [2, 0, 2], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0]] as $roleId => $levels) {
            $this->assertEffectiveLevels($roleId, $levels);
        }
        self::assertSame($untouched, array_values(array_filter($this->permissions(), static fn (array $row): bool => $row['role_id'] >= 4)));
        $defaults = $this->permissions();
        self::assertCount(11, $defaults);
        $this->forgetMigration();
        $this->runMigrator();
        self::assertSame($defaults, $this->permissions());
        $this->runMigrator();
        self::assertSame($defaults, $this->permissions());

        $insert = $this->server->prepare('INSERT INTO role_permissions(role_id,permission_key,access_level) VALUES(?,?,?) ON DUPLICATE KEY UPDATE access_level=VALUES(access_level)');
        foreach (range(1, 6) as $roleId) {
            foreach (self::KEYS as $index => $key) $insert->execute([$roleId, $key, ($roleId + $index) % 3]);
        }
        $explicit = $this->permissions();
        $this->forgetMigration();
        $this->runMigrator();
        self::assertSame($explicit, $this->permissions());
        foreach (range(1, 6) as $roleId) {
            $this->assertEffectiveLevels($roleId, $roleId === 5 ? [0, 0, 0] : [$roleId % 3, ($roleId + 1) % 3, ($roleId + 2) % 3]);
        }
    }

    private function createDatabase(): void
    {
        $config = Config::load(dirname(__DIR__, 4));
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->database = 'myucto_receivable_roles_' . bin2hex(random_bytes(6)) . '_test';
        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->get('db.host'), $config->get('db.port', 3306)),
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->ownsDatabase = true;
        $this->server->exec("USE `{$this->database}`");
        $this->server->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->server->exec("CREATE TABLE roles (
            id INT UNSIGNED PRIMARY KEY, system_key VARCHAR(32) NULL UNIQUE, name VARCHAR(120) NOT NULL,
            role_type ENUM('staff','client','superadmin') NOT NULL, is_active TINYINT NOT NULL
        ) ENGINE=InnoDB;
        CREATE TABLE role_permissions (
            role_id INT UNSIGNED NOT NULL, permission_key VARCHAR(120) NOT NULL, access_level TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY(role_id,permission_key), FOREIGN KEY(role_id) REFERENCES roles(id), CHECK(access_level IN(0,1,2))
        ) ENGINE=InnoDB;
        CREATE TABLE supplier(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE user_suppliers(user_id INT UNSIGNED NOT NULL,supplier_id INT UNSIGNED NOT NULL,role_id INT UNSIGNED NULL,PRIMARY KEY(user_id,supplier_id)) ENGINE=InnoDB");
        $data = $config->all();
        $data['db']['name'] = $this->database;
        $this->db = new Connection(new Config($data));
        self::assertSame($this->database, $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    private function assertEffectiveLevels(int $roleId, array $levels): void
    {
        $catalog = new PermissionCatalog();
        $resolver = new PermissionResolver($this->db, new SupplierAccessResolver($this->db, new UserSupplierRepository($this->db)), $catalog);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withHeader('X-Supplier-Id', '1')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role_id' => $roleId, 'role_summary' => ['type' => $roleId === 5 ? 'client' : 'staff']]);
        $role = $resolver->resolve($request);
        self::assertSame($roleId, $role->id);
        $checker = new PermissionChecker($catalog);
        foreach (self::KEYS as $index => $key) {
            self::assertSame($levels[$index], $role->level($key)->value, "Role {$roleId}: {$key}");
            self::assertSame($levels[$index] >= 1, $checker->allows($role, $key, AccessLevel::READ));
            self::assertSame($levels[$index] === 2, $checker->allows($role, $key, AccessLevel::WRITE));
        }
    }

    private function permissions(): array
    {
        return $this->server->query('SELECT role_id,permission_key,access_level FROM role_permissions ORDER BY role_id,permission_key')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function forgetMigration(): void
    {
        $this->server->prepare('DELETE FROM migrations WHERE filename=?')->execute([self::MIGRATION]);
    }

    private function runMigrator(): void
    {
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = $env['MYSQL_DATABASE'] = $this->database;
        $root = dirname(__DIR__, 4);
        $process = proc_open(
            [PHP_BINARY, $root . '/api/bin/migrate.php', '--no-backfills', '--no-analyze', '--only=' . self::MIGRATION],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env, ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "{$stdout}\n{$stderr}");
    }
}
