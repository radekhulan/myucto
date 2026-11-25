ALTER TABLE api_tokens
    ADD COLUMN IF NOT EXISTS issued_external_identity_id BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS mobile_oidc_codes (
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    code_challenge CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    external_identity_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    UNIQUE KEY uq_mobile_oidc_challenge (code_challenge),
    KEY ix_mobile_oidc_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
