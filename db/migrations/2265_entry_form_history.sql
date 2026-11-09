-- MyÚčto.cz — forma účetního zápisu firmy v čase (jednoduchý / složený zápis).
--
-- Firma si od zvoleného data zapíná jednoduchý (párový) zápis: každý řádek deníku
-- patří do dvojice MD/Dal se stejnou částkou. Forma platí k `entry_date` zápisu,
-- proto je to historie {valid_from, entry_form}, ne sloupec v nastavení firmy
-- (vzor supplier_vat_status_history, migrace 1120/1181).
--
-- Firma bez řádku = složený zápis jako dosud. Migrace proto nic nedoplňuje:
-- firma, která volbu nezapne, nemá v tabulce nic a nepozná žádnou změnu.
-- Jediná čtecí i zápisová cesta je EntryFormService.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_entry_form_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL COMMENT 'Forma platí pro zápisy s entry_date >= valid_from (do další změny)',
    entry_form ENUM('composite', 'single') NOT NULL COMMENT 'composite = složený zápis, single = jednoduchý (párový)',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) NULL COMMENT 'Poznámka ke změně formy',
    PRIMARY KEY (id),
    UNIQUE KEY uq_aefh_supplier_from (supplier_id, valid_from),
    KEY idx_aefh_supplier_form (supplier_id, entry_form),
    KEY idx_aefh_created_by (created_by),
    CONSTRAINT fk_aefh_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
    CONSTRAINT fk_aefh_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Forma účetního zápisu firmy v čase; žádný řádek = složený zápis';
