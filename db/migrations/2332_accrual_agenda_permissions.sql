SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Agenda Časové rozlišení (migrace 2331): čtení pro vestavěné role včetně čtenáře, správa
-- položek pro správce a účetní.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'accounting.accruals', IF(r.system_key = 'readonly', 1, 2)
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant', 'readonly');

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'accounting.accruals.write', 2
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant');
