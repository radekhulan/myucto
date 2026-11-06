-- MyÚčto.cz - Ostatní pohledávky a závazky: jistina, úrok a poplatek ve splátce.
--
-- Splátka úvěru, půjčky nebo leasingu se může rozdělit na jistinu, úrok a poplatek
-- (`principal_amount`, `interest_amount`, `fee_amount`). NULL ve všech třech = splátka
-- nerozdělená, platí jen `amount` jako dosud. Rozdělená splátka má vyplněné všechny tři
-- složky a jejich součet je přesně `amount` (částka, kterou je třeba zaplatit).
--
-- Částka položky je jistina: součet COALESCE(principal_amount, amount) přes splátky
-- odpovídá `other_items.amount`. Úrok a poplatek nejsou součástí předpisu na účtu
-- položky, účtují se až s úhradou splátky přímo do nákladů nebo výnosů.
--
-- `other_item_allocations.amount` zůstává částkou, o kterou úhrada snižuje saldo
-- položky (jistina). Úrok a poplatek uhrazené toutéž platbou nese
-- `interest_amount` a `fee_amount`; peněžní částka úhrady je součet všech tří.
-- Úhrada, která pokryje jen úrok nebo poplatek, má jistinu 0, proto se původní
-- kontrola `amount > 0` mění na kontrolu kladného součtu.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS, DROP/ADD CONSTRAINT IF [NOT] EXISTS.

SET NAMES utf8mb4;

ALTER TABLE other_item_installments
  ADD COLUMN IF NOT EXISTS principal_amount DECIMAL(14,2) NULL AFTER amount,
  ADD COLUMN IF NOT EXISTS interest_amount DECIMAL(14,2) NULL AFTER principal_amount,
  ADD COLUMN IF NOT EXISTS fee_amount DECIMAL(14,2) NULL AFTER interest_amount;

ALTER TABLE other_item_installments
  ADD CONSTRAINT IF NOT EXISTS chk_other_installment_components CHECK (
    (principal_amount IS NULL AND interest_amount IS NULL AND fee_amount IS NULL)
    OR (principal_amount IS NOT NULL AND interest_amount IS NOT NULL AND fee_amount IS NOT NULL
        AND principal_amount >= 0 AND interest_amount >= 0 AND fee_amount >= 0
        AND principal_amount + interest_amount + fee_amount = amount)
  );

ALTER TABLE other_item_allocations
  ADD COLUMN IF NOT EXISTS interest_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER amount,
  ADD COLUMN IF NOT EXISTS fee_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER interest_amount;

ALTER TABLE other_item_allocations
  DROP CONSTRAINT IF EXISTS chk_other_allocation_amount;

ALTER TABLE other_item_allocations
  ADD CONSTRAINT IF NOT EXISTS chk_other_allocation_amounts CHECK (
    amount >= 0 AND interest_amount >= 0 AND fee_amount >= 0 AND amount + interest_amount + fee_amount > 0
  );
