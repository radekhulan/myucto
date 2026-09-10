CREATE TABLE IF NOT EXISTS accounting_receivable_provisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_receivable_provision (supplier_id, period_id, invoice_id),
    CONSTRAINT fk_receivable_provision_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_receivable_provision_period FOREIGN KEY (period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_receivable_provision_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
