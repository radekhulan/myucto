<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GroupDashboardAccess
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PermissionResolver $permissions,
    ) {}

    public function companies(Request $request): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) return [];
        $superadmin = RequestAuthorization::isSuperadmin($request);
        $ids = $superadmin
            ? array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN))
            : $this->memberships->allowedSupplierIds($userId);
        $ids = $this->supplierAccess->filterAllowedSupplierIds($request, $ids);
        $find = $this->db->pdo()->prepare(
            "SELECT id, COALESCE(NULLIF(display_name, ''), company_name) AS name,
                    accounting_mode, is_vat_payer FROM supplier WHERE id = ?"
        );
        $companies = [];
        foreach ($ids as $id) {
            $scoped = $request->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $id)->withQueryParams([]);
            $access = $this->supplierAccess->resolve($scoped);
            if ($access->denied || $access->supplierId !== $id) continue;
            $role = $this->permissions->resolve($scoped);
            $scoped = $scoped->withAttribute('auth.effective_role', $role)
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $id);
            if (!$role->isActive || $role->isClientType()
                || !RequestAuthorization::allows($scoped, 'dashboard.portfolio')) continue;
            $find->execute([$id]);
            $row = $find->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) continue;
            $companies[] = ['supplier' => $row, 'request' => $scoped];
        }
        return $companies;
    }
}
