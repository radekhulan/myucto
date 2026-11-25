ALTER TABLE myeet_checkouts
    ADD COLUMN IF NOT EXISTS license_readonly_until BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER license_valid_until;
