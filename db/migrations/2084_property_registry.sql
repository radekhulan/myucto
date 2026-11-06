SET NAMES utf8mb4;

ALTER TABLE clients ADD UNIQUE KEY IF NOT EXISTS uq_property_client_tenant (supplier_id, id);
ALTER TABLE dimension_values ADD UNIQUE KEY IF NOT EXISTS uq_property_dimension_tenant (supplier_id, id);

CREATE TABLE IF NOT EXISTS property_module_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    effective_to DATETIME(6) NULL,
    enabled TINYINT(1) NOT NULL,
    reason TEXT NOT NULL,
    title_ref TEXT NOT NULL,
    idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_property_profile_revision (supplier_id, revision_no),
    UNIQUE KEY uq_property_profile_request (supplier_id, idempotency_key),
    CONSTRAINT chk_property_profile_interval CHECK (effective_to IS NULL OR effective_to > effective_from),
    CONSTRAINT chk_property_profile_enabled CHECK (enabled IN (0,1)),
    CONSTRAINT fk_property_profile_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_property_profile_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_objects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(80) NOT NULL,
    dimension_value_id BIGINT UNSIGNED NULL,
    current_revision INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_property_object_tenant (supplier_id, id),
    UNIQUE KEY uq_property_object_code (supplier_id, kind, code),
    UNIQUE KEY uq_property_object_dimension (dimension_value_id),
    CONSTRAINT chk_property_object_kind CHECK (kind IN ('house','unit','service')),
    CONSTRAINT chk_property_object_parent CHECK ((kind = 'unit' AND parent_id IS NOT NULL) OR (kind <> 'unit' AND parent_id IS NULL)),
    CONSTRAINT fk_property_object_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_property_object_parent FOREIGN KEY (supplier_id, parent_id) REFERENCES property_objects(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_property_object_dimension FOREIGN KEY (supplier_id, dimension_value_id) REFERENCES dimension_values(supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    effective_to DATETIME(6) NULL,
    resolves_revision_id BIGINT UNSIGNED NULL,
    state_json LONGTEXT NOT NULL CHECK (JSON_VALID(state_json)),
    state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason TEXT NOT NULL,
    title_ref TEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_property_revision_no (supplier_id, object_id, revision_no),
    UNIQUE KEY uq_property_revision_request (supplier_id, idempotency_key),
    UNIQUE KEY uq_property_revision_tenant (supplier_id, id),
    UNIQUE KEY uq_property_revision_object (supplier_id, object_id, id),
    UNIQUE KEY uq_property_revision_resolution (resolves_revision_id),
    KEY idx_property_revision_interval (supplier_id, object_id, effective_from, effective_to),
    CONSTRAINT chk_property_revision_interval CHECK (effective_to IS NULL OR effective_to > effective_from),
    CONSTRAINT chk_property_revision_status CHECK (status IN ('pending','confirmed')),
    CONSTRAINT fk_property_revision_object FOREIGN KEY (supplier_id, object_id) REFERENCES property_objects(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_property_revision_resolution FOREIGN KEY (supplier_id, object_id, resolves_revision_id) REFERENCES property_revisions(supplier_id, object_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_property_revision_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_revision_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    group_key VARCHAR(80) NOT NULL,
    kind VARCHAR(24) NOT NULL,
    share_num VARCHAR(18) CHARACTER SET ascii NOT NULL,
    share_den VARCHAR(18) CHARACTER SET ascii NOT NULL,
    title_ref TEXT NOT NULL,
    UNIQUE KEY uq_property_group_key (revision_id, group_key),
    UNIQUE KEY uq_property_group_tenant (supplier_id, id),
    CONSTRAINT chk_property_group_kind CHECK (kind IN ('single','coownership_share','marital_community')),
    CONSTRAINT fk_property_group_revision FOREIGN KEY (supplier_id, revision_id) REFERENCES property_revisions(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_revision_members (
    supplier_id INT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, client_id),
    CONSTRAINT fk_property_member_group FOREIGN KEY (supplier_id, group_id) REFERENCES property_revision_groups(supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_property_member_client FOREIGN KEY (supplier_id, client_id) REFERENCES clients(supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_revision_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(24) NOT NULL,
    scopes_json LONGTEXT NOT NULL CHECK (JSON_VALID(scopes_json)),
    title_ref TEXT NOT NULL,
    UNIQUE KEY uq_property_role (revision_id, client_id, role),
    CONSTRAINT chk_property_role CHECK (role IN ('payer','representative','delivery')),
    CONSTRAINT fk_property_role_revision FOREIGN KEY (supplier_id, revision_id) REFERENCES property_revisions(supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_property_role_client FOREIGN KEY (supplier_id, client_id) REFERENCES clients(supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_revision_identifiers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL,
    value VARCHAR(190) NOT NULL,
    payment_scope VARCHAR(80) NOT NULL,
    source VARCHAR(16) NOT NULL,
    title_ref TEXT NOT NULL,
    KEY idx_property_identifier_lookup (supplier_id, kind, payment_scope, value),
    CONSTRAINT fk_property_identifier_revision FOREIGN KEY (supplier_id, revision_id) REFERENCES property_revisions(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_revision_services (
    supplier_id INT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (revision_id, service_id),
    CONSTRAINT fk_property_service_revision FOREIGN KEY (supplier_id, revision_id) REFERENCES property_revisions(supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_property_service_object FOREIGN KEY (supplier_id, service_id) REFERENCES property_objects(supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    object_id BIGINT UNSIGNED NULL,
    revision_id BIGINT UNSIGNED NULL,
    action VARCHAR(32) NOT NULL,
    payload_json LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json)),
    created_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    KEY idx_property_audit_object (supplier_id, object_id, id),
    CONSTRAINT fk_property_audit_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_property_audit_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE TRIGGER trg_property_object_identity
BEFORE UPDATE ON property_objects FOR EACH ROW
BEGIN
    IF NOT (NEW.supplier_id <=> OLD.supplier_id) OR NOT (NEW.kind <=> OLD.kind)
       OR NOT (NEW.parent_id <=> OLD.parent_id) OR NOT (NEW.code <=> OLD.code)
       OR (OLD.dimension_value_id IS NOT NULL AND NOT (NEW.dimension_value_id <=> OLD.dimension_value_id))
       OR NEW.current_revision < OLD.current_revision OR NEW.current_revision > OLD.current_revision + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_identity_immutable';
    END IF;
END$$
CREATE OR REPLACE TRIGGER trg_pr_module_profiles_update
BEFORE UPDATE ON property_module_profiles FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_module_profiles_delete
BEFORE DELETE ON property_module_profiles FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revisions_update
BEFORE UPDATE ON property_revisions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revisions_delete
BEFORE DELETE ON property_revisions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_groups_update
BEFORE UPDATE ON property_revision_groups FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_groups_delete
BEFORE DELETE ON property_revision_groups FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_members_update
BEFORE UPDATE ON property_revision_members FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_members_delete
BEFORE DELETE ON property_revision_members FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_roles_update
BEFORE UPDATE ON property_revision_roles FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_roles_delete
BEFORE DELETE ON property_revision_roles FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_identifiers_update
BEFORE UPDATE ON property_revision_identifiers FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_identifiers_delete
BEFORE DELETE ON property_revision_identifiers FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_services_update
BEFORE UPDATE ON property_revision_services FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_revision_services_delete
BEFORE DELETE ON property_revision_services FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_audit_update
BEFORE UPDATE ON property_audit FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_pr_audit_delete
BEFORE DELETE ON property_audit FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_history_immutable';
END$$
DELIMITER ;

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key,
    CASE WHEN r.system_key = 'readonly' OR (r.system_key = 'accountant' AND p.permission_key = 'property.export') THEN 1 ELSE 2 END
FROM roles r
JOIN (
    SELECT 'property' AS permission_key
    UNION ALL SELECT 'property.registry.manage'
    UNION ALL SELECT 'property.registry.confirm'
    UNION ALL SELECT 'property.export'
) p
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant', 'readonly')
    AND (r.system_key <> 'readonly' OR p.permission_key = 'property')
    AND (r.system_key <> 'accountant' OR p.permission_key <> 'property.registry.confirm');

