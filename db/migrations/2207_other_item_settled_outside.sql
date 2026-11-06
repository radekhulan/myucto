-- MyÚčto.cz — Ostatní pohledávky a závazky: část uhrazená mimo banku a pokladnu.
--
-- Úhradu ostatní položky nese alokace bankovního pohybu nebo pokladního dokladu
-- (`other_item_allocations`, právě jeden z nich). Doklad převzatý z jiného programu
-- ale mohl být uhrazen zápočtem, interním dokladem nebo v roce, který se nepřeváděl.
-- `settled_outside_amount` nese tuto část podle stavu úhrady ve zdroji, takže doklad,
-- který zdroj vede jako uhrazený, nezůstane v agendě ani v předpovědi otevřený.
-- Zbývá uhradit = částka - alokace - settled_outside_amount.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS, CHECK se nejdřív zahodí.

SET NAMES utf8mb4;

ALTER TABLE other_items
  ADD COLUMN IF NOT EXISTS settled_outside_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER amount_czk;

ALTER TABLE other_items DROP CONSTRAINT IF EXISTS chk_other_item_settled_outside;
ALTER TABLE other_items
  ADD CONSTRAINT chk_other_item_settled_outside CHECK (settled_outside_amount >= 0 AND settled_outside_amount <= amount);
