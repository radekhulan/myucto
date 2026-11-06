-- MyÚčto.cz - oficiální kód právní formy dodavatele z ARES.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS legal_form VARCHAR(3) NULL DEFAULT NULL AFTER taxpayer_type;
