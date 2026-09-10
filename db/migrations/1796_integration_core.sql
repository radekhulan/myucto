SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS integration_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_uuid CHAR(36) NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    connector_key VARCHAR(80) NOT NULL,
    name VARCHAR(150) NOT NULL,
    status ENUM('draft','active','paused','error') NOT NULL DEFAULT 'draft',
    mappings_json LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(mappings_json)),
    field_ownership_json LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(field_ownership_json)),
    credentials_enc MEDIUMTEXT NULL,
    webhook_secret_enc TEXT NULL,
    webhook_secret_hash CHAR(64) NULL,
    rate_limit_per_minute SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    last_synced_at DATETIME(6) NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_at DATETIME(6) NULL,
    last_reconcile_enqueued_at DATETIME(6) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_integration_connection_uuid (connection_uuid),
    UNIQUE KEY uq_integration_connection_name (supplier_id, name),
    UNIQUE KEY uq_integration_connection_tenant (supplier_id, id),
    KEY ix_integration_connection_status (supplier_id, status),
    CONSTRAINT fk_integration_connection_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT chk_integration_connection_rate CHECK (rate_limit_per_minute BETWEEN 1 AND 6000),
    CONSTRAINT chk_integration_connection_retention CHECK (retention_days BETWEEN 1 AND 365)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT id, 'eshop.integrations', 2 FROM roles WHERE system_key IN ('admin', 'admin_plus');

ALTER TABLE external_entity_map
    ADD COLUMN IF NOT EXISTS connection_id BIGINT UNSIGNED NULL AFTER supplier_id,
    ADD COLUMN IF NOT EXISTS external_parent_id VARCHAR(255) COLLATE utf8mb4_bin NULL AFTER external_id,
    ADD COLUMN IF NOT EXISTS internal_sub_id VARCHAR(255) COLLATE utf8mb4_bin NULL AFTER internal_id,
    ADD COLUMN IF NOT EXISTS external_version BIGINT UNSIGNED NULL AFTER internal_sub_id,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER created_at,
    ADD COLUMN IF NOT EXISTS connection_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(connection_id, 0)) STORED AFTER connection_id;

ALTER TABLE external_entity_map
    ADD KEY IF NOT EXISTS ix_external_entity_connection (supplier_id, connection_id),
    ADD UNIQUE KEY IF NOT EXISTS uq_external_entity_connection_identity
        (supplier_id, connection_scope_id, source_key, entity_type, external_id),
    DROP INDEX IF EXISTS uq_external_entity_identity;

ALTER TABLE external_entity_map
    DROP FOREIGN KEY IF EXISTS fk_external_entity_connection;
ALTER TABLE external_entity_map
    ADD UNIQUE KEY IF NOT EXISTS uq_external_entity_connection_lookup
        (supplier_id, connection_id, entity_type, external_id);
