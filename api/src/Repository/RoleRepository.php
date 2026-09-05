<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionCatalog;

final class RoleRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PermissionCatalog $catalog,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT r.id, r.system_key, r.name, r.role_type, r.is_active, r.created_at, r.updated_at,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS default_usage,
                    (SELECT COUNT(*) FROM user_suppliers us WHERE us.role_id = r.id) AS override_usage
               FROM roles r ORDER BY r.role_type, r.name, r.id'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'normalize'], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, system_key, name, role_type, is_active, created_at, updated_at FROM roles WHERE id = ?'
        );
        $stmt->execute([$id]);
        $role = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$role) return null;
        $role = $this->normalize($role);
        $role['permissions'] = array_fill_keys(array_keys($this->catalog->all()), 0);
        $perm = $this->db->pdo()->prepare('SELECT permission_key, access_level FROM role_permissions WHERE role_id = ?');
        $perm->execute([$id]);
        foreach ($perm->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['permission_key'];
            if ($this->catalog->has($key)) $role['permissions'][$key] = (int) $row['access_level'];
        }
        $role['usage'] = $this->usage($id);
        return $role;
    }

    public function findBySystemKey(string $systemKey): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ? LIMIT 1');
        $stmt->execute([$systemKey]);
        $id = (int) $stmt->fetchColumn();
        return $id > 0 ? $this->find($id) : null;
    }

    /** @param array<string, int|string|AccessLevel> $permissions */
    public function create(string $name, string $roleType, array $permissions): array
    {
        if (!in_array($roleType, ['staff', 'client'], true)) {
            throw new \InvalidArgumentException('Invalid role type');
        }
        $name = $this->validateName($name, $roleType);
        $levels = $this->validatePermissions($roleType, $permissions);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO roles (system_key, name, role_type, is_active) VALUES (NULL, ?, ?, 1)');
            $stmt->execute([$name, $roleType]);
            $id = (int) $pdo->lastInsertId();
            $this->replacePermissions($id, $levels);
            $pdo->commit();
            return $this->find($id) ?? throw new \RuntimeException('Created role not found');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, int|string|AccessLevel> $permissions */
    public function update(int $id, string $name, bool $isActive, array $permissions, string $revision): array
    {
        $current = $this->find($id) ?? throw new \OutOfBoundsException('Role not found');
        if ($this->isLockedSystemRole($current)) throw new SystemRoleLocked();
        if ((string) $current['updated_at'] !== $revision) throw new RoleRevisionConflict();
        $name = $this->validateName($name, (string) $current['role_type'], $id);
        $levels = $this->validatePermissions((string) $current['role_type'], $permissions);
        $this->guardReadOnlyRole($current, $levels);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE roles SET name = ?, is_active = ?,
                        updated_at = CASE WHEN updated_at >= CURRENT_TIMESTAMP
                                          THEN updated_at + INTERVAL 1 SECOND ELSE CURRENT_TIMESTAMP END
                  WHERE id = ? AND updated_at = ?'
            );
            $stmt->execute([$name, $isActive ? 1 : 0, $id, $revision]);
            if ($stmt->rowCount() !== 1) throw new RoleRevisionConflict();
            $this->replacePermissions($id, $levels);
            if ($ownsTransaction) $pdo->commit();
            return $this->find($id) ?? throw new \RuntimeException('Updated role not found');
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function duplicate(int $id, string $name): array
    {
        $source = $this->find($id) ?? throw new \OutOfBoundsException('Role not found');
        if ($this->isLockedSystemRole($source)) throw new SystemRoleLocked();
        return $this->create($name, (string) $source['role_type'], (array) $source['permissions']);
    }

    public function delete(int $id): void
    {
        $role = $this->find($id) ?? throw new \OutOfBoundsException('Role not found');
        if ($this->isLockedSystemRole($role)) throw new SystemRoleLocked();
        $usage = $this->usage($id);
        if ($usage['total'] > 0) throw new RoleInUse($usage);
        $this->db->pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
    }

    /** @return array{default:int,overrides:int,total:int} */
    public function usage(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM users WHERE role_id = ?) AS defaults,
                    (SELECT COUNT(*) FROM user_suppliers WHERE role_id = ?) AS overrides'
        );
        $stmt->execute([$id, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $default = (int) ($row['defaults'] ?? 0);
        $overrides = (int) ($row['overrides'] ?? 0);
        return ['default' => $default, 'overrides' => $overrides, 'total' => $default + $overrides];
    }

    /**
     * Role „Pouze pro čtení" musí zůstat jen pro čtení.
     *
     * ⚠️ Je to LICENČNÍ pojistka, ne kosmetika. Účty s touhle rolí nezabírají
     * licenční místo, protože nemají právo zápisu. Kdyby se dala přepsat na
     * zapisující, dal by se jedním požadavkem vyrobit neomezený počet
     * plnohodnotných uživatelů zdarma — a nikde by to nebylo vidět, protože
     * role by se dál jmenovala stejně.
     *
     * Jméno, aktivita ani rozsah ČTENÍ se tím nezamykají; upravit se nedá jen
     * úroveň zápisu. Vlastní zapisující role se zakládají zvlášť a ty místo
     * zabírají, jak mají.
     *
     * @param array<string, mixed> $current
     * @param array<string, int> $levels
     */
    private function guardReadOnlyRole(array $current, array $levels): void
    {
        if (($current['system_key'] ?? null) !== 'readonly') {
            return;
        }
        foreach ($levels as $key => $level) {
            // Vlastní profil je zápis i u role jen pro čtení — jinak by si
            // uživatel nezměnil ani heslo. Licenční místo z toho neplyne.
            if ($key === 'profile') {
                continue;
            }
            if ($level >= AccessLevel::WRITE->value) {
                throw new SystemRoleLocked();
            }
        }
    }

    /** @param array<string, mixed> $role */
    private function isLockedSystemRole(array $role): bool
    {
        return in_array($role['system_key'] ?? null, ['superadmin', 'admin', 'admin_plus'], true);
    }

    /** @param array<string, int|string|AccessLevel> $permissions @return array<string, int> */
    private function validatePermissions(string $roleType, array $permissions): array
    {
        $out = [];
        foreach ($permissions as $key => $rawLevel) {
            if (!$this->catalog->has((string) $key)) throw new InvalidPermission((string) $key);
            $level = AccessLevel::fromMixed($rawLevel);
            if ($level !== AccessLevel::NONE && !$this->catalog->allowsRoleType((string) $key, $roleType)) {
                throw new PermissionNotAllowedForRoleType((string) $key, $roleType);
            }
            if ($level !== AccessLevel::NONE) $out[(string) $key] = $level->value;
        }
        return $out;
    }

    /** @param array<string, int> $permissions */
    private function replacePermissions(int $id, array $permissions): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$id]);
        if ($permissions === []) return;
        $stmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($permissions as $key => $level) $stmt->execute([$id, $key, $level]);
    }

    private function validateName(string $name, string $roleType, ?int $exceptId = null): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('Invalid role name');
        $sql = 'SELECT id FROM roles WHERE role_type = ? AND is_active = 1 AND LOWER(name) = LOWER(?)';
        $params = [$roleType, $name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) throw new DuplicateRoleName();
        return $name;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];
        foreach (['default_usage', 'override_usage'] as $key) {
            if (isset($row[$key])) $row[$key] = (int) $row[$key];
        }
        return $row;
    }
}

final class InvalidPermission extends \InvalidArgumentException {}
final class PermissionNotAllowedForRoleType extends \InvalidArgumentException
{
    public function __construct(public readonly string $permissionKey, public readonly string $roleType)
    {
        parent::__construct("Permission $permissionKey is not allowed for $roleType");
    }
}
final class DuplicateRoleName extends \DomainException {}
final class SystemRoleLocked extends \DomainException {}
final class RoleRevisionConflict extends \RuntimeException {}
final class RoleInUse extends \DomainException
{
    /** @param array{default:int,overrides:int,total:int} $usage */
    public function __construct(public readonly array $usage) { parent::__construct('Role is in use'); }
}
