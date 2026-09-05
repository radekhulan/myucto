<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;

final class PayrollUsagePolicy
{
    public function __construct(private readonly Connection $db) {}

    public function countActiveEmployees(): int
    {
        if (!$this->db->hasTable('payroll_employees')) {
            return 0;
        }
        $statement = $this->db->pdo()->query(
            'SELECT COUNT(*) FROM payroll_employees WHERE is_active = 1'
        );
        if ($statement === false) {
            throw new \RuntimeException('Počet aktivních zaměstnanců se nepodařilo načíst.');
        }

        return (int) $statement->fetchColumn();
    }

    public function countActiveUsers(): int
    {
        if (!$this->supportsPermissionCounting()) {
            return 0;
        }

        $write = AccessLevel::WRITE->value;
        $statement = $this->db->pdo()->query(
            "SELECT COUNT(DISTINCT users.id)
               FROM users
              WHERE users.is_active = 1
                AND (
                    EXISTS (
                        SELECT 1
                          FROM roles global_role
                          JOIN supplier payroll_supplier
                            ON payroll_supplier.payroll_enabled = 1
                         WHERE global_role.id = users.role_id
                           AND global_role.is_active = 1
                           AND global_role.role_type = 'superadmin'
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM user_suppliers membership
                          JOIN supplier payroll_supplier
                            ON payroll_supplier.id = membership.supplier_id
                           AND payroll_supplier.payroll_enabled = 1
                          JOIN roles effective_role
                            ON effective_role.id = COALESCE(membership.role_id, users.role_id)
                           AND effective_role.is_active = 1
                          JOIN role_permissions permission
                            ON permission.role_id = effective_role.id
                           AND permission.permission_key LIKE 'payroll%'
                           AND permission.access_level >= {$write}
                         WHERE membership.user_id = users.id
                    )
                )"
        );
        if ($statement === false) {
            throw new \RuntimeException('Počet aktivních uživatelů mezd se nepodařilo načíst.');
        }

        return (int) $statement->fetchColumn();
    }

    private function supportsPermissionCounting(): bool
    {
        return $this->db->hasTable('users')
            && $this->db->hasTable('roles')
            && $this->db->hasTable('role_permissions')
            && $this->db->hasTable('user_suppliers')
            && $this->db->hasTable('supplier')
            && $this->db->hasColumn('users', 'role_id')
            && $this->db->hasColumn('user_suppliers', 'role_id')
            && $this->db->hasColumn('supplier', 'payroll_enabled');
    }
}
