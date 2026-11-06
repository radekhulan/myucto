SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nonprofit_tax_drafts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 revision_no INT UNSIGNED NOT NULL DEFAULT 1,
 source_input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 header_json LONGTEXT NOT NULL CHECK(JSON_VALID(header_json)),
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_ntd_owner(supplier_id,period_id,created_by),
 UNIQUE KEY uq_ntd_tenant(supplier_id,id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
 FOREIGN KEY(supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE CASCADE,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
 CHECK(revision_no>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_draft_activities (
 supplier_id INT UNSIGNED NOT NULL,
 draft_id BIGINT UNSIGNED NOT NULL,
 activity_kind_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_json LONGTEXT NOT NULL CHECK(JSON_VALID(payload_json)),
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,draft_id,activity_kind_id),
 FOREIGN KEY(supplier_id,draft_id) REFERENCES nonprofit_tax_drafts(supplier_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_draft_decisions (
 supplier_id INT UNSIGNED NOT NULL,
 draft_id BIGINT UNSIGNED NOT NULL,
 source_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_json LONGTEXT NOT NULL CHECK(JSON_VALID(payload_json)),
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,draft_id,source_id),
 FOREIGN KEY(supplier_id,draft_id) REFERENCES nonprofit_tax_drafts(supplier_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_draft_funding_pools (
 supplier_id INT UNSIGNED NOT NULL,
 draft_id BIGINT UNSIGNED NOT NULL,
 pool_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 anchor VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_json LONGTEXT NOT NULL CHECK(JSON_VALID(payload_json)),
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,draft_id,pool_kind,anchor),
 FOREIGN KEY(supplier_id,draft_id) REFERENCES nonprofit_tax_drafts(supplier_id,id) ON DELETE CASCADE,
 CHECK(pool_kind IN ('revenue','expense'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_draft_funding_members (
 supplier_id INT UNSIGNED NOT NULL,
 draft_id BIGINT UNSIGNED NOT NULL,
 pool_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 anchor VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 member_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,draft_id,pool_kind,anchor,member_id),
 KEY ix_ntdfm_member(supplier_id,draft_id,member_id),
 FOREIGN KEY(supplier_id,draft_id,pool_kind,anchor) REFERENCES nonprofit_tax_draft_funding_pools(supplier_id,draft_id,pool_kind,anchor) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_draft_funding_links (
 supplier_id INT UNSIGNED NOT NULL,
 draft_id BIGINT UNSIGNED NOT NULL,
 source_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cost_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_json LONGTEXT NOT NULL CHECK(JSON_VALID(payload_json)),
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,draft_id,source_id,cost_id),
 KEY ix_ntdfl_cost(supplier_id,draft_id,cost_id,source_id),
 FOREIGN KEY(supplier_id,draft_id) REFERENCES nonprofit_tax_drafts(supplier_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_calculation_chunks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 chunk_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 schema_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 chunk_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_count SMALLINT UNSIGNED NOT NULL,
 payload_json LONGTEXT NOT NULL CHECK(JSON_VALID(payload_json)),
 created_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_ntcc_tenant(supplier_id,id),
 UNIQUE KEY uq_ntcc_content(supplier_id,chunk_kind,schema_version,chunk_hash),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CHECK(chunk_kind IN ('decision','result','coverage','usage')),
 CHECK(row_count<=500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_calculation_manifests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 chunk_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 schema_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 calculation_input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 header_json LONGTEXT NOT NULL CHECK(JSON_VALID(header_json)),
 root_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_count BIGINT UNSIGNED NOT NULL,
 chunk_count INT UNSIGNED NOT NULL,
 summary_json LONGTEXT NOT NULL CHECK(JSON_VALID(summary_json)),
 created_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_ntcm_tenant(supplier_id,id),
 UNIQUE KEY uq_ntcm_content(supplier_id,chunk_kind,schema_version,root_hash),
 KEY ix_ntcm_input(supplier_id,period_id,calculation_input_hash,chunk_kind,id),
 KEY ix_ntcm_period(supplier_id,period_id,chunk_kind,id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 CHECK(chunk_kind IN ('decision','result','coverage','usage'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nonprofit_tax_calculation_manifests ADD COLUMN IF NOT EXISTS calculation_input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER schema_version;
ALTER TABLE nonprofit_tax_calculation_manifests ADD COLUMN IF NOT EXISTS header_json LONGTEXT NOT NULL CHECK(JSON_VALID(header_json)) AFTER calculation_input_hash;
ALTER TABLE nonprofit_tax_calculation_manifests ADD KEY IF NOT EXISTS ix_ntcm_input(supplier_id,period_id,calculation_input_hash,chunk_kind,id);

CREATE TABLE IF NOT EXISTS nonprofit_tax_calculation_manifest_chunks (
 supplier_id INT UNSIGNED NOT NULL,
 manifest_id BIGINT UNSIGNED NOT NULL,
 ordinal INT UNSIGNED NOT NULL,
 chunk_id BIGINT UNSIGNED NOT NULL,
 chunk_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,manifest_id,ordinal),
 KEY ix_ntcmc_chunk(supplier_id,chunk_id),
 FOREIGN KEY(supplier_id,manifest_id) REFERENCES nonprofit_tax_calculation_manifests(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,chunk_id) REFERENCES nonprofit_tax_calculation_chunks(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS storage_version VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy_json_v1' AFTER ruleset;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS source_manifest_id BIGINT UNSIGNED NULL AFTER storage_version;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS decision_manifest_id BIGINT UNSIGNED NULL AFTER source_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS result_manifest_id BIGINT UNSIGNED NULL AFTER decision_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS coverage_manifest_id BIGINT UNSIGNED NULL AFTER result_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS usage_manifest_id BIGINT UNSIGNED NULL AFTER coverage_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS calculation_input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER usage_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS result_root_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER calculation_input_hash;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS coverage_root_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER result_root_hash;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS summary_json LONGTEXT NULL CHECK(summary_json IS NULL OR JSON_VALID(summary_json)) AFTER coverage_root_hash;
ALTER TABLE nonprofit_tax_assessments ADD KEY IF NOT EXISTS ix_nta_confirmed_history(supplier_id,status,period_id,revision_no,id);
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_source_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,source_manifest_id) REFERENCES nonprofit_tax_snapshot_manifests(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_decision_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,decision_manifest_id) REFERENCES nonprofit_tax_calculation_manifests(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_result_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,result_manifest_id) REFERENCES nonprofit_tax_calculation_manifests(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_coverage_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,coverage_manifest_id) REFERENCES nonprofit_tax_calculation_manifests(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_usage_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,usage_manifest_id) REFERENCES nonprofit_tax_calculation_manifests(supplier_id,id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS nonprofit_tax_assessment_gift_elections (
 supplier_id INT UNSIGNED NOT NULL,
 assessment_id BIGINT UNSIGNED NOT NULL,
 origin_line_id BIGINT UNSIGNED NOT NULL,
 claim_exemption TINYINT(1) NOT NULL,
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,assessment_id,origin_line_id),
 KEY ix_ntage_origin(supplier_id,origin_line_id,assessment_id),
 FOREIGN KEY(supplier_id,assessment_id) REFERENCES nonprofit_tax_assessments(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_assessment_funding_usage (
 supplier_id INT UNSIGNED NOT NULL,
 assessment_id BIGINT UNSIGNED NOT NULL,
 anchor VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 net_source_cents BIGINT NOT NULL,
 prior_drawdown_cents BIGINT NOT NULL,
 drawdown_cents BIGINT NOT NULL,
 remaining_cents BIGINT NOT NULL,
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,assessment_id,anchor),
 FOREIGN KEY(supplier_id,assessment_id) REFERENCES nonprofit_tax_assessments(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_tax_assessment_funding_members (
 supplier_id INT UNSIGNED NOT NULL,
 assessment_id BIGINT UNSIGNED NOT NULL,
 anchor VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 member_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,assessment_id,anchor,member_id),
 KEY ix_ntafm_member(supplier_id,member_id,assessment_id),
 FOREIGN KEY(supplier_id,assessment_id,anchor) REFERENCES nonprofit_tax_assessment_funding_usage(supplier_id,assessment_id,anchor) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_ntcc_immutable_update;
DROP TRIGGER IF EXISTS trg_ntcc_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntcm_immutable_update;
DROP TRIGGER IF EXISTS trg_ntcm_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntcmc_immutable_update;
DROP TRIGGER IF EXISTS trg_ntcmc_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntage_immutable_update;
DROP TRIGGER IF EXISTS trg_ntage_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntafu_immutable_update;
DROP TRIGGER IF EXISTS trg_ntafu_immutable_delete;
DROP TRIGGER IF EXISTS trg_ntafm_immutable_update;
DROP TRIGGER IF EXISTS trg_ntafm_immutable_delete;

DELIMITER $$
CREATE TRIGGER trg_ntcc_immutable_update BEFORE UPDATE ON nonprofit_tax_calculation_chunks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_calculation_immutable'; END$$
CREATE TRIGGER trg_ntcc_immutable_delete BEFORE DELETE ON nonprofit_tax_calculation_chunks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_calculation_immutable'; END$$
CREATE TRIGGER trg_ntcm_immutable_update BEFORE UPDATE ON nonprofit_tax_calculation_manifests FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_calculation_immutable'; END$$
CREATE TRIGGER trg_ntcm_immutable_delete BEFORE DELETE ON nonprofit_tax_calculation_manifests FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_calculation_immutable'; END$$
CREATE TRIGGER trg_ntcmc_immutable_update BEFORE UPDATE ON nonprofit_tax_calculation_manifest_chunks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_calculation_immutable'; END$$
CREATE TRIGGER trg_ntcmc_immutable_delete BEFORE DELETE ON nonprofit_tax_calculation_manifest_chunks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_calculation_immutable'; END$$
CREATE TRIGGER trg_ntage_immutable_update BEFORE UPDATE ON nonprofit_tax_assessment_gift_elections FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_history_immutable'; END$$
CREATE TRIGGER trg_ntage_immutable_delete BEFORE DELETE ON nonprofit_tax_assessment_gift_elections FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_history_immutable'; END$$
CREATE TRIGGER trg_ntafu_immutable_update BEFORE UPDATE ON nonprofit_tax_assessment_funding_usage FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_history_immutable'; END$$
CREATE TRIGGER trg_ntafu_immutable_delete BEFORE DELETE ON nonprofit_tax_assessment_funding_usage FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_history_immutable'; END$$
CREATE TRIGGER trg_ntafm_immutable_update BEFORE UPDATE ON nonprofit_tax_assessment_funding_members FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_history_immutable'; END$$
CREATE TRIGGER trg_ntafm_immutable_delete BEFORE DELETE ON nonprofit_tax_assessment_funding_members FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_history_immutable'; END$$
DELIMITER ;
