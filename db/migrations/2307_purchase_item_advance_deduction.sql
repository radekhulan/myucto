-- Odpočet poskytnuté zálohy na řádku přijaté konečné faktury.
--
-- Konečná (vyúčtovací) faktura od dodavatele, který k záloze vystavil daňový doklad
-- k přijaté platbě (DDKP), odečítá zálohu záporným řádkem se základem a daní DDKP.
-- Dosud se takový řádek od běžného záporného řádku (vratka, dobropisovaná položka)
-- nedal rozeznat: šel na náklad, zaúčtování konečné faktury s DDKP se odmítalo
-- a bez řádku hrozil dvojí odpočet DPH (DDKP v jednom období, plná daň konečné
-- faktury v dalším). Sloupec `advance_purchase_invoice_id` řádek výslovně označí jako
-- odpočet zálohy, kterou konečná faktura vyúčtovává (zálohová faktura nebo samostatný
-- DDKP). Bez označení zůstává řádek běžnou položkou, takže stávající data se nemění.
--
-- Mazání zálohy odkaz jen vynuluje, stejně jako u odpočtových řádků vydaných faktur
-- (migrace 2298).
SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS advance_purchase_invoice_id BIGINT UNSIGNED NULL
        COMMENT 'poskytnutá záloha, jejíž odpočet řádek nese; NULL = běžná položka'
        AFTER purchase_order_line_id;

CREATE INDEX IF NOT EXISTS ix_pii_advance_purchase_invoice ON purchase_invoice_items (advance_purchase_invoice_id, purchase_invoice_id);

ALTER TABLE purchase_invoice_items
    ADD CONSTRAINT fk_pii_advance_purchase_invoice FOREIGN KEY IF NOT EXISTS (advance_purchase_invoice_id) REFERENCES purchase_invoices (id) ON DELETE SET NULL;
