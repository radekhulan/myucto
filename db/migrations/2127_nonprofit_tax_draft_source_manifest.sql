SET NAMES utf8mb4;

ALTER TABLE nonprofit_tax_drafts
 ADD COLUMN IF NOT EXISTS source_manifest_id BIGINT UNSIGNED NULL AFTER period_id,
 ADD KEY IF NOT EXISTS ix_ntd_source_manifest(supplier_id,source_manifest_id),
 ADD CONSTRAINT fk_ntd_source_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,source_manifest_id)
  REFERENCES nonprofit_tax_snapshot_manifests(supplier_id,id) ON DELETE RESTRICT;
