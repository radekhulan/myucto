-- Měsíční zaúčtování mezd načtené z PAMICY (dataPack pro POHODU): interní doklad
-- s mzdovými náklady a ostatní závazky k pojišťovnám, finančnímu úřadu a zaměstnancům.
--
-- Řádek je pojistka proti druhému načtení téhož měsíce. Klíčem je IČO a párovací symbol
-- PAMICY (`symPar`, např. MZ202603), ne číslo exportu: opakovaný export téhož měsíce
-- dostane v PAMICE nové číslo dataPacku, ale účetně je to pořád tentýž měsíc.
-- Vzniklé doklady drží `internal_document_ids` a `other_item_ids` (JSON seznamy). Když jsou
-- všechny stornované nebo smazané, smí se měsíc načíst znovu a řádek se přepíše.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_posting_imports (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  source_application    VARCHAR(20) NOT NULL,
  source_ico            VARCHAR(16) NOT NULL,
  sym_par               VARCHAR(40) NOT NULL,
  period                CHAR(7) NOT NULL,
  pack_id               VARCHAR(64) NULL,
  file_name             VARCHAR(255) NULL,
  file_sha256           CHAR(64) NOT NULL,
  total_amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
  internal_document_ids LONGTEXT NOT NULL,
  other_item_ids        LONGTEXT NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payroll_posting_import_source (supplier_id, source_application, source_ico, sym_par),
  KEY idx_payroll_posting_import_period (supplier_id, period),
  CONSTRAINT fk_payroll_posting_import_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
