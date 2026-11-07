<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

/**
 * Výsledek resoluce supplier scope pro aktuální request (Epic F0).
 *
 *   - supplierId   — platné supplier id, se kterým má request pracovat
 *   - denied       — true = uživatel explicitně požádal o firmu MIMO své
 *                    membership (user_suppliers) → middleware vrací 403
 *   - roleIdOverride — per-supplier role z user_suppliers.role_id (NULL = zdědit
 *                      výchozí users.role_id)
 */
final class SupplierAccess
{
    public function __construct(
        public readonly int $supplierId,
        public readonly bool $denied,
        public readonly ?int $roleIdOverride,
    ) {}
}
