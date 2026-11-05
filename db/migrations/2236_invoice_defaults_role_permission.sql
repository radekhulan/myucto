-- Provozní výchozí hodnoty dokladů se oddělují od široké správy firmy.
-- Jen stávající interní role s právem zápisu firmy; klient a běžná účetní jsou opt-in.
-- INSERT IGNORE zachová výslovný zákaz. Marker zabrání obnovení později odebraného grantu.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'settings.invoice_defaults', 2
FROM roles r
JOIN role_permissions source ON source.role_id = r.id
WHERE r.role_type = 'staff'
  AND source.permission_key = 'settings.company.write'
  AND source.access_level = 2
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_invoice_defaults_initialized');

INSERT IGNORE INTO app_meta (k, v)
VALUES ('rbac_invoice_defaults_initialized', '1');
