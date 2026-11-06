SET NAMES utf8mb4;

ALTER TABLE tax_losses
    ADD COLUMN IF NOT EXISTS origin_starts_on DATE NULL AFTER origin_year,
    ADD COLUMN IF NOT EXISTS origin_ends_on DATE NULL AFTER origin_starts_on,
    ADD COLUMN IF NOT EXISTS period_proof_kind VARCHAR(32) NULL AFTER origin_ends_on,
    ADD COLUMN IF NOT EXISTS period_proof_hash CHAR(64) NULL AFTER period_proof_kind,
    ADD KEY IF NOT EXISTS idx_tax_loss_period (supplier_id, taxpayer_type, origin_starts_on, origin_ends_on);
