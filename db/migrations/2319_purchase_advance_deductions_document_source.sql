-- Přijatá konečná faktura, která vyúčtuje víc poskytnutých záloh (vyúčtování energií:
-- jedna faktura za rok, dvanáct a víc záloh, k většině daňový doklad k platbě).
--
-- Vazba hlavičkou `purchase_invoices.advance_purchase_invoice_id` je 1:1 a zůstává pro
-- fakturu s jedinou zálohou. Faktura s víc zálohami hlavičku nemá a každou zálohu nese
-- řádek čerpání po dvojicích v `purchase_advance_deductions` se zdrojem `document`.
-- Saldokonto 314 ho tak čte stejně jako čerpání z převodu nebo z akce Vyrovnat. Řádek
-- zapisuje a přepočítává jen uložení a propojení dokladu (PurchaseAdvanceLinks),
-- v saldokontu se nezadává ani neruší.
SET NAMES utf8mb4;

ALTER TABLE purchase_advance_deductions
  MODIFY COLUMN source ENUM('import','saldo','document') NOT NULL DEFAULT 'import'
      COMMENT 'import = převod z účetního programu, saldo = ručně v saldokontu, document = konečná faktura vyúčtovávající víc záloh';

-- Řádek document vzniká propojením zálohy dřív, než faktura nese její odpočet (a záloha
-- bez DDKP ještě nemusí být uhrazená), takže smí mít nulovou částku. Ostatní zdroje dál
-- jen kladnou.
ALTER TABLE purchase_advance_deductions DROP CONSTRAINT IF EXISTS chk_pad_amount;
ALTER TABLE purchase_advance_deductions
  ADD CONSTRAINT chk_pad_amount CHECK (amount > 0 OR (source = 'document' AND amount >= 0));

CREATE INDEX IF NOT EXISTS ix_pad_final_source ON purchase_advance_deductions (supplier_id, final_invoice_id, source);
