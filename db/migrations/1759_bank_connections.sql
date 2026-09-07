-- 1759: Přímé bankovní konektory a jednorázové odeslání platebních příkazů.
--
-- Token je uložen výhradně jako context-bound enc:v2 ciphertext. Ověřený snapshot
-- účtu a měny chrání automatický import před pozdější změnou řádku currencies.
-- Odeslání příkazu je append-only pokus s unikátním (supplier, payment_order),
-- takže timeout ani odpojení konektoru nikdy neotevře cestu k opakované platbě.

SET NAMES utf8mb4;

ALTER TABLE currencies
    ADD UNIQUE KEY IF NOT EXISTS uq_currencies_supplier_id (supplier_id, id);

ALTER TABLE payment_orders
    ADD UNIQUE KEY IF NOT EXISTS uq_payment_orders_supplier_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS bank_connections (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  currency_id              INT UNSIGNED NOT NULL,
  provider                 VARCHAR(32) NOT NULL,
  token_ciphertext         TEXT NULL,
  enabled                  TINYINT(1) NOT NULL DEFAULT 0,
  verified_account_number  VARCHAR(34) NULL,
  verified_bank_code       CHAR(4) NULL,
  verified_currency        CHAR(3) NULL,
  validated_at             DATETIME NULL,
  sync_watermark_date      DATE NULL,
  last_sync_at             DATETIME NULL,
  last_sync_status         ENUM('success','error') NULL,
  last_sync_error_code     VARCHAR(80) NULL,
  disconnected_at          DATETIME NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bank_connection_currency (supplier_id, currency_id),
  UNIQUE KEY uq_bank_connection_supplier_id (supplier_id, id),
  KEY idx_bank_connection_enabled (enabled, supplier_id),
  CONSTRAINT fk_bank_connection_currency
    FOREIGN KEY (supplier_id, currency_id) REFERENCES currencies(supplier_id, id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_connector_cooldowns (
  credential_hash  CHAR(64) PRIMARY KEY,
  last_called_at   DATETIME(6) NOT NULL,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_payment_order_submissions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  payment_order_id    BIGINT UNSIGNED NOT NULL,
  connection_id       BIGINT UNSIGNED NULL,
  provider            VARCHAR(32) NOT NULL,
  payload_hash        CHAR(64) NOT NULL,
  submitted_by_user_id BIGINT UNSIGNED NULL,
  status              ENUM('accepted_awaiting_authorization','rejected','unknown') NOT NULL DEFAULT 'unknown',
  provider_reference  TEXT NULL,
  error_code          VARCHAR(80) NULL,
  accepted_count      INT UNSIGNED NULL,
  rejected_count      INT UNSIGNED NULL,
  remote_http_status  SMALLINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at        DATETIME NULL,
  UNIQUE KEY uq_bank_submission_order (supplier_id, payment_order_id),
  KEY idx_bank_submission_connection (supplier_id, connection_id),
  CONSTRAINT fk_bank_submission_order
    FOREIGN KEY (supplier_id, payment_order_id) REFERENCES payment_orders(supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_bank_submission_connection
    FOREIGN KEY (supplier_id, connection_id) REFERENCES bank_connections(supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_bank_submission_user
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
