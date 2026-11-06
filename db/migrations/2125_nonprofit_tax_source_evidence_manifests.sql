SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nonprofit_tax_source_evidence_chunks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 evidence_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 schema_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 chunk_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_count SMALLINT UNSIGNED NOT NULL,
 payload_json LONGTEXT NOT NULL CHECK(JSON_VALID(payload_json)),
 created_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_ntsec_tenant(supplier_id,id),
 UNIQUE KEY uq_ntsec_content(supplier_id,evidence_kind,schema_version,chunk_hash),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CHECK(evidence_kind IN ('funding_reference','funding_history')),
 CHECK(row_count<=500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_source_evidence_manifests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 evidence_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 schema_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 header_json LONGTEXT NOT NULL CHECK(JSON_VALID(header_json)),
 root_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_count BIGINT UNSIGNED NOT NULL,
 chunk_count INT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_ntsem_tenant(supplier_id,id),
 UNIQUE KEY uq_ntsem_content(supplier_id,evidence_kind,schema_version,root_hash),
 KEY ix_ntsem_source(supplier_id,period_id,source_input_hash,evidence_kind,id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 CHECK(evidence_kind IN ('funding_reference','funding_history'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_source_evidence_manifest_chunks (
 supplier_id INT UNSIGNED NOT NULL,
 manifest_id BIGINT UNSIGNED NOT NULL,
 ordinal INT UNSIGNED NOT NULL,
 chunk_id BIGINT UNSIGNED NOT NULL,
 chunk_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,manifest_id,ordinal),
 KEY ix_ntsemc_chunk(supplier_id,chunk_id),
 FOREIGN KEY(supplier_id,manifest_id) REFERENCES nonprofit_tax_source_evidence_manifests(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,chunk_id) REFERENCES nonprofit_tax_source_evidence_chunks(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS funding_reference_manifest_id BIGINT UNSIGNED NULL AFTER source_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS funding_history_manifest_id BIGINT UNSIGNED NULL AFTER funding_reference_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_funding_reference_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,funding_reference_manifest_id) REFERENCES nonprofit_tax_source_evidence_manifests(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_funding_history_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,funding_history_manifest_id) REFERENCES nonprofit_tax_source_evidence_manifests(supplier_id,id) ON DELETE RESTRICT;

DROP TRIGGER IF EXISTS trg_ntsec_immutable_update;
DROP TRIGGER IF EXISTS trg_ntsec_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntsem_immutable_update;
DROP TRIGGER IF EXISTS trg_ntsem_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntsemc_immutable_update;
DROP TRIGGER IF EXISTS trg_ntsemc_immutable_delete;

DELIMITER $$
CREATE TRIGGER trg_ntsec_immutable_update BEFORE UPDATE ON nonprofit_tax_source_evidence_chunks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_source_evidence_immutable'; END$$
CREATE TRIGGER trg_ntsec_immutable_delete BEFORE DELETE ON nonprofit_tax_source_evidence_chunks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_source_evidence_immutable'; END$$
CREATE TRIGGER trg_ntsem_immutable_update BEFORE UPDATE ON nonprofit_tax_source_evidence_manifests FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_source_evidence_immutable'; END$$
CREATE TRIGGER trg_ntsem_immutable_delete BEFORE DELETE ON nonprofit_tax_source_evidence_manifests FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_source_evidence_immutable'; END$$
CREATE TRIGGER trg_ntsemc_immutable_update BEFORE UPDATE ON nonprofit_tax_source_evidence_manifest_chunks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_source_evidence_immutable'; END$$
CREATE TRIGGER trg_ntsemc_immutable_delete BEFORE DELETE ON nonprofit_tax_source_evidence_manifest_chunks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_source_evidence_immutable'; END$$
DELIMITER ;
