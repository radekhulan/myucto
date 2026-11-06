-- MyÚčto — chronologie fondů SVJ se hlídá jen v rámci stejného zdroje (jednotka × účet).
--
-- Kontrola hledá pozdější operaci domu, která se dotkla stejného účtu jednotky.
-- Index nad operacemi zúží hledání na dům a datum, index nad účinky na účet a jednotku.

SET NAMES utf8mb4;

ALTER TABLE property_funding_operations
  ADD INDEX IF NOT EXISTS ix_funding_house_date (supplier_id, house_id, accounting_date);

ALTER TABLE property_funding_effects
  ADD INDEX IF NOT EXISTS ix_funding_effect_account (supplier_id, operation_id, unit_id, account_code);
