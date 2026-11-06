-- MyÚčto.cz — výchozí oprávnění EET pro systémové role.
--
-- Samostatně od tabulek EET (2181), aby šel seed rolí spustit i nad instalací,
-- kde se migrace rolí přehrávají izolovaně. Idempotentní přes app_meta, vlastní role
-- se nerozšiřují.

SET NAMES utf8mb4;

-- Oprávnění EET: správci vše, účetní běžnou práci (nastavení jen čtení, certifikáty
-- ne), čtenář modul jen ke čtení. Vlastní role se nerozšiřují.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, 2
FROM roles r
CROSS JOIN (
    SELECT 'eet' AS permission_key
    UNION ALL SELECT 'eet.accept_payment'
    UNION ALL SELECT 'eet.correct'
    UNION ALL SELECT 'eet.settings'
    UNION ALL SELECT 'eet.certificates'
    UNION ALL SELECT 'eet.export'
) p
WHERE r.role_type = 'staff'
  AND r.system_key IN ('admin', 'admin_plus')
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_eet_initialized');

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, p.access_level
FROM roles r
CROSS JOIN (
    SELECT 'eet' AS permission_key, 2 AS access_level
    UNION ALL SELECT 'eet.accept_payment', 2
    UNION ALL SELECT 'eet.correct', 2
    UNION ALL SELECT 'eet.settings', 1
    UNION ALL SELECT 'eet.export', 2
) p
WHERE r.role_type = 'staff'
  AND r.system_key = 'accountant'
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_eet_initialized');

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'eet', 1
FROM roles r
WHERE r.role_type = 'staff'
  AND r.system_key = 'readonly'
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_eet_initialized');

INSERT IGNORE INTO app_meta (k, v)
VALUES ('rbac_eet_initialized', '1');
