-- Sazba je měnitelná šablona; finanční důkaz na ní nezávisí.
CREATE TABLE IF NOT EXISTS nonprofit_membership_rates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    receivable_account VARCHAR(20) NOT NULL,
    revenue_account VARCHAR(20) NOT NULL,
    title_key VARCHAR(80) NOT NULL,
    period_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    title_reference TEXT NOT NULL,
    evidence TEXT NOT NULL,
    classification_json LONGTEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nmr_tenant (supplier_id,id),
    KEY idx_nmr_active (supplier_id,is_active,id),
    CONSTRAINT fk_nmr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT chk_nmr_amount CHECK (amount > 0),
    CONSTRAINT chk_nmr_classification CHECK (classification_json IS NULL OR JSON_VALID(classification_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nonprofit_membership_charge_commands
    ADD COLUMN IF NOT EXISTS rate_id INT UNSIGNED NULL,
    ADD INDEX IF NOT EXISTS idx_nmcmd_rate (supplier_id,rate_id,id),
    ADD CONSTRAINT fk_nmcmd_rate FOREIGN KEY IF NOT EXISTS (supplier_id,rate_id)
        REFERENCES nonprofit_membership_rates(supplier_id,id);
