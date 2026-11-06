-- EET: evidence tržeb na základě pověření (§ 10 odst. 1 ZoET).
--
-- Pověření je vlastnost pokladního zařízení: obsluha pro tržby v pověření volí
-- zařízení, které je tak nastavené (metodika „Popis položek datové zprávy
-- s příklady jejich vyplnění“ v1.0, kap. 3, situace 4 a 5). Ke každé situaci se
-- posílá samostatná datová zpráva, takže vlastní tržby a tržby v pověření jdou
-- přes různá zařízení.
--
-- eet_devices.delegation: `none` = poplatník eviduje sám nebo v zastoupení
-- (situace 1 až 3, eic_poverujiciho ani povereni_vice_popl se neuvádí),
-- `single` = pověření jedním poplatníkem (situace 4, povinné eic_poverujiciho),
-- `multiple` = pověření více poplatníky (situace 5, povereni_vice_popl="true",
-- eic_poverujiciho nepovinně).
--
-- eet_records.multiple_delegators: snapshot příznaku povereni_vice_popl k tržbě.
-- EIČ pověřujícího nese už existující eet_records.delegating_eic.

SET NAMES utf8mb4;

ALTER TABLE eet_devices
  ADD COLUMN IF NOT EXISTS delegation ENUM('none','single','multiple') NOT NULL DEFAULT 'none'
    COMMENT 'evidence v pověření: none, single (situace 4), multiple (situace 5)'
    AFTER cash_register_id,
  ADD COLUMN IF NOT EXISTS delegating_eic VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'EIČ pověřujícího poplatníka (eic_poverujiciho)'
    AFTER delegation;

ALTER TABLE eet_devices
  ADD CONSTRAINT IF NOT EXISTS chk_eet_device_delegation CHECK (
    (delegation = 'none' AND delegating_eic IS NULL)
    OR (delegation = 'single' AND delegating_eic IS NOT NULL)
    OR delegation = 'multiple'
  );

ALTER TABLE eet_records
  ADD COLUMN IF NOT EXISTS multiple_delegators TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'povereni_vice_popl: tržba plyne více poplatníkům, kteří pověřili evidencí'
    AFTER delegating_eic;

-- Formát EIČ pověřujícího (CZ + 8 až 10 číslic, nikdy prázdný řetězec) hlídá
-- i databáze, aby se do zprávy nedostala hodnota, kterou XSD odmítne.
ALTER TABLE eet_devices
  ADD CONSTRAINT IF NOT EXISTS chk_eet_device_delegating_eic CHECK (
    delegating_eic IS NULL OR delegating_eic REGEXP '^CZ[0-9]{8,10}$'
  );

ALTER TABLE eet_records
  ADD CONSTRAINT IF NOT EXISTS chk_eet_records_delegating_eic CHECK (
    delegating_eic IS NULL OR delegating_eic REGEXP '^CZ[0-9]{8,10}$'
  ),
  ADD CONSTRAINT IF NOT EXISTS chk_eet_records_multiple_delegators CHECK (multiple_delegators IN (0, 1));
