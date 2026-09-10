ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS tracking_mode ENUM('none','lot','serial') NOT NULL DEFAULT 'none' AFTER unit;

ALTER TABLE stock_document_lines
  ADD COLUMN IF NOT EXISTS tracking_input_json LONGTEXT NULL CHECK (tracking_input_json IS NULL OR JSON_VALID(tracking_input_json)) AFTER note;

ALTER TABLE warehouses ADD UNIQUE KEY IF NOT EXISTS uq_wh_supplier_id (supplier_id, id);
ALTER TABLE stock_items ADD UNIQUE KEY IF NOT EXISTS uq_si_supplier_id (supplier_id, id);
ALTER TABLE stock_document_lines ADD UNIQUE KEY IF NOT EXISTS uq_sdl_supplier_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS warehouse_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_warehouse_location_code (supplier_id, warehouse_id, code),
  UNIQUE KEY uq_warehouse_location_owner (supplier_id, warehouse_id, id),
  UNIQUE KEY uq_warehouse_location_supplier_id (supplier_id, id),
  CONSTRAINT fk_wl_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_wl_warehouse FOREIGN KEY (supplier_id, warehouse_id) REFERENCES warehouses(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_item_units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  unit_code VARCHAR(20) NOT NULL,
  numerator BIGINT UNSIGNED NOT NULL COMMENT 'alternative quantity multiplied by numerator/denominator gives base quantity',
  denominator BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stock_item_unit (supplier_id, stock_item_id, unit_code),
  CONSTRAINT fk_siu_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_siu_item FOREIGN KEY (supplier_id, stock_item_id) REFERENCES stock_items(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_siu_ratio CHECK (numerator > 0 AND denominator > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_tracking_units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  tracking_type ENUM('lot','serial') NOT NULL,
  tracking_key VARCHAR(191) NOT NULL,
  lot_code VARCHAR(100) NULL,
  serial_number VARCHAR(191) NULL,
  expires_on DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tracking_identity (supplier_id, stock_item_id, tracking_key),
  UNIQUE KEY uq_tracking_owner (supplier_id, stock_item_id, id),
  UNIQUE KEY uq_tracking_supplier_id (supplier_id, id),
  CONSTRAINT fk_stu_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_stu_item FOREIGN KEY (supplier_id, stock_item_id) REFERENCES stock_items(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_tracking_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_document_line_id BIGINT UNSIGNED NOT NULL,
  stock_tracking_unit_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  direction ENUM('in','out') NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  original_allocation_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_tracking_history (supplier_id, stock_tracking_unit_id, id),
  KEY ix_tracking_location_balance (supplier_id, warehouse_id, location_id, stock_tracking_unit_id),
  UNIQUE KEY uq_tracking_allocation_supplier_id (supplier_id, id),
  UNIQUE KEY uq_tracking_line_leg (stock_document_line_id, stock_tracking_unit_id, warehouse_id, location_id, direction),
  CONSTRAINT fk_sta_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sta_line FOREIGN KEY (supplier_id, stock_document_line_id) REFERENCES stock_document_lines(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_sta_unit FOREIGN KEY (supplier_id, stock_tracking_unit_id) REFERENCES stock_tracking_units(supplier_id, id),
  CONSTRAINT fk_sta_warehouse FOREIGN KEY (supplier_id, warehouse_id) REFERENCES warehouses(supplier_id, id),
  CONSTRAINT fk_sta_location FOREIGN KEY (supplier_id, warehouse_id, location_id) REFERENCES warehouse_locations(supplier_id, warehouse_id, id),
  CONSTRAINT fk_sta_original FOREIGN KEY (supplier_id, original_allocation_id) REFERENCES stock_tracking_allocations(supplier_id, id),
  CONSTRAINT chk_sta_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_cycle_counts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  status ENUM('draft','queued','running','counting','closed','cancelled','failed') NOT NULL DEFAULT 'draft',
  cutoff_document_line_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cutoff_allocation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  preparation_job_id BIGINT UNSIGNED NULL,
  take_date DATE NOT NULL,
  note TEXT NULL,
  created_by INT UNSIGNED NULL,
  closed_by INT UNSIGNED NULL,
  closed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_cycle_tenant_status (supplier_id, status, id),
  KEY ix_cycle_preparation_job (supplier_id, preparation_job_id),
  UNIQUE KEY uq_cycle_supplier_id (supplier_id, id),
  CONSTRAINT fk_scc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_scc_warehouse FOREIGN KEY (supplier_id, warehouse_id) REFERENCES warehouses(supplier_id, id),
  CONSTRAINT fk_scc_location FOREIGN KEY (supplier_id, warehouse_id, location_id) REFERENCES warehouse_locations(supplier_id, warehouse_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_cycle_count_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  cycle_count_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  stock_tracking_unit_id BIGINT UNSIGNED NULL,
  expected_qty DECIMAL(14,3) NOT NULL,
  counted_qty DECIMAL(14,3) NULL,
  surplus_unit_cost DECIMAL(15,6) NULL,
  UNIQUE KEY uq_cycle_line (cycle_count_id, stock_item_id, stock_tracking_unit_id),
  CONSTRAINT fk_sccl_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sccl_cycle FOREIGN KEY (supplier_id, cycle_count_id) REFERENCES stock_cycle_counts(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_sccl_item FOREIGN KEY (supplier_id, stock_item_id) REFERENCES stock_items(supplier_id, id),
  CONSTRAINT fk_sccl_tracking FOREIGN KEY (supplier_id, stock_item_id, stock_tracking_unit_id) REFERENCES stock_tracking_units(supplier_id, stock_item_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
