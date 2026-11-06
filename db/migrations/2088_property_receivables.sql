SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS property_claim_party_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 registry_revision_id BIGINT UNSIGNED NOT NULL,
 effective_at DATETIME(6) NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 snapshot_json LONGTEXT NOT NULL CHECK(JSON_VALID(snapshot_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_property_party_event_tenant(supplier_id,id),
 UNIQUE KEY uq_property_party_event_token(supplier_id,idempotency_key),
 CONSTRAINT fk_property_party_event_revision FOREIGN KEY(supplier_id,unit_id,registry_revision_id) REFERENCES property_revisions(supplier_id,object_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_party_event_actor FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_claim_party_event_debts (
 supplier_id INT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NOT NULL,
 debt_id BIGINT UNSIGNED NOT NULL,
 predecessor_event_id BIGINT UNSIGNED NULL,
 remaining_cents DECIMAL(30,0) NOT NULL CHECK(remaining_cents>=0),
 PRIMARY KEY(event_id,debt_id),
 UNIQUE KEY uq_property_party_event_debt(supplier_id,event_id,debt_id),
 CONSTRAINT fk_property_party_debt_event FOREIGN KEY(supplier_id,event_id) REFERENCES property_claim_party_events(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_party_debt_source FOREIGN KEY(supplier_id,debt_id) REFERENCES unit_payment_debts(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_party_debt_predecessor FOREIGN KEY(supplier_id,predecessor_event_id,debt_id) REFERENCES property_claim_party_event_debts(supplier_id,event_id,debt_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_claim_party_members (
 supplier_id INT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NOT NULL,
 debt_id BIGINT UNSIGNED NOT NULL,
 group_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role VARCHAR(16) NOT NULL CHECK(role IN ('debtor','guarantor')),
 client_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(event_id,debt_id,group_key,client_id),
 CONSTRAINT fk_property_party_member_event FOREIGN KEY(supplier_id,event_id,debt_id) REFERENCES property_claim_party_event_debts(supplier_id,event_id,debt_id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_party_member_client FOREIGN KEY(supplier_id,client_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_debt_certificates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 as_of DATETIME(6) NOT NULL,
 known_at DATETIME(6) NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 snapshot_json LONGTEXT NOT NULL CHECK(JSON_VALID(snapshot_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_property_certificate_tenant(supplier_id,id),
 UNIQUE KEY uq_property_certificate_token(supplier_id,idempotency_key),
 CONSTRAINT fk_property_certificate_unit FOREIGN KEY(supplier_id,unit_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_certificate_actor FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE TRIGGER trg_property_party_event_no_update BEFORE UPDATE ON property_claim_party_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property party history is immutable'; END$$
CREATE OR REPLACE TRIGGER trg_property_party_event_no_delete BEFORE DELETE ON property_claim_party_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property party history is immutable'; END$$
CREATE OR REPLACE TRIGGER trg_property_party_debt_no_update BEFORE UPDATE ON property_claim_party_event_debts FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property party history is immutable'; END$$
CREATE OR REPLACE TRIGGER trg_property_party_debt_no_delete BEFORE DELETE ON property_claim_party_event_debts FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property party history is immutable'; END$$
CREATE OR REPLACE TRIGGER trg_property_party_member_no_update BEFORE UPDATE ON property_claim_party_members FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property party history is immutable'; END$$
CREATE OR REPLACE TRIGGER trg_property_party_member_no_delete BEFORE DELETE ON property_claim_party_members FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property party history is immutable'; END$$
CREATE OR REPLACE TRIGGER trg_property_certificate_no_update BEFORE UPDATE ON property_debt_certificates FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property certificate is immutable'; END$$
CREATE OR REPLACE TRIGGER trg_property_certificate_no_delete BEFORE DELETE ON property_debt_certificates FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property certificate is immutable'; END$$
DELIMITER ;
