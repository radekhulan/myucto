-- MyÚčto.cz — vydaná faktura navázaná na ostatní pohledávku.
--
-- Prodej (typicky pozemku) se účtuje už při podpisu smlouvy ostatní pohledávkou
-- (311.x / 641.x) a úhrada se páruje na ni. Faktura vystavená později na tutéž částku
-- výnos podruhé neúčtuje a její úhrada je úhradou ostatní pohledávky.
--
-- invoices.other_item_id = ostatní pohledávka, jejíž zápis je účetním předpisem faktury.
-- Faktura s vazbou nemá vlastní zápis v deníku. Jedna pohledávka patří nejvýše jedné
-- faktuře (UNIQUE), storno faktury vazbu ruší. Platby faktury zrcadlí přiřazené úhrady
-- pohledávky ve zdroji `other_item`, který banka ani pokladna znovu nepárují.
--
-- Re-run safe: ADD COLUMN/INDEX/FOREIGN KEY IF NOT EXISTS, MODIFY append-only ENUM.

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS other_item_id BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'ostatní pohledávka, jejíž zápis účtuje fakturu';

ALTER TABLE invoices
  ADD UNIQUE INDEX IF NOT EXISTS uq_invoices_other_item (other_item_id);

ALTER TABLE invoices
  ADD CONSTRAINT fk_invoices_other_item FOREIGN KEY IF NOT EXISTS (other_item_id)
    REFERENCES other_items (id) ON DELETE SET NULL;

ALTER TABLE invoice_payments
  MODIFY COLUMN source
  ENUM('manual','mark_paid','bank','legacy','cash','settlement','credit_note','other_item')
  NOT NULL DEFAULT 'manual';
