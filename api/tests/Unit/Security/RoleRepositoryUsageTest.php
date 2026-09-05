<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RoleInUse;
use MyInvoice\Repository\RoleRepository;
use MyInvoice\Repository\SystemRoleLocked;
use MyInvoice\Security\PermissionCatalog;
use PDO;
use PHPUnit\Framework\TestCase;

final class RoleRepositoryUsageTest extends TestCase
{
    private PDO $pdo;
    private RoleRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                system_key TEXT NULL,
                name TEXT NOT NULL,
                role_type TEXT NOT NULL,
                is_active INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_key TEXT NOT NULL,
                access_level INTEGER NOT NULL
            );
            CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER NULL);
            CREATE TABLE user_suppliers (user_id INTEGER NOT NULL, supplier_id INTEGER NOT NULL, role_id INTEGER NULL)'
        );
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $this->repository = new RoleRepository($db, new PermissionCatalog());
    }

    public function testUsageCountsDefaultsAndOverridesSeparately(): void
    {
        $this->insertRole(5, null);
        $this->pdo->exec('INSERT INTO users (id, role_id) VALUES (1, 5), (2, 5), (3, NULL)');
        $this->pdo->exec(
            'INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (1, 10, 5), (2, 11, NULL), (3, 12, 5)'
        );

        self::assertSame(
            ['default' => 2, 'overrides' => 2, 'total' => 4],
            $this->repository->usage(5),
        );
    }

    public function testRoleInUseCannotBeDeletedAndReportsUsage(): void
    {
        $this->insertRole(5, null);
        $this->pdo->exec('INSERT INTO users (id, role_id) VALUES (1, 5)');

        try {
            $this->repository->delete(5);
            self::fail('Používaná role měla vyvolat RoleInUse.');
        } catch (RoleInUse $e) {
            self::assertSame(['default' => 1, 'overrides' => 0, 'total' => 1], $e->usage);
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = 5')->fetchColumn());
    }

    public function testUnusedCustomRoleCanBeDeleted(): void
    {
        $this->insertRole(5, null);

        $this->repository->delete(5);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = 5')->fetchColumn());
    }

    public function testSuperadminRoleCannotBeDeletedEvenWhenUnused(): void
    {
        $this->insertRole(1, 'superadmin', 'superadmin');

        $this->expectException(SystemRoleLocked::class);
        $this->repository->delete(1);
    }

    public function testFixedCompanyAdminRolesCannotBeUpdatedDeletedOrDuplicated(): void
    {
        $this->insertRole(2, 'admin');
        $this->insertRole(3, 'admin_plus');

        foreach ([2, 3] as $id) {
            $role = $this->repository->find($id);
            self::assertIsArray($role);
            try {
                $this->repository->update($id, 'Změněná role', true, [], (string) $role['updated_at']);
                self::fail('Předdefinovaná role měla být uzamčena proti úpravě.');
            } catch (SystemRoleLocked) {
            }
            try {
                $this->repository->delete($id);
                self::fail('Předdefinovaná role měla být uzamčena proti smazání.');
            } catch (SystemRoleLocked) {
            }
            try {
                $this->repository->duplicate($id, 'Kopie');
                self::fail('Předdefinovaná role měla být uzamčena proti duplikaci.');
            } catch (SystemRoleLocked) {
            }
        }
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
    }

    private function insertRole(int $id, ?string $systemKey, string $type = 'staff'): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (id, system_key, name, role_type, is_active, created_at, updated_at)
             VALUES (?, ?, 'Testovací role', ?, 1, '2026-07-12 12:00:00', '2026-07-12 12:00:00')"
        );
        $stmt->execute([$id, $systemKey, $type]);
    }
}
