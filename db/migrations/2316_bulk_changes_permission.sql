SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Hromadné změny dokladů a zápisů (Hromadné akce v seznamech) jako samostatné právo.
-- Systémové role správců ho dostanou rovnou; účetní a vlastní role jen výslovně
-- v editoru rolí (fail-closed), stejně jako hromadné přeúčtování (2311).
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'accounting.bulk_changes', 2
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus');
