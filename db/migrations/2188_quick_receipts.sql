-- Rychlá účtenka (POST /api/mobile/receipts): idempotence a vazby jedné operace.
--
-- Jedna účtenka = vystavený doklad + okamžitá úhrada (+ pokladní doklad u hotovosti)
-- + případná tržba EET, vše v jedné transakci. Řádek vzniká jako první krok
-- transakce: opakované odeslání se stejným Idempotency-Key narazí na unikátní klíč
-- a vrátí původní výsledek, jiné tělo se stejným klíčem skončí 409.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS quick_receipts (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id      INT UNSIGNED    NOT NULL,
  idempotency_key  VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  invoice_id       BIGINT UNSIGNED NULL,
  payment_event_id BIGINT UNSIGNED NULL,
  cash_document_id BIGINT UNSIGNED NULL,
  device_id        BIGINT UNSIGNED NULL,
  channel          VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  received_amount  DECIMAL(14,2)   NULL COMMENT 'přijatá hotovost; vráceno = přijato − celkem, jen informativně',
  note             VARCHAR(255)    NULL COMMENT 'interní poznámka obsluhy, na doklad se netiskne',
  created_by       BIGINT UNSIGNED NULL,
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_quick_receipts_idempotency (supplier_id, idempotency_key),
  UNIQUE KEY uq_quick_receipts_invoice (invoice_id),
  KEY ix_quick_receipts_created (supplier_id, created_at, id),
  CONSTRAINT fk_quick_receipts_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_quick_receipts_invoice  FOREIGN KEY (invoice_id)  REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_quick_receipts_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
