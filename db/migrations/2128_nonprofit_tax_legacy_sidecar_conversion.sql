SET NAMES utf8mb4;

ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS legacy_storage_version VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER j6_receipt_json;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS legacy_input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER legacy_storage_version;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS legacy_snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER legacy_input_hash;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS legacy_assessment_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER legacy_snapshot_sha256;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS legacy_result_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER legacy_assessment_sha256;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS sidecar_converted_at_utc DATETIME(6) NULL AFTER legacy_result_sha256;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS sidecar_converted_by BIGINT UNSIGNED NULL AFTER sidecar_converted_at_utc;
ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS sidecar_conversion_reason VARCHAR(500) NULL AFTER sidecar_converted_by;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT IF NOT EXISTS chk_nta_legacy_sidecar_complete CHECK(
    (legacy_storage_version IS NULL AND legacy_input_hash IS NULL AND legacy_snapshot_sha256 IS NULL
        AND legacy_assessment_sha256 IS NULL AND legacy_result_sha256 IS NULL AND sidecar_converted_at_utc IS NULL
        AND sidecar_converted_by IS NULL AND sidecar_conversion_reason IS NULL)
    OR
    (legacy_storage_version IS NOT NULL AND legacy_input_hash IS NOT NULL AND legacy_snapshot_sha256 IS NOT NULL
        AND legacy_assessment_sha256 IS NOT NULL AND legacy_result_sha256 IS NOT NULL AND sidecar_converted_at_utc IS NOT NULL
        AND sidecar_converted_by IS NOT NULL AND sidecar_conversion_reason IS NOT NULL)
);
