-- MyÚčto.cz: životní cyklus skladové karty a znovupoužitelné šablony obsahu.
SET NAMES utf8mb4;

ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS lifecycle_status ENUM('draft', 'ready', 'retired') NOT NULL DEFAULT 'ready' AFTER is_active,
  ADD COLUMN IF NOT EXISTS retired_at TIMESTAMP NULL DEFAULT NULL AFTER lifecycle_status;

CREATE INDEX IF NOT EXISTS idx_si_supplier_lifecycle
  ON stock_items (supplier_id, lifecycle_status, is_active);

CREATE TABLE IF NOT EXISTS stock_item_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  content_json JSON NOT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sit_supplier_name (supplier_id, name),
  KEY idx_sit_supplier_updated (supplier_id, updated_at),
  CONSTRAINT fk_stock_item_templates_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_stock_item_templates_row_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
