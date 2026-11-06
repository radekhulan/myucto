SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nonprofit_tax_snapshot_chunks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 schema_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 chunk_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_count SMALLINT UNSIGNED NOT NULL,
 first_cursor_json VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL CHECK(first_cursor_json IS NULL OR JSON_VALID(first_cursor_json)),
 last_cursor_json VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL CHECK(last_cursor_json IS NULL OR JSON_VALID(last_cursor_json)),
 payload_json LONGTEXT NOT NULL CHECK(JSON_VALID(payload_json)),
 created_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_ntsc_tenant(supplier_id,id),
 UNIQUE KEY uq_ntsc_content(supplier_id,schema_version,chunk_hash),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CHECK(row_count<=500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_snapshot_manifests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 schema_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ruleset VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 header_json LONGTEXT NOT NULL CHECK(JSON_VALID(header_json)),
 header_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 root_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_count BIGINT UNSIGNED NOT NULL,
 finding_count BIGINT UNSIGNED NOT NULL,
 chunk_count INT UNSIGNED NOT NULL,
 membership_fact_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 captured_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_ntsm_tenant(supplier_id,id),
 UNIQUE KEY uq_ntsm_content(supplier_id,schema_version,input_hash),
 KEY ix_ntsm_period(supplier_id,period_id,id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_snapshot_manifest_chunks (
 supplier_id INT UNSIGNED NOT NULL,
 manifest_id BIGINT UNSIGNED NOT NULL,
 ordinal INT UNSIGNED NOT NULL,
 chunk_id BIGINT UNSIGNED NOT NULL,
 chunk_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,manifest_id,ordinal),
 KEY ix_ntsmc_chunk(supplier_id,chunk_id),
 FOREIGN KEY(supplier_id,manifest_id) REFERENCES nonprofit_tax_snapshot_manifests(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,chunk_id) REFERENCES nonprofit_tax_snapshot_chunks(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_ntsc_immutable_update;
DROP TRIGGER IF EXISTS trg_ntsc_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntsm_immutable_update;
DROP TRIGGER IF EXISTS trg_ntsm_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntsmc_immutable_update;
DROP TRIGGER IF EXISTS trg_ntsmc_immutable_delete;

DELIMITER $$
CREATE TRIGGER trg_ntsc_immutable_update BEFORE UPDATE ON nonprofit_tax_snapshot_chunks FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_snapshot_immutable'; END$$
CREATE TRIGGER trg_ntsc_immutable_delete BEFORE DELETE ON nonprofit_tax_snapshot_chunks FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_snapshot_immutable'; END$$
CREATE TRIGGER trg_ntsm_immutable_update BEFORE UPDATE ON nonprofit_tax_snapshot_manifests FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_snapshot_immutable'; END$$
CREATE TRIGGER trg_ntsm_immutable_delete BEFORE DELETE ON nonprofit_tax_snapshot_manifests FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_snapshot_immutable'; END$$
CREATE TRIGGER trg_ntsmc_immutable_update BEFORE UPDATE ON nonprofit_tax_snapshot_manifest_chunks FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_snapshot_immutable'; END$$
CREATE TRIGGER trg_ntsmc_immutable_delete BEFORE DELETE ON nonprofit_tax_snapshot_manifest_chunks FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_snapshot_immutable'; END$$
DELIMITER ;

