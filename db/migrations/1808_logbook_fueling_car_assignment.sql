-- MyÚčto.cz — Kniha jízd: podle čeho bylo vozidlo k tankování přiřazeno a kterou
-- kartou se tankování platilo.
--
--   fuelings.car_assigned_by  explicit = vybral uživatel, plate = SPZ z dokladu,
--                             text = SPZ nalezená v textu dokladu, card = platební karta
--                             → držitel → jeho vozidlo, default = výchozí / jediné vozidlo.
--                             NULL = záznam z doby před evidencí způsobu přiřazení.
--   fuelings.card_last4       koncovka karty z dokladu (nikdy celé číslo — CHECK).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, CHECK přes DROP IF EXISTS + ADD.

SET NAMES utf8mb4;

ALTER TABLE fuelings
  ADD COLUMN IF NOT EXISTS car_assigned_by ENUM('explicit','plate','text','card','default') NULL
      COMMENT 'Podle čeho bylo vozidlo přiřazeno' AFTER car_id,
  ADD COLUMN IF NOT EXISTS card_last4 CHAR(4) NULL
      COMMENT 'Koncovka platební karty z dokladu' AFTER source_journal_entry_id;

ALTER TABLE fuelings DROP CONSTRAINT IF EXISTS chk_fuelings_card_last4;
ALTER TABLE fuelings
  ADD CONSTRAINT chk_fuelings_card_last4 CHECK (card_last4 IS NULL OR card_last4 REGEXP '^[0-9]{4}$');
