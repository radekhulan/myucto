SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Výmaz osobních údajů bývalých členů (#321, krok 10) jako samostatné právo. Nevratný výmaz patří
-- ke správcovským rolím (vzor 2272); výchozí účetní ani vlastní role ho nedostanou, správce ho přidělí
-- v editoru rolí. INSERT IGNORE je bezpečný při opakovaném spuštění.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'accounting.membership_erasure', 2
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus');
