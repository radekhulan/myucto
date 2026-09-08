CREATE TABLE IF NOT EXISTS bank_api_months (
    supplier_id BIGINT UNSIGNED NOT NULL,
    account_key VARCHAR(80) NOT NULL,
    currency CHAR(3) NOT NULL,
    month_start DATE NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (supplier_id, account_key, currency, month_start),
    UNIQUE KEY uq_bam_statement (statement_id),
    CONSTRAINT fk_bam_statement FOREIGN KEY (statement_id) REFERENCES bank_statements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_api_evidence_months (
    supplier_id BIGINT UNSIGNED NULL,
    evidence_statement_id BIGINT UNSIGNED NOT NULL,
    monthly_statement_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (evidence_statement_id, monthly_statement_id),
    KEY idx_baem_monthly (monthly_statement_id),
    CONSTRAINT fk_baem_evidence FOREIGN KEY (evidence_statement_id) REFERENCES bank_statements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_baem_monthly FOREIGN KEY (monthly_statement_id) REFERENCES bank_statements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bank_api_evidence_months ADD COLUMN IF NOT EXISTS supplier_id BIGINT UNSIGNED NULL;
UPDATE bank_api_evidence_months e JOIN bank_api_months m ON m.statement_id = e.monthly_statement_id
SET e.supplier_id = m.supplier_id WHERE e.supplier_id IS NULL;
