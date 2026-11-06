-- MyÚčto — rozpouštění dohadů služeb SVJ jako samostatné právo.
--
-- Rozpuštění dohadu je běžný krok konečného zúčtování, ne storno. Dosud vyžadovalo
-- právo storna vyúčtování, které výchozí účetní nemá. Nové právo dostanou správci
-- a účetní; role, které měly právo storna vyúčtování, ho dostanou také.

SET NAMES utf8mb4;

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'property.settlements.unwind', 2
FROM roles r
WHERE r.role_type = 'staff'
  AND r.system_key IN ('admin', 'admin_plus', 'accountant')
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_property_settlements_unwind_initialized');

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT rp.role_id, 'property.settlements.unwind', rp.access_level
FROM role_permissions rp
WHERE rp.permission_key = 'property.settlements.reverse'
  AND rp.access_level > 0
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_property_settlements_unwind_initialized');

INSERT IGNORE INTO app_meta (k, v)
VALUES ('rbac_property_settlements_unwind_initialized', '1');
