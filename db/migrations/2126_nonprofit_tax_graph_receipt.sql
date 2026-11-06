SET NAMES utf8mb4;

ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS j6_receipt_json LONGTEXT NULL CHECK(j6_receipt_json IS NULL OR JSON_VALID(j6_receipt_json)) AFTER summary_json;
ALTER TABLE nonprofit_tax_assessments ADD KEY IF NOT EXISTS ix_nta_paged_history(supplier_id,storage_version,period_id,revision_no,id);
