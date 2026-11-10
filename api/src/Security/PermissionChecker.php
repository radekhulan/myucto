<?php

declare(strict_types=1);

namespace MyInvoice\Security;

final class PermissionChecker
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    public function allows(EffectiveRole $role, string $key, AccessLevel $minimum = AccessLevel::READ): bool
    {
        if ($role->isSuperadmin()) return true;
        if ($role->type === 'instance_admin' || $role->systemKey === 'instance_admin') return false;
        if (!$role->isActive || !$this->catalog->has($key)) return false;
        if (!$this->catalog->allowsRoleType($key, $role->type)) return false;
        // Archiv připravených podání je jen ke čtení pro každý typ role.
        if ($key === 'reports.submissions' && $minimum === AccessLevel::WRITE) return false;
        // Klient nemá jinou cestu k podáním než archiv, proto odeslání bez něj neplatí.
        // Interní role si ponechává legacy samotné `reports.submit` (podává z agendy výkazů).
        if ($role->isClientType() && $key === 'reports.submit'
            && !$role->level('reports.submissions')->allows(AccessLevel::READ)) return false;
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
