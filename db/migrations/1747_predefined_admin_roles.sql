SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO roles (system_key, name, role_type, is_active)
SELECT seed.system_key, seed.name, 'staff', 1
FROM (
    SELECT 'admin' AS system_key, 'Admin' AS name
    UNION ALL
    SELECT 'admin_plus', 'Admin Plus'
) seed
WHERE NOT EXISTS (
    SELECT 1 FROM roles existing WHERE existing.system_key = seed.system_key
);

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT role.id, permission.permission_key, 2
FROM roles role
JOIN (
    SELECT DISTINCT permission_key
    FROM role_permissions
    WHERE permission_key <> 'documents.submit'
    UNION SELECT 'accounting.periods.close'
    UNION SELECT 'accounting.periods.close_override'
    UNION SELECT 'accounting.periods.manage'
    UNION SELECT 'documents.delete'
    UNION SELECT 'documents.inbox.delete'
    UNION SELECT 'logbook.delete'
    UNION SELECT 'payroll.approve'
    UNION SELECT 'payroll.enforcement'
    UNION SELECT 'payroll.enforcement.cooperation'
    UNION SELECT 'payroll.erasure'
    UNION SELECT 'payroll.health_evidence'
    UNION SELECT 'payroll.insolvency'
    UNION SELECT 'payroll.person.read_sensitive'
    UNION SELECT 'payroll.reopen'
    UNION SELECT 'payroll.retention'
    UNION SELECT 'payroll.rulesets'
    UNION SELECT 'payroll.settings'
    UNION SELECT 'settings.ai_provider'
    UNION SELECT 'settings.bank_accounts'
    UNION SELECT 'settings.branding'
    UNION SELECT 'settings.company.write'
    UNION SELECT 'settings.domains'
    UNION SELECT 'stock.orders.write'
    UNION SELECT 'stock.vendors.write'
    UNION SELECT 'utilities.import'
) permission
WHERE role.system_key IN ('admin', 'admin_plus');
