-- MyÚčto.cz: období časového rozlišení výnosu u ostatních pohledávek.
--
-- PROČ: pohledávka za nájem, licenci nebo jiné plnění přes rozvahový den zaúčtuje celý
-- výnos k datu dokladu. Uzávěrka (krok „Časové rozlišení") z ní stejně jako z řádků
-- vydaných faktur odloží na 384 část připadající na příští období a další rok ji rozpustí.
-- Obě data jsou buď vyplněná (od <= do), nebo prázdná; prázdná = bez rozlišení.
-- Index slouží uzávěrce, která hledá položky s obdobím končícím za rozvahovým dnem.

SET NAMES utf8mb4;

ALTER TABLE other_items
  ADD COLUMN IF NOT EXISTS accrual_from DATE NULL AFTER due_on,
  ADD COLUMN IF NOT EXISTS accrual_to DATE NULL AFTER accrual_from;

ALTER TABLE other_items ADD INDEX IF NOT EXISTS idx_other_item_accrual_to (supplier_id, accrual_to);
