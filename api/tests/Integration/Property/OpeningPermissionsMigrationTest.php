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
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OpeningPermissionsMigrationTest extends TestCase
{
    private const MIGRATION = '2095_property_opening.sql';
    private const KEYS = ['property.opening.manage', 'property.opening.post', 'property.opening.revise'];
    private ?PDO $server = null;
    private ?Connection $db = null;
    private string $database = '';
    private bool $ownsDatabase = false;
    private ?\WeakReference $ownedPdo = null;

    protected function tearDown(): void
    {
        $this->closeOwnedConnection();
        if ($this->ownsDatabase && $this->server !== null && preg_match('/^myucto_opening_roles_[a-f0-9]{12}_test$/D', $this->database)) {
            $this->assertOwnedSessionsClosed();
            $this->server->exec("DROP DATABASE `{$this->database}`");
        }
    }

    public function testCleanupRejectsAnOwnedPdoThatIsStillReferenced(): void
    {
        $this->createDatabase();
        $held = $this->db->pdo();
        try {
            try {
                $this->closeOwnedConnection();
                self::fail('Cleanup accepted a retained PDO reference.');
            } catch (AssertionFailedError $error) {
                self::assertStringContainsString('Owned opening PDO is still referenced.', $error->getMessage());
            }
        } finally {
            unset($held);
        }
    }

    public function testCleanupStillRejectsAnotherLiveSessionOnOwnedDatabase(): void
    {
        $this->createDatabase();
        $config = Config::load(dirname(__DIR__, 4));
        $other = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->get('db.host'), $config->get('db.port', 3306), $this->database),
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $this->closeOwnedConnection();
            try {
                $this->assertOwnedSessionsClosed(10);
                self::fail('Cleanup accepted another live session.');
            } catch (AssertionFailedError $error) {
                self::assertStringContainsString('Owned opening test database still has active sessions.', $error->getMessage());
            }
        } finally {
            $other = null;
        }
    }

    private function closeOwnedConnection(): void
    {
        $this->db?->close();
        self::assertNull($this->ownedPdo?->get(), 'Owned opening PDO is still referenced.');
    }

    private function assertOwnedSessionsClosed(int $timeoutMilliseconds = 1000): void
    {
        $sessions = $this->server->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=? AND ID<>CONNECTION_ID()');
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        do {
            $sessions->execute([$this->database]);
            $remaining = (int) $sessions->fetchColumn();
            if ($remaining === 0 || hrtime(true) >= $deadline) break;
            // Zánik PDO odešle QUIT, ale server jej nemusí zpracovat před dotazem jiného spojení.
            usleep(1000);
        } while (true);
        self::assertSame(0, $remaining, 'Owned opening test database still has active sessions.');
    }

    public function testLegacyAccountantCanPrepareAndPostButCannotReviseOpening(): void
    {
        $catalog = new PermissionCatalog();
        $checker = new PermissionChecker($catalog);
        $accountant = new EffectiveRole(3, 'Synthetic accountant', 'staff', true, $catalog->legacyPreset('accountant'), 'accountant');
        self::assertTrue($checker->allows($accountant, self::KEYS[0], AccessLevel::WRITE));
        self::assertTrue($checker->allows($accountant, self::KEYS[1], AccessLevel::WRITE));
        self::assertFalse($checker->allows($accountant, self::KEYS[2], AccessLevel::READ));
        self::assertFalse($checker->allows($accountant, 'property.receivables.confirm', AccessLevel::READ));
        self::assertNotSame('2026-10-property-receivables-v1', PermissionCatalog::VERSION);
    }

    public function testNativeMigrationSeedsOnlyDefaultsAndPreservesEveryExplicitLevel(): void
    {
        $this->createDatabase();
        $roles = $this->server->query("SELECT system_key,id FROM roles WHERE system_key IN ('admin','admin_plus','accountant','client','readonly') ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertCount(5, $roles);
        $this->server->exec("INSERT INTO roles(system_key,name,role_type,is_active) VALUES(NULL,'Synthetic opening custom','staff',1)");
        $roles['custom'] = (int) $this->server->lastInsertId();
        foreach ($roles as $id) $this->assertEffectiveLevels((int) $id, [0, 0, 0]);
        $untouched = $this->permissions(false);
        $this->runMigrator('--only=' . self::MIGRATION);
        foreach ($roles as $key => $id) {
            $this->assertEffectiveLevels((int) $id, match ($key) {
                'admin', 'admin_plus' => [2, 2, 2], 'accountant' => [2, 2, 0], default => [0, 0, 0],
            });
        }
        self::assertSame($untouched, $this->permissions(false));
        $defaults = $this->permissions(true);
        self::assertCount(8, $defaults);
        $this->forgetMigration();
        $this->runMigrator('--only=' . self::MIGRATION);
        self::assertSame($defaults, $this->permissions(true));
        $this->runMigrator('--only=' . self::MIGRATION);
        self::assertSame($defaults, $this->permissions(true));
        $insert = $this->server->prepare('INSERT INTO role_permissions(role_id,permission_key,access_level) VALUES(?,?,?) ON DUPLICATE KEY UPDATE access_level=VALUES(access_level)');
        foreach (array_values($roles) as $roleIndex => $id) {
            foreach (self::KEYS as $index => $key) $insert->execute([$id, $key, ($roleIndex + $index) % 3]);
        }
        $explicit = $this->permissions(true);
        $this->forgetMigration();
        $this->runMigrator('--only=' . self::MIGRATION);
        self::assertSame($explicit, $this->permissions(true));
        self::assertSame($untouched, $this->permissions(false));
        foreach (array_keys($roles) as $index => $key) {
            $this->assertEffectiveLevels((int) $roles[$key], $key === 'client' ? [0, 0, 0] : [$index % 3, ($index + 1) % 3, ($index + 2) % 3]);
        }
    }

    private function createDatabase(): void
    {
        $config = Config::load(dirname(__DIR__, 4));
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->database = 'myucto_opening_roles_' . bin2hex(random_bytes(6)) . '_test';
        $this->server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->get('db.host'), $config->get('db.port', 3306)), $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->ownsDatabase = true;
        $this->server->exec("USE `{$this->database}`");
        $this->server->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->server->exec("CREATE TABLE roles (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, system_key VARCHAR(32) NULL UNIQUE, name VARCHAR(120) NOT NULL, role_type ENUM('staff','client','superadmin') NOT NULL, is_active TINYINT NOT NULL) ENGINE=InnoDB;
            CREATE TABLE role_permissions (role_id INT UNSIGNED NOT NULL, permission_key VARCHAR(120) NOT NULL, access_level TINYINT UNSIGNED NOT NULL, PRIMARY KEY(role_id,permission_key), FOREIGN KEY(role_id) REFERENCES roles(id), CHECK(access_level IN(0,1,2))) ENGINE=InnoDB;
            CREATE TABLE supplier(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
            CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
            CREATE TABLE journal_entry_lines(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
            CREATE TABLE accounting_periods(id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, UNIQUE KEY(supplier_id,id)) ENGINE=InnoDB;
            CREATE TABLE journal_entries(id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, UNIQUE KEY(supplier_id,id)) ENGINE=InnoDB;
            CREATE TABLE property_objects(id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, UNIQUE KEY(supplier_id,id)) ENGINE=InnoDB;
            CREATE TABLE clients(id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, UNIQUE KEY(supplier_id,id)) ENGINE=InnoDB;
            CREATE TABLE unit_payment_debts(id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, charge_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
            CREATE TABLE unit_credit_lots(id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, operation_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
            CREATE TABLE unit_payment_allocations(id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, charge_id BIGINT UNSIGNED NOT NULL, charge_line_id BIGINT UNSIGNED NULL, accessory_id BIGINT UNSIGNED NULL, amount_cents DECIMAL(30,0) NOT NULL) ENGINE=InnoDB;
            CREATE TABLE unit_claim_accessory_receipts(id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, charge_id BIGINT UNSIGNED NOT NULL, journal_line_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
            INSERT INTO roles(system_key,name,role_type,is_active) VALUES
            ('admin','Synthetic admin','staff',1),('admin_plus','Synthetic admin plus','staff',1),('accountant','Synthetic accountant','staff',1),('client','Synthetic client','client',1),('readonly','Synthetic readonly','staff',1);
            INSERT INTO role_permissions(role_id,permission_key,access_level) SELECT id,'property',2 FROM roles WHERE system_key<>'client'");
        $data = $config->all();
        $data['db']['name'] = $this->database;
        $this->db = Connection::withoutSharedTestConnection(static fn (): Connection => new Connection(new Config($data)));
        self::assertSame($this->database, $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->ownedPdo = \WeakReference::create($this->db->pdo());
    }

    private function assertEffectiveLevels(int $roleId, array $levels): void
    {
        $catalog = new PermissionCatalog();
        $resolver = new PermissionResolver($this->db, new SupplierAccessResolver($this->db, new UserSupplierRepository($this->db)), $catalog);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role_id' => $roleId]);
        $role = $resolver->resolveDefault($request);
        self::assertSame($roleId, $role->id);
        $checker = new PermissionChecker($catalog);
        foreach (self::KEYS as $index => $key) {
            self::assertSame($levels[$index], $role->level($key)->value, "Role {$roleId}: {$key}");
            self::assertSame($levels[$index] >= 1, $checker->allows($role, $key, AccessLevel::READ));
            self::assertSame($levels[$index] === 2, $checker->allows($role, $key, AccessLevel::WRITE));
        }
    }

    private function permissions(bool $opening): array
    {
        return $this->server->query("SELECT role_id,permission_key,access_level FROM role_permissions WHERE permission_key " . ($opening ? 'IN' : 'NOT IN') . " ('property.opening.manage','property.opening.post','property.opening.revise') ORDER BY role_id,permission_key")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function forgetMigration(): void
    {
        $this->server->prepare('DELETE FROM migrations WHERE filename=?')->execute([self::MIGRATION]);
    }

    private function runMigrator(string $selector): void
    {
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = $env['MYSQL_DATABASE'] = $this->database;
        $root = dirname(__DIR__, 4);
        $process = proc_open([PHP_BINARY, $root . '/api/bin/migrate.php', '--no-backfills', '--no-analyze', $selector], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "{$stdout}\n{$stderr}");
    }
}
