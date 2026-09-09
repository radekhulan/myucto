ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS valuation_version BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE stock_takes MODIFY COLUMN status ENUM('draft','preparing','counting','closed') NOT NULL DEFAULT 'draft';
ALTER TABLE stock_takes ADD COLUMN IF NOT EXISTS preparation_job_id BIGINT UNSIGNED NULL;
ALTER TABLE stock_document_lines ADD COLUMN IF NOT EXISTS ledger_booked_at TIMESTAMP NULL;
UPDATE stock_document_lines l JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
    SET l.ledger_booked_at = d.booked_at
    WHERE d.status IN ('posted','reversed') AND l.ledger_booked_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_sdl_valuation_cursor ON stock_document_lines
    (supplier_id, stock_item_id, doc_date, ledger_booked_at, document_id, line_no, id);

CREATE TABLE IF NOT EXISTS stock_valuation_snapshots (
    supplier_id INT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    stock_item_id BIGINT UNSIGNED NOT NULL,
    cutoff_date DATE NOT NULL,
    qty DECIMAL(18,3) NOT NULL,
    value_total DECIMAL(20,2) NOT NULL,
    source_version BIGINT UNSIGNED NOT NULL,
    algorithm_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (supplier_id, warehouse_id, stock_item_id, cutoff_date),
    CONSTRAINT fk_svs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_valuation_rows (
    job_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    stock_item_id BIGINT UNSIGNED NOT NULL,
    started TINYINT(1) NOT NULL DEFAULT 0,
    completed TINYINT(1) NOT NULL DEFAULT 0,
    base_date DATE NULL,
    cursor_json LONGTEXT NULL,
    qty DECIMAL(18,3) NOT NULL DEFAULT 0,
    value_total DECIMAL(20,2) NOT NULL DEFAULT 0,
    movements BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (job_id, stock_item_id, warehouse_id),
    KEY idx_svr_pending (job_id, completed, stock_item_id, warehouse_id),
    CONSTRAINT fk_svr_job FOREIGN KEY (job_id) REFERENCES catalog_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_svr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
