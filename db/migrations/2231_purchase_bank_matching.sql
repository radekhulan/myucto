-- Diagnostika má vlastní životní cyklus a nemění updated_at dokladu.
CREATE TABLE IF NOT EXISTS purchase_bank_matching_attempts (
  purchase_invoice_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  result_json JSON NOT NULL,
  KEY idx_pbma_supplier_purchase (supplier_id, purchase_invoice_id),
  CONSTRAINT fk_pbma_purchase FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_pbma_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS idx_bt_statement_status_date ON bank_transactions (statement_id, match_status, posted_at);
CREATE INDEX IF NOT EXISTS idx_bt_statement_status_vs ON bank_transactions (statement_id, match_status, variable_symbol);
CREATE INDEX IF NOT EXISTS idx_bma_supplier_purchase_suggestion ON bank_match_audit (supplier_id, purchase_invoice_id, suggestion_id);
