SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sales_quote_orders (
  supplier_id INT UNSIGNED NOT NULL,
  quote_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  UNIQUE KEY uq_sales_quote_order_once (supplier_id, quote_id, idempotency_key),
  KEY ix_sales_quote_order_version (version_id),
  CONSTRAINT fk_sales_quote_order_version FOREIGN KEY (version_id) REFERENCES sales_quote_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_quote_order_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
