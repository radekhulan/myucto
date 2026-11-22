SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'sales_quotes', IF(r.system_key = 'readonly', 1, 2)
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant', 'readonly');

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, 2
FROM roles r
CROSS JOIN (
  SELECT 'sales_quotes.write' AS permission_key
  UNION ALL SELECT 'sales_quotes.send'
) p
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant');
