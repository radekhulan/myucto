SET NAMES utf8mb4;

ALTER TABLE nonprofit_tax_calculation_manifests ADD COLUMN IF NOT EXISTS calculation_input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER schema_version;
ALTER TABLE nonprofit_tax_calculation_manifests ADD COLUMN IF NOT EXISTS header_json LONGTEXT NOT NULL CHECK(JSON_VALID(header_json)) AFTER calculation_input_hash;
ALTER TABLE nonprofit_tax_calculation_manifests ADD KEY IF NOT EXISTS ix_ntcm_input(supplier_id,period_id,calculation_input_hash,chunk_kind,id);

ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS usage_manifest_id BIGINT UNSIGNED NULL AFTER coverage_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_usage_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,usage_manifest_id) REFERENCES nonprofit_tax_calculation_manifests(supplier_id,id) ON DELETE RESTRICT;
