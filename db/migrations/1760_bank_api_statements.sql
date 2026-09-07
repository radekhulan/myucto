ALTER TABLE bank_statements
    MODIFY COLUMN source ENUM('gpc','email_notice','pdf','idoklad','bank_api') NOT NULL DEFAULT 'gpc';

ALTER TABLE bank_connections
    MODIFY COLUMN token_ciphertext MEDIUMTEXT NULL;
