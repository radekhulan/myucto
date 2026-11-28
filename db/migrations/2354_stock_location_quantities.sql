ALTER TABLE stock_document_lines
  ADD COLUMN IF NOT EXISTS location_id BIGINT UNSIGNED NULL AFTER tracking_input_json,
  ADD COLUMN IF NOT EXISTS location_to_id BIGINT UNSIGNED NULL AFTER location_id,
  ADD CONSTRAINT fk_sdl_location FOREIGN KEY IF NOT EXISTS (supplier_id, location_id) REFERENCES warehouse_locations(supplier_id, id),
  ADD CONSTRAINT fk_sdl_location_to FOREIGN KEY IF NOT EXISTS (supplier_id, location_to_id) REFERENCES warehouse_locations(supplier_id, id);

CREATE TABLE IF NOT EXISTS stock_location_levels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  UNIQUE KEY uq_sll_item_location (supplier_id, stock_item_id, warehouse_id, location_id),
  KEY ix_sll_location_item (supplier_id, location_id, stock_item_id),
  CONSTRAINT fk_sll_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sll_item FOREIGN KEY (supplier_id, stock_item_id) REFERENCES stock_items(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_sll_location FOREIGN KEY (supplier_id, warehouse_id, location_id) REFERENCES warehouse_locations(supplier_id, warehouse_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_sll_quantity CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_location_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  stock_document_line_id BIGINT UNSIGNED NOT NULL,
  doc_date DATE NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  KEY ix_slm_item_date (supplier_id, stock_item_id, warehouse_id, doc_date),
  KEY ix_slm_location_date (supplier_id, location_id, doc_date, stock_item_id),
  KEY ix_slm_line (supplier_id, stock_document_line_id),
  CONSTRAINT fk_slm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_slm_item FOREIGN KEY (supplier_id, stock_item_id) REFERENCES stock_items(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_slm_location FOREIGN KEY (supplier_id, warehouse_id, location_id) REFERENCES warehouse_locations(supplier_id, warehouse_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_slm_line FOREIGN KEY (supplier_id, stock_document_line_id) REFERENCES stock_document_lines(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_slm_quantity CHECK (quantity <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE stock_takes
    ADD COLUMN IF NOT EXISTS location_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS location_key BIGINT UNSIGNED AS (COALESCE(location_id, 0)) PERSISTENT,
    ADD UNIQUE KEY IF NOT EXISTS uq_st_supplier_wh_location_date (supplier_id, warehouse_id, location_key, take_date),
    ADD KEY IF NOT EXISTS idx_st_supplier_location_date (supplier_id, location_id, take_date, id),
    ADD CONSTRAINT fk_st_location FOREIGN KEY IF NOT EXISTS (supplier_id, warehouse_id, location_id)
        REFERENCES warehouse_locations (supplier_id, warehouse_id, id),
    DROP INDEX IF EXISTS uq_st_supplier_wh_date;

ALTER TABLE stock_valuation_rows ADD COLUMN IF NOT EXISTS location_qty DECIMAL(14,3) NULL;
