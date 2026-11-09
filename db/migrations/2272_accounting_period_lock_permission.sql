SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Ruční posun zámku účtování k datu (accounting_supplier_settings.locked_until) jako
-- samostatné právo, které správce přidělí i roli hlavní účetní v editoru rolí.
-- Systémové role správců ho dostanou rovnou; účetní ani vlastní role ne (fail-closed).
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'accounting.period_lock', 2
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus');
