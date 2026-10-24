<?php

declare(strict_types=1);

namespace MyInvoice\Security;

final class PermissionChecker
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    public function allows(EffectiveRole $role, string $key, AccessLevel $minimum = AccessLevel::READ): bool
    {
        if ($role->isSuperadmin()) return true;
        if (!$role->isActive || !$this->catalog->has($key)) return false;
        if (!$this->catalog->allowsRoleType($key, $role->type)) return false;
        if ($role->isClientType()) {
            if ($key === 'reports.submissions' && $minimum === AccessLevel::WRITE) return false;
            if ($key === 'reports.submit' && !$role->level('reports.submissions')->allows(AccessLevel::READ)) return false;
        }
        if ($role->isClientType() && in_array($key, ['bank.import', 'bank.match', 'bank.ignore'], true)
            && !$role->level('bank')->allows(AccessLevel::READ)) return false;
        if ($role->isClientType() && ($key === 'stock' || str_starts_with($key, 'stock.'))) {
            if ($key === 'stock' && $minimum === AccessLevel::WRITE) return false;
            if ($key !== 'stock' && !$role->level('stock')->allows(AccessLevel::READ)) return false;
        }
        return $role->level($key)->allows($minimum);
    }

    public function require(EffectiveRole $role, string $key, AccessLevel $minimum = AccessLevel::READ): void
    {
        if (!$this->allows($role, $key, $minimum)) {
            throw new PermissionDenied($key, $minimum);
        }
    }
}
