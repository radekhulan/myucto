CREATE TABLE IF NOT EXISTS bank_transaction_imports (
    statement_id BIGINT UNSIGNED NOT NULL,
    bank_transaction_id BIGINT UNSIGNED NOT NULL,
    import_fingerprint CHAR(64) NOT NULL,
    PRIMARY KEY (statement_id, bank_transaction_id),
    KEY idx_bti_fingerprint (import_fingerprint),
    CONSTRAINT fk_bti_statement FOREIGN KEY (statement_id) REFERENCES bank_statements(id) ON DELETE CASCADE,
    CONSTRAINT fk_bti_transaction FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
