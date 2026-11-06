-- MyÚčto.cz — nastavení ostatních pohledávek a závazků per firma.
--
-- `document_folder` je šablona složky v Dokumentech, kam se ukládají soubory
-- nahrané k ostatní pohledávce nebo závazku (kupní smlouva, smlouva o zápůjčce).
-- Zástupné znaky {YYYY} a {MM} se nahradí rokem a měsícem data vystavení dokladu.
-- NULL = výchozí „Ostatní pohledávky a závazky/{YYYY}/{MM}".
--
-- Re-run safe: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS other_item_settings (
  supplier_id INT UNSIGNED NOT NULL,
  document_folder VARCHAR(500) NULL DEFAULT NULL COMMENT 'šablona složky dokumentů, {YYYY} a {MM} z data vystavení',
  updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id),
  CONSTRAINT fk_other_item_settings_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
