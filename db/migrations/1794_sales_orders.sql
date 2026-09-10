-- MyÚčto.cz - SK-10 prodejní objednávky a tvrdé rezervace.
-- Objednávka ani rezervace nejsou účetní ani skladový pohyb. Fyzický stav
-- zůstává výhradně ve stock_levels/stock_documents, rezervace se odvozuje z
-- řádků níže a při potvrzení se kontroluje pod zámkem skutečného stock_levels.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sales_orders (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_uuid            CHAR(36) NOT NULL,
  supplier_id           INT UNSIGNED NOT NULL,
  client_id             BIGINT UNSIGNED NOT NULL,
  order_number          VARCHAR(50) NOT NULL,
  external_source       VARCHAR(50) NULL,
  external_id           VARCHAR(191) NULL,
  commercial_status     ENUM('draft','confirmed','cancelled','completed') NOT NULL DEFAULT 'draft',
  payment_status        ENUM('unpaid','authorized','partially_paid','paid','refunded','partially_refunded') NOT NULL DEFAULT 'unpaid',
  fulfillment_status    ENUM('unfulfilled','partially_reserved','reserved','partially_fulfilled','fulfilled','cancelled') NOT NULL DEFAULT 'unfulfilled',
  allocation_policy     ENUM('all_or_nothing','partial') NOT NULL DEFAULT 'all_or_nothing',
  currency_id           INT UNSIGNED NOT NULL,
  currency_code         CHAR(3) NOT NULL,
  exchange_rate         DECIMAL(15,8) NULL,
  prices_include_vat    TINYINT(1) NOT NULL DEFAULT 0,
  customer_snapshot     LONGTEXT NOT NULL CHECK (JSON_VALID(customer_snapshot)),
  shipping_snapshot     LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(shipping_snapshot)),
  discount_snapshot     LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(discount_snapshot)),
  total_without_vat     DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_vat             DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_with_vat        DECIMAL(15,2) NOT NULL DEFAULT 0,
  reservation_expires_at DATETIME NULL,
  row_version           BIGINT UNSIGNED NOT NULL DEFAULT 1,
  confirmed_at          DATETIME NULL,
  cancelled_at          DATETIME NULL,
  completed_at          DATETIME NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_orders_uuid (supplier_id, order_uuid),
  UNIQUE KEY uq_sales_orders_number (supplier_id, order_number),
  UNIQUE KEY uq_sales_orders_external (supplier_id, external_source, external_id),
  KEY ix_sales_orders_status (supplier_id, commercial_status, fulfillment_status, created_at),
  KEY ix_sales_orders_expiry (supplier_id, commercial_status, reservation_expires_at),
  CONSTRAINT fk_sales_orders_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_orders_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_sales_orders_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_sales_orders_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_lines (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  line_uuid             CHAR(36) NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  supplier_id           INT UNSIGNED NOT NULL,
  line_no               INT UNSIGNED NOT NULL,
  stock_item_id         BIGINT UNSIGNED NULL,
  warehouse_id          BIGINT UNSIGNED NULL,
  description           VARCHAR(500) NOT NULL,
  sku_snapshot          VARCHAR(80) NULL,
  unit                  VARCHAR(20) NOT NULL DEFAULT 'ks',
  quantity              DECIMAL(14,3) NOT NULL,
  unit_price            DECIMAL(15,6) NOT NULL,
  discount_percent      DECIMAL(7,4) NOT NULL DEFAULT 0,
  vat_rate_id           INT UNSIGNED NULL,
  vat_rate_snapshot     DECIMAL(5,2) NOT NULL DEFAULT 0,
  total_without_vat     DECIMAL(15,2) NOT NULL,
  total_vat             DECIMAL(15,2) NOT NULL,
  total_with_vat        DECIMAL(15,2) NOT NULL,
  product_snapshot      LONGTEXT NOT NULL CHECK (JSON_VALID(product_snapshot)),
  component_snapshot    LONGTEXT NOT NULL DEFAULT '[]' CHECK (JSON_VALID(component_snapshot)),
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_order_line_uuid (supplier_id, line_uuid),
  UNIQUE KEY uq_sales_order_line_no (order_id, line_no),
  KEY ix_sales_order_lines_item (supplier_id, stock_item_id),
  CONSTRAINT fk_sales_order_lines_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_lines_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_lines_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_order_lines_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_order_lines_vat FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_reservations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  order_line_id         BIGINT UNSIGNED NOT NULL,
  component_no          INT UNSIGNED NOT NULL,
  warehouse_id          BIGINT UNSIGNED NOT NULL,
  stock_item_id         BIGINT UNSIGNED NOT NULL,
  qty_reserved          DECIMAL(14,3) NOT NULL,
  qty_consumed          DECIMAL(14,3) NOT NULL DEFAULT 0,
  qty_released          DECIMAL(14,3) NOT NULL DEFAULT 0,
  status                ENUM('active','consumed','released') NOT NULL DEFAULT 'active',
  expires_at            DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_order_reservation_component (order_line_id, component_no),
  KEY ix_sales_order_reservation_available (supplier_id, warehouse_id, stock_item_id, status),
  KEY ix_sales_order_reservation_expiry (supplier_id, status, expires_at),
  CONSTRAINT fk_sales_order_reservations_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_reservations_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_reservations_line FOREIGN KEY (order_line_id) REFERENCES sales_order_lines(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_reservations_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_sales_order_reservations_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_invoice_links (
  supplier_id           INT UNSIGNED NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  invoice_id            BIGINT UNSIGNED NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  UNIQUE KEY uq_sales_order_invoice (invoice_id),
  KEY ix_sales_order_invoice_tenant (supplier_id, invoice_id),
  CONSTRAINT fk_sales_order_invoice_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_invoice_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_invoice_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_fulfillment_links (
  supplier_id           INT UNSIGNED NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  shipment_id           BIGINT UNSIGNED NOT NULL,
  stock_document_id     BIGINT UNSIGNED NULL,
  consumed_json         LONGTEXT NOT NULL CHECK (JSON_VALID(consumed_json)),
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, order_id, shipment_id),
  KEY ix_sales_order_fulfillment_document (stock_document_id),
  CONSTRAINT fk_sales_order_fulfillment_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_fulfillment_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_fulfillment_document FOREIGN KEY (stock_document_id) REFERENCES stock_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_operation_keys (
  supplier_id           INT UNSIGNED NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  operation             VARCHAR(40) NOT NULL,
  idempotency_key       VARCHAR(191) NOT NULL,
  result_json           LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(result_json)),
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, operation, idempotency_key),
  KEY ix_sales_order_operation_order (order_id),
  CONSTRAINT fk_sales_order_operation_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_operation_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_returns (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_uuid           CHAR(36) NOT NULL,
  supplier_id           INT UNSIGNED NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  commercial_resolution ENUM('none','credit_note','refund') NOT NULL DEFAULT 'none',
  physical_resolution   ENUM('none','return_to_stock') NOT NULL DEFAULT 'none',
  credit_note_id        BIGINT UNSIGNED NULL,
  stock_document_id     BIGINT UNSIGNED NULL,
  lines_json            LONGTEXT NOT NULL CHECK (JSON_VALID(lines_json)),
  created_by            BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_order_return_uuid (supplier_id, return_uuid),
  KEY ix_sales_order_returns_order (supplier_id, order_id),
  CONSTRAINT fk_sales_order_returns_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_returns_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_order_returns_credit FOREIGN KEY (credit_note_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_order_returns_stock FOREIGN KEY (stock_document_id) REFERENCES stock_documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_order_returns_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sales_orders
  DROP INDEX IF EXISTS uq_sales_orders_uuid,
  ADD UNIQUE KEY uq_sales_orders_uuid (supplier_id, order_uuid);

ALTER TABLE sales_order_lines
  DROP INDEX IF EXISTS uq_sales_order_line_uuid,
  ADD UNIQUE KEY uq_sales_order_line_uuid (supplier_id, line_uuid);

ALTER TABLE sales_order_returns
  DROP INDEX IF EXISTS uq_sales_order_return_uuid,
  ADD UNIQUE KEY uq_sales_order_return_uuid (supplier_id, return_uuid);
