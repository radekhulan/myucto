<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PermissionResolver
{
    /** @var array<string, EffectiveRole> */
    private array $memo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PermissionCatalog $catalog,
    ) {}

    public function resolve(Request $request): EffectiveRole
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) return EffectiveRole::denied();

        $isSuperadmin = (bool) ($user['is_superadmin'] ?? false)
            || (($user['role_summary']['type'] ?? null) === 'superadmin')
            || (($user['role_summary']['system_key'] ?? null) === 'superadmin');
        if ($isSuperadmin) {
            return new EffectiveRole(
                (int) ($user['role_id'] ?? ($user['role_summary']['id'] ?? 0)),
                (string) ($user['role_summary']['name'] ?? 'Superadmin'),
                'superadmin',
                true,
                [],
                'superadmin',
            );
        }

        $access = $this->supplierAccess->resolve($request);
        if ($access->denied || $access->supplierId <= 0) return EffectiveRole::denied();

        $defaultRoleId = (int) ($user['role_id'] ?? ($user['role_summary']['id'] ?? 0));
        $roleId = $access->roleIdOverride ?? $defaultRoleId;
        if ($roleId <= 0) return EffectiveRole::denied();

        $key = $userId . ':' . $access->supplierId . ':' . $roleId;
        $resolved = $this->memo[$key] ??= $this->load($roleId);
        if ($access->roleIdOverride !== null) {
            $defaultType = (string) ($user['role_summary']['type'] ?? '');
            if ($resolved->isSuperadmin() || $defaultType === '' || $resolved->type !== $defaultType) {
                return EffectiveRole::denied();
            }
        }
        return $resolved;
    }

    public function resolveDefault(Request $request): EffectiveRole
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) return EffectiveRole::denied();

        $roleId = (int) ($user['role_id'] ?? ($user['role_summary']['id'] ?? 0));
        $isSuperadmin = (bool) ($user['is_superadmin'] ?? false)
            || (($user['role_summary']['type'] ?? null) === 'superadmin')
            || (($user['role_summary']['system_key'] ?? null) === 'superadmin');
        if ($isSuperadmin) {
            return new EffectiveRole(
                $roleId,
                (string) ($user['role_summary']['name'] ?? 'Superadmin'),
                'superadmin',
                true,
                [],
                'superadmin',
            );
        }
        return $roleId > 0 ? $this->load($roleId) : EffectiveRole::denied();
    }

    private function load(int $roleId): EffectiveRole
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, r.system_key, r.name, r.role_type, r.is_active,
                    rp.permission_key, rp.access_level
               FROM roles r
          LEFT JOIN role_permissions rp ON rp.role_id = r.id
              WHERE r.id = ?'
        );
        $stmt->execute([$roleId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) return EffectiveRole::denied();

        $first = $rows[0];
        $type = (string) $first['role_type'];
        $permissions = [];
        foreach ($rows as $row) {
            $permissionKey = $row['permission_key'] !== null ? (string) $row['permission_key'] : '';
            $level = (int) ($row['access_level'] ?? 0);
            if ($permissionKey === '' || !$this->catalog->has($permissionKey)) continue;
            if (!$this->catalog->allowsRoleType($permissionKey, $type)) continue;
            if (AccessLevel::tryFrom($level) === null) continue;
            $permissions[$permissionKey] = $level;
        }

        return new EffectiveRole(
            (int) $first['id'],
            (string) $first['name'],
            $type,
            (int) $first['is_active'] === 1,
            $permissions,
            $first['system_key'] !== null ? (string) $first['system_key'] : null,
        );
    }
}
