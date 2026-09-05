<?php

declare(strict_types=1);

namespace MyInvoice\Security;

final class EffectiveRole
{
    /** @param array<string, int> $permissions */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly bool $isActive,
        public readonly array $permissions = [],
        public readonly ?string $systemKey = null,
    ) {}

    public static function denied(): self
    {
        return new self(0, '', 'staff', false);
    }

    public function isSuperadmin(): bool
    {
        return $this->type === 'superadmin' && $this->systemKey === 'superadmin';
    }

    public function isCompanyAdmin(): bool
    {
        return $this->isSuperadmin()
            || ($this->type === 'staff' && in_array($this->systemKey, ['admin', 'admin_plus'], true));
    }

    public function canCreateSupplier(): bool
    {
        return $this->isSuperadmin()
            || ($this->type === 'staff' && $this->systemKey === 'admin_plus');
    }

    public function isClientType(): bool
    {
        return $this->type === 'client';
    }

    public function level(string $key): AccessLevel
    {
        if ($this->isSuperadmin()) return AccessLevel::WRITE;
        if (!$this->isActive) return AccessLevel::NONE;
        return AccessLevel::tryFrom((int) ($this->permissions[$key] ?? 0)) ?? AccessLevel::NONE;
    }
}
