-- Výchozí práva vyúčtování služeb SVJ. Jednorázový marker zachová pozdější odebrání
-- práva v editoru rolí i při opakovaném běhu migrace.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, 2 FROM roles r
JOIN (SELECT 'property.settlements.manage' AS permission_key UNION ALL SELECT 'property.settlements.post' UNION ALL SELECT 'property.settlements.reverse') p
WHERE r.system_key IN ('admin','admin_plus','accountant')
AND (r.system_key <> 'accountant' OR p.permission_key <> 'property.settlements.reverse')
AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_property_settlements_defaults_initialized');

INSERT IGNORE INTO app_meta (k, v)
VALUES ('rbac_property_settlements_defaults_initialized', '1');
