<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use Psr\Http\Message\ServerRequestInterface as Request;

final class RequestAuthorization
{
    public static function effectiveRole(Request $request): EffectiveRole
    {
        $role = $request->getAttribute('auth.effective_role');
        if ($role instanceof EffectiveRole) return $role;

        $user = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);
        $legacy = is_string($user['role'] ?? null) ? $user['role'] : '';
        if ($legacy === 'admin') {
            return new EffectiveRole(0, 'Superadmin', 'superadmin', true, [], 'superadmin');
        }
        if (!in_array($legacy, ['accountant', 'readonly', 'client'], true)) {
            return EffectiveRole::denied();
        }
        $catalog = new PermissionCatalog();
        return new EffectiveRole(
            0,
            $legacy,
            $legacy === 'client' ? 'client' : 'staff',
            true,
            $catalog->legacyPreset($legacy),
            $legacy,
        );
    }

    public static function isSuperadmin(Request $request): bool
    {
        return self::effectiveRole($request)->isSuperadmin();
    }

    public static function isCompanyAdmin(Request $request): bool
    {
        return self::effectiveRole($request)->isCompanyAdmin();
    }

    public static function canCreateSupplier(Request $request): bool
    {
        return self::effectiveRole($request)->canCreateSupplier();
    }

    public static function isClientType(Request $request): bool
    {
        return self::effectiveRole($request)->isClientType();
    }

    public static function allows(
        Request $request,
        string $permission,
        AccessLevel $minimum = AccessLevel::READ,
    ): bool {
        $role = self::effectiveRole($request);
        return $role->isSuperadmin() || ($role->isActive && $role->level($permission)->allows($minimum));
    }
}
