SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Hromadné přeúčtování jako samostatné právo vedle zaúčtování. Systémové role správců
-- ho dostanou rovnou; účetní a vlastní role jen výslovně v editoru rolí (fail-closed).
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'accounting.journal.bulk_repost', 2
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus');