ALTER TABLE external_entity_map
    ADD CONSTRAINT fk_external_entity_connection FOREIGN KEY (supplier_id, connection_id)
        REFERENCES integration_connections(supplier_id, id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS integration_change_state (
    supplier_id INT UNSIGNED NOT NULL,
    last_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    retention_floor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (supplier_id),
    CONSTRAINT fk_integration_change_state_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT chk_integration_change_state_floor CHECK (retention_floor <= last_cursor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_change_log (
    cursor_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(40) NOT NULL DEFAULT 'stock_item',
    entity_id BIGINT UNSIGNED NOT NULL,
    change_type ENUM('upsert','tombstone') NOT NULL,
    source_area ENUM('product','price','media','i18n','availability','reservation') NOT NULL,
    occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (supplier_id, cursor_id),
    KEY ix_integration_changes_entity (supplier_id, entity_type, entity_id, cursor_id),
    KEY ix_integration_changes_retention (supplier_id, occurred_at),
    CONSTRAINT fk_integration_changes_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE integration_change_log
    MODIFY COLUMN cursor_id BIGINT UNSIGNED NOT NULL,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (supplier_id, cursor_id);

INSERT INTO integration_change_state (supplier_id, last_cursor)
SELECT supplier_id, MAX(cursor_id) FROM integration_change_log GROUP BY supplier_id
ON DUPLICATE KEY UPDATE last_cursor = GREATEST(last_cursor, VALUES(last_cursor));

CREATE TABLE IF NOT EXISTS integration_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_uuid CHAR(36) NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    aggregate_version BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    payload_json LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json)),
    payload_sha256 CHAR(64) NOT NULL,
    status ENUM('pending','processing','retry','delivered','dead_letter') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME(6) NULL,
    available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    lease_token CHAR(64) NULL,
    lease_until DATETIME(6) NULL,
    last_error_code VARCHAR(100) NULL,
    payload_redacted_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    delivered_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_integration_outbox_uuid (event_uuid),
    UNIQUE KEY uq_integration_outbox_idempotency (connection_id, idempotency_key),
    UNIQUE KEY uq_integration_outbox_version (connection_id, entity_type, entity_id, aggregate_version, event_type),
    KEY ix_integration_outbox_claim (connection_id, status, available_at, id),
    KEY ix_integration_outbox_rate (connection_id, last_attempt_at),
    CONSTRAINT fk_integration_outbox_connection FOREIGN KEY (supplier_id, connection_id)
        REFERENCES integration_connections(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_inbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    external_event_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    aggregate_version BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json)),
    payload_sha256 CHAR(64) NOT NULL,
    status ENUM('queued','processing','processed','retry','dead_letter','ignored') NOT NULL DEFAULT 'queued',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_error_code VARCHAR(100) NULL,
    payload_redacted_at DATETIME(6) NULL,
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    processed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_integration_inbox_event (connection_id, external_event_id),
    KEY ix_integration_inbox_claim (connection_id, status, available_at, id),
    KEY ix_integration_inbox_order (connection_id, entity_type, entity_id, aggregate_version),
    CONSTRAINT fk_integration_inbox_connection FOREIGN KEY (supplier_id, connection_id)
        REFERENCES integration_connections(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_inbox_state (
    supplier_id INT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    aggregate_version BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (connection_id, entity_type, entity_id),
    CONSTRAINT fk_integration_inbox_state_connection FOREIGN KEY (supplier_id, connection_id)
        REFERENCES integration_connections(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE integration_outbox ADD COLUMN IF NOT EXISTS payload_redacted_at DATETIME(6) NULL;
ALTER TABLE integration_inbox ADD COLUMN IF NOT EXISTS payload_redacted_at DATETIME(6) NULL;

DROP TRIGGER IF EXISTS trg_integration_stock_item_insert;
DROP TRIGGER IF EXISTS trg_integration_stock_item_update;
DROP TRIGGER IF EXISTS trg_integration_stock_item_delete;
DROP TRIGGER IF EXISTS trg_integration_stock_price_insert;
DROP TRIGGER IF EXISTS trg_integration_stock_price_update;
DROP TRIGGER IF EXISTS trg_integration_stock_price_delete;
DROP TRIGGER IF EXISTS trg_integration_stock_i18n_insert;
DROP TRIGGER IF EXISTS trg_integration_stock_i18n_update;
DROP TRIGGER IF EXISTS trg_integration_stock_i18n_delete;
DROP TRIGGER IF EXISTS trg_integration_stock_media_insert;
DROP TRIGGER IF EXISTS trg_integration_stock_media_update;
DROP TRIGGER IF EXISTS trg_integration_stock_media_delete;
DROP TRIGGER IF EXISTS trg_integration_stock_level_insert;
DROP TRIGGER IF EXISTS trg_integration_stock_level_update;
DROP TRIGGER IF EXISTS trg_integration_stock_level_delete;
DROP TRIGGER IF EXISTS trg_integration_change_allocate;

DELIMITER $$
CREATE TRIGGER trg_integration_change_allocate BEFORE INSERT ON integration_change_log FOR EACH ROW
BEGIN
    DECLARE allocated_cursor BIGINT UNSIGNED;
    INSERT INTO integration_change_state (supplier_id, last_cursor, retention_floor)
        VALUES (NEW.supplier_id, 0, 0)
        ON DUPLICATE KEY UPDATE supplier_id = VALUES(supplier_id);
    UPDATE integration_change_state SET last_cursor = last_cursor + 1 WHERE supplier_id = NEW.supplier_id;
    SELECT last_cursor INTO allocated_cursor FROM integration_change_state WHERE supplier_id = NEW.supplier_id;
    SET NEW.cursor_id = allocated_cursor;
END$$
CREATE TRIGGER trg_integration_stock_item_insert AFTER INSERT ON stock_items FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.id, IF(NEW.is_active = 0 OR NEW.lifecycle_status = 'retired', 'tombstone', 'upsert'), 'product'); END$$
CREATE TRIGGER trg_integration_stock_item_update AFTER UPDATE ON stock_items FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.id, IF(NEW.is_active = 0 OR NEW.lifecycle_status = 'retired', 'tombstone', 'upsert'), 'product'); END$$
CREATE TRIGGER trg_integration_stock_item_delete AFTER DELETE ON stock_items FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (OLD.supplier_id, OLD.id, 'tombstone', 'product'); END$$
CREATE TRIGGER trg_integration_stock_price_insert AFTER INSERT ON stock_item_prices FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.stock_item_id, 'upsert', 'price'); END$$
CREATE TRIGGER trg_integration_stock_price_update AFTER UPDATE ON stock_item_prices FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.stock_item_id, 'upsert', 'price'); END$$
CREATE TRIGGER trg_integration_stock_price_delete AFTER DELETE ON stock_item_prices FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (OLD.supplier_id, OLD.stock_item_id, 'upsert', 'price'); END$$
CREATE TRIGGER trg_integration_stock_i18n_insert AFTER INSERT ON stock_item_i18n FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.stock_item_id, 'upsert', 'i18n'); END$$
CREATE TRIGGER trg_integration_stock_i18n_update AFTER UPDATE ON stock_item_i18n FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.stock_item_id, 'upsert', 'i18n'); END$$
CREATE TRIGGER trg_integration_stock_i18n_delete AFTER DELETE ON stock_item_i18n FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (OLD.supplier_id, OLD.stock_item_id, 'upsert', 'i18n'); END$$
CREATE TRIGGER trg_integration_stock_media_insert AFTER INSERT ON stock_media FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.stock_item_id, 'upsert', 'media'); END$$
CREATE TRIGGER trg_integration_stock_media_update AFTER UPDATE ON stock_media FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.stock_item_id, 'upsert', 'media'); END$$
CREATE TRIGGER trg_integration_stock_media_delete AFTER DELETE ON stock_media FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (OLD.supplier_id, OLD.stock_item_id, 'upsert', 'media'); END$$
CREATE TRIGGER trg_integration_stock_level_insert AFTER INSERT ON stock_levels FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.stock_item_id, 'upsert', 'availability'); END$$
CREATE TRIGGER trg_integration_stock_level_update AFTER UPDATE ON stock_levels FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (NEW.supplier_id, NEW.stock_item_id, 'upsert', 'availability'); END$$
CREATE TRIGGER trg_integration_stock_level_delete AFTER DELETE ON stock_levels FOR EACH ROW
BEGIN INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (OLD.supplier_id, OLD.stock_item_id, 'upsert', 'availability'); END$$
DELIMITER ;
