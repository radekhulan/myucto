-- Zkratky předkontací v zaúčtování mezd z mzdového programu (PAMICA nastavená na
-- předkontace POHODY): zkratka (např. 7Int, 4Oz) → dvojice účtů MD a Dal z osnovy firmy.
--
-- Export PAMICY nese v `typ:ids` buď přímo dvojici účtů (521000/331000), nebo zkratku
-- předkontace POHODY. Zkratka účty neříká, proto je účetní při prvním načtení přiřadí
-- a import je příště použije sám. Jeden řádek = firma × program × zkratka; zkratka se
-- porovnává bez ohledu na velikost písmen (collation sloupce), stejně jako v POHODĚ.
--
-- Účty jsou kódy osnovy MyÚčta (521, 336.001). Existenci a aktivitu účtů hlídá import
-- při uložení i při každém načtení, cizí klíč na osnovu tu není, protože osnova má
-- historii definic k datu.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_posting_code_maps (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  source_application  VARCHAR(20) NOT NULL,
  code                VARCHAR(20) NOT NULL,
  debit_account_code  VARCHAR(20) NOT NULL,
  credit_account_code VARCHAR(20) NOT NULL,
  updated_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payroll_posting_code_map (supplier_id, source_application, code),
  CONSTRAINT fk_payroll_posting_code_map_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
