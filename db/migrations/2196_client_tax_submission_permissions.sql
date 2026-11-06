-- Správa nastavení podání se odděluje od samotného odesílání klientem.
-- Výchozí interní role zachovají dosavadní správu; vlastní role dál používají
-- původní reports.submit. Marker chrání i pozdější výslovné odebrání práva.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'reports.submissions.manage', 2
FROM roles r
WHERE r.role_type = 'staff'
  AND r.system_key IN ('admin', 'admin_plus', 'accountant')
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_tax_submission_defaults_initialized');

INSERT IGNORE INTO app_meta (k, v)
VALUES ('rbac_tax_submission_defaults_initialized', '1');
