-- MyÚčto.cz — Přijaté faktury: část uhrazená mimo evidované kanály.
--
-- Úhradu přijaté faktury nese banka, pokladna nebo zápočet. Doklad převzatý z jiného
-- programu ale mohl být částečně uhrazen v roce, který se nepřevádí, nebo zápočtem,
-- který převod nedokáže navázat. V deníku je to správně (počáteční stav, interní doklad),
-- jen „Zbývá uhradit" by ukazovalo celý doklad. `settled_outside_amount` nese tuto část,
-- stejně jako u ostatních pohledávek a závazků (migrace 2207).
-- Zbývá uhradit = amount_to_pay - evidované úhrady - settled_outside_amount.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS, CHECK se nejdřív zahodí.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS settled_outside_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER advance_paid_amount;

ALTER TABLE purchase_invoices DROP CONSTRAINT IF EXISTS chk_purchase_settled_outside;
ALTER TABLE purchase_invoices
  ADD CONSTRAINT chk_purchase_settled_outside CHECK (settled_outside_amount >= 0);
