-- MyÚčto.cz: období časového rozlišení u ostatních pohledávek a závazků.
--
-- PROČ: pohledávka za nájem, licenci nebo jiné plnění přes rozvahový den zaúčtuje celý
-- výnos k datu dokladu, závazek (předplatné, pojištění) celý náklad. Uzávěrka (krok
-- „Časové rozlišení") z nich stejně jako z řádků faktur odloží část připadající na příští
-- období (výnos na 384, náklad na 381) a další rok ji rozpustí.
-- Obě data jsou buď vyplněná (od <= do), nebo prázdná; prázdná = bez rozlišení.
-- Index slouží uzávěrce, která hledá položky s obdobím končícím za rozvahovým dnem.

SET NAMES utf8mb4;

ALTER TABLE other_items
  ADD COLUMN IF NOT EXISTS accrual_from DATE NULL AFTER due_on,
  ADD COLUMN IF NOT EXISTS accrual_to DATE NULL AFTER accrual_from;

ALTER TABLE other_items ADD INDEX IF NOT EXISTS idx_other_item_accrual_to (supplier_id, accrual_to);
