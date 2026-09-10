-- Koncovka karty, kterou byl přijatý doklad (účtenka, faktura) zaplacen.
--
-- Párování plateb kartou ji používá jako silný signál: pohyb kartou se spáruje
-- jen s dokladem téže karty, takže dvě stejné částky dvěma kartami téhož dne
-- se nemohou spárovat křížem. NULL = karta na dokladu není uvedena.

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS card_last4 CHAR(4) NULL AFTER payment_method_source;

ALTER TABLE purchase_invoices
  ADD INDEX IF NOT EXISTS idx_pi_card_last4 (supplier_id, card_last4);

ALTER TABLE purchase_invoices DROP CONSTRAINT IF EXISTS chk_pi_card_last4;
ALTER TABLE purchase_invoices
  ADD CONSTRAINT chk_pi_card_last4 CHECK (card_last4 IS NULL OR card_last4 REGEXP '^[0-9]{4}$');
