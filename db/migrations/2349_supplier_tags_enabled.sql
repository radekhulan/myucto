-- Interní štítky na vystavených fakturách a šablonách pravidelné fakturace jako volba
-- firmy v Nastavení → Fakturace a e-maily. Výchozí vypnuto, většina firem je nepotřebuje.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS tags_enabled TINYINT(1) NOT NULL DEFAULT 0;
