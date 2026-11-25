CREATE TABLE IF NOT EXISTS myeet_checkouts (
  order_id     CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  payment_id   VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  supplier_id  BIGINT UNSIGNED NOT NULL,
  invoice_id   BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_myeet_checkouts_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
