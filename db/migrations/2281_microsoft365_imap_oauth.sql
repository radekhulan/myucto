ALTER TABLE bank_email_imap_settings
    ADD COLUMN IF NOT EXISTS auth_mode ENUM('password', 'microsoft365') NOT NULL DEFAULT 'password',
    ADD COLUMN IF NOT EXISTS oauth_tenant_id CHAR(36) NULL,
    ADD COLUMN IF NOT EXISTS oauth_client_id CHAR(36) NULL,
    ADD COLUMN IF NOT EXISTS oauth_client_secret_enc TEXT NULL;

ALTER TABLE email_profiles
    ADD COLUMN IF NOT EXISTS imap_auth_mode ENUM('password', 'microsoft365') NOT NULL DEFAULT 'password',
    ADD COLUMN IF NOT EXISTS imap_oauth_tenant_id CHAR(36) NULL,
    ADD COLUMN IF NOT EXISTS imap_oauth_client_id CHAR(36) NULL,
    ADD COLUMN IF NOT EXISTS imap_oauth_client_secret_enc TEXT NULL;
