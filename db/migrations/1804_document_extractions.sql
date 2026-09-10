-- Uložené AI vytěžení obsahu dokumentu.
--
-- Vytěžení je drahé (volání modelu) a výsledek se neliší, dokud se nezmění obsah
-- souboru. Proto je klíčem obsah (sha256) v rámci firmy, ne konkrétní řádek
-- `documents`: stejný sken nahraný podruhé se znovu nevytěžuje.
--
-- Vedle úplné odpovědi modelu (`payload`) nese řádek normalizované sloupce, podle
-- kterých se páruje a porovnává: čárový kód, strany dokladu, číslo, VS, data,
-- částky, SPZ, koncovka karty a role firmy na dokladu. Nad těmi sloupci staví
-- i kontrola zaúčtovaných dokladů proti přílohám — dotaz podle dokumentu jde
-- přes sha256, podle dokladu přes `document_links` → `documents`.
--
-- `schema_version` se zvedne, když se změní vytěžovaná pole; starší řádky pak
-- zůstanou jako historie a dokument se vytěží znovu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS document_extractions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id      INT UNSIGNED NOT NULL,
  document_id      BIGINT UNSIGNED NULL COMMENT 'dokument, ze kterého extrakce vznikla; klíčem je sha256',
  sha256           CHAR(64) NOT NULL,
  schema_version   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status           ENUM('ok','failed') NOT NULL,
  provider         VARCHAR(32) NULL,
  model            VARCHAR(100) NULL,
  company_role     ENUM('buyer','vendor','both','none') NULL COMMENT 'role firmy na dokladu podle modelu',
  document_kind    VARCHAR(32) NULL,
  barcode          VARCHAR(64) NULL,
  vendor_name      VARCHAR(255) NULL,
  vendor_ico       VARCHAR(20) NULL,
  vendor_dic       VARCHAR(20) NULL,
  buyer_name       VARCHAR(255) NULL,
  buyer_ico        VARCHAR(20) NULL,
  buyer_dic        VARCHAR(20) NULL,
  document_number  VARCHAR(64) NULL,
  variable_symbol  VARCHAR(32) NULL,
  issue_date       DATE NULL,
  tax_date         DATE NULL,
  total_with_vat   DECIMAL(15,2) NULL,
  amount_due       DECIMAL(15,2) NULL,
  currency         CHAR(3) NULL,
  license_plate    VARCHAR(20) NULL,
  card_last4       CHAR(4) NULL,
  payload          LONGTEXT NULL COMMENT 'úplná odpověď modelu (JSON)',
  error            VARCHAR(500) NULL,
  extracted_at     DATETIME NOT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_docext_supplier_sha (supplier_id, sha256, schema_version),
  KEY idx_docext_document (supplier_id, document_id),
  KEY idx_docext_barcode (supplier_id, barcode),
  KEY idx_docext_vendor (supplier_id, vendor_ico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
