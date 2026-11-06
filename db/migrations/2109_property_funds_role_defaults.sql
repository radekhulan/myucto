INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, 2
FROM roles r
CROSS JOIN (
    SELECT 'property.funds.post' AS permission_key
    UNION ALL SELECT 'property.funds.reverse'
) p
WHERE r.role_type = 'staff'
  AND (r.system_key IN ('admin', 'admin_plus')
    OR (r.system_key = 'accountant' AND p.permission_key = 'property.funds.post'))
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_property_funds_defaults_initialized');

INSERT IGNORE INTO app_meta (k, v)
VALUES ('rbac_property_funds_defaults_initialized', '1');
