-- MyÚčto.cz — Výchozí dimenze vlastního bankovního účtu (Firma → Dimenze)
--
-- Pohyb, který nehradí žádný doklad (úrok, srážková daň, poplatek), nemá při zaúčtování
-- odkud dimenzi převzít. Vlastní bankovní účet (`supplier_bank_accounts`) proto nese
-- výchozí hodnoty stejně jako klient, zakázka, produkt a kategorie. Zaúčtování je doplní
-- typům, které pohyb nemá sám ani z hrazených dokladů (DimensionDefaults::forBank()).
--
-- Idempotentní: ADD COLUMN / INDEX / FOREIGN KEY IF NOT EXISTS, kontrola vlastníka
-- se zahodí a založí znovu se všemi sloupci.

SET NAMES utf8mb4;

ALTER TABLE dimension_defaults
  ADD COLUMN IF NOT EXISTS supplier_bank_account_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER product_category_id;

ALTER TABLE dimension_defaults
  ADD UNIQUE INDEX IF NOT EXISTS uq_dimdef_bank_account_type (supplier_bank_account_id, dimension_type_id);

ALTER TABLE dimension_defaults
  ADD CONSTRAINT fk_dimdef_bank_account FOREIGN KEY IF NOT EXISTS (supplier_bank_account_id)
    REFERENCES supplier_bank_accounts(id) ON DELETE CASCADE;

ALTER TABLE dimension_defaults DROP CONSTRAINT IF EXISTS chk_dimdef_owner;

ALTER TABLE dimension_defaults
  ADD CONSTRAINT chk_dimdef_owner CHECK (
    (client_id IS NOT NULL) + (project_id IS NOT NULL)
      + (product_id IS NOT NULL) + (product_category_id IS NOT NULL)
      + (supplier_bank_account_id IS NOT NULL) = 1
  );
