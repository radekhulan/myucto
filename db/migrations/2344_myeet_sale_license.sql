ALTER TABLE myeet_checkouts
    ADD COLUMN IF NOT EXISTS sale_managed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS license_valid_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS license_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD KEY IF NOT EXISTS ix_myeet_checkouts_license (user_id, supplier_id, sale_managed);
