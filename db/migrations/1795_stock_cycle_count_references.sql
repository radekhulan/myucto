ALTER TABLE stock_cycle_count_lines
  ADD COLUMN IF NOT EXISTS count_reference_qty DECIMAL(14,3) NULL AFTER counted_qty,
  ADD COLUMN IF NOT EXISTS counted_at DATETIME(6) NULL AFTER count_reference_qty;

ALTER TABLE stock_documents
  ADD UNIQUE KEY IF NOT EXISTS uq_sd_supplier_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS stock_cycle_count_documents (
  supplier_id INT UNSIGNED NOT NULL,
  cycle_count_id BIGINT UNSIGNED NOT NULL,
  stock_document_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (cycle_count_id, stock_document_id),
  KEY ix_sccd_supplier_document (supplier_id, stock_document_id),
  CONSTRAINT fk_sccd_cycle FOREIGN KEY (supplier_id, cycle_count_id)
    REFERENCES stock_cycle_counts (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_sccd_document FOREIGN KEY (supplier_id, stock_document_id)
    REFERENCES stock_documents (supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
