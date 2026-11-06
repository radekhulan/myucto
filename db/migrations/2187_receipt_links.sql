-- Veřejná účtenka pro zákazníka (/r/{token}).
--
-- Odkaz na vystavený doklad rychlé účtenky (nebo fakturu zaplacenou na místě), který
-- si zákazník načte z QR kódu do telefonu. Token má 256 bitů a v databázi je jen jeho
-- otisk SHA-256: kdo získá kopii databáze, odkaz nesestaví. Odkaz má omezenou
-- platnost a lze ho odvolat. Smazání firmy nebo dokladu odkaz odstraní.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_links (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED    NOT NULL,
  invoice_id  BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 tokenu (hex), token sám se neukládá',
  expires_at  DATETIME        NOT NULL,
  revoked_at  DATETIME        NULL,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipt_links_token (token_hash),
  KEY ix_receipt_links_invoice (supplier_id, invoice_id, revoked_at),
  CONSTRAINT fk_receipt_links_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_receipt_links_invoice  FOREIGN KEY (invoice_id)  REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_receipt_links_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
