SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS unit_charge_components (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 code VARCHAR(80) NOT NULL,
 name VARCHAR(190) NOT NULL,
 kind VARCHAR(32) NOT NULL,
 service_id BIGINT UNSIGNED NULL,
 account_code VARCHAR(32) NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_charge_component_tenant (supplier_id,id),
 UNIQUE KEY uq_charge_component_code (supplier_id,code),
 UNIQUE KEY uq_charge_component_request (supplier_id,idempotency_key),
 CONSTRAINT chk_charge_component_kind CHECK (kind IN ('long_term_advance','admin_advance','service_advance','contribution','other')),
 CONSTRAINT chk_charge_component_service CHECK ((kind='service_advance' AND service_id IS NOT NULL) OR (kind<>'service_advance' AND service_id IS NULL)),
 CONSTRAINT fk_charge_component_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_charge_component_service FOREIGN KEY (supplier_id,service_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_charge_component_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE unit_charge_components ADD COLUMN IF NOT EXISTS request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json));

CREATE TABLE IF NOT EXISTS unit_charge_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 house_id BIGINT UNSIGNED NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 effective_from DATETIME(6) NOT NULL,
 effective_to DATETIME(6) NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 preview_json LONGTEXT NOT NULL CHECK (JSON_VALID(preview_json)),
 preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_charge_version_tenant (supplier_id,id),
 UNIQUE KEY uq_charge_version_revision (supplier_id,house_id,revision_no),
 UNIQUE KEY uq_charge_version_request (supplier_id,idempotency_key),
 CONSTRAINT chk_charge_version_interval CHECK (effective_to IS NULL OR effective_to > effective_from),
 CONSTRAINT fk_charge_version_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_charge_version_house FOREIGN KEY (supplier_id,house_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_charge_version_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charge_rates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 version_id BIGINT UNSIGNED NOT NULL,
 component_id BIGINT UNSIGNED NOT NULL,
 rate_json LONGTEXT NOT NULL CHECK (JSON_VALID(rate_json)),
 calculation_json LONGTEXT NOT NULL CHECK (JSON_VALID(calculation_json)),
 UNIQUE KEY uq_charge_rate_component (supplier_id,version_id,component_id),
 CONSTRAINT fk_charge_rate_version FOREIGN KEY (supplier_id,version_id) REFERENCES unit_charge_versions(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_charge_rate_component FOREIGN KEY (supplier_id,component_id) REFERENCES unit_charge_components(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charge_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 action VARCHAR(20) NOT NULL,
 component_id BIGINT UNSIGNED NULL,
 version_id BIGINT UNSIGNED NULL,
 payload_json LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json)),
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 CONSTRAINT chk_charge_audit_action CHECK ((action='component' AND component_id IS NOT NULL AND version_id IS NULL) OR (action='version' AND component_id IS NULL AND version_id IS NOT NULL)),
 CONSTRAINT fk_charge_audit_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_charge_audit_component FOREIGN KEY (supplier_id,component_id) REFERENCES unit_charge_components(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_charge_audit_version FOREIGN KEY (supplier_id,version_id) REFERENCES unit_charge_versions(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_charge_audit_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER IF NOT EXISTS unit_charge_components_immutable_update BEFORE UPDATE ON unit_charge_components FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable property charge history';
CREATE TRIGGER IF NOT EXISTS unit_charge_components_immutable_delete BEFORE DELETE ON unit_charge_components FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable property charge history';
CREATE TRIGGER IF NOT EXISTS unit_charge_versions_immutable_update BEFORE UPDATE ON unit_charge_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable property charge history';
CREATE TRIGGER IF NOT EXISTS unit_charge_versions_immutable_delete BEFORE DELETE ON unit_charge_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable property charge history';
CREATE TRIGGER IF NOT EXISTS unit_charge_rates_immutable_update BEFORE UPDATE ON unit_charge_rates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable property charge history';
CREATE TRIGGER IF NOT EXISTS unit_charge_rates_immutable_delete BEFORE DELETE ON unit_charge_rates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable property charge history';
CREATE TRIGGER IF NOT EXISTS unit_charge_audit_immutable_update BEFORE UPDATE ON unit_charge_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable property charge history';
CREATE TRIGGER IF NOT EXISTS unit_charge_audit_immutable_delete BEFORE DELETE ON unit_charge_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable property charge history';

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, 2
FROM roles r
JOIN (
    SELECT 'property.charges.manage' AS permission_key
    UNION ALL SELECT 'property.charges.confirm'
) p
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant')
    AND (r.system_key <> 'accountant' OR p.permission_key <> 'property.charges.confirm');
