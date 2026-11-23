-- Samostatná cenová ujednání; výkaz vždy nese neměnnou kopii nastavení.
CREATE TABLE IF NOT EXISTS work_report_billing_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    settings JSON NOT NULL,
    archived_at TIMESTAMP NULL,
    INDEX idx_wr_billing_supplier (supplier_id, name),
    CONSTRAINT fk_wr_billing_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE work_report_billing_templates ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS billing_template_id INT UNSIGNED NULL;
ALTER TABLE work_reports
    ADD COLUMN IF NOT EXISTS billing_template_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS billing_snapshot JSON NULL,
    ADD COLUMN IF NOT EXISTS billing_period CHAR(7) NULL,
    ADD COLUMN IF NOT EXISTS billing_transferred_fingerprint CHAR(64) NULL;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS work_report_billing_key VARCHAR(32) NULL;

-- Krycí index pro volitelný výhled nevyfakturovaných konceptů v CRM.
CREATE INDEX IF NOT EXISTS idx_inv_draft_cashflow ON invoices (supplier_id, status, due_date, currency_id, invoice_type, amount_to_pay, paid_total);
