-- MyÚčto.cz — jeden číselník číselných řad všech druhů dokladů.
--
-- `number_series` je číselník řad firmy. Převedená řada (storage = 'legacy') si šablonu
-- i počítadlo nechává v dosavadním úložišti (invoice_counters, purchase_invoice_counters,
-- accounting_document_series), číselník k ní drží název, výchozí, ukončení a kontrolu
-- úplnosti. Nová řada (storage = 'native') má šablonu na řádku a počítadlo
-- v number_series_counters. Převod dělá NumberSeriesCatalog po migraci
-- (api/bin/number-series-convert.php, auto-backfill v migrate.php), klíč převodu
-- `legacy_key` je unikátní na firmu.
--
-- `number_series_id` na dokladu: koncept nese volbu uživatele, vystavený doklad jen
-- nativní řadu (NULL = dosavadní číslování).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN/INDEX IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS number_series (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  doc_kind      VARCHAR(32) NOT NULL COMMENT 'invoice/proforma/credit_note/simplified/purchase/cash_in/cash_out/manual/stock_in/... /bank',
  scope_type    ENUM('company','cash_register','client','revenue_category','other_item_kind','bank_account') NOT NULL DEFAULT 'company',
  scope_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  storage       ENUM('legacy','native') NOT NULL DEFAULT 'native',
  legacy_key    VARCHAR(80) NULL DEFAULT NULL COMMENT 'Identita převedené řady; NULL u nové řady',
  name          VARCHAR(100) NOT NULL,
  prefix        VARCHAR(10) NULL DEFAULT NULL,
  template      VARCHAR(60) NULL DEFAULT NULL COMMENT 'Šablona nativní řady; u převedené NULL (čte se z dosavadního nastavení)',
  period        ENUM('year','month','none') NULL DEFAULT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  valid_to      DATE NULL DEFAULT NULL COMMENT 'Ukončení řady: doklady s pozdějším datem z ní číslo nedostanou',
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  created_by    BIGINT UNSIGNED NULL DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_number_series_legacy (supplier_id, legacy_key),
  KEY idx_number_series_kind (supplier_id, doc_kind, scope_type, scope_id),
  CONSTRAINT fk_number_series_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS number_series_counters (
  series_id     INT UNSIGNED NOT NULL,
  period_key    VARCHAR(8) NOT NULL COMMENT 'YYYY, YYYYMM nebo ALL',
  last_number   INT UNSIGNED NOT NULL DEFAULT 0,
  floor_number  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ruční začátek řady: čísla pod ním v systému nevznikla',
  PRIMARY KEY (series_id, period_key),
  CONSTRAINT fk_number_series_counter FOREIGN KEY (series_id) REFERENCES number_series(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS number_series_user_defaults (
  supplier_id   INT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  doc_kind      VARCHAR(32) NOT NULL,
  scope_id      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Pokladna u PPD/VPD, jinak 0',
  series_id     INT UNSIGNED NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, user_id, doc_kind, scope_id),
  KEY idx_nsud_series (series_id),
  CONSTRAINT fk_nsud_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_nsud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_nsud_series FOREIGN KEY (series_id) REFERENCES number_series(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS number_series_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Řada z číselníku; NULL = dosavadní číslování',
  ADD INDEX IF NOT EXISTS idx_inv_number_series (supplier_id, number_series_id, issue_date);

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS number_series_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Řada z číselníku; NULL = dosavadní číslování',
  ADD INDEX IF NOT EXISTS idx_pi_number_series (supplier_id, number_series_id, issue_date);

ALTER TABLE cash_documents
  ADD COLUMN IF NOT EXISTS number_series_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Řada z číselníku; NULL = dosavadní číslování',
  ADD INDEX IF NOT EXISTS idx_cashdoc_number_series (supplier_id, number_series_id, issue_date);

ALTER TABLE internal_documents
  ADD COLUMN IF NOT EXISTS number_series_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Řada z číselníku; NULL = dosavadní číslování',
  ADD INDEX IF NOT EXISTS idx_internal_document_number_series (supplier_id, number_series_id, entry_date);
